<?php
/**
 * Agent credentials across plugin deactivation and reactivation.
 *
 * @package WordPress\AI\Experiments\Agent_Users
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Agent_Users;

use WP_Application_Passwords;
use WP_Error;
use WP_User;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Keeps agent credentials from outliving the safeguards that bound them.
 *
 * The agent safeguards are plugin code, so nothing rejects or limits an
 * agent's Application Passwords while the plugin is inactive. Deactivating
 * the plugin on purpose therefore revokes them. Core deactivates and
 * reactivates plugins silently while updating them, without running
 * deactivation hooks, so updates leave credentials alone.
 *
 * @since x.x.x
 */
final class Agent_Credentials {
	/**
	 * User meta key recording an agent's revoked credentials.
	 *
	 * Holds the hashes of the revoked Application Passwords, when they were
	 * revoked, and whether the parent was told, until the agent gets a new
	 * one. It is user meta, so it survives the plugin being uninstalled and
	 * installed again.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const META_REVOKED = 'wpai_agent_revoked_credentials';

	/**
	 * Network option marking a plugin update in progress.
	 *
	 * Core deactivates the plugin silently while updating it. If the update
	 * fails and someone activates the plugin by hand, that activation must not
	 * revoke credentials the update never meant to touch. A successful update
	 * clears it, and so does any deliberate activation or deactivation.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const UPDATING_OPTION = 'wpai_agent_plugin_updating';

	/**
	 * The failed agent authentication of the current request, if any.
	 *
	 * @since x.x.x
	 *
	 * @var \WP_Error|null
	 */
	private ?WP_Error $agent_failure = null;

	/**
	 * Registers the credential lifecycle hooks.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		$basename = plugin_basename( WPAI_PLUGIN_FILE );

		add_action( 'after_plugin_row_' . $basename, array( $this, 'render_deactivation_warning' ), 10, 0 );
		add_filter( 'plugin_action_links_' . $basename, array( $this, 'confirm_deactivation' ) );
		add_filter( 'network_admin_plugin_action_links_' . $basename, array( $this, 'confirm_deactivation' ) );
		add_action( 'admin_notices', array( $this, 'render_reissue_notice' ) );
		add_action( 'wp_create_application_password', array( $this, 'forget_revoked_credentials' ) );
		add_filter( 'upgrader_pre_install', array( $this, 'note_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'forget_update' ), 10, 2 );
		add_action( 'application_password_failed_authentication', array( $this, 'explain_agent_failure' ) );
		// After core's cookie check, the first point where the REST API has authenticated the request.
		add_filter( 'rest_authentication_errors', array( $this, 'report_agent_failure' ), 101 );
	}

	/**
	 * Revokes every agent's Application Passwords on deliberate deactivation.
	 *
	 * On multisite, agents are provisioned only while the plugin is
	 * network-active, so only network deactivation revokes them; other sites
	 * keep protecting the same network-wide accounts after a single site
	 * deactivates the plugin.
	 *
	 * @since x.x.x
	 *
	 * @param bool $network_wide Whether the plugin is being deactivated across the network.
	 */
	public static function revoke_on_deactivation( bool $network_wide ): void {
		if ( is_multisite() && ! $network_wide ) {
			return;
		}

		$revoked = self::revoke_all();
		delete_site_option( self::UPDATING_OPTION );
		if ( 0 === $revoked || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::warning(
			sprintf(
				/* translators: %d: Number of agents. */
				_n(
					'Revoked the Application Passwords of %d agent. Its parent user has to create a new one after the plugin is reactivated.',
					'Revoked the Application Passwords of %d agents. Their parent users have to create new ones after the plugin is reactivated.',
					$revoked,
					'ai'
				),
				$revoked
			)
		);
	}

	/**
	 * Revokes the Application Passwords of every agent that has any.
	 *
	 * The hashes are kept with the agent so a revoked credential can still be
	 * recognized and explained once the plugin is active again.
	 *
	 * @since x.x.x
	 *
	 * @return int Number of agents whose credentials were revoked.
	 */
	public static function revoke_all(): int {
		$revoked = 0;

		foreach ( self::agent_ids() as $agent_id ) {
			$passwords = WP_Application_Passwords::get_user_application_passwords( $agent_id );
			if ( array() === $passwords ) {
				continue;
			}

			update_user_meta(
				$agent_id,
				self::META_REVOKED,
				array(
					'hashes'     => array_values( wp_list_pluck( $passwords, 'password' ) ),
					'revoked_at' => time(),
					'notified'   => false,
				)
			);
			WP_Application_Passwords::delete_all_application_passwords( $agent_id );
			++$revoked;
		}

		return $revoked;
	}

	/**
	 * Tells parents which agents need new credentials once the plugin is active again.
	 *
	 * Runs on deliberate activation. Core reactivates plugins silently after
	 * updating them, and updates revoke nothing, so there is nothing to tell
	 * then. Any agent credentials that exist now were never bounded by the
	 * plugin: created while it was inactive, or kept because it stopped
	 * without its deactivation step, as when deactivated from recovery mode or
	 * when its files went missing. They are revoked too, unless an update left
	 * the plugin inactive. After a deliberate deactivation this finds nothing.
	 *
	 * @since x.x.x
	 *
	 * @param bool $network_wide Whether the plugin is being activated across the network.
	 */
	public static function on_activation( bool $network_wide ): void {
		if ( is_multisite() && ! $network_wide ) {
			return;
		}

		if ( false === get_site_option( self::UPDATING_OPTION ) ) {
			self::revoke_all();
		}

		delete_site_option( self::UPDATING_OPTION );
		self::notify_parents();
	}

	/**
	 * Marks an update of this plugin as in progress.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $response   Installation response, passed through.
	 * @param mixed $hook_extra Extra arguments passed to the upgrader.
	 * @return mixed The response.
	 */
	public function note_update( $response, $hook_extra ) {
		if ( is_array( $hook_extra ) && plugin_basename( WPAI_PLUGIN_FILE ) === ( $hook_extra['plugin'] ?? '' ) ) {
			update_site_option( self::UPDATING_OPTION, 1 );
		}

		return $response;
	}

	/**
	 * Clears the update marker once an update of this plugin succeeded.
	 *
	 * Core fires this after failed single-plugin updates too, so the result is
	 * checked; a failed update keeps the marker for the manual activation.
	 * Bulk updates never deactivate plugins, so they always clear it.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $upgrader   The upgrader.
	 * @param mixed $hook_extra Extra arguments passed to the upgrader.
	 */
	public function forget_update( $upgrader, $hook_extra ): void {
		$basename = plugin_basename( WPAI_PLUGIN_FILE );
		if (
			! is_array( $hook_extra ) ||
			'plugin' !== ( $hook_extra['type'] ?? '' ) ||
			! in_array( $basename, array_merge( (array) ( $hook_extra['plugins'] ?? array() ), array( $hook_extra['plugin'] ?? '' ) ), true )
		) {
			return;
		}

		$result = $upgrader instanceof \WP_Upgrader ? $upgrader->skin->result : null;
		if ( empty( $hook_extra['bulk'] ) && ( is_wp_error( $result ) || false === $result ) ) {
			return;
		}

		delete_site_option( self::UPDATING_OPTION );
	}

	/**
	 * Emails each parent once about their agents with revoked credentials.
	 *
	 * @since x.x.x
	 */
	public static function notify_parents(): void {
		$agents_by_parent = array();

		foreach ( self::revoked_agent_ids() as $agent_id ) {
			$record = self::revoked_record( $agent_id );
			if ( null === $record || ! empty( $record['notified'] ) ) {
				continue;
			}

			$record['notified'] = true;
			update_user_meta( $agent_id, self::META_REVOKED, $record );

			$parent = Agent_Account::get_parent( $agent_id );
			if ( null === $parent ) {
				continue;
			}

			$agents_by_parent[ $parent->ID ][] = $agent_id;
		}

		foreach ( $agents_by_parent as $parent_id => $agent_ids ) {
			self::email_parent( $parent_id, $agent_ids );
		}
	}

	/**
	 * Lists the current user's agents that need new credentials.
	 *
	 * The notice stays until each agent has a new Application Password, so a
	 * parent who misses the email still sees it.
	 *
	 * @since x.x.x
	 */
	public function render_reissue_notice(): void {
		$links = array();
		foreach ( Agent_Account::get_agent_ids( get_current_user_id() ) as $agent_id ) {
			$agent = get_userdata( $agent_id );
			if ( ! $agent instanceof \WP_User || null === self::revoked_record( $agent_id ) || ! current_user_can( 'edit_user', $agent_id ) ) {
				continue;
			}

			$links[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( Profile_Screen::url( $agent_id ) ), esc_html( $agent->display_name ) );
		}

		if ( array() === $links ) {
			return;
		}

		wp_admin_notice(
			wp_kses(
				sprintf(
					/* translators: %s: Comma-separated links to agent profiles. */
					__( 'The Application Passwords of your agents were revoked when the AI plugin was deactivated. Create a new one for each agent so it can reconnect: %s', 'ai' ),
					implode( ', ', $links )
				),
				array( 'a' => array( 'href' => array() ) )
			),
			array(
				'type'        => 'warning',
				'dismissible' => false,
				'id'          => 'wpai-agent-reissue-notice',
			)
		);
	}

	/**
	 * Forgets an agent's revoked credentials once it gets a new one.
	 *
	 * @since x.x.x
	 *
	 * @param int $user_id User the Application Password was created for.
	 */
	public function forget_revoked_credentials( $user_id ): void {
		delete_user_meta( (int) $user_id, self::META_REVOKED );
	}

	/**
	 * Explains a failed agent authentication and keeps it for the REST response.
	 *
	 * A credential revoked on deactivation fails like any wrong password, so
	 * one that matches a revoked hash gets an error that says what happened
	 * and who can create a new one. Only the plugin's own errors are kept for
	 * the REST response: the revoked one, and suspension, which core reports
	 * only after a password matched. Both reach only someone who held a real
	 * credential, so other failures, such as a wrong password, still look the
	 * same for agents and humans and do not reveal which accounts are agents.
	 * XML-RPC reports every failure the same way, so it is left alone.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Error $error The authentication error, changed in place.
	 */
	public function explain_agent_failure( $error ): void {
		if ( ! $error instanceof WP_Error || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}

		$agent = self::requesting_agent();
		if ( null === $agent ) {
			return;
		}

		if ( 'incorrect_password' === $error->get_error_code() && self::presents_revoked_credential( $agent->ID ) ) {
			$error->remove( 'incorrect_password' );
			$error->add( 'wpai_agent_credentials_revoked', self::revoked_message( $agent ), array( 'status' => 401 ) );
		}

		if ( 0 !== strpos( (string) $error->get_error_code(), 'wpai_agent_' ) ) {
			return;
		}

		$this->agent_failure = $error;
	}

	/**
	 * Returns a failed agent authentication as the REST response.
	 *
	 * Core reports Application Password failures before it authenticates
	 * the request, so they would otherwise reach clients as a generic
	 * `rest_not_logged_in`. Only the plugin's own agent errors are reported
	 * here; every other failure keeps core's behavior.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $result The authentication result so far.
	 * @return mixed The result, or the agent's authentication error.
	 */
	public function report_agent_failure( $result ) {
		$failure             = $this->agent_failure;
		$this->agent_failure = null;

		// Core's cookie check returns true for a request without a nonce, meaning
		// "not authenticated by cookie"; only an earlier error takes precedence.
		if ( is_wp_error( $result ) || null === $failure ) {
			return $result;
		}

		$data = $failure->get_error_data();
		if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
			$failure->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => 401 ) ) );
		}

		return $failure;
	}

	/**
	 * Warns on the Plugins screen that deactivation revokes agent credentials.
	 *
	 * Core has no hook on the deactivate action itself, so the warning sits
	 * under the plugin row, where update notices appear, for as long as agents
	 * exist. It is shown only where deactivation revokes them.
	 *
	 * @since x.x.x
	 */
	public function render_deactivation_warning(): void {
		if ( ! self::deactivation_revokes() || ! current_user_can( 'deactivate_plugin', plugin_basename( WPAI_PLUGIN_FILE ) ) || ! Agent_Account::agents_exist() ) {
			return;
		}

		$list_table = $GLOBALS['wp_list_table'] ?? null;
		$columns    = $list_table instanceof \WP_List_Table ? $list_table->get_column_count() : 4;

		printf(
			'<tr class="plugin-update-tr active wpai-agent-deactivation-warning"><td colspan="%1$d" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt"><p>%2$s</p></div></td></tr>',
			(int) $columns,
			esc_html__( 'Agent accounts exist. Deactivating this plugin revokes every agent\'s Application Passwords, so agents stop working until their parent users create new ones after the plugin is reactivated. Updating the plugin does not revoke them.', 'ai' )
		);
	}

	/**
	 * Asks for confirmation before the Deactivate link revokes agent credentials.
	 *
	 * The row warning stays visible for bulk deactivation, which has no
	 * per-plugin link to confirm.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, string> $actions Plugin action links.
	 * @return array<string, string> Action links, with a confirmation on Deactivate.
	 */
	public function confirm_deactivation( array $actions ): array {
		if ( ! isset( $actions['deactivate'] ) || ! self::deactivation_revokes() || ! Agent_Account::agents_exist() ) {
			return $actions;
		}

		$message = __( 'Deactivating this plugin revokes every agent\'s Application Passwords. Agents stop working until their parent users create new ones after the plugin is reactivated. Deactivate anyway?', 'ai' );

		$actions['deactivate'] = preg_replace(
			'/^<a /',
			'<a onclick="' . esc_attr( 'return window.confirm( ' . wp_json_encode( $message ) . ' );' ) . '" ',
			$actions['deactivate']
		) ?? $actions['deactivate'];

		return $actions;
	}

	/**
	 * Checks whether deactivating the plugin from the current screen revokes credentials.
	 *
	 * @since x.x.x
	 *
	 * @return bool True on single site and in the network admin.
	 */
	private static function deactivation_revokes(): bool {
		return ! is_multisite() || is_network_admin();
	}

	/**
	 * Returns the agent named by the request's Basic authentication, if any.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_User|null The agent, or null.
	 */
	private static function requesting_agent(): ?WP_User {
		// phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.BasicAuthentication -- Reads the credentials core just checked in wp_validate_application_password().
		if ( ! isset( $_SERVER['PHP_AUTH_USER'] ) ) {
			return null;
		}

		$login = sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) );
		// phpcs:enable WordPressVIPMinimum.Variables.ServerVariables.BasicAuthentication
		$user = get_user_by( 'login', $login );
		if ( ! $user instanceof WP_User && is_email( $login ) ) {
			$user = get_user_by( 'email', $login );
		}

		return $user instanceof WP_User && Agent_Account::is_agent( $user ) ? $user : null;
	}

	/**
	 * Checks whether the request presents one of an agent's revoked credentials.
	 *
	 * @since x.x.x
	 *
	 * @param int $agent_id Agent user ID.
	 * @return bool True when the password matches a revoked one.
	 */
	private static function presents_revoked_credential( int $agent_id ): bool {
		// phpcs:disable WordPressVIPMinimum.Variables.ServerVariables.BasicAuthentication -- Reads the credentials core just checked in wp_validate_application_password().
		$record = self::revoked_record( $agent_id );
		if ( null === $record || ! isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			return false;
		}

		// Normalized exactly as core does before comparing Application Passwords.
		$password = (string) preg_replace( '/[^a-z\d]/i', '', (string) wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password, compared against hashes only.
		// phpcs:enable WordPressVIPMinimum.Variables.ServerVariables.BasicAuthentication

		foreach ( $record['hashes'] as $hash ) {
			if ( WP_Application_Passwords::check_password( $password, $hash ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Explains to the agent's software how to get working credentials again.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User $agent The agent.
	 * @return string The message.
	 */
	private static function revoked_message( WP_User $agent ): string {
		$parent = Agent_Account::get_parent( $agent );
		if ( null === $parent ) {
			return __( 'This agent\'s Application Password was revoked when the AI plugin was deactivated. Ask a site administrator to create a new one on the agent\'s profile.', 'ai' );
		}

		return sprintf(
			/* translators: %s: Parent user display name. */
			__( 'This agent\'s Application Password was revoked when the AI plugin was deactivated. Ask %s, who this agent acts for, to create a new one on the agent\'s profile.', 'ai' ),
			$parent->display_name
		);
	}

	/**
	 * Returns an agent's revoked credentials record.
	 *
	 * @since x.x.x
	 *
	 * @param int $agent_id Agent user ID.
	 * @return array{hashes: array<int, string>, revoked_at: int, notified: bool}|null The record, or null.
	 */
	public static function revoked_record( int $agent_id ): ?array {
		$record = get_user_meta( $agent_id, self::META_REVOKED, true );
		if ( ! is_array( $record ) || ! isset( $record['hashes'] ) || ! is_array( $record['hashes'] ) ) {
			return null;
		}

		return array(
			'hashes'     => array_values( array_filter( $record['hashes'], 'is_string' ) ),
			'revoked_at' => (int) ( $record['revoked_at'] ?? 0 ),
			'notified'   => ! empty( $record['notified'] ),
		);
	}

	/**
	 * Emails a parent the agents whose credentials need to be created again.
	 *
	 * @since x.x.x
	 *
	 * @param int             $parent_id Parent user ID.
	 * @param array<int, int> $agent_ids Agents with revoked credentials.
	 */
	private static function email_parent( int $parent_id, array $agent_ids ): void {
		$parent = get_userdata( $parent_id );
		if ( ! $parent instanceof \WP_User ) {
			return;
		}

		$switched = switch_to_user_locale( $parent_id );

		$lines = array();
		foreach ( $agent_ids as $agent_id ) {
			$agent = get_userdata( $agent_id );
			if ( ! $agent instanceof \WP_User ) {
				continue;
			}

			$lines[] = sprintf( '- %1$s: %2$s', $agent->display_name, self::credentials_url( $agent_id, $parent_id ) );
		}

		$network = is_multisite() ? get_network() : null;
		$site    = null !== $network ? $network->site_name : wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		// One email per parent, sent only when the plugin is activated again.
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- A handful of notifications on activation, not bulk mail.
		wp_mail(
			$parent->user_email,
			/* translators: %s: Site or network name. */
			sprintf( __( '[%s] Your agents need new Application Passwords', 'ai' ), $site ),
			sprintf(
				/* translators: 1: Site or network name, 2: List of agents with links to their profiles. */
				__( "The AI plugin was deactivated on %1\$s, which revoked the Application Passwords of the agents that act on your behalf. The plugin is active again. Create a new Application Password for each agent and give it to the software that uses the agent:\n\n%2\$s\n", 'ai' ),
				$site,
				implode( "\n", $lines )
			)
		);

		if ( ! $switched ) {
			return;
		}

		restore_previous_locale();
	}

	/**
	 * Returns the link to an agent's Application Passwords for its parent.
	 *
	 * On multisite the link points to the parent's primary site, where they
	 * can reach the agent's profile.
	 *
	 * @since x.x.x
	 *
	 * @param int $agent_id  Agent user ID.
	 * @param int $parent_id Parent user ID.
	 * @return string The URL.
	 */
	private static function credentials_url( int $agent_id, int $parent_id ): string {
		$site_id = null;
		if ( is_multisite() ) {
			$site_id = (int) get_user_meta( $parent_id, 'primary_blog', true );
			$site_id = $site_id > 0 ? $site_id : get_main_site_id();
		}

		return get_admin_url( $site_id, 'user-edit.php?user_id=' . $agent_id ) . '#application-passwords-section';
	}

	/**
	 * Returns the IDs of agents with revoked credentials across the network.
	 *
	 * @since x.x.x
	 *
	 * @return array<int, int> Agent user IDs.
	 */
	private static function revoked_agent_ids(): array {
		$ids = get_users(
			array(
				'blog_id'      => 0,
				'fields'       => 'ID',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs on activation; agents are a small set.
				'meta_key'     => self::META_REVOKED,
				'meta_compare' => 'EXISTS',
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Returns the IDs of every agent across the network.
	 *
	 * @since x.x.x
	 *
	 * @return array<int, int> Agent user IDs.
	 */
	private static function agent_ids(): array {
		$ids = get_users(
			array(
				'blog_id'      => 0,
				'fields'       => 'ID',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs on deactivation; agents are a small set.
				'meta_key'     => Agent_Account::META_KEY,
				'meta_compare' => 'EXISTS',
			)
		);

		return array_map( 'intval', $ids );
	}
}

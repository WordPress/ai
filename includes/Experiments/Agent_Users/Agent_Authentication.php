<?php
/**
 * Agent account authentication rules.
 *
 * @package WordPress\AI\Experiments\Agent_Users
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Agent_Users;

use WP_Error;
use WP_User;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Keeps agent authentication non-interactive.
 *
 * Agents cannot log in with a password or reset one, and they authenticate
 * with Application Passwords, which stop working while they are suspended.
 *
 * @since x.x.x
 */
final class Agent_Authentication {
	/**
	 * Hooks the authentication rules into WordPress.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_filter( 'wp_authenticate_user', array( $this, 'block_interactive_login' ) );
		add_filter( 'allow_password_reset', array( $this, 'disable_password_reset' ), 10, 2 );
		add_filter( 'wp_pre_insert_user_data', array( $this, 'keep_agent_password' ), 10, 3 );
		add_filter( 'send_password_change_email', array( $this, 'skip_agent_password_change_email' ), 10, 2 );
		add_action( 'wp_set_password', array( $this, 'restore_agent_password' ), 10, 3 );
		add_filter( 'rest_request_before_callbacks', array( $this, 'reject_agent_password_over_rest' ), 10, 3 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( $this, 'ensure_application_passwords' ), 10, 2 );
		add_filter( 'map_meta_cap', array( $this, 'block_agent_credentials_without_network_safeguards' ), 10, 4 );
		add_action( 'wp_authenticate_application_password_errors', array( $this, 'reject_suspended_agent_credentials' ), 10, 2 );
	}

	/**
	 * Blocks interactive login for agent accounts.
	 *
	 * Runs on the `wp_authenticate_user` filter, which fires for password
	 * form logins (wp-login.php and XML-RPC). Application Passwords
	 * authenticate through a separate path and keep working.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User|\WP_Error $user The authenticated user, or an error from an earlier check.
	 * @return \WP_User|\WP_Error The user, or an error for agent accounts.
	 */
	public function block_interactive_login( $user ) {
		if ( ! $user instanceof WP_User || ! Agent_Account::is_agent( $user ) ) {
			return $user;
		}

		return new WP_Error(
			'wpai_agent_login_disabled',
			__( 'Agent accounts cannot log in with a password. Authenticate with an Application Password instead; the agent\'s parent user or an administrator can create one on the agent\'s profile.', 'ai' )
		);
	}

	/**
	 * Disables password resets for agent accounts.
	 *
	 * @since x.x.x
	 *
	 * @param bool $allow   Whether the reset is allowed.
	 * @param int  $user_id The user requesting a reset.
	 * @return bool False for agent accounts.
	 */
	public function disable_password_reset( bool $allow, int $user_id ): bool {
		if ( Agent_Account::is_agent( $user_id ) ) {
			return false;
		}

		return $allow;
	}

	/**
	 * Keeps an agent's password when its account is updated.
	 *
	 * Agents authenticate with Application Passwords, or with whatever other
	 * mechanism a site adds through core's authentication hooks, never with a
	 * password. Provisioning sets an unknown random one; keeping it means
	 * nobody holds a password that would work if the safeguards were gone.
	 * This covers every `wp_update_user()` caller, including the profile
	 * screen, REST, and WP-CLI.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $data    User data about to be saved.
	 * @param bool                 $update  Whether an existing user is being updated.
	 * @param int|null             $user_id The updated user, or null for a new one.
	 * @return array<string, mixed> User data, with the stored password for agents.
	 */
	public function keep_agent_password( array $data, bool $update, ?int $user_id ): array {
		if ( ! $update || null === $user_id || ! Agent_Account::is_agent( $user_id ) ) {
			return $data;
		}

		$stored = get_userdata( $user_id );
		if ( $stored instanceof WP_User ) {
			$data['user_pass'] = $stored->user_pass;
		}

		return $data;
	}

	/**
	 * Skips the "password changed" email for agents, whose password never changes.
	 *
	 * @since x.x.x
	 *
	 * @param bool                 $send Whether to send the email.
	 * @param array<string, mixed> $user The user before the update.
	 * @return bool False for agent accounts.
	 */
	public function skip_agent_password_change_email( bool $send, array $user ): bool {
		if ( Agent_Account::is_agent( (int) ( $user['ID'] ?? 0 ) ) ) {
			return false;
		}

		return $send;
	}

	/**
	 * Restores an agent's password after a direct `wp_set_password()` call.
	 *
	 * `wp_set_password()` writes the hash without any filter, so the stored
	 * one is put back right after it.
	 *
	 * @since x.x.x
	 *
	 * @param string            $password      The new plaintext password.
	 * @param int               $user_id       The user whose password was set.
	 * @param \WP_User|null     $old_user_data The user before the change.
	 */
	public function restore_agent_password( string $password, int $user_id, $old_user_data = null ): void {
		if ( ! $old_user_data instanceof WP_User || ! Agent_Account::is_agent( $user_id ) ) {
			return;
		}

		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_pass' => $old_user_data->user_pass ), array( 'ID' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users -- Undoing wp_set_password(), which writes the same way.
		clean_user_cache( $user_id );
	}

	/**
	 * Refuses REST requests that set an agent's password.
	 *
	 * `keep_agent_password()` already ignores the value; the error tells the
	 * client so instead of reporting a successful update.
	 *
	 * @since x.x.x
	 *
	 * @param mixed                $response Response to replace the handler's, or null.
	 * @param array<string, mixed> $handler  Route handler.
	 * @param \WP_REST_Request     $request  The request.
	 * @return mixed The response, or an error for agent password changes.
	 */
	public function reject_agent_password_over_rest( $response, array $handler, \WP_REST_Request $request ) {
		if (
			null !== $response ||
			'GET' === $request->get_method() ||
			! $request->has_param( 'password' ) ||
			1 !== preg_match( '#^/wp/v2/users/(\d+|me)$#', $request->get_route(), $matches )
		) {
			return $response;
		}

		$user_id = 'me' === $matches[1] ? get_current_user_id() : (int) $matches[1];
		if ( ! Agent_Account::is_agent( $user_id ) ) {
			return $response;
		}

		return new WP_Error(
			'wpai_agent_password_not_allowed',
			__( 'Agent accounts have no password. Authenticate with an Application Password instead.', 'ai' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Keeps Application Passwords available for agent accounts.
	 *
	 * Application Passwords are the built-in credential path for agents, so a
	 * user-level filter must not lock them out. The global availability check,
	 * including the HTTPS requirement, is not overridden. On multisite the
	 * credential identifies the network user; site roles determine its authority.
	 *
	 * @since x.x.x
	 *
	 * @param bool     $available Whether Application Passwords are available for the user.
	 * @param \WP_User $user      The user being checked.
	 * @return bool True for agent accounts.
	 */
	public function ensure_application_passwords( bool $available, WP_User $user ): bool {
		if ( Agent_Account::is_agent( $user ) ) {
			return true;
		}

		return $available;
	}

	/**
	 * Rejects Application Passwords of suspended agents.
	 *
	 * The credentials are kept so an administrator can inspect and revoke them,
	 * but they stop authenticating while the agent is suspended.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Error $error Errors collected while authenticating, added to in place.
	 * @param \WP_User  $user  The user authenticating.
	 */
	public function reject_suspended_agent_credentials( WP_Error $error, WP_User $user ): void {
		$messages = array(
			'no_parent'           => __( 'This agent is suspended because it has no parent user.', 'ai' ),
			'role_not_allowed'    => __( 'This agent is suspended on this site because its role manages the site, which agents cannot do.', 'ai' ),
			'parent_not_member'   => __( 'This agent is suspended on this site because its parent user is not a member of it.', 'ai' ),
			'parent_not_eligible' => __( 'This agent is suspended because its parent user can no longer have agents on this site.', 'ai' ),
		);

		$reason = Agent_Account::get_suspension_reason( $user );
		if ( ! isset( $messages[ $reason ] ) ) {
			return;
		}

		$error->add( 'wpai_agent_suspended', $messages[ $reason ] );
	}

	/**
	 * Stops new agent credentials while the plugin is active on only some sites.
	 *
	 * An Application Password authenticates the agent on every site of the
	 * network, but the safeguards bounding it run only where the plugin is
	 * active. Without network activation, a credential created on one site
	 * would work unbounded on the others, so only existing credentials can be
	 * listed and revoked.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, string> $caps    Primitive capabilities resolved by `map_meta_cap()`.
	 * @param string             $cap     The capability being checked.
	 * @param int                $user_id The user the check runs for.
	 * @param array<int, mixed>  $args    Additional arguments, starting with the target user ID.
	 * @return array<int, string> Filtered primitive capabilities.
	 */
	public function block_agent_credentials_without_network_safeguards( array $caps, string $cap, int $user_id, array $args ): array {
		if (
			'create_app_password' !== $cap ||
			empty( $args[0] ) ||
			! Agent_Account::is_agent( (int) $args[0] ) ||
			Agent_Account::can_enforce_network_safeguards()
		) {
			return $caps;
		}

		return array( 'do_not_allow' );
	}
}

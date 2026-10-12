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
	 * Holds the hashes of the revoked Application Passwords and when they
	 * were revoked, until the agent gets a new one. It is user meta, so it
	 * survives the plugin being uninstalled and installed again.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const META_REVOKED = 'wpai_agent_revoked_credentials';

	/**
	 * Registers the credential lifecycle hooks.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'after_plugin_row_' . plugin_basename( WPAI_PLUGIN_FILE ), array( $this, 'render_deactivation_warning' ), 10, 0 );
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
				)
			);
			WP_Application_Passwords::delete_all_application_passwords( $agent_id );
			++$revoked;
		}

		return $revoked;
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

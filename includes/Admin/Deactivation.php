<?php
/**
 * Runs on plugin deactivation.
 *
 * @package WordPress\AI\Admin
 * @since 1.1.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

use WordPress\AI\Experiments\Agent_Users\Agent_Account;
use WordPress\AI\Experiments\Key_Encryption\Key_Encryption;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Deactivation routines.
 *
 * @internal
 *
 * @since 1.1.0
 */
final class Deactivation {
	/**
	 * Runs on plugin deactivation.
	 *
	 * Revokes the credentials of suspended agents, which nothing
	 * rejects once the plugin is inactive. Reverses the Key
	 * Encryption experiment when it is currently enabled so the
	 * user is never locked out of their API keys after
	 * deactivating the plugin.
	 *
	 * @since 1.1.0
	 * @since x.x.x Revokes the Application Passwords of suspended agents.
	 */
	public static function deactivation_callback(): void {
		Agent_Account::revoke_suspended_agent_credentials();

		if ( ! Key_Encryption::is_effectively_enabled() ) {
			return;
		}

		Key_Encryption::get_bridge()->decrypt_all();
	}
}

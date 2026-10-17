<?php
/**
 * Runs on plugin deactivation.
 *
 * @package WordPress\AI\Admin
 * @since 1.1.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

use WordPress\AI\Experiments\Agent_Users\Agent_Credentials;
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
	 * Revokes every agent's Application Passwords, which nothing
	 * bounds once the plugin is inactive. Reverses the Key
	 * Encryption experiment when it is currently enabled so the
	 * user is never locked out of their API keys after
	 * deactivating the plugin.
	 *
	 * Core deactivates plugins silently while updating them, so
	 * this only runs when the plugin is deactivated on purpose.
	 *
	 * @since 1.1.0
	 * @since x.x.x Revokes every agent's Application Passwords.
	 *
	 * @param bool|null $network_wide Whether the plugin is being deactivated across the network.
	 *                                WP-CLI passes null rather than false.
	 */
	public static function deactivation_callback( $network_wide = false ): void {
		Agent_Credentials::revoke_on_deactivation( (bool) $network_wide );

		if ( ! Key_Encryption::is_effectively_enabled() ) {
			return;
		}

		Key_Encryption::get_bridge()->decrypt_all();
	}
}

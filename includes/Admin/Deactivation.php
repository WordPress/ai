<?php
/**
 * Runs on plugin deactivation.
 *
 * @package WordPress\AI\Admin
 * @since 1.1.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

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
	 * Reverses the Key Encryption experiment when it is
	 * currently enabled so the user is never locked out of
	 * their API keys after deactivating the plugin.
	 *
	 * @since 1.1.0
	 * @since x.x.x Added the `$network_wide` parameter.
	 *
	 * @param bool|null $network_wide Whether the plugin is being deactivated for the whole network.
	 *                                WordPress can pass null here, which is treated as false.
	 */
	public static function deactivation_callback( ?bool $network_wide = false ): void {
		Key_Encryption::for_each_site(
			true === $network_wide,
			static function (): void {
				if ( ! Key_Encryption::is_effectively_enabled() ) {
					return;
				}

				Key_Encryption::get_bridge()->decrypt_all();
			}
		);
	}
}

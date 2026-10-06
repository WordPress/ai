<?php
/**
 * Runs on plugin deactivation.
 *
 * @package WordPress\AI\Admin
 * @since 1.1.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

use Throwable;
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
	 * their API keys after deactivating the plugin. On a network-wide
	 * deactivation this is done for every site in the network.
	 *
	 * @since 1.1.0
	 * @since x.x.x Added the `$network_wide` parameter.
	 *
	 * @param bool $network_wide Whether the plugin is being deactivated for the whole network.
	 */
	public static function deactivation_callback( bool $network_wide = false ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			self::restore_plaintext_keys();
			return;
		}

		$bridge   = Key_Encryption::get_bridge();
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
			$bridge->reset_provider();

			try {
				self::restore_plaintext_keys();
			} catch ( Throwable $e ) {
				// A site whose secrets cannot be decrypted must not stop the other sites
				// from getting their keys back, or block the deactivation itself.
				unset( $e );
			}

			restore_current_blog();
		}

		$bridge->reset_provider();
	}

	/**
	 * Restores the current site's plaintext keys when Key Encryption is enabled on it.
	 *
	 * @since x.x.x
	 */
	private static function restore_plaintext_keys(): void {
		if ( ! Key_Encryption::is_effectively_enabled() ) {
			return;
		}

		Key_Encryption::get_bridge()->decrypt_all();
	}
}

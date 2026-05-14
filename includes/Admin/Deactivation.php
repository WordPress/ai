<?php
/**
 * Runs on plugin deactivation.
 *
 * @package WordPress\AI\Admin
 * @since x.x.x
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
 * @since x.x.x
 */
final class Deactivation {
	/**
	 * Runs on plugin deactivation.
	 *
	 * Reverses the Key Encryption experiment when it is
	 * currently enabled so the user is never locked out of
	 * their API keys after deactivating the plugin.
	 *
	 * @since x.x.x
	 */
	public static function deactivation_callback(): void {
		$option = 'wpai_feature_' . Key_Encryption::get_id() . '_enabled';
		if ( ! (bool) get_option( $option, false ) ) {
			return;
		}

		Key_Encryption::get_bridge()->decrypt_all();
	}
}

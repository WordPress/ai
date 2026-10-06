<?php
/**
 * Runs on plugin activation.
 *
 * @package WordPress\AI\Admin
 * @since 0.6.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

use WordPress\AI\Experiments\Key_Encryption\Key_Encryption;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Activation.
 *
 * @internal
 *
 * @since 0.6.0
 */
final class Activation {
	/**
	 * Runs on plugin activation.
	 *
	 * @since 0.6.0
	 * @since x.x.x Added the `$network_wide` parameter.
	 *
	 * @param bool $network_wide Whether the plugin is being activated for the whole network.
	 */
	public static function activation_callback( bool $network_wide = false ): void {
		// Check and run any pending upgrades.
		Upgrades::do_upgrades();

		// Schedule the Key Encryption experiment to re-encrypt plaintext keys on the next request.
		if ( ! $network_wide || ! is_multisite() ) {
			Key_Encryption::flag_resume_migration();
			return;
		}

		// A network-wide deactivation restores plaintext keys on every site, so every
		// site needs the flag to have them encrypted again.
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
			Key_Encryption::flag_resume_migration();
			restore_current_blog();
		}
	}
}

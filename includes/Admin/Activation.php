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
	 * @param bool|null $network_wide Whether the plugin is being activated for the whole network.
	 *                                WordPress can pass null here, which is treated as false.
	 */
	public static function activation_callback( ?bool $network_wide = false ): void {
		// Check and run any pending upgrades.
		Upgrades::do_upgrades();

		// Schedule the Key Encryption experiment to re-encrypt plaintext keys on the next request.
		// A network-wide deactivation restores plaintext keys on every site, so a
		// network-wide activation has to flag every site.
		Key_Encryption::for_each_site( true === $network_wide, array( Key_Encryption::class, 'flag_resume_migration' ) );
	}
}

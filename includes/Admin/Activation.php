<?php
/**
 * Runs on plugin activation.
 *
 * @package WordPress\AI\Admin
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Activation.
 *
 * @internal
 *
 * @since x.x.x
 */
final class Activation {
	/**
	 * Runs on plugin activation.
	 *
	 * @since x.x.x
	 */
	public static function activation_callback(): void {
		// Check and run any pending upgrades.
		Upgrades::do_upgrades();
	}
}

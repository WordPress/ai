<?php
/**
 * Runs on plugin activation.
 *
 * @package WordPress\AI\Admin
 * @since 0.6.0
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
 * @since 0.6.0
 */
final class Activation {
	/**
	 * Runs on plugin activation.
	 *
	 * @since 0.6.0
	 */
	public static function activation_callback(): void {
		// Check and run any pending upgrades.
		Upgrades::do_upgrades();
	}
}

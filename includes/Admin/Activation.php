<?php
/**
 * Runs on plugin activation.
 *
 * @package WordPress\AI\Admin
 * @since 0.6.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin;

use WordPress\AI\Experiments\Agent_Users\Agent_Credentials;
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
	 * @since x.x.x Tells parents which agents need new credentials.
	 *
	 * @param bool|null $network_wide Whether the plugin is being activated across the network.
	 *                                WP-CLI passes null rather than false.
	 */
	public static function activation_callback( $network_wide = false ): void {
		// Check and run any pending upgrades.
		Upgrades::do_upgrades();

		// Schedule the Key Encryption experiment to re-encrypt plaintext keys on the next request.
		Key_Encryption::flag_resume_migration();

		// Agent credentials revoked on deactivation have to be created again.
		Agent_Credentials::on_activation( (bool) $network_wide );
	}
}

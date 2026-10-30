<?php
/**
 * Upgrade routines for version 1.5.0
 *
 * @package WordPress\AI\Admin\Upgrades
 * @since 1.5.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin\Upgrades;

use WordPress\AI\Experiments\Key_Encryption\Key_Encryption;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Upgrade routine for version 1.5.0.
 *
 * Migrates legacy encrypted secret keys (`_secret_ai/*`) to the new Secrets API
 * when the feature plugin or core API is available.
 *
 * @since 1.5.0
 * @internal
 */
class V1_5_0 extends Abstract_Upgrade {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.5.0
	 */
	public static string $version = '1.5.0';

	/**
	 * {@inheritDoc}
	 *
	 * Migrates legacy secrets to the new Secrets API if supported.
	 *
	 * @since 1.5.0
	 */
	protected function upgrade(): void {
		if ( '' === $this->db_version ) {
			return;
		}

		Key_Encryption::get_bridge()->maybe_migrate_legacy_secrets();
	}
}

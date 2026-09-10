<?php
/**
 * Upgrade routines for version x.x.x
 *
 * @package WordPress\AI\Admin\Upgrades
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin\Upgrades;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Upgrade routine for clearing the legacy SEO plugin detection cache.
 *
 * Earlier versions cached the detected SEO plugin in a transient with no
 * expiration, so on existing sites it could persist indefinitely. Deleting it
 * once lets the new TTL-based caching repopulate it cleanly.
 *
 * @since x.x.x
 * @internal
 */
class V1_4_0 extends Abstract_Upgrade {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public static string $version = '1.4.0';

	/**
	 * {@inheritDoc}
	 *
	 * Clears the previously non-expiring SEO plugin detection cache.
	 *
	 * @since x.x.x
	 */
	protected function upgrade(): void {
		if ( '' === $this->db_version ) {
			return;
		}

		// Keeping the literal here, because if key changed in future,
		// this upgrade routine should target this key only.
		delete_transient( 'wpai_active_seo_plugin' );
	}
}

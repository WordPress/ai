<?php
/**
 * Upgrade routines for version 1.4.0
 *
 * @package WordPress\AI\Admin\Upgrades
 * @since 1.4.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin\Upgrades;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Upgrade routine for version 1.4.0.
 *
 * Clears the legacy SEO plugin detection cache and retires the global
 * "Enable AI" toggle.
 *
 * @since 1.4.0
 * @internal
 */
class V1_4_0 extends Abstract_Upgrade {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.4.0
	 */
	public static string $version = '1.4.0';

	/**
	 * {@inheritDoc}
	 *
	 * Clears the previously non-expiring SEO plugin detection cache and
	 * removes the global features toggle.
	 *
	 * @since 1.4.0
	 */
	protected function upgrade(): void {
		if ( '' === $this->db_version ) {
			return;
		}

		// Keeping the literal here, because if key changed in future,
		// this upgrade routine should target this key only.
		delete_transient( 'wpai_active_seo_plugin' );

		$this->remove_global_enabled_option();
	}

	/**
	 * Removes the global features toggle while preserving which features are active.
	 *
	 * @since 1.4.0
	 */
	private function remove_global_enabled_option(): void {
		global $wpdb;

		// Literals are used so this routine keeps targeting these keys even if they change later.
		$global_option   = 'wpai_features_enabled';
		$completion_flag = 'wpai_global_toggle_removed';

		if ( '1' === get_option( $completion_flag ) ) {
			return;
		}

		if ( ! (bool) get_option( $global_option, false ) ) {
			$like = $wpdb->esc_like( 'wpai_feature_' ) . '%' . $wpdb->esc_like( '_enabled' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time upgrade routine.
			$option_names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$like
				)
			);

			foreach ( $option_names as $option_name ) {
				if ( ! (bool) get_option( $option_name, false ) ) {
					continue;
				}

				update_option( $option_name, false );
			}
		}

		delete_option( $global_option );
		update_option( $completion_flag, '1', false );
	}
}

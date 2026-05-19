<?php
/**
 * Upgrade routines for version 1.0.0.
 *
 * @package WordPress\AI\Admin\Upgrades
 * @since 1.0.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin\Upgrades;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Upgrade routine for renaming review-notes and refine-notes slugs to editorial-notes and editorial-updates.
 *
 * @since 1.0.0
 * @internal
 */
class V1_0_0 extends Abstract_Upgrade {

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	public static string $version = '1.0.0';

	/**
	 * {@inheritDoc}
	 *
	 * Migrates DB option keys from old experiment slugs to new slugs.
	 *
	 * @since 1.0.0
	 */
	protected function upgrade(): void {
		$this->migrate_global_enabled_option();
		$this->migrate_option( 'wpai_feature_review-notes_enabled', 'wpai_feature_editorial-notes_enabled' );
		$this->migrate_option( 'wpai_feature_refine-notes_enabled', 'wpai_feature_editorial-updates_enabled' );
	}

	/**
	 * Repairs legacy and incorrect global enabled option names.
	 *
	 * @since 1.0.0
	 */
	private function migrate_global_enabled_option(): void {
		foreach ( array( 'ai_experiments_enabled', 'ai_experiment_enabled', 'wpai_feature_enabled' ) as $old_option ) {
			$this->migrate_option( $old_option, 'wpai_features_enabled' );
		}
	}

	/**
	 * Migrates an individual option from the old name.
	 *
	 * Will skip migration if the new option already has a value.
	 *
	 * @param string $old_option The old option name.
	 * @param string $new_option The new option name.
	 */
	private function migrate_option( string $old_option, string $new_option ): void {
		$old_value = get_option( $old_option, '' );
		if ( '' === $old_value || '' !== get_option( $new_option, '' ) ) {
			return;
		}

		update_option( $new_option, $old_value );
		delete_option( $old_option );
	}
}

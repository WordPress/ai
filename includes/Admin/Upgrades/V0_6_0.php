<?php
/**
 * Upgrade routines for version 0.6.0.
 *
 * @package WordPress\AI\Admin\Upgrades
 * @since 0.6.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Admin\Upgrades;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Upgrade routine for migrating legacy ai_experiment options to the wpai_* equivalents.
 *
 * @since 0.6.0
 * @internal
 */
class V0_6_0 extends Abstract_Upgrade {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.6.0
	 */
	public static string $version = '0.6.0';

	/**
	 * {@inheritDoc}
	 *
	 * Copies legacy ai_experiment options to the v0.6.0 wpai_* equivalents.
	 *
	 * Reads the old options, copies them, then deletes the old options.
	 *
	 * @since 0.6.0
	 */
	protected function upgrade(): void {
		// Update the global options first.
		$this->migrate_option( 'ai_experiment_enabled', 'wpai_feature_enabled' );

		// Loop through the features and migrate them.
		// We don't use the classes to protect for future compatibility.
		$features = array(
			'abilities-explorer',
			'alt-text-generation',
			'example-experiment',
			'excerpt-generation',
			'image-generation',
			'review-notes',
			'summarization',
			'title-generation',
		);

		foreach ( $features as $feature ) {
			$this->migrate_option( "ai_experiment_{$feature}_enabled", "wpai_feature_{$feature}_enabled" );
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

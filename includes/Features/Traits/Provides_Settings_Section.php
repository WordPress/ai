<?php
/**
 * Shared helpers for registering admin settings sections from features.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Features\Traits;

use WordPress\AI\Admin\Settings\Settings_Registry;
use WordPress\AI\Admin\Settings\Settings_Section;

/**
 * Allows features to expose configuration panels on the admin settings screen.
 *
 * @since 0.1.0
 */
trait Provides_Settings_Section {
	/**
	 * Registers a settings section that belongs to the feature.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Admin\Settings\Settings_Registry $registry Settings registry instance.
	 * @param string                                         $section_id Unique section identifier.
	 * @param string                                         $title Section title.
	 * @param callable                                       $render_callback Render callback accepting Settings_Toggle and Settings_Section.
	 * @param array<string, mixed>                           $args Additional section arguments:
	 *                                  - description (string)
	 *                                  - priority (int)
	 *                                  - supports (array)
	 *                                  - feature_id (string)
	 * @return bool True when registration succeeds.
	 */
	protected function register_feature_settings_section(
		Settings_Registry $registry,
		string $section_id,
		string $title,
		callable $render_callback,
		array $args = array()
	): bool {
		$description = isset( $args['description'] ) ? (string) $args['description'] : '';
		$priority    = isset( $args['priority'] ) ? (int) $args['priority'] : 10;
		$supports    = $this->prepare_section_supports(
			isset( $args['supports'] ) && is_array( $args['supports'] ) ? $args['supports'] : array()
		);

		$feature_id = isset( $args['feature_id'] ) ? (string) $args['feature_id'] : null;
		if ( ! $feature_id ) {
			$feature_id = $this->get_id();
		}

		$section = new Settings_Section(
			$section_id,
			$title,
			$description,
			$render_callback,
			$priority,
			$feature_id,
			$supports
		);

		return $registry->register_section( $section );
	}

	/**
	 * Builds a standardized supports payload for the settings section.
	 *
	 * Ensures Experimental badges are present and asset handles are normalised.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $supports Caller-provided supports array.
	 * @return array<string, mixed> Normalized supports definition.
	 */
	protected function prepare_section_supports( array $supports ): array {
		$normalized = array(
			'assets' => array(
				'scripts' => array(),
				'styles'  => array(),
			),
			'badges' => array(),
		);

		if ( isset( $supports['assets'] ) && is_array( $supports['assets'] ) ) {
			$normalized['assets'] = array(
				'scripts' => isset( $supports['assets']['scripts'] ) && is_array( $supports['assets']['scripts'] )
					? array_values( $supports['assets']['scripts'] )
					: array(),
				'styles'  => isset( $supports['assets']['styles'] ) && is_array( $supports['assets']['styles'] )
					? array_values( $supports['assets']['styles'] )
					: array(),
			);
		}

		if ( isset( $supports['badges'] ) && is_array( $supports['badges'] ) ) {
			$normalized['badges'] = array_values(
				array_filter(
					$supports['badges'],
					static function ( $badge ) {
						return is_array( $badge ) && isset( $badge['label'] );
					}
				)
			);
		}

		$has_experimental_badge = false;
		foreach ( $normalized['badges'] as $badge ) {
			if ( isset( $badge['label'] ) && 'Experimental' === $badge['label'] ) {
				$has_experimental_badge = true;
				break;
			}
		}

		if ( ! $has_experimental_badge ) {
			array_unshift(
				$normalized['badges'],
				array(
					'label'   => __( 'Experimental', 'ai' ),
					'context' => 'status',
				)
			);
		}

		return $normalized;
	}
}

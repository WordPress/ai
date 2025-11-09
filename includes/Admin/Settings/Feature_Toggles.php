<?php
/**
 * Per-feature toggle management service.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Admin\Settings;

/**
 * Manages individual feature enable/disable toggles.
 *
 * @since 0.1.0
 */
class Feature_Toggles {
	/**
	 * Option key for storing feature toggles.
	 */
	public const OPTION_KEY = 'wp_ai_feature_toggles';

	/**
	 * REST field name for feature toggles.
	 */
	public const REST_FIELD = 'wpAiFeatureToggles';

	/**
	 * Registers the feature toggles option.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		register_setting(
			Settings_Toggle::SETTINGS_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'object',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => array(
					'name'          => self::REST_FIELD,
					'schema'        => array(
						'type'                 => 'object',
						'additionalProperties' => array(
							'type' => 'boolean',
						),
					),
					'auth_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
				),
			)
		);
	}

	/**
	 * Sanitizes feature toggle data.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, bool> Sanitized toggles.
	 */
	public function sanitize( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $value as $feature_id => $enabled ) {
			if ( ! is_string( $feature_id ) || empty( $feature_id ) ) {
				continue;
			}

			$sanitized[ sanitize_key( $feature_id ) ] = (bool) $enabled;
		}

		return $sanitized;
	}

	/**
	 * Checks if a specific feature is enabled.
	 *
	 * @since 0.1.0
	 *
	 * @param string $feature_id Feature identifier.
	 * @param bool   $default    Default value when no stored toggle exists.
	 * @return bool True when enabled.
	 */
	public function is_feature_enabled( string $feature_id, bool $default = true ): bool {
		$toggles = $this->get_all();

		if ( array_key_exists( $feature_id, $toggles ) ) {
			return (bool) $toggles[ $feature_id ];
		}

		return $default;
	}

	/**
	 * Updates the toggle state for a specific feature.
	 *
	 * @since 0.1.0
	 *
	 * @param string $feature_id Feature identifier.
	 * @param bool   $enabled    Desired state.
	 */
	public function set_feature_enabled( string $feature_id, bool $enabled ): void {
		$toggles                = $this->get_all();
		$toggles[ $feature_id ] = $enabled;

		update_option( self::OPTION_KEY, $toggles );
	}

	/**
	 * Returns all feature toggles.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool> Feature toggles keyed by feature ID.
	 */
	public function get_all(): array {
		$toggles = get_option( self::OPTION_KEY, array() );

		// Ensure we always return an associative array (object in JSON), not an indexed array
		if ( ! is_array( $toggles ) || empty( $toggles ) ) {
			return array();
		}

		return $toggles;
	}

	/**
	 * Returns data for React hydration.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Feature toggles metadata.
	 */
	public function to_array(): array {
		$toggles = $this->get_all();

		// Force empty array to be serialized as {} not [] in JSON
		if ( empty( $toggles ) ) {
			$toggles = (object) array();
		}

		return array(
			'optionKey' => self::OPTION_KEY,
			'restField' => self::REST_FIELD,
			'toggles'   => $toggles,
		);
	}
}

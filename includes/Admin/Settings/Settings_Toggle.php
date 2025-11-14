<?php
/**
 * Global experimental features toggle service.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Admin\Settings;

/**
 * Encapsulates registration and access to the global experiments toggle option.
 *
 * @since 0.1.0
 */
class Settings_Toggle {
	/**
	 * Option key used to persist the toggle state.
	 */
	public const OPTION_KEY = 'wp_ai_experiments_enabled';

	/**
	 * Settings group for form nonce generation.
	 */
	public const SETTINGS_GROUP = 'ai_experiments';

	/**
	 * REST field name exposed via the Settings endpoint.
	 */
	public const REST_FIELD = 'wpAiExperimentsEnabled';

	/**
	 * Cached toggle value to avoid repeated DB hits.
	 *
	 * @var bool|null
	 */
	private $cached_value = null;

	/**
	 * Registers the toggle option with WordPress.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => array(
					'name'          => self::REST_FIELD,
					'schema'        => array(
						'type' => 'boolean',
					),
					'auth_callback' => static function () {
						return current_user_can( 'manage_options' );
					},
				),
			)
		);
	}

	/**
	 * Sanitizes the toggle value received from user input.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Raw value submitted by the user.
	 * @return bool Sanitized boolean flag.
	 */
	public function sanitize( $value ): bool {
		if ( is_string( $value ) ) {
			$normalized = strtolower( $value );

			if ( in_array( $normalized, array( '1', 'true', 'on', 'yes' ), true ) ) {
				return true;
			}

			if ( in_array( $normalized, array( '0', 'false', 'off', 'no', '' ), true ) ) {
				return false;
			}
		}

		if ( function_exists( 'rest_sanitize_boolean' ) ) {
			return (bool) rest_sanitize_boolean( $value );
		}

		if ( function_exists( 'wp_validate_boolean' ) ) {
			return wp_validate_boolean( $value );
		}

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Returns the current toggle value.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when experiments are enabled.
	 */
	public function is_enabled(): bool {
		if ( null === $this->cached_value ) {
			$this->cached_value = (bool) get_option( self::OPTION_KEY, true );
		}
		return $this->cached_value;
	}

	/**
	 * Updates the stored toggle value.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $enabled Desired toggle state.
	 */
	public function update( bool $enabled ): void {
		update_option( self::OPTION_KEY, $enabled );
		$this->cached_value = $enabled;
	}

	/**
	 * Filters global feature enablement based on the toggle state.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $enabled Incoming enabled flag.
	 * @return bool Filtered flag.
	 */
	public function filter_features_enabled( bool $enabled ): bool {
		return $enabled && $this->is_enabled();
	}

	/**
	 * Returns data used when hydrating the React application.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Toggle metadata.
	 */
	public function to_array(): array {
		return array(
			'optionKey' => self::OPTION_KEY,
			'restField' => self::REST_FIELD,
			'enabled'   => $this->is_enabled(),
			'group'     => 'ai_experiments',
			'default'   => true,
		);
	}
}

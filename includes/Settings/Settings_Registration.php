<?php
/**
 * Settings registration for the AI plugin.
 *
 * @package WordPress\AI
 *
 * @since 0.1.0
 */

declare(strict_types=1);

namespace WordPress\AI\Settings;

use WordPress\AI\Features\Registry;

/**
 * Handles registration of settings for the AI plugin.
 *
 * @since 0.1.0
 */
class Settings_Registration {

	/**
	 * The experiment registry instance.
	 *
	 * @since 0.1.0
	 *
	 * @var \WordPress\AI\Features\Registry
	 */
	private Registry $registry;

	/**
	 * The option group name for settings registration.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OPTION_GROUP = 'ai_experiments';

	/**
	 * The option name for the global experiments toggle.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GLOBAL_OPTION = 'wpai_features_enabled';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Features\Registry $registry The feature registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Initializes the settings registration hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init(): void {
		$this->register_settings();
	}

	/**
	 * Registers all settings.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_settings(): void {
		// Register the global toggle.
		register_setting(
			self::OPTION_GROUP,
			self::GLOBAL_OPTION,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			)
		);

		// Register settings for each experiment.
		foreach ( $this->registry->get_all_features() as $feature ) {
			$feature_id = $feature::get_id();
			$option_key = "wpai_feature_{$feature_id}_enabled";

			register_setting(
				self::OPTION_GROUP,
				$option_key,
				array(
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				)
			);

			// Allow experiments to register their own custom settings.
			if ( ! method_exists( $feature, 'register_settings' ) ) {
				continue;
			}

			$feature->register_settings();
		}
	}
}

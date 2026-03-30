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
use WordPress\AI\Permissions\Permissions_Manager;

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

		// Register plugin permission settings.
		$this->register_plugin_permission_settings();
	}

	/**
	 * Registers settings options for each plugin that has declared itself as an AI consumer.
	 *
	 * Two options are registered per plugin:
	 * - A boolean access toggle (`wpai_plugin_access_{key}`).
	 * - A string of comma-separated provider slugs for routing (`wpai_plugin_providers_{key}`).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function register_plugin_permission_settings(): void {
		$permissions_manager = Permissions_Manager::get_instance();
		$plugin_registry     = $permissions_manager->get_plugin_registry();

		foreach ( $plugin_registry->get_all_plugins() as $plugin ) {
			$plugin_key = $permissions_manager->sanitize_option_key( $plugin['id'] );

			register_setting(
				self::OPTION_GROUP,
				Permissions_Manager::PLUGIN_ACCESS_OPTION_PREFIX . $plugin_key,
				array(
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				)
			);

			register_setting(
				self::OPTION_GROUP,
				Permissions_Manager::PLUGIN_PROVIDER_OPTION_PREFIX . $plugin_key,
				array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => array( $this, 'sanitize_provider_preferences' ),
				)
			);

			// Register a boolean toggle for each declared capability.
			// Defaults to true — capabilities are on when a plugin is first granted access.
			foreach ( $plugin['capabilities'] as $capability ) {
				register_setting(
					self::OPTION_GROUP,
					Permissions_Manager::PLUGIN_CAPABILITY_OPTION_PREFIX . $plugin_key . '_' . $capability,
					array(
						'type'              => 'boolean',
						'default'           => true,
						'sanitize_callback' => 'rest_sanitize_boolean',
					)
				);
			}
		}
	}

	/**
	 * Sanitizes a comma-separated list of provider slugs.
	 *
	 * Each provider slug is sanitized with `sanitize_key()`. Empty values are discarded.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value The raw submitted value.
	 * @return string A sanitized, comma-separated list of provider slugs.
	 */
	public function sanitize_provider_preferences( $value ): string {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return '';
		}

		$providers = array_values(
			array_filter(
				array_map( 'sanitize_key', explode( ',', $value ) )
			)
		);

		return implode( ',', $providers );
	}
}

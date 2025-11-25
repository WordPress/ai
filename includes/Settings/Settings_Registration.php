<?php
/**
 * Settings registration for AI Experiments.
 *
 * @package WordPress\AI
 *
 * @since 0.1.0
 */

declare(strict_types=1);

namespace WordPress\AI\Settings;

use WordPress\AI\Experiment_Registry;

/**
 * Handles registration of settings for AI experiments.
 *
 * @since 0.1.0
 */
class Settings_Registration {

	/**
	 * The experiment registry instance.
	 *
	 * @since 0.1.0
	 *
	 * @var \WordPress\AI\Experiment_Registry
	 */
	private Experiment_Registry $registry;

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
	public const GLOBAL_OPTION = 'ai_experiments_enabled';

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Experiment_Registry $registry The experiment registry.
	 */
	public function __construct( Experiment_Registry $registry ) {
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
	 * Registers all settings for experiments.
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
		foreach ( $this->registry->get_all_experiments() as $experiment ) {
			$experiment_id     = $experiment->get_id();
			$experiment_option = "ai_experiment_{$experiment_id}_enabled";

			register_setting(
				self::OPTION_GROUP,
				$experiment_option,
				array(
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				)
			);

			// Allow experiments to register their own custom settings.
			if ( ! method_exists( $experiment, 'register_settings' ) ) {
				continue;
			}

			$experiment->register_settings();
		}
	}
}

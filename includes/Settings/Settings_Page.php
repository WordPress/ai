<?php
/**
 * Settings page for AI Experiments.
 *
 * @package WordPress\AI
 *
 * @since 0.1.0
 */

declare(strict_types=1);

namespace WordPress\AI\Settings;

use WordPress\AI\Experiment_Registry;

use function WordPress\AI\has_valid_ai_credentials;

/**
 * Manages the admin settings page for AI experiments.
 *
 * @since 0.1.0
 */
class Settings_Page {

	/**
	 * The experiment registry instance.
	 *
	 * @since 0.1.0
	 *
	 * @var \WordPress\AI\Experiment_Registry
	 */
	private Experiment_Registry $registry;

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
	 * Initializes the settings page hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Registers the admin menu item.
	 *
	 * Uses wp-build generated page registration.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_menu(): void {
		// The build/index.php is loaded in bootstrap.php and provides the render function.
		if ( ! function_exists( 'ai_experiments_wp_admin_render_page' ) ) {
			return;
		}

		add_options_page(
			__( 'AI Experiments', 'ai' ),
			__( 'AI Experiments', 'ai' ),
			'manage_options',
			'ai-experiments-wp-admin',
			'ai_experiments_wp_admin_render_page' // @phpstan-ignore argument.type (Dynamic function from wp-build)
		);
	}

	/**
	 * Gets the initial data for the React settings app.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The initial settings data.
	 */
	public function get_initial_data(): array {
		$experiments = array();

		foreach ( $this->registry->get_all_experiments() as $experiment ) {
			$experiment_data = array(
				'id'          => $experiment->get_id(),
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
				'enabled'     => (bool) get_option(
					"ai_experiment_{$experiment->get_id()}_enabled",
					false
				),
				'hasSettings' => $experiment->has_settings(),
				'entryPoints' => $experiment->get_entry_points(),
			);

			// Include settings fields and values if the experiment has settings.
			if ( $experiment->has_settings() ) {
				$experiment_data['settingsFields'] = $experiment->get_settings_fields();
				$experiment_data['settingsValues'] = $experiment->get_settings_values();
			}

			$experiments[] = $experiment_data;
		}

		return array(
			'globalEnabled'       => (bool) get_option( Settings_Registration::GLOBAL_OPTION, false ),
			'experiments'         => $experiments,
			'hasValidCredentials' => has_valid_ai_credentials(),
			'credentialsUrl'      => admin_url( 'options-general.php?page=wp-ai-client' ),
		);
	}
}

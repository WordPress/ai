<?php
/**
 * REST API Settings Controller.
 *
 * @package WordPress\AI\REST
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\REST;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WordPress\AI\Experiment_Registry;
use WordPress\AI\Settings\Settings_Registration;

/**
 * REST API controller for AI Experiments settings.
 *
 * Provides endpoints for retrieving and updating experiment settings.
 *
 * @since x.x.x
 */
class Settings_Controller extends WP_REST_Controller {

	/**
	 * The experiment registry instance.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiment_Registry
	 */
	private Experiment_Registry $registry;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiment_Registry $registry The experiment registry.
	 */
	public function __construct( Experiment_Registry $registry ) {
		$this->namespace = 'ai/v1';
		$this->rest_base = 'settings';
		$this->registry  = $registry;
	}

	/**
	 * Registers the routes for the controller.
	 *
	 * @since x.x.x
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_update_args(),
				),
			)
		);
	}

	/**
	 * Checks if the current user has permission to manage settings.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the user can manage options, false otherwise.
	 */
	public function permissions_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Gets the arguments for the update endpoint.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array<string, mixed>> The endpoint arguments.
	 */
	public function get_update_args(): array {
		return array(
			'globalEnabled'      => array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'experiments'        => array(
				'type'                 => 'object',
				'additionalProperties' => array(
					'type' => 'boolean',
				),
			),
			'experimentSettings' => array(
				'type'                 => 'object',
				'additionalProperties' => array(
					'type' => 'object',
				),
			),
		);
	}

	/**
	 * Retrieves all settings data.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The request object.
	 * @return \WP_REST_Response The response containing settings data.
	 */
	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
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

		return new WP_REST_Response(
			array(
				'globalEnabled'       => (bool) get_option( Settings_Registration::GLOBAL_OPTION, false ),
				'experiments'         => $experiments,
				'hasValidCredentials' => \WordPress\AI\has_valid_ai_credentials(),
				'credentialsUrl'      => admin_url( 'options-general.php?page=wp-ai-client' ),
			)
		);
	}

	/**
	 * Updates settings.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The request object.
	 * @return \WP_REST_Response|\WP_Error The updated settings or an error.
	 */
	public function update_settings( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		// Update global enabled toggle.
		if ( isset( $params['globalEnabled'] ) ) {
			update_option(
				Settings_Registration::GLOBAL_OPTION,
				(bool) $params['globalEnabled']
			);
		}

		// Update individual experiment toggles.
		if ( isset( $params['experiments'] ) && is_array( $params['experiments'] ) ) {
			foreach ( $params['experiments'] as $experiment_id => $enabled ) {
				$experiment = $this->registry->get_experiment( $experiment_id );
				if ( ! $experiment ) {
					continue;
				}

				update_option(
					"ai_experiment_{$experiment_id}_enabled",
					(bool) $enabled
				);
			}
		}

		// Update experiment-specific settings.
		if ( isset( $params['experimentSettings'] ) && is_array( $params['experimentSettings'] ) ) {
			foreach ( $params['experimentSettings'] as $experiment_id => $settings_data ) {
				$experiment = $this->registry->get_experiment( $experiment_id );
				if ( ! $experiment || ! $experiment->has_settings() || ! is_array( $settings_data ) ) {
					continue;
				}

				$experiment->update_settings( $settings_data );
			}
		}

		return $this->get_settings( $request );
	}
}

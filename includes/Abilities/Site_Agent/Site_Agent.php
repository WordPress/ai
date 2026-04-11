<?php
/**
 * Site Agent Ability Class.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Site_Agent;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use function WordPress\AI\get_preferred_models_for_text_generation;

/**
 * Class Site_Agent
 *
 * Converts natural language commands into WordPress admin actions.
 *
 * @since 0.7.0
 */
class Site_Agent extends Abstract_Ability {

	/**
	 * Execute the ability.
	 *
	 * @since 0.7.0
	 *
	 * @param mixed $input Arguments for the ability (command).
	 * @return array<string, mixed>|\WP_Error The agent's response or WP_Error.
	 */
	public function execute_callback( $input ) {
		if ( empty( $input['command'] ) ) {
			return new WP_Error( 'missing_command', __( 'A command string is required.', 'ai' ) );
		}

		$system_instruction = $this->get_system_instruction();
		$prompt_text        = sprintf( 'User Command: "%s"', sanitize_text_field( $input['command'] ) );

		$prompt_builder = wp_ai_client_prompt( $prompt_text )
			->using_system_instruction( $system_instruction )
			->using_model_preference( ...get_preferred_models_for_text_generation() )
			->as_json_response( $this->output_schema() );

		$prompt_builder = $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Text generation failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$response_text = $prompt_builder->generate_text();

		if ( is_wp_error( $response_text ) ) {
			return $response_text;
		}

		$command_data = json_decode( $response_text, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_agent_response', __( 'The agent returned an invalid command format.', 'ai' ) );
		}

		if ( isset( $command_data['action_found'] ) && true === $command_data['action_found'] ) {
			$this->execute_wordpress_action( $command_data );
		}

		return $command_data;
	}

	/**
	 * Safely execute the parsed action within WordPress.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string, mixed> $command_data The parsed JSON from the AI.
	 */
	private function execute_wordpress_action( array $command_data ): void {
		$action = $command_data['action'] ?? '';
		$args   = $command_data['args'] ?? array();

		switch ( $action ) {
			case 'update_site_title':
				if ( isset( $args['new_title'] ) && is_string( $args['new_title'] ) ) {
					update_option( 'blogname', sanitize_text_field( $args['new_title'] ) );
				}
				break;
			case 'update_site_description':
				if ( isset( $args['new_description'] ) && is_string( $args['new_description'] ) ) {
					update_option( 'blogdescription', sanitize_text_field( $args['new_description'] ) );
				}
				break;
			case 'create_draft_post':
				if ( isset( $args['post_title'], $args['post_content'] ) && is_string( $args['post_title'] ) && is_string( $args['post_content'] ) ) {
					wp_insert_post(
						array(
							'post_title'   => sanitize_text_field( $args['post_title'] ),
							'post_content' => wp_kses_post( $args['post_content'] ),
							'post_status'  => 'draft',
							'post_type'    => 'post',
						)
					);
				}
				break;
		}
	}

	/**
	 * Returns the input schema of the ability.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'command' => array(
					'type' => 'string',
				),
			),
			'required'   => array( 'command' ),
		);
	}

	/**
	 * Returns the output schema of the ability.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action_found' => array(
					'type' => 'boolean',
				),
				'action'       => array(
					'type' => 'string',
				),
				'args'         => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'message'      => array(
					'type' => 'string',
				),
			),
			'required'   => array( 'action_found', 'message' ),
		);
	}

	/**
	 * Checks whether the current user has permission to execute the ability.
	 *
	 * @since 0.7.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return bool|\WP_Error True if the user has permission, WP_Error otherwise.
	 */
	protected function permission_callback( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'unauthorized', __( 'You do not have permission to use the Site Agent.', 'ai' ) );
		}

		return true;
	}

	/**
	 * Returns the meta of the ability.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, mixed> The meta of the ability.
	 */
	protected function meta(): array {
		return array();
	}
}

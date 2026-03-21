<?php
/**
 * Plugin prompt enhancement WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Plugin_Builder;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;

use function WordPress\AI\get_preferred_models_for_text_generation;

class Plugin_Prompt_Enhancement extends Abstract_Ability {

	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'prompt' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'description'       => esc_html__( 'The user\'s raw plugin description to enhance.', 'ai' ),
				),
			),
			'required'   => array( 'prompt' ),
		);
	}

	protected function output_schema(): array {
		return array(
			'type'        => 'string',
			'description' => esc_html__( 'The enhanced plugin description prompt.', 'ai' ),
		);
	}

	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'prompt' => '',
			),
		);

		$prompt = trim( $args['prompt'] );

		if ( empty( $prompt ) ) {
			return new WP_Error(
				'prompt_not_provided',
				esc_html__( 'A prompt is required to enhance.', 'ai' )
			);
		}

		$result = $this->enhance_prompt( $prompt );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No enhanced prompt was generated.', 'ai' )
			);
		}

		return sanitize_textarea_field( trim( $result ) );
	}

	protected function permission_callback( $input ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to use the plugin builder.', 'ai' )
			);
		}

		return true;
	}

	protected function meta(): array {
		return array(
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
				'type'   => 'prompt',
			),
		);
	}

	protected function enhance_prompt( string $prompt ) {
		$content = '<user-prompt>' . $prompt . '</user-prompt>';

		return wp_ai_client_prompt( $content )
			->using_system_instruction( $this->get_system_instruction( 'system-instruction.php' ) )
			->using_temperature( 0.3 )
			->using_model_preference( ...get_preferred_models_for_text_generation() )
			->generate_text();
	}
}


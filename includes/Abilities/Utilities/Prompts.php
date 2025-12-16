<?php
/**
 * Prompt-related WordPress Abilities.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Utilities;

use WordPress\AI_Client\AI_Client;

use function WordPress\AI\get_preferred_models;

/**
 * Prompt utility WordPress Abilities.
 *
 * @since x.x.x
 */
class Prompts {

	/**
	 * The system instruction for prompt generation.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private static string $prompt_generation_system_instruction = <<<'INSTRUCTION'
You are a helpful assistant that generates LLM-friendly prompts for a specific purpose. The intended purpose for the prompt will be provided as well as additional context that should be used to generate the prompt. This context will be provided in a structured format, with each key-value pair being a separate line.

Your job is to generate a prompt that can be used as a system instruction for an LLM. Only return this prompt, do not include any other text. The purpose and context will be delimited by triple quotes.
INSTRUCTION;

	/**
	 * Register any needed hooks.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since x.x.x
	 */
	public function register_abilities(): void {
		$this->register_generate_prompt_ability();
	}

	/**
	 * Registers the generate-prompt ability.
	 *
	 * @since x.x.x
	 */
	private function register_generate_prompt_ability(): void {
		wp_register_ability(
			'ai/generate-prompt',
			array(
				'label'               => esc_html__( 'Generate a prompt', 'ai' ),
				'description'         => esc_html__( 'Generate a prompt for a specific purpose.', 'ai' ),
				'category'            => AI_EXPERIMENTS_DEFAULT_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'purpose' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => esc_html__( 'The intended purpose for the prompt.', 'ai' ),
						),
						'context' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => esc_html__( 'Any additional context to help generate the prompt.', 'ai' ),
						),
					),
					'required'   => array( 'purpose', 'context' ),
				),
				'output_schema'       => array(
					'type'        => 'string',
					'description' => esc_html__( 'The generated prompt.', 'ai' ),
				),
				'execute_callback'    => static function ( array $input ) {
					// Default arguments.
					$args = wp_parse_args(
						$input,
						array(
							'purpose' => '',
							'context' => '',
						),
					);

					$content = "## Purpose\n" . $args['purpose'];

					// If context is provided, add it to the content.
					if ( ! empty( $args['context'] ) ) {
						$content .= "\n\n## Context\n" . $args['context'];
					}

					// Generate the prompt using the AI client.
					return AI_Client::prompt_with_wp_error( '"""' . $content . '"""' )
						->using_system_instruction( self::$prompt_generation_system_instruction )
						->using_temperature( 0.9 )
						->using_model_preference( ...get_preferred_models() )
						->generate_text();
				},
				'permission_callback' => 'is_user_logged_in',
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);
	}
}

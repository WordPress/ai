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
You are a helpful assistant that generates LLM-ready system prompts for a specific downstream purpose.

You will be given:
- A prompt purpose, describing what the downstream LLM should do
- Additional context, provided in a structured, line-by-line key-value format

The purpose and context will be delimited by triple quotes.

Your task is to synthesize this information into a single, complete system prompt that can be passed directly to another LLM to accomplish the stated purpose.

Requirements:
- Incorporate relevant context faithfully and accurately
- Do not reference the existence or structure of the input context
- Do not include explanations, headings, or commentary
- Output only the final system prompt text
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
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'prompt',
					),
				),
			)
		);
	}
}

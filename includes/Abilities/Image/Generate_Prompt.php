<?php
/**
 * Image prompt generation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Image;

use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI_Client\AI_Client;

use function WordPress\AI\get_preferred_models;

/**
 * Image prompt generation WordPress Ability.
 *
 * @since x.x.x
 */
class Generate_Prompt extends Abstract_Ability {

	/**
	 * The system instruction for prompt generation.
	 *
	 * @since x.x.x
	 * @var string
	 */
	// phpcs:ignore Squiz.PHP.Heredoc.NotAllowed
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
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
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
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function output_schema(): array {
		return array(
			'type'        => 'string',
			'description' => esc_html__( 'The image generation prompt.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function execute_callback( $input ) {
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
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function permission_callback( $args ) {
		// Ensure the user is logged in.
		return is_user_logged_in();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
				'type'   => 'prompt',
			),
		);
	}
}

<?php
/**
 * Content resizing WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content_Resizing;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;

use function WordPress\AI\get_preferred_models_for_text_generation;
use function WordPress\AI\normalize_content;

/**
 * Content resizing WordPress Ability.
 *
 * @since x.x.x
 */
class Content_Resizing extends Abstract_Ability {

	/**
	 * The default action.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	protected const ACTION_DEFAULT = 'rephrase';

	/**
	 * The minimum word count for the shorten action.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	protected const SHORTEN_MIN_WORDS = 5;

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'The block content to resize.', 'ai' ),
				),
				'action'  => array(
					'type'        => 'enum',
					'enum'        => array( 'shorten', 'expand', 'rephrase' ),
					'default'     => self::ACTION_DEFAULT,
					'description' => esc_html__( 'The resizing action to perform.', 'ai' ),
				),
			),
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
			'description' => esc_html__( 'The resized content.', 'ai' ),
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
				'content' => null,
				'action'  => self::ACTION_DEFAULT,
			),
		);

		$content = normalize_content( $args['content'] ?? '' );

		// If we have no content, return an error.
		if ( empty( $content ) ) {
			return new WP_Error(
				'content_not_provided',
				esc_html__( 'Content is required to resize.', 'ai' )
			);
		}

		// Validate minimum word count for the shorten action.
		if ( 'shorten' === $args['action'] && str_word_count( wp_strip_all_tags( $content ) ) < self::SHORTEN_MIN_WORDS ) {
			return new WP_Error(
				'content_too_short',
				sprintf(
					/* translators: %d: Minimum word count. */
					esc_html__( 'A minimum of %d words is required to shorten the content.', 'ai' ),
					self::SHORTEN_MIN_WORDS
				)
			);
		}

		$prompt = $this->build_prompt( $content, $args['action'] );

		/**
		 * Filters the prompt for the content resizing.
		 *
		 * @since x.x.x
		 *
		 * @param string $prompt The prompt to use for the content resizing.
		 * @param string $action The resizing action to perform.
		 * @return string The filtered prompt.
		 */
		$prompt = (string) apply_filters( 'wpai_content_resizing_prompt', $prompt, $args['action'] );

		// Generate the resized content.
		$result = $this->generate_resized_content( $prompt, $args['action'] );

		// If we have an error, return it.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// If we have no results, return an error.
		if ( empty( $result ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No resized content was generated.', 'ai' )
			);
		}

		// Return the resized content in the format the Ability expects.
		return wp_kses_post( $result );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function permission_callback( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to resize content.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Builds the the prompt for content resizing.
	 *
	 * @since x.x.x
	 *
	 * @param string $content The content to resize.
	 * @param string $action  The resizing action to perform.
	 * @return string The prompt.
	 */
	protected function build_prompt( $content, $action = self::ACTION_DEFAULT ) {
		$prompt_parts = array();

		// Determine the action-specific instruction.
		$action_desc = 'Rephrase the content using different wording and sentence structure while preserving the exact same meaning, tone, and level of detail. The output should be approximately the same length as the input.';
		if ( 'shorten' === $action ) {
			$action_desc = 'Condense the following text to roughly half its current length. Preserve the core meaning, key facts, and tone. Remove redundancy and filler. Do not add new information.';
		} elseif ( 'expand' === $action ) {
			$action_desc = 'Expand the following text to roughly 1.5 to 2 times its current length. Add supporting detail, elaboration, or examples that are consistent with the original meaning and tone. Do not introduce contradictory information.';
		}

		/**
		 * Filters the action description for the content resizing.
		 *
		 * @since x.x.x
		 *
		 * @param string $action_desc The action description to use for the content resizing.
		 * @param string $action      The resizing action to perform.
		 * @return string The filtered action description.
		 */
		$action_desc = (string) apply_filters( 'wpai_content_resizing_action_description', $action_desc, $action );

		$prompt_parts[] = '<goal>' . $action_desc . '</goal>';
		$prompt_parts[] = '<content>' . $content . '</content>';

		return implode( "\n", $prompt_parts );
	}

	/**
	 * Generates resized content using the AI client.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The prompt to use for the content resizing.
	 * @param string $action The resizing action to perform.
	 * @return string|\WP_Error The resized content, or a WP_Error if there was an error.
	 */
	protected function generate_resized_content( string $prompt, string $action ) {
		/**
		 * Filters the temperature for the content resizing.
		 * Default is 0.7.
		 *
		 * @since x.x.x
		 *
		 * @param float  $temperature The temperature to use for the content resizing.
		 * @param string $action      The resizing action to perform.
		 * @return float The filtered temperature.
		 */
		$temperature = (float) apply_filters( 'wpai_content_resizing_temperature', 0.7, $action );

		return wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction() )
			->using_temperature( $temperature )
			->using_model_preference( ...get_preferred_models_for_text_generation() )
			->generate_text();
	}
}

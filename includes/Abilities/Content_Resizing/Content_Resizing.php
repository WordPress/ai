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
				'post_id' => array(
					'type'        => 'integer',
					'description' => esc_html__( 'The ID of the post to resize content for.', 'ai' ),
				),
				'content' => array(
					'type'        => 'string',
					'description' => esc_html__( 'The block content to resize.', 'ai' ),
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
				'post_id' => null,
				'content' => null,
				'action'  => self::ACTION_DEFAULT,
			),
		);

		// Skip normalization of content to retain HTML tags.
		$content = $args['content'] ?? '';

		if ( empty( $content ) ) {
			return new WP_Error(
				'content_not_provided',
				esc_html__( 'Content is required to resize.', 'ai' )
			);
		}

		// "shorten" action requires a minimum word count.
		if (
			'shorten' === $args['action'] &&
			str_word_count( wp_strip_all_tags( $content ) ) < self::SHORTEN_MIN_WORDS
		) {
			return new WP_Error(
				'content_too_short',
				sprintf(
					/* translators: %d: Minimum word count. */
					esc_html__( 'A minimum of %d words is required to shorten the content.', 'ai' ),
					self::SHORTEN_MIN_WORDS
				)
			);
		}

		$prompt = $this->structure_prompt( $content, $args['action'] );

		// Generate the resized content.
		$result = $this->generate_resized_content( $prompt );

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
		// If a post ID is provided, ensure the user has permission to edit the post.
		if ( isset( $args['post_id'] ) ) {
			$post_id = absint( $args['post_id'] );
			$post    = get_post( $post_id );

			// Ensure the post exists.
			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), $post_id )
				);
			}

			// Ensure the user has permission to edit this particular post.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to run AI refinements on this post.', 'ai' )
				);
			}
		} elseif ( ! current_user_can( 'edit_posts' ) ) {
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
	private function structure_prompt( $content, $action = self::ACTION_DEFAULT ) {
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

		$prompt = implode( "\n", $prompt_parts );

		/**
		 * Filters the prompt for the content resizing.
		 *
		 * @since x.x.x
		 *
		 * @param string        $prompt       The prompt to use for the content resizing.
		 * @param string        $action       The resizing action to perform.
		 * @param array<string> $prompt_parts The prompt parts.
		 * @return string The filtered prompt.
		 */
		$prompt = (string) apply_filters( 'wpai_content_resizing_prompt', $prompt, $action, $prompt_parts );

		return $prompt;
	}

	/**
	 * Generates resized content using the AI client.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The prompt to use for the content resizing.
	 * @return string|\WP_Error The resized content, or a WP_Error if there was an error.
	 */
	protected function generate_resized_content( string $prompt ) {
		$builder = $this->get_prompt_builder( $prompt );
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		return $builder->generate_text();
	}

	/**
	 * Returns a prompt builder for content resizing.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The prompt to build.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error if there isn't a model that supports text generation.
	 */
	private function get_prompt_builder( string $prompt ) {
		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction() )
			->using_temperature( 0.7 )
			->using_model_preference( ...get_preferred_models_for_text_generation() );

		return $this->ensure_text_generation_supported(
			$builder,
			esc_html__( 'Content resizing failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}
}

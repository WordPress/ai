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
use WordPress\AI\Experiments\Content_Resizing\Content_Resizing as Content_Resizing_Experiment;

use function WordPress\AI\count_characters_excluding_spaces;
use function WordPress\AI\get_min_content_length;

/**
 * Content resizing WordPress Ability.
 *
 * @since 0.9.0
 */
class Content_Resizing extends Abstract_Ability {

	/**
	 * The default action.
	 *
	 * @since 0.9.0
	 *
	 * @var string
	 */
	protected const ACTION_DEFAULT = 'rephrase';

	/**
	 * The default minimum content length in characters for the shorten action.
	 *
	 * @since 1.1.0
	 *
	 * @var int
	 */
	public const DEFAULT_MIN_CONTENT_LENGTH = 25;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.9.0
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
					'type'        => 'string',
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
	 * @since 0.9.0
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
	 * @since 0.9.0
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

		$min_content_length = get_min_content_length(
			'content-resizing',
			self::DEFAULT_MIN_CONTENT_LENGTH
		);

		// "shorten" action requires a minimum character count.
		if (
			'shorten' === $args['action'] &&
			count_characters_excluding_spaces( $content ) < $min_content_length
		) {
			return new WP_Error(
				'content_too_short',
				sprintf(
					/* translators: %d: Minimum content length in characters. */
					esc_html__( 'A minimum of %d characters is required to shorten the content.', 'ai' ),
					$min_content_length
				)
			);
		}

		$prompt = '<content>' . $content . '</content>';

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
	 * @since 0.9.0
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

			// Ensure the post type is allowed in REST endpoints.
			$post_type_obj = get_post_type_object( $post->post_type );

			if ( ! $post_type_obj || empty( $post_type_obj->show_in_rest ) ) {
				return false;
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
	 * @since 0.9.0
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Generates resized content using the AI client.
	 *
	 * @since 0.9.0
	 *
	 * @param string $prompt The prompt to use for the content resizing.
	 * @param string $action The resizing action to perform.
	 * @return string|\WP_Error The resized content, or a WP_Error if there was an error.
	 */
	protected function generate_resized_content( string $prompt, string $action = self::ACTION_DEFAULT ) {
		$builder = $this->get_prompt_builder( $prompt, $action );
		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		return $builder->generate_text();
	}

	/**
	 * Returns a prompt builder for content resizing.
	 *
	 * @since 0.9.0
	 *
	 * @param string $prompt The prompt to build.
	 * @param string $action The resizing action to perform.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error if there isn't a model that supports text generation.
	 */
	private function get_prompt_builder( string $prompt, string $action = self::ACTION_DEFAULT ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction(
				$this->get_system_instruction( 'system-instruction.php', array( 'action' => $action ) )
			)
			->using_temperature( 0.7 );

		$prompt_builder = $this->set_provider_model_preference( $prompt_builder, Content_Resizing_Experiment::class );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Content resizing failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}
}

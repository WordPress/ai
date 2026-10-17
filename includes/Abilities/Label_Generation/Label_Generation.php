<?php
/**
 * Label generation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Label_Generation;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Label_Generation\Label_Generation as Label_Generation_Experiment;

use function WordPress\AI\count_characters_excluding_spaces;
use function WordPress\AI\get_min_content_length;
use function WordPress\AI\normalize_content;

/**
 * Label Generation WordPress Ability.
 *
 * @since x.x.x
 */
class Label_Generation extends Abstract_Ability {

	/**
	 * The default minimum content length in characters for the label generation.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const DEFAULT_MIN_CONTENT_LENGTH = 25;

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
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'The ID of the post to generate a label for.', 'ai' ),
				),
				'content' => array(
					'type'        => 'string',
					'description' => esc_html__( 'The block content to generate a label for.', 'ai' ),
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
			'description' => esc_html__( 'The generated label for the post.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function permission_callback( $args ) {
		// Ensure the user has the perimssion to edit the post if a post ID is provided.
		if ( isset( $args['post_id'] ) ) {
			$post_id = absint( $args['post_id'] );
			$post    = get_post( $post_id );

			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					sprintf(
						/* translators: %d: post ID */
						esc_html__( 'Post with ID %d not found.', 'ai' ),
						$post_id
					)
				);
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'insufficient_permissions',
					esc_html__( 'You do not have permission to edit this post.', 'ai' )
				);
			}

			// Ensure the post type is allowed in REST endpoints.
			$post_type = get_post_type_object( $post->post_type );

			if ( ! $post_type || empty( $post_type->show_in_rest ) ) {
				return false;
			}
		} elseif ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				esc_html__( 'You do not have permission to edit posts.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * Returns a prompt builder for content translation.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The prompt to build.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error if there isn't a model that supports text generation.
	 */
	private function get_prompt_builder( string $prompt ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction(
				$this->get_system_instruction(
					'system-instruction.php'
				)
			);

		$prompt_builder = $this->filter_prompt_builder(
			$prompt_builder,
			Label_Generation_Experiment::class,
			array(),
			$prompt
		);

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__(
				'Label generation failed. Please ensure you have a connected provider that supports text generation.',
				'ai'
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'post_id' => null,
				'content' => null,
			)
		);

		$content = normalize_content( $args['content'] ?? '' );
		if ( empty( $content ) ) {
			return new WP_Error(
				'content_not_provided',
				esc_html__( 'No content provided to generate a label.', 'ai' )
			);
		}

		$min_content_length = get_min_content_length(
			'label-generation',
			self::DEFAULT_MIN_CONTENT_LENGTH
		);

		if ( count_characters_excluding_spaces( $content ) < $min_content_length ) {
			return new WP_Error(
				'content_too_short',
				sprintf(
					/* translators: %d: Minimum content length in characters. */
					esc_html__( 'A minimum of %d characters is required to generate a label.', 'ai' ),
					$min_content_length
				)
			);
		}

		$prompt = sprintf( '<content>%s</content>', $content );
		$result = $this->generate_label( $prompt );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No label was generated.', 'ai' )
			);
		}

		return wp_kses_post( $result );
	}

	/**
	 * Generates a label for the given content using the AI client.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The prompt to use for the label generation.
	 * @return string|\WP_Error The generated label, or a WP_Error if there was an error.
	 */
	protected function generate_label( string $prompt ) {
		$prompt  = $this->filter_prompt( $prompt );
		$builder = $this->get_prompt_builder( $prompt );

		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		return $builder->generate_text();
	}
}

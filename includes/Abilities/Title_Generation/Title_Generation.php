<?php
/**
 * Title generation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Title_Generation;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;

use function WordPress\AI\get_post_context;
use function WordPress\AI\get_preferred_models_for_text_generation;
use function WordPress\AI\normalize_content;

/**
 * Title generation WordPress Ability.
 *
 * @since 0.1.0
 */
class Title_Generation extends Abstract_Ability {

	/**
	 * The default number of candidates to generate.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	protected const CANDIDATES_DEFAULT = 3;

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content'    => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Content to generate title suggestions for.', 'ai' ),
				),
				'context'    => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Additional context to use when generating title suggestions. This can either be a string of additional context or can be a post ID that will then be used to get context from that post (if it exists). If no content is provided but a valid post ID is used here, the content from that post will be used.', 'ai' ),
				),
				'candidates' => array(
					'type'              => 'integer',
					'minimum'           => 1,
					'maximum'           => 10,
					'default'           => self::CANDIDATES_DEFAULT,
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'Number of titles to generate', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'titles' => array(
					'type'        => 'array',
					'description' => esc_html__( 'Generated title suggestions.', 'ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function execute_callback( $input ) {
		// Default arguments.
		$args = wp_parse_args(
			$input,
			array(
				'content'    => null,
				'context'    => null,
				'candidates' => self::CANDIDATES_DEFAULT,
			),
		);

		// If a post ID is provided, ensure the post exists before using its' content.
		if ( is_numeric( $args['context'] ) ) {
			$post = get_post( (int) $args['context'] );

			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), absint( $args['context'] ) )
				);
			}

			// Get the post context.
			$context = get_post_context( $post->ID );
			$content = $context['content'] ?? '';
			unset( $context['content'] );

			// Default to the passed in content if it exists.
			if ( $args['content'] ) {
				$content = normalize_content( $args['content'] );
			}
		} else {
			$content = normalize_content( $args['content'] ?? '' );
			$context = $args['context'] ?? '';
		}

		// If we have no content, return an error.
		if ( empty( $content ) ) {
			return new WP_Error(
				'content_not_provided',
				esc_html__( 'Content is required to generate title suggestions.', 'ai' )
			);
		}

		// Generate the titles.
		$result = $this->generate_titles( $content, $context, $args['candidates'] );

		// If we have an error, return it.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// If we have no results, return an error.
		if ( empty( $result ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No title suggestions were generated.', 'ai' )
			);
		}

		// Return the titles in the format the Ability expects.
		return array(
			'titles' => array_map(
				static function ( $title ) {
					return sanitize_text_field( trim( $title, ' "\'' ) );
				},
				$result
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function permission_callback( $args ) {
		$post_id = isset( $args['context'] ) && is_numeric( $args['context'] ) ? absint( $args['context'] ) : null;

		if ( $post_id ) {
			$post = get_post( $post_id );

			// Ensure the post exists.
			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), absint( $post_id ) )
				);
			}

			// Ensure the user has permission to edit this particular post.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to generate titles for this post.', 'ai' )
				);
			}

			// Ensure the post type is allowed in REST endpoints.
			$post_type = get_post_type( $post_id );

			if ( ! $post_type ) {
				return false;
			}

			$post_type_obj = get_post_type_object( $post_type );

			if ( ! $post_type_obj || empty( $post_type_obj->show_in_rest ) ) {
				return false;
			}
		} elseif ( ! current_user_can( 'edit_posts' ) ) {
			// Ensure the user has permission to edit posts in general.
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to generate titles.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Generates title suggestions from the given content.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content The content to generate title suggestions for.
	 * @param string|array<string, string> $context Additional context to use.
	 * @param int $candidates The number of titles to generate.
	 * @return array<string>|\WP_Error The generated titles, or a WP_Error if there was an error.
	 */
	protected function generate_titles( string $content, $context, int $candidates = 1 ) {
		// Convert the context to a string if it's an array.
		if ( is_array( $context ) ) {
			$context = implode(
				"\n",
				array_map(
					static function ( $key, $value ) {
						return sprintf(
							'%s: %s',
							ucwords( str_replace( '_', ' ', $key ) ),
							$value
						);
					},
					array_keys( $context ),
					$context
				)
			);
		}

		$content = '<content>' . $content . '</content>';

		// If we have additional context, add it to the content.
		if ( $context ) {
			$content .= "\n\n<additional-context>" . $context . '</additional-context>';
		}

		// Generate the titles using the AI client.
		return wp_ai_client_prompt( $content )
			->using_system_instruction( $this->get_system_instruction() )
			->using_temperature( 0.7 )
			->using_candidate_count( (int) $candidates )
			->using_model_preference( ...get_preferred_models_for_text_generation() )
			->generate_texts();
	}
}

<?php
/**
 * Content summarization WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Summarization;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI_Client\AI_Client;

use function WordPress\AI\get_post_context;
use function WordPress\AI\get_preferred_models_for_text_generation;
use function WordPress\AI\normalize_content;

/**
 * Content summarization WordPress Ability.
 *
 * @since 0.2.0
 */
class Summarization extends Abstract_Ability {

	/**
	 * The default length of the summary.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	protected const LENGTH_DEFAULT = 'medium';

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Content to summarize.', 'ai' ),
				),
				'context' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Additional context to use when summarizing the content. This can either be a string of additional context or can be a post ID that will then be used to get context from that post (if it exists). If no content is provided but a valid post ID is used here, the content from that post will be used.', 'ai' ),
				),
				'length'  => array(
					'type'        => 'enum',
					'enum'        => array( 'short', 'medium', 'long' ),
					'default'     => self::LENGTH_DEFAULT,
					'description' => esc_html__( 'The length of the summary.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	protected function output_schema(): array {
		return array(
			'type'        => 'string',
			'description' => esc_html__( 'The summary of the content.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	protected function execute_callback( $input ) {
		// Default arguments.
		$args = wp_parse_args(
			$input,
			array(
				'content' => null,
				'context' => null,
				'length'  => self::LENGTH_DEFAULT,
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
				esc_html__( 'Content is required to generate a summary.', 'ai' )
			);
		}

		// Generate the summary.
		$result = $this->generate_summary( $content, $context, $args['length'] );

		// If we have an error, return it.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// If we have no results, return an error.
		if ( empty( $result ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No summary was generated.', 'ai' )
			);
		}

		// Return the summary in the format the Ability expects.
		return sanitize_text_field( trim( $result ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
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
					esc_html__( 'You do not have permission to summarize this post.', 'ai' )
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
				esc_html__( 'You do not have permission to summarize content.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.2.0
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Generates a summary from the given content.
	 *
	 * @since 0.2.0
	 *
	 * @param string $content The content to summarize.
	 * @param string|array<string, string> $context Additional context to use.
	 * @param string $length The desired length of the summary.
	 * @return string|\WP_Error The generated summary, or a WP_Error if there was an error.
	 */
	protected function generate_summary( string $content, $context, string $length ) {
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

		// Generate the summary using the AI client.
		return AI_Client::prompt_with_wp_error( $content )
			->using_system_instruction( $this->get_system_instruction( 'system-instruction.php', array( 'length' => $length ) ) )
			->using_temperature( 0.9 )
			->using_model_preference( ...get_preferred_models_for_text_generation() )
			->generate_text();
	}
}

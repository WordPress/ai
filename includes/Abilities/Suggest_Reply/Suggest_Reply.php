<?php
/**
 * Reply suggestion ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Suggest_Reply;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;

use function WordPress\AI\get_preferred_models_for_text_generation;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Ability that generates reply suggestions for a comment.
 *
 * @since x.x.x
 */
class Suggest_Reply extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function guideline_categories(): array {
		return array( 'site', 'copy' );
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
				'comment_id' => array(
					'type'        => 'integer',
					'description' => esc_html__( 'The ID of the comment to generate a reply for.', 'ai' ),
				),
				'tone'       => array(
					'type'        => 'string',
					'enum'        => array( 'professional', 'friendly', 'casual' ),
					'default'     => 'friendly',
					'description' => esc_html__( 'The tone for the reply.', 'ai' ),
				),
			),
			'required'   => array( 'comment_id' ),
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
			'description' => esc_html__( 'The generated reply suggestion.', 'ai' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return string|\WP_Error The generated reply suggestion.
	 */
	protected function execute_callback( $input ) {
		$input = wp_parse_args(
			(array) $input,
			array(
				'comment_id' => 0,
				'tone'       => 'friendly',
			)
		);

		$comment_id = absint( $input['comment_id'] );

		if ( ! $comment_id ) {
			return new WP_Error(
				'missing_comment_id',
				esc_html__( 'A comment ID is required.', 'ai' )
			);
		}

		$comment = get_comment( $comment_id );

		if ( ! $comment || ! is_a( $comment, '\WP_Comment' ) ) {
			return new WP_Error(
				'comment_not_found',
				sprintf(
					/* translators: %d: Comment ID. */
					esc_html__( 'Comment with ID %d not found.', 'ai' ),
					$comment_id
				)
			);
		}

		// Fetch post context.
		$post = get_post( (int) $comment->comment_post_ID );

		if ( ! $post instanceof \WP_Post ) {
			return new WP_Error( 'post_not_found', esc_html__( 'Post not found.', 'ai' ) );
		}

		$post_title   = $post->post_title;
		$post_excerpt = get_the_excerpt( $post );

		$tone = in_array( $input['tone'], array( 'professional', 'friendly', 'casual' ), true )
			? $input['tone']
			: 'friendly';

		// Build the prompt context.
		$context = $this->build_context( $comment, $post_title, $post_excerpt, $tone );

		// Generate the reply.
		$reply = $this->generate_reply( $context );

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		return $reply;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function permission_callback( $input ) {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to generate reply suggestions.', 'ai' )
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
	 * Builds the prompt context string from the comment and post data.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Comment $comment      The comment to reply to.
	 * @param string      $post_title   The title of the parent post.
	 * @param string      $post_excerpt A short excerpt of the parent post content.
	 * @param string      $tone         The desired reply tone (e.g. 'friendly', 'professional').
	 * @return string The assembled prompt context.
	 */
	private function build_context(
		\WP_Comment $comment,
		string $post_title,
		string $post_excerpt,
		string $tone
	): string {
		$parts = array();

		if ( '' !== $post_title ) {
			$parts[] = sprintf( '<post-title>%s</post-title>', $post_title );
		}

		if ( '' !== $post_excerpt ) {
			$parts[] = sprintf( '<post-context>%s</post-context>', $post_excerpt );
		}

		$parts[] = sprintf( '<comment-author>%s</comment-author>', $comment->comment_author );
		$parts[] = sprintf( '<comment>%s</comment>', $comment->comment_content );
		$parts[] = sprintf( '<requested-tone>%s</requested-tone>', $tone );

		return implode( "\n", $parts );
	}

	/**
	 * Generates a reply suggestion via the AI client.
	 *
	 * @since x.x.x
	 *
	 * @param string $context The assembled prompt context string.
	 * @return string|\WP_Error The sanitized reply text, or a WP_Error on failure.
	 */
	private function generate_reply( string $context ) {
		$prompt_builder = wp_ai_client_prompt( $context )
			->using_system_instruction( $this->get_system_instruction() )
			->using_model_preference( ...get_preferred_models_for_text_generation() );

		$is_supported = $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Reply suggestion could not be generated. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);

		if ( is_wp_error( $is_supported ) ) {
			return $is_supported;
		}

		$result = $prompt_builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return sanitize_textarea_field( trim( (string) $result ) );
	}
}

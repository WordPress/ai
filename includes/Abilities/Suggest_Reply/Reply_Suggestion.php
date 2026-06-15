<?php

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Suggest_Reply;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;

use function WordPress\AI\get_preferred_models_for_text_generation;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class Reply_Suggestion extends Abstract_Ability {

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
				'guidelines' => array(
					'type'        => 'string',
					'default'     => '',
					'description' => esc_html__( 'Optional free-text editorial guidelines to apply when writing the reply.', 'ai' ),
				),
			),
			'required'   => array( 'comment_id' ),
		);
	}

	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'comment_id' => array(
					'type'        => 'integer',
					'description' => esc_html__( 'The comment ID.', 'ai' ),
				),
				'reply'      => array(
					'type'        => 'string',
					'description' => esc_html__( 'The generated reply suggestion.', 'ai' ),
				),
			),
		);
	}

	protected function execute_callback( $input ) {
		$input = wp_parse_args(
			(array) $input,
			array(
				'comment_id' => 0,
				'tone'       => 'friendly',
				'guidelines' => '',
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
		$post         = get_post( $comment->comment_post_ID );
		$post_title   = $post instanceof \WP_Post ? $post->post_title : '';
		$post_excerpt = $post instanceof \WP_Post
			? wp_trim_words( wp_strip_all_tags( $post->post_content ), 50 )
			: '';

		$tone       = in_array( $input['tone'], array( 'professional', 'friendly', 'casual' ), true )
			? $input['tone']
			: 'friendly';
		$guidelines = sanitize_textarea_field( (string) $input['guidelines'] );

		// Build the prompt context.
		$context = $this->build_context( $comment, $post_title, $post_excerpt, $tone, $guidelines );

		// Generate the reply.
		$reply = $this->generate_reply( $context );

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		return array(
			'comment_id' => $comment_id,
			'reply'      => $reply,
		);
	}

	protected function permission_callback( $input ) {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to generate reply suggestions.', 'ai' )
			);
		}

		return true;
	}

	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	private function build_context(
		\WP_Comment $comment,
		string $post_title,
		string $post_excerpt,
		string $tone,
		string $guidelines = ''
	): string {
		$parts = array();

		if ( '' !== $post_title ) {
			$parts[] = sprintf( 'Post Title: %s', $post_title );
		}

		if ( '' !== $post_excerpt ) {
			$parts[] = sprintf( 'Post Context: %s', $post_excerpt );
		}

		$parts[] = sprintf( 'Comment Author: %s', $comment->comment_author );
		$parts[] = sprintf( 'Comment: """%s"""', $comment->comment_content );
		$parts[] = sprintf( 'Requested Tone: %s', $tone );

		if ( '' !== $guidelines ) {
			$parts[] = sprintf( 'Editorial Guidelines: %s', $guidelines );
		}

		return implode( "\n", $parts );
	}

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

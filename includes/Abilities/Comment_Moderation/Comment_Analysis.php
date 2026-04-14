<?php
/**
 * Comment Analysis WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Comment_Moderation;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Comment_Moderation\Comment_Moderation;
use WordPress\AI_Client\AI_Client;

use function WordPress\AI\get_preferred_models_for_text_generation;

/**
 * Comment Analysis WordPress Ability.
 *
 * Analyzes comments for toxicity and sentiment.
 *
 * @since x.x.x
 */
class Comment_Analysis extends Abstract_Ability {

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
					'description' => esc_html__( 'The ID of the comment to analyze.', 'ai' ),
					'required'    => true,
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
			'type'       => 'object',
			'properties' => array(
				'comment_id'     => array(
					'type'        => 'integer',
					'description' => esc_html__( 'The analyzed comment ID.', 'ai' ),
				),
				'toxicity_score' => array(
					'type'        => 'number',
					'minimum'     => 0,
					'maximum'     => 1,
					'description' => esc_html__( 'Toxicity score from 0 (not toxic) to 1 (highly toxic).', 'ai' ),
				),
				'sentiment'      => array(
					'type'        => 'string',
					'enum'        => array( 'positive', 'negative', 'neutral' ),
					'description' => esc_html__( 'The sentiment of the comment.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array{comment_id: int, toxicity_score: float, sentiment: string}|\WP_Error The result of the ability execution.
	 */
	protected function execute_callback( $input ) {
		$comment_id = absint( $input['comment_id'] ?? 0 );

		if ( ! $comment_id ) {
			return new WP_Error(
				'missing_comment_id',
				esc_html__( 'Comment ID is required.', 'ai' )
			);
		}

		$comment = get_comment( $comment_id );

		if ( ! is_a( $comment, '\WP_Comment' ) ) {
			return new WP_Error(
				'comment_not_found',
				sprintf(
					/* translators: %d: Comment ID. */
					esc_html__( 'Comment with ID %d not found.', 'ai' ),
					$comment_id
				)
			);
		}

		// Check if already being processed (lock mechanism).
		$current_status = get_comment_meta( $comment_id, Comment_Moderation::META_ANALYSIS_STATUS, true );

		if ( Comment_Moderation::STATUS_PROCESSING === $current_status ) {
			return new WP_Error(
				'already_processing',
				esc_html__( 'This comment is already being analyzed.', 'ai' )
			);
		}

		// Set status to processing.
		update_comment_meta( $comment_id, Comment_Moderation::META_ANALYSIS_STATUS, Comment_Moderation::STATUS_PROCESSING );

		// Analyze the comment.
		$result = $this->analyze_comment( $comment->comment_content, $comment->comment_author );

		if ( is_wp_error( $result ) ) {
			// Mark as failed.
			update_comment_meta( $comment_id, Comment_Moderation::META_ANALYSIS_STATUS, Comment_Moderation::STATUS_FAILED );
			return $result;
		}

		// Store the results.
		update_comment_meta( $comment_id, Comment_Moderation::META_TOXICITY_SCORE, $result['toxicity_score'] );
		update_comment_meta( $comment_id, Comment_Moderation::META_SENTIMENT, $result['sentiment'] );
		update_comment_meta( $comment_id, Comment_Moderation::META_ANALYSIS_STATUS, Comment_Moderation::STATUS_COMPLETE );
		update_comment_meta( $comment_id, Comment_Moderation::META_ANALYZED_AT, time() );

		return array(
			'comment_id'     => $comment_id,
			'toxicity_score' => $result['toxicity_score'],
			'sentiment'      => $result['sentiment'],
		);
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
				esc_html__( 'You do not have permission to analyze comments.', 'ai' )
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
	 * Analyzes a comment for toxicity and sentiment.
	 *
	 * @since x.x.x
	 *
	 * @param string $content The comment content.
	 * @param string $author  The comment author name.
	 * @return array{toxicity_score: float, sentiment: string}|\WP_Error The analysis result.
	 */
	private function analyze_comment( string $content, string $author ) {
		$prompt = sprintf(
			"Comment by %s:\n\"\"\"%s\"\"\"",
			$author,
			$content
		);

		$prompt_builder = $this->get_prompt_builder( $content );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$result = $prompt_builder->generate_text();;

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Parse the JSON response.
		$parsed = json_decode( $result, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $parsed ) ) {
			return new WP_Error(
				'parse_error',
				esc_html__( 'Failed to parse AI response.', 'ai' )
			);
		}

		// Validate and sanitize the response.
		$toxicity_score = isset( $parsed['toxicity_score'] )
			? max( 0, min( 1, (float) $parsed['toxicity_score'] ) )
			: 0;

		$valid_sentiments = array( 'positive', 'negative', 'neutral' );
		$sentiment        = isset( $parsed['sentiment'] ) && in_array( $parsed['sentiment'], $valid_sentiments, true )
			? $parsed['sentiment']
			: 'neutral';

		return array(
			'toxicity_score' => $toxicity_score,
			'sentiment'      => $sentiment,
		);
	}

	/**
	 * Gets a prompt builder for analyzing a comment.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The prompt to analyze a comment.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error on failure.
	 */
	private function get_prompt_builder( string $prompt ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction() )
			->using_model_preference( ...get_preferred_models_for_text_generation() );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Comment analysis failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}
}

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

use function WordPress\AI\get_preferred_models_for_text_generation;

/**
 * Comment Analysis WordPress Ability.
 *
 * Analyzes comments for toxicity and sentiment.
 *
 * @since 0.9.0
 */
class Comment_Analysis extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.9.0
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'comment_id' => array(
					'type'        => 'integer',
					'description' => esc_html__( 'The ID of the comment to analyze.', 'ai' ),
				),
			),
			'required'   => array( 'comment_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.9.0
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
					'enum'        => array_keys( Comment_Moderation::get_sentiment_config() ),
					'description' => esc_html__( 'The sentiment of the comment.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.9.0
	 *
	 * @return array{comment_id: int, toxicity_score: float, sentiment: string}|\WP_Error The result of the ability execution.
	 */
	protected function execute_callback( $input ) {
		return $this->analyze_comment_by_id( absint( $input['comment_id'] ?? 0 ) );
	}

	/**
	 * Analyzes a comment by ID from trusted internal plugin flows.
	 *
	 * This method intentionally does not perform user capability checks. User-invoked
	 * ability execution must go through execute(), which runs permission_callback().
	 *
	 * @since 0.9.0
	 *
	 * @param int $comment_id Comment ID.
	 * @return array{comment_id: int, toxicity_score: float, sentiment: string}|\WP_Error The result of the analysis.
	 */
	public function analyze_comment_by_id( int $comment_id ) {
		$comment_id = absint( $comment_id );

		if ( ! $comment_id ) {
			return new WP_Error(
				'missing_comment_id',
				esc_html__( 'Comment ID is required.', 'ai' )
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
	 * @since 0.9.0
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
	 * @since 0.9.0
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Returns the JSON schema used for the output of the comment analysis.
	 *
	 * @since 0.9.0
	 *
	 * @return array<string, mixed> JSON schema for the output of the comment analysis.
	 */
	private function response_schema(): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'toxicity_score' => array( 'type' => 'number' ),
				'sentiment'      => array( 'type' => 'string' ),
			),
			'required'             => array( 'toxicity_score', 'sentiment' ),
			'additionalProperties' => false,
		);

		/**
		 * Filters the JSON schema used for the output of the comment analysis.
		 *
		 * @since 0.9.0
		 *
		 * @param array<string, mixed> $schema JSON schema for the output of the comment analysis.
		 */
		return (array) apply_filters( 'wpai_comment_analysis_response_schema', $schema );
	}

	/**
	 * Analyzes a comment for toxicity and sentiment.
	 *
	 * @since 0.9.0
	 *
	 * @param string $content The comment content.
	 * @param string $author  The comment author name.
	 * @return array{toxicity_score: float, sentiment: string}|\WP_Error The analysis result.
	 */
	private function analyze_comment( string $content, string $author ) {
		/**
		 * Filters the comment analysis result before calling the AI provider.
		 *
		 * Returning an array short-circuits the provider call. This is primarily useful for tests
		 * and integrations that provide their own comment analysis implementation.
		 *
		 * @since 0.9.0
		 *
		 * @param array{toxicity_score: float, sentiment: string}|null $result  Precomputed analysis result.
		 * @param string                                              $content Comment content.
		 * @param string                                              $author  Comment author name.
		 */
		$pre_result = apply_filters( 'wpai_comment_analysis_result', null, $content, $author );

		if ( is_array( $pre_result ) ) {
			return $this->sanitize_analysis_result( $pre_result );
		}

		$prompt = sprintf(
			"Comment by %s:\n\"\"\"%s\"\"\"",
			$author,
			$content
		);

		$prompt_builder = $this->get_prompt_builder( $prompt );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$result = $prompt_builder->generate_text();

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

		return $this->sanitize_analysis_result( $parsed );
	}

	/**
	 * Gets a prompt builder for analyzing a comment.
	 *
	 * @since 0.9.0
	 *
	 * @param string $prompt The prompt to analyze a comment.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error on failure.
	 */
	private function get_prompt_builder( string $prompt ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction() )
			->using_model_preference( ...get_preferred_models_for_text_generation() )
			->as_json_response( $this->response_schema() );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Comment analysis failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}

	/**
	 * Sanitizes an analysis result.
	 *
	 * @since 0.9.0
	 *
	 * @param array<string, mixed> $result Raw analysis result.
	 * @return array{toxicity_score: float, sentiment: string} Sanitized analysis result.
	 */
	private function sanitize_analysis_result( array $result ): array {
		// Validate and sanitize the response.
		$toxicity_score = isset( $result['toxicity_score'] )
			? max( 0, min( 1, (float) $result['toxicity_score'] ) )
			: 0;

		$valid_sentiments = array_keys( Comment_Moderation::get_sentiment_config() );
		$sentiment        = isset( $result['sentiment'] ) && in_array( $result['sentiment'], $valid_sentiments, true )
			? $result['sentiment']
			: Comment_Moderation::SENTIMENT_NEUTRAL;

		return array(
			'toxicity_score' => $toxicity_score,
			'sentiment'      => $sentiment,
		);
	}
}

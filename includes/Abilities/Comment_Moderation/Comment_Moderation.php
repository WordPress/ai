<?php
/**
 * Comment Moderation Ability Class.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Comment_Moderation;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use function WordPress\AI\get_preferred_models_for_text_generation;

/**
 * Class Comment_Moderation
 *
 * Analyzes incoming comments for spam and toxicity using AI.
 *
 * @since 0.7.0
 */
class Comment_Moderation extends Abstract_Ability {

	/**
	 * Execute the ability.
	 *
	 * @since 0.7.0
	 *
	 * @param mixed $input Arguments for the ability (comment_text, author_name, author_url).
	 * @return array<string, mixed>|WP_Error The moderation result as an array, or WP_Error on failure.
	 */
	public function execute_callback( $input ) {
		if ( empty( $input['comment_text'] ) ) {
			return new WP_Error( 'missing_comment_text', __( 'Comment text is required for moderation.', 'ai' ) );
		}

		$system_instruction = $this->get_system_instruction();

		$prompt_text = sprintf(
			"Analyze this comment:\n\nAuthor Name: %s\nAuthor URL: %s\nComment Text: %s",
			sanitize_text_field( $input['author_name'] ?? 'Anonymous' ),
			sanitize_url( $input['author_url'] ?? '' ),
			sanitize_textarea_field( $input['comment_text'] )
		);

		$prompt_builder = wp_ai_client_prompt( $prompt_text )
			->using_system_instruction( $system_instruction )
			->using_model_preference( ...get_preferred_models_for_text_generation() )
			->as_json_response( $this->output_schema() );

		$prompt_builder = $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Text generation failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$response_text = $prompt_builder->generate_text();

		if ( is_wp_error( $response_text ) ) {
			return $response_text;
		}

		$decoded_response = json_decode( $response_text, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', __( 'The AI returned invalid JSON.', 'ai' ) );
		}

		return $decoded_response;
	}

	/**
	 * Returns the input schema of the ability.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'comment_text' => array(
					'type' => 'string',
				),
				'author_name'  => array(
					'type' => 'string',
				),
				'author_url'   => array(
					'type' => 'string',
				),
			),
			'required'   => array( 'comment_text' ),
		);
	}

	/**
	 * Returns the output schema of the ability.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'is_spam'        => array(
					'type' => 'boolean',
				),
				'toxicity_score' => array(
					'type' => 'integer',
				),
				'reason'         => array(
					'type' => 'string',
				),
				'recommendation' => array(
					'type' => 'string',
					'enum' => array( 'approve', 'hold', 'spam' ),
				),
			),
			'required'   => array( 'is_spam', 'toxicity_score', 'reason', 'recommendation' ),
		);
	}

	/**
	 * Checks whether the current user has permission to execute the ability.
	 *
	 * @since 0.7.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return bool|\WP_Error True if the user has permission, WP_Error otherwise.
	 */
	protected function permission_callback( $input ) {
		// Comment moderation needs to run for guest users (unauthenticated).
		return true;
	}

	/**
	 * Returns the meta of the ability.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, mixed> The meta of the ability.
	 */
	protected function meta(): array {
		return array();
	}
}

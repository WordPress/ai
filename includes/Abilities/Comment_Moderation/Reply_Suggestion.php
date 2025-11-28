<?php
/**
 * Reply Suggestion WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Comment_Moderation;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI_Client\AI_Client;

use function WordPress\AI\get_preferred_models;

/**
 * Reply Suggestion WordPress Ability.
 *
 * Generates AI-powered reply suggestions for comments.
 *
 * @since 0.1.0
 */
class Reply_Suggestion extends Abstract_Ability {

	/**
	 * The default number of suggestions to generate.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	protected const CANDIDATES_DEFAULT = 3;

	/**
	 * Returns the input schema of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'comment_id' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'The ID of the comment to generate replies for.', 'ai' ),
					'required'          => true,
				),
				'candidates' => array(
					'type'              => 'integer',
					'minimum'           => 1,
					'maximum'           => 5,
					'default'           => self::CANDIDATES_DEFAULT,
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'Number of reply suggestions to generate.', 'ai' ),
				),
				'tone'       => array(
					'type'              => 'string',
					'enum'              => array( 'professional', 'friendly', 'casual' ),
					'default'           => 'friendly',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'The tone for the reply suggestions.', 'ai' ),
				),
			),
			'required'   => array( 'comment_id' ),
		);
	}

	/**
	 * Returns the output schema of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'comment_id' => array(
					'type'        => 'integer',
					'description' => esc_html__( 'The comment ID.', 'ai' ),
				),
				'replies'    => array(
					'type'        => 'array',
					'description' => esc_html__( 'Generated reply suggestions.', 'ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
			),
		);
	}

	/**
	 * Executes the ability with the given input arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return array{comment_id: int, replies: array<string>}|\WP_Error The result of the ability execution.
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'comment_id' => 0,
				'candidates' => self::CANDIDATES_DEFAULT,
				'tone'       => 'friendly',
			)
		);

		$comment_id = absint( $args['comment_id'] );

		if ( ! $comment_id ) {
			return new WP_Error(
				'missing_comment_id',
				esc_html__( 'Comment ID is required.', 'ai' )
			);
		}

		$comment = get_comment( $comment_id );

		if ( ! $comment ) {
			return new WP_Error(
				'comment_not_found',
				sprintf(
					/* translators: %d: Comment ID. */
					esc_html__( 'Comment with ID %d not found.', 'ai' ),
					$comment_id
				)
			);
		}

		// Get the post for context.
		$post         = get_post( $comment->comment_post_ID );
		$post_title   = $post ? $post->post_title : '';
		$post_excerpt = $post ? wp_trim_words( wp_strip_all_tags( $post->post_content ), 50 ) : '';

		// Build context for the AI.
		$context = $this->build_context( $comment, $post_title, $post_excerpt, $args['tone'] );

		// Generate replies.
		$result = $this->generate_replies( $context, (int) $args['candidates'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'comment_id' => $comment_id,
			'replies'    => $result,
		);
	}

	/**
	 * Returns the permission callback of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return bool|\WP_Error True if the user has permission, WP_Error otherwise.
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
	 * Returns the meta of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The meta of the ability.
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Builds the context string for the AI prompt.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Comment $comment      The comment object.
	 * @param string      $post_title   The post title.
	 * @param string      $post_excerpt The post excerpt.
	 * @param string      $tone         The desired tone.
	 * @return string The context string.
	 */
	private function build_context( \WP_Comment $comment, string $post_title, string $post_excerpt, string $tone ): string {
		$context_parts = array();

		if ( $post_title ) {
			$context_parts[] = sprintf( 'Post Title: %s', $post_title );
		}

		if ( $post_excerpt ) {
			$context_parts[] = sprintf( 'Post Excerpt: %s', $post_excerpt );
		}

		$context_parts[] = sprintf( 'Comment Author: %s', $comment->comment_author );
		$context_parts[] = sprintf( 'Comment: """%s"""', $comment->comment_content );
		$context_parts[] = sprintf( 'Desired Tone: %s', $tone );

		return implode( "\n", $context_parts );
	}

	/**
	 * Generates reply suggestions using AI.
	 *
	 * @since 0.1.0
	 *
	 * @param string $context    The context for generating replies.
	 * @param int    $candidates The number of suggestions to generate.
	 * @return array<string>|\WP_Error The generated replies or error.
	 */
	private function generate_replies( string $context, int $candidates ) {
		$result = AI_Client::prompt_with_wp_error( $context )
			->using_system_instruction( $this->get_system_instruction( 'reply-system-instruction.php' ) )
			->using_candidate_count( max( 1, $candidates ) )
			->using_model_preference( ...$this->get_reply_model_preferences() )
			->generate_texts();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Clean up the responses.
		return array_map(
			static function ( $reply ) {
				return sanitize_textarea_field( trim( $reply ) );
			},
			$result
		);
	}

	/**
	 * Returns model preferences prioritizing OpenAI for replies.
	 *
	 * @return array<int, array{string, string}>
	 */
	private function get_reply_model_preferences(): array {
		$openai_preferences = array(
			array( 'openai', 'gpt-4.1' ),
			array( 'openai', 'gpt-4o-mini' ),
		);

		$remaining = array_filter(
			get_preferred_models(),
			static function ( array $model ): bool {
				return 'openai' !== $model[0];
			}
		);

		return array_merge( $openai_preferences, $remaining );
	}
}

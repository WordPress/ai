<?php
/**
 * Type-ahead (ghost text) WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Type_Ahead;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI_Client\AI_Client;

use function WordPress\AI\get_post_context;
use function WordPress\AI\normalize_content;

/**
 * Generates inline completion suggestions for block content.
 *
 * @since 0.1.0
 */
class Type_Ahead extends Abstract_Ability {
	/**
	 * Cache group identifier.
	 */
	private const CACHE_GROUP = 'ai-type-ahead';

	/**
	 * Cache lifetime in seconds.
	 */
	private const CACHE_TTL = 45;

	/**
	 * Maximum context characters sent to the provider.
	 */
	private const CONTEXT_LIMIT = 5000;

	/**
	 * Allowed completion modes.
	 */
	private const MODES = array( 'word', 'sentence', 'paragraph', 'smart' );

	/**
	 * Logs structured debug details when WP_DEBUG is enabled.
	 */
	private function log_debug( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || true !== WP_DEBUG ) {
			return;
		}

		if ( ! empty( $context ) ) {
			$encoded = wp_json_encode( $context );
			if ( false !== $encoded ) {
				$message .= ' ' . $encoded;
			}
		}

		error_log( '[AI Type Ahead] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'             => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'Post ID used to gather additional context.', 'ai' ),
				),
				'block_id'            => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'description'       => esc_html__( 'Unique identifier of the block requesting the suggestion.', 'ai' ),
				),
				'block_content'       => array(
					'type'        => 'string',
					'description' => esc_html__( 'Full text content of the active block.', 'ai' ),
				),
				'preceding_text'      => array(
					'type'        => 'string',
					'description' => esc_html__( 'Text that appears before the caret within the block.', 'ai' ),
				),
				'following_text'      => array(
					'type'        => 'string',
					'description' => esc_html__( 'Text after the caret within the block.', 'ai' ),
				),
				'surrounding_context' => array(
					'type'        => 'string',
					'description' => esc_html__( 'Neighboring block content for additional context.', 'ai' ),
				),
				'cursor_position'     => array(
					'type'        => 'integer',
					'description' => esc_html__( 'Caret offset within the block plain text.', 'ai' ),
				),
				'mode'                => array(
					'type' => 'string',
					'enum' => self::MODES,
				),
				'max_words'           => array(
					'type'        => 'integer',
					'description' => esc_html__( 'Maximum number of words in the suggestion.', 'ai' ),
				),
				'manual_trigger'      => array(
					'type' => 'boolean',
				),
			),
			'required'   => array( 'block_content' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'suggestion'      => array(
					'type'        => 'string',
					'description' => esc_html__( 'Suggested continuation.', 'ai' ),
				),
				'confidence'      => array(
					'type'        => 'number',
					'description' => esc_html__( 'Confidence score between 0 and 1.', 'ai' ),
				),
				'cursor_position' => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array{suggestion: string, confidence: float, cursor_position: int}|\WP_Error
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'post_id'             => null,
				'block_id'            => '',
				'block_content'       => '',
				'preceding_text'      => '',
				'following_text'      => '',
				'surrounding_context' => '',
				'cursor_position'     => 0,
				'mode'                => 'smart',
				'max_words'           => 20,
				'manual_trigger'      => false,
			)
		);

		$mode      = in_array( $args['mode'], self::MODES, true ) ? $args['mode'] : 'smart';
		$max_words = max( 1, min( 50, absint( $args['max_words'] ) ) );

		$block_content   = $this->truncate_text( (string) $args['block_content'] );
		$preceding_text  = $this->truncate_text( (string) $args['preceding_text'] );
		$following_text  = $this->truncate_text( (string) $args['following_text'] );
		$surrounding     = $this->truncate_text( (string) $args['surrounding_context'] );
		$cursor_position = absint( $args['cursor_position'] );

		$this->log_debug(
			'Received Type Ahead request',
			array(
				'post_id'         => $args['post_id'],
				'block_id'        => $args['block_id'],
				'mode'            => $mode,
				'max_words'       => $max_words,
				'cursor_position' => $cursor_position,
				'manual_trigger'  => (bool) $args['manual_trigger'],
				'block_length'    => mb_strlen( $block_content ),
				'preceding_len'   => mb_strlen( $preceding_text ),
				'following_len'   => mb_strlen( $following_text ),
				'surrounding_len' => mb_strlen( $surrounding ),
			)
		);

		if ( '' === $block_content ) {
			$this->log_debug( 'Rejected request with empty block content', array( 'block_id' => $args['block_id'] ) );
			return new WP_Error( 'ai_type_ahead_missing_block', esc_html__( 'Block content is required for type-ahead suggestions.', 'ai' ) );
		}

		if ( $cursor_position > mb_strlen( wp_strip_all_tags( $block_content ) ) ) {
			$cursor_position = mb_strlen( wp_strip_all_tags( $block_content ) );
		}

		$cache_key = $this->build_cache_key( $block_content, $preceding_text, $mode, $max_words );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( ! empty( $cached ) ) {
			$this->log_debug(
				'Cache hit for Type Ahead request',
				array(
					'block_id'        => $args['block_id'],
					'cursor_position' => $cursor_position,
					'mode'            => $mode,
					'max_words'       => $max_words,
				)
			);
			return $cached;
		}

		$context = $this->prepare_prompt_context( $args['post_id'], $block_content, $preceding_text, $following_text, $surrounding, $cursor_position, $mode, $max_words, (bool) $args['manual_trigger'] );

		$this->log_debug(
			'Dispatching Type Ahead prompt',
			array(
				'block_id'        => $args['block_id'],
				'cursor_position' => $cursor_position,
				'manual_trigger'  => (bool) $args['manual_trigger'],
			)
		);

		$start_time = microtime( true );
		$result     = $this->generate_suggestion( $context );
		$duration   = ( microtime( true ) - $start_time ) * 1000;

		if ( is_wp_error( $result ) ) {
			$this->log_debug(
				'Type Ahead provider returned WP_Error',
				array(
					'code'        => $result->get_error_code(),
					'message'     => $result->get_error_message(),
					'duration_ms' => (int) round( $duration ),
				)
			);
			return $result;
		}

		$result['cursor_position'] = $cursor_position;

		$this->log_debug(
			'Type Ahead suggestion ready',
			array(
				'block_id'        => $args['block_id'],
				'cursor_position' => $cursor_position,
				'confidence'      => $result['confidence'],
				'preview'         => mb_substr( $result['suggestion'], 0, 80 ),
				'duration_ms'     => (int) round( $duration ),
			)
		);

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function permission_callback( $args ) {
		$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : null;

		if ( $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				$this->log_debug( 'Permission denied: post not found', array( 'post_id' => $post_id ) );
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), $post_id )
				);
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				$this->log_debug( 'Permission denied: cannot edit post', array( 'post_id' => $post_id ) );
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to request type-ahead suggestions for this post.', 'ai' )
				);
			}
		} elseif ( ! current_user_can( 'edit_posts' ) ) {
			$this->log_debug( 'Permission denied: cannot edit posts capability missing', array( 'user_id' => get_current_user_id() ) );
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to request type-ahead suggestions.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
			'mcp'          => array(
				'public'   => true,
				'type'     => 'tool',
				'category' => 'editor',
			),
		);
	}

	/**
	 * Builds a cache key for the request.
	 */
	private function build_cache_key( string $block_content, string $preceding_text, string $mode, int $max_words ): string {
		return 'type_ahead_' . md5( $block_content . '|' . $preceding_text . '|' . $mode . '|' . $max_words );
	}

	/**
	 * Generates the suggestion via the AI client.
	 *
	 * @param array<string, mixed> $context Prompt context payload.
	 * @return array{suggestion: string, confidence: float}|\WP_Error
	 */
	private function generate_suggestion( array $context ) {
		$this->log_debug(
			'Calling AI client for Type Ahead',
			array(
				'mode'         => $context['mode'],
				'max_words'    => $context['max_words'],
				'cursor'       => $context['cursor_position'],
				'manual'       => (bool) $context['manual_trigger'],
				'block_length' => mb_strlen( (string) $context['block_content'] ),
			)
		);

		$response = AI_Client::prompt_with_wp_error( wp_json_encode( $context ) )
			->using_system_instruction( $this->get_system_instruction() )
			->using_candidate_count( 1 )
			->using_model_preference( ...$this->get_model_preferences() )
			->generate_texts();

		if ( is_wp_error( $response ) ) {
			$this->log_debug(
				'AI client returned WP_Error',
				array(
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
				)
			);
			return $response;
		}

		$text = $response[0] ?? '';

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			$this->log_debug( 'AI client returned empty response text' );
			return new WP_Error( 'ai_type_ahead_empty', esc_html__( 'The AI provider returned an empty suggestion.', 'ai' ) );
		}

		$data = $this->decode_suggestion_payload( $text );

		if ( ! is_array( $data ) || empty( $data['suggestion'] ) ) {
			$this->log_debug( 'AI response failed JSON decode', array( 'raw' => mb_substr( $text, 0, 160 ) ) );
			return new WP_Error( 'ai_type_ahead_invalid', esc_html__( 'Unable to parse the type-ahead suggestion response.', 'ai' ) );
		}

		$suggestion = sanitize_textarea_field( $data['suggestion'] );

		if ( '' === $suggestion ) {
			$this->log_debug( 'Suggestion blank after sanitization' );
			return new WP_Error( 'ai_type_ahead_blank', esc_html__( 'The suggestion returned was blank after sanitization.', 'ai' ) );
		}

		$confidence = isset( $data['confidence'] ) ? min( 1, max( 0, (float) $data['confidence'] ) ) : 0.0;

		return array(
			'suggestion' => $suggestion,
			'confidence' => $confidence,
		);
	}

	/**
	 * Attempts to decode a JSON payload that may be wrapped in markdown fences or extra prose.
	 */
	private function decode_suggestion_payload( string $raw ): ?array {
		$clean = trim( $raw );

		if ( str_starts_with( $clean, '```' ) ) {
			$clean = preg_replace( '/^```[a-zA-Z0-9_-]*\s*/', '', $clean ) ?? $clean;
			if ( str_contains( $clean, '```' ) ) {
				$clean = substr( $clean, 0, strpos( $clean, '```' ) );
			}
			$clean = trim( $clean );
		}

		$decoded = json_decode( $clean, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		if ( preg_match( '/\{.*\}/s', $clean, $matches ) === 1 ) {
			$decoded = json_decode( $matches[0], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Prepares the structured context payload for the prompt.
	 *
	 * @param int|null $post_id Optional post ID.
	 * @param string   $block_content Block content.
	 * @param string   $preceding_text Text before caret.
	 * @param string   $following_text Text after caret.
	 * @param string   $surrounding_context Neighboring block text.
	 * @param int      $cursor_position Caret offset.
	 * @param string   $mode Completion mode.
	 * @param int      $max_words Maximum words in suggestion.
	 * @param bool     $manual_trigger Whether the user explicitly requested the suggestion.
	 *
	 * @return array<string, mixed>
	 */
	private function prepare_prompt_context( ?int $post_id, string $block_content, string $preceding_text, string $following_text, string $surrounding_context, int $cursor_position, string $mode, int $max_words, bool $manual_trigger ): array {
		$post_context = array();

		if ( $post_id ) {
			$post_context = get_post_context( $post_id );
		}

		return array(
			'mode'                => $mode,
			'max_words'           => $max_words,
			'cursor_position'     => $cursor_position,
			'block_content'       => $block_content,
			'preceding_text'      => $preceding_text,
			'following_text'      => $following_text,
			'surrounding_context' => $surrounding_context,
			'post_context'        => $post_context,
			'manual_trigger'      => $manual_trigger,
		);
	}

	/**
	 * Truncates text to the context limit.
	 */
	private function truncate_text( string $value ): string {
		$value = normalize_content( $value );

		if ( mb_strlen( $value ) > self::CONTEXT_LIMIT ) {
			return mb_substr( $value, -1 * self::CONTEXT_LIMIT );
		}

		return $value;
	}
}

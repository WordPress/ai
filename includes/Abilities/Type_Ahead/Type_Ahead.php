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
use WordPress\AI\Experiments\Type_Ahead\Type_Ahead as Type_Ahead_Experiment;

use function WordPress\AI\normalize_content;

/**
 * Generates inline completion suggestions for block content.
 *
 * @since 1.1.0
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
	private const MODES = array( 'word', 'sentence', 'paragraph', 'smart' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 */
	protected function guideline_categories(): array {
		return array( 'site', 'copy' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'             => array(
					'type'        => 'integer',
					'description' => esc_html__( 'Post ID used to gather additional context.', 'ai' ),
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
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
	 *
	 * @return array{suggestion: string, confidence: float, cursor_position: int}|\WP_Error
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'post_id'             => null,
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

		if ( $cursor_position > mb_strlen( wp_strip_all_tags( $block_content ) ) ) {
			$cursor_position = mb_strlen( wp_strip_all_tags( $block_content ) );
		}

		$cache_key = $this->build_cache_key( $block_content, $preceding_text, $mode, $max_words );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( ! empty( $cached ) ) {
			return $cached;
		}

		$context = $this->prepare_prompt_context( $block_content, $preceding_text, $following_text, $surrounding, $cursor_position, $mode, $max_words, (bool) $args['manual_trigger'] );

		$result = $this->generate_suggestion( $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['cursor_position'] = $cursor_position;

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 */
	protected function permission_callback( $args ) {
		$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : null;

		if ( $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), $post_id )
				);
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to request type-ahead suggestions for this post.', 'ai' )
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
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to request type-ahead suggestions.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Returns the JSON schema used for structured output generation.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, mixed> JSON schema for a type-ahead suggestion.
	 */
	private function suggestion_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'suggestion' => array(
					'type' => 'string',
				),
				'confidence' => array(
					'type' => 'number',
				),
			),
			'required'             => array( 'suggestion', 'confidence' ),
			'additionalProperties' => false,
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
		$prompt = wp_json_encode(
			$context,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		if ( ! is_string( $prompt ) ) {
			return new WP_Error( 'ai_type_ahead_invalid_prompt', esc_html__( 'Unable to encode the type-ahead prompt.', 'ai' ) );
		}

		$prompt_builder = $this->get_prompt_builder( $prompt );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$raw = $prompt_builder->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		if ( empty( $raw ) ) {
			return new WP_Error( 'ai_type_ahead_empty', esc_html__( 'The AI provider returned an empty suggestion.', 'ai' ) );
		}

		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['suggestion'] ) || ! is_string( $decoded['suggestion'] ) ) {
			return new WP_Error( 'ai_type_ahead_invalid', esc_html__( 'Unable to parse the type-ahead suggestion response.', 'ai' ) );
		}

		$suggestion = sanitize_textarea_field( $decoded['suggestion'] );

		if ( '' === $suggestion ) {
			return new WP_Error( 'ai_type_ahead_blank', esc_html__( 'The suggestion returned was blank after sanitization.', 'ai' ) );
		}

		$confidence = isset( $decoded['confidence'] ) ? min( 1, max( 0, (float) $decoded['confidence'] ) ) : 0.0;

		return array(
			'suggestion' => $suggestion,
			'confidence' => $confidence,
		);
	}

	/**
	 * Gets a prompt builder for generating type-ahead suggestions.
	 *
	 * @since 1.1.0
	 *
	 * @param string $prompt The prompt to generate type-ahead suggestions from.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error on failure.
	 */
	private function get_prompt_builder( string $prompt ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction( null, array( 'block_name' => 'core/paragraph' ) ) )
			->as_json_response( $this->suggestion_schema() );

		$prompt_builder = $this->set_provider_model_preference(
			$prompt_builder,
			Type_Ahead_Experiment::class,
			array(
				array( 'anthropic', 'claude-haiku-4-5' ),
				array( 'google', 'gemini-2.5-flash' ),
				array( 'openai', 'gpt-4.1-nano' ),
			)
		);

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Type-ahead suggestion generation failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}

	/**
	 * Prepares the structured context payload for the prompt.
	 *
	 * @param string $block_content Block content.
	 * @param string $preceding_text Text before caret.
	 * @param string $following_text Text after caret.
	 * @param string $surrounding_context Neighboring block text.
	 * @param int    $cursor_position Caret offset.
	 * @param string $mode Completion mode.
	 * @param int    $max_words Maximum words in suggestion.
	 * @param bool   $manual_trigger Whether the user explicitly requested the suggestion.
	 *
	 * @return array<string, mixed>
	 */
	private function prepare_prompt_context( string $block_content, string $preceding_text, string $following_text, string $surrounding_context, int $cursor_position, string $mode, int $max_words, bool $manual_trigger ): array {
		return array(
			'mode'                => $mode,
			'max_words'           => $max_words,
			'cursor_position'     => $cursor_position,
			'block_content'       => $block_content,
			'preceding_text'      => $preceding_text,
			'following_text'      => $following_text,
			'surrounding_context' => $surrounding_context,
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

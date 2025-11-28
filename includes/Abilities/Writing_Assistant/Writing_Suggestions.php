<?php
/**
 * Writing Assistant suggestions ability.
 *
 * @package WordPress\AI\Abilities\Writing_Assistant
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Writing_Assistant;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI_Client\AI_Client;

use function absint;
use function esc_html__;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function wp_json_encode;

use function WordPress\AI\get_post_context;
use function WordPress\AI\normalize_content;

/**
 * Generates structured suggestion streams for the writing assistant sidebar.
 *
 * @since 0.1.0
 */
class Writing_Suggestions extends Abstract_Ability {
	/**
	 * Supported suggestion types.
	 *
	 * @var string[]
	 */
	private const SUGGESTION_TYPES = array(
		'readability',
		'seo',
		'internal-link',
		'fact-check',
		'structure',
		'tone',
		'grammar',
	);

	/**
	 * Allowed priority levels.
	 *
	 * @var string[]
	 */
	private const PRIORITIES = array( 'high', 'medium', 'low' );

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'        => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'Post ID to load content context from.', 'ai' ),
				),
				'content'        => array(
					'type'        => 'string',
					'description' => esc_html__( 'Direct HTML content to analyze.', 'ai' ),
				),
				'requested_types' => array(
					'type'        => 'array',
					'description' => esc_html__( 'Subset of suggestion categories to generate.', 'ai' ),
					'items'       => array(
						'type' => 'string',
						'enum' => self::SUGGESTION_TYPES,
					),
				),
				'session'       => array(
					'type'        => 'object',
					'description' => esc_html__( 'Current writing session context.', 'ai' ),
					'properties'  => array(
						'id'                    => array(
							'type'        => 'string',
							'description' => esc_html__( 'Unique identifier for the session.', 'ai' ),
						),
						'words_written'         => array(
							'type'        => 'integer',
							'description' => esc_html__( 'Words written during this session.', 'ai' ),
						),
						'suggestions_received'  => array(
							'type'        => 'integer',
							'description' => esc_html__( 'Number of suggestions already surfaced.', 'ai' ),
						),
						'suggestions_applied'   => array(
							'type'        => 'integer',
							'description' => esc_html__( 'Number of suggestions applied.', 'ai' ),
						),
						'ghost_accepts'         => array(
							'type'        => 'integer',
							'description' => esc_html__( 'Number of ghost text acceptances.', 'ai' ),
						),
						'timer_duration'        => array(
							'type'        => 'integer',
							'description' => esc_html__( 'Configured timer duration in seconds.', 'ai' ),
						),
						'timer_remaining'       => array(
							'type'        => 'integer',
							'description' => esc_html__( 'Seconds left on the active timer.', 'ai' ),
						),
					),
				),
				'trigger'       => array(
					'type'        => 'string',
					'description' => esc_html__( 'Event that triggered the request (manual, word-delta, timer, idle).', 'ai' ),
				),
				'word_delta'    => array(
					'type'        => 'integer',
					'description' => esc_html__( 'Words written since the previous request.', 'ai' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'session_id' => array(
					'type'        => 'string',
					'description' => esc_html__( 'Session identifier echoed from the input.', 'ai' ),
				),
				'meta'       => array(
					'type'        => 'object',
					'description' => esc_html__( 'Additional diagnostic information.', 'ai' ),
					'properties'  => array(
						'analyzed_at' => array(
							'type'        => 'string',
							'description' => esc_html__( 'ISO8601 timestamp when the analysis completed.', 'ai' ),
						),
						'word_count'  => array(
							'type'        => 'integer',
							'description' => esc_html__( 'Word count of the analyzed content.', 'ai' ),
						),
					),
				),
				'suggestions' => array(
					'type'        => 'array',
					'description' => esc_html__( 'Generated suggestions.', 'ai' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'        => array(
								'type'        => 'string',
								'description' => esc_html__( 'Unique suggestion identifier.', 'ai' ),
							),
							'type'      => array(
								'type'        => 'string',
								'description' => esc_html__( 'Suggestion category.', 'ai' ),
								'enum'        => self::SUGGESTION_TYPES,
							),
							'priority'  => array(
								'type'        => 'string',
								'description' => esc_html__( 'Priority level.', 'ai' ),
								'enum'        => self::PRIORITIES,
							),
							'summary'   => array(
								'type'        => 'string',
								'description' => esc_html__( 'One-line summary of the suggestion.', 'ai' ),
							),
							'details'   => array(
								'type'        => 'string',
								'description' => esc_html__( 'Expanded recommendation details.', 'ai' ),
							),
							'context'   => array(
								'type'        => 'string',
								'description' => esc_html__( 'Paragraph or block reference.', 'ai' ),
							),
							'action'    => array(
								'type'        => array( 'object', 'null' ),
								'description' => esc_html__( 'Optional structured action payload.', 'ai' ),
							),
							'timestamp' => array(
								'type'        => 'string',
								'description' => esc_html__( 'Generated timestamp.', 'ai' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $input Input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'post_id'        => null,
				'content'        => null,
				'requested_types' => array(),
				'session'       => array(),
				'trigger'       => 'manual',
				'word_delta'    => 0,
			)
		);

		$post_id   = $args['post_id'] ? absint( $args['post_id'] ) : null;
		$content   = '';
		$post_meta = array(
			'title'      => '',
			'excerpt'    => '',
			'post_type'  => '',
			'permalink'  => '',
			'taxonomies' => array(),
		);

		if ( $post_id ) {
			$post_context = get_post_context( $post_id );
			$content      = $post_context['content'] ?? '';
			$post_meta    = $post_context['meta'] ?? $post_meta;
		}

		if ( ! empty( $args['content'] ) ) {
			$content = normalize_content( (string) $args['content'] );
		}

		$content = trim( (string) $content );

		if ( '' === $content ) {
			return new WP_Error(
				'ai_writing_assistant_empty',
				esc_html__( 'Content is required to generate suggestions.', 'ai' )
			);
		}

		$types = $this->normalize_types( $args['requested_types'] );

		if ( empty( $types ) ) {
			$types = self::SUGGESTION_TYPES;
		}

		$session = $this->sanitize_session_context( (array) $args['session'] );

		$context = array(
			'content'         => $content,
			'word_count'      => $this->count_words( $content ),
			'requested_types' => $types,
			'session'         => $session,
			'trigger'         => sanitize_key( (string) $args['trigger'] ?: 'manual' ),
			'word_delta'      => (int) $args['word_delta'],
			'post_meta'       => $post_meta,
		);

		try {
			$response = AI_Client::prompt_with_wp_error( wp_json_encode( $context ) )
				->using_system_instruction( $this->get_system_instruction() )
				->using_model_preference( ...$this->get_model_preferences() )
				->using_candidate_count( 1 )
				->generate_texts();
		} catch ( \Throwable $error ) {
			error_log(
				sprintf(
					'[AI Writing Assistant] Generation failed: %s',
					$error->getMessage()
				)
			);
			$response = new WP_Error(
				'ai_writing_assistant_exception',
				$error->getMessage()
			);
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_payload = $response[0] ?? '';
		$decoded     = $this->decode_payload( $raw_payload );

		if ( null === $decoded ) {
			return new WP_Error(
				'ai_writing_assistant_unexpected_response',
				esc_html__( 'The AI service returned an unexpected response.', 'ai' )
			);
		}

		$normalized = $this->normalize_suggestions( $decoded['suggestions'] ?? array(), $types );

		if ( empty( $normalized ) ) {
			return new WP_Error(
				'ai_writing_assistant_empty_response',
				esc_html__( 'The AI service did not return any suggestions.', 'ai' )
			);
		}

		return $this->format_response( $decoded['session_id'] ?? $session['id'], $context['word_count'], $normalized );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed $args Input payload.
	 * @return bool|\WP_Error
	 */
	protected function permission_callback( $args ) {
		$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;

		if ( $post_id > 0 ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'ai_writing_assistant_cap',
					esc_html__( 'You do not have permission to analyze this post.', 'ai' )
				);
			}
		} elseif ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'ai_writing_assistant_cap',
				esc_html__( 'You do not have permission to request writing suggestions.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function meta(): array {
		return array(
			'category'     => 'editor',
			'show_in_rest' => true,
		);
	}

	/**
	 * Normalizes the requested suggestion types.
	 *
	 * @param mixed $raw Raw requested types.
	 * @return array<string>
	 */
	private function normalize_types( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$valid = array();

		foreach ( $raw as $type ) {
			$slug = sanitize_key( (string) $type );
			if ( in_array( $slug, self::SUGGESTION_TYPES, true ) ) {
				$valid[] = $slug;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Sanitizes the session context structure.
	 *
	 * @param array<string, mixed> $session Raw session data.
	 * @return array<string, mixed>
	 */
	private function sanitize_session_context( array $session ): array {
		return array(
			'id'                   => sanitize_text_field( (string) ( $session['id'] ?? '' ) ),
			'words_written'        => isset( $session['words_written'] ) ? absint( $session['words_written'] ) : 0,
			'suggestions_received' => isset( $session['suggestions_received'] ) ? absint( $session['suggestions_received'] ) : 0,
			'suggestions_applied'  => isset( $session['suggestions_applied'] ) ? absint( $session['suggestions_applied'] ) : 0,
			'ghost_accepts'        => isset( $session['ghost_accepts'] ) ? absint( $session['ghost_accepts'] ) : 0,
			'timer_duration'       => isset( $session['timer_duration'] ) ? absint( $session['timer_duration'] ) : 0,
			'timer_remaining'      => isset( $session['timer_remaining'] ) ? absint( $session['timer_remaining'] ) : 0,
		);
	}

	/**
	 * Counts the number of words in the provided string.
	 *
	 * @param string $content Content to analyze.
	 * @return int
	 */
	private function count_words( string $content ): int {
		$words = preg_split( '/\s+/u', wp_strip_all_tags( $content ) );
		if ( ! is_array( $words ) ) {
			return 0;
		}

		return count( array_filter( $words ) );
	}

	/**
	 * Attempts to decode a JSON payload.
	 *
	 * @param string $raw Raw model response.
	 * @return array<string, mixed>|null
	 */
	private function decode_payload( string $raw ): ?array {
		$clean = trim( $raw );

		if ( '' === $clean ) {
			return null;
		}

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
	 * Normalizes AI-provided suggestions to the schema.
	 *
	 * @param mixed    $payload Potential suggestions list.
	 * @param string[] $allowed_types Allowed suggestion types.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_suggestions( $payload, array $allowed_types ): array {
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$results = array();
		$timestamp = gmdate( 'c' );

		foreach ( $payload as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$type = isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '';
			if ( ! in_array( $type, $allowed_types, true ) ) {
				continue;
			}

			$priority = isset( $item['priority'] ) ? sanitize_key( (string) $item['priority'] ) : 'medium';
			if ( ! in_array( $priority, self::PRIORITIES, true ) ) {
				$priority = 'medium';
			}

			$results[] = array(
				'id'        => sanitize_text_field( (string) ( $item['id'] ?? uniqid( "{$type}_", true ) ) ),
				'type'      => $type,
				'priority'  => $priority,
				'summary'   => sanitize_text_field( (string) ( $item['summary'] ?? '' ) ),
				'details'   => sanitize_textarea_field( (string) ( $item['details'] ?? '' ) ),
				'context'   => sanitize_textarea_field( (string) ( $item['context'] ?? '' ) ),
				'action'    => isset( $item['action'] ) && is_array( $item['action'] ) ? $item['action'] : null,
				'timestamp' => sanitize_text_field( (string) ( $item['timestamp'] ?? $timestamp ) ),
			);
		}

		return $results;
	}

	/**
	 * Formats the final response payload.
	 *
	 * @param string $session_id Session identifier.
	 * @param int    $word_count Word count analyzed.
	 * @param array<int, array<string, mixed>> $suggestions Suggestions to return.
	 * @return array<string, mixed>
	 */
	private function format_response( string $session_id, int $word_count, array $suggestions ): array {
		return array(
			'session_id' => $session_id,
			'meta'       => array(
				'analyzed_at' => gmdate( 'c' ),
				'word_count'  => $word_count,
			),
			'suggestions' => $suggestions,
		);
	}
}

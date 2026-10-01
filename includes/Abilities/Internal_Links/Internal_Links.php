<?php
/**
 * Internal Links WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Internal_Links;

use WP_Error;
use WP_Query;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Internal_Links\Internal_Links as Internal_Links_Experiment;

use function WordPress\AI\generate_embeddings;
use function WordPress\AI\normalize_content;
use function WordPress\AI\supports_embedding_generation;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal Links WordPress Ability.
 *
 * Uses a two-phase approach to suggest internal links:
 *
 * 1. **Embedding retrieval** — Generates an embedding vector for the current
 *    post content and for each published post, then ranks them by cosine
 *    similarity to find the most semantically related candidates.
 *
 * 2. **LLM anchor selection** — Passes the top candidates to the AI and asks
 *    it to choose exact phrases from the post content as anchor text.
 *
 * @since x.x.x
 */
class Internal_Links extends Abstract_Ability {

	/**
	 * Number of semantically similar posts passed to the LLM as candidates.
	 *
	 * After cosine-similarity ranking, only the top N posts are sent to the
	 * LLM for anchor-text selection. Keeping this small makes the prompt
	 * focused and accurate.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const EMBEDDING_CANDIDATE_LIMIT = 10;

	/**
	 * Absolute cap on the number of suggestions that can be requested.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const MAX_SUGGESTIONS_CAP = 10;

	/**
	 * Default maximum number of suggestions to return.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_SUGGESTIONS = 5;

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_content'     => array(
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
					'description'       => esc_html__( 'The HTML content of the post being edited.', 'ai' ),
				),
				'post_id'          => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'ID of the post being edited.', 'ai' ),
				),
				'max_suggestions'  => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'Maximum number of link suggestions to return (1–10).', 'ai' ),
					'default'           => self::DEFAULT_MAX_SUGGESTIONS,
				),
				'excluded_anchors' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => esc_html__( 'Anchor texts already hyperlinked in the post that should not be suggested again.', 'ai' ),
					'default'     => array(),
				),
			),
			'required'   => array( 'post_content', 'post_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	protected function output_schema(): array {
		return array(
			'type'        => 'object',
			'description' => esc_html__( 'Internal link suggestions for the post.', 'ai' ),
			'properties'  => array(
				'suggestions' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'anchor_text' => array(
								'type'        => 'string',
								'description' => esc_html__( 'Exact phrase from the post content to use as anchor text.', 'ai' ),
							),
							'url'         => array(
								'type'        => 'string',
								'description' => esc_html__( 'Permalink of the target post or page.', 'ai' ),
							),
							'title'       => array(
								'type'        => 'string',
								'description' => esc_html__( 'Title of the target post or page.', 'ai' ),
							),
							'context'     => array(
								'type'        => 'string',
								'description' => esc_html__( 'The sentence or clause from the post that contains the anchor text.', 'ai' ),
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
	 * @since x.x.x
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return array{suggestions: list<array{anchor_text: string, url: string, title: string, context: string}>}|\WP_Error
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'post_content'     => '',
				'post_id'          => 0,
				'max_suggestions'  => self::DEFAULT_MAX_SUGGESTIONS,
				'excluded_anchors' => array(),
			)
		);

		$post_content     = wp_kses_post( (string) $args['post_content'] );
		$post_id          = absint( $args['post_id'] );
		$max_suggestions  = min( absint( $args['max_suggestions'] ), self::MAX_SUGGESTIONS_CAP );
		$excluded_anchors = is_array( $args['excluded_anchors'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', $args['excluded_anchors'] ) ) )
			: array();

		if ( empty( $post_content ) ) {
			return new WP_Error(
				'post_content_required',
				esc_html__( 'Post content is required to suggest internal links.', 'ai' )
			);
		}

		if ( $max_suggestions < 1 ) {
			$max_suggestions = self::DEFAULT_MAX_SUGGESTIONS;
		}

		// Embedding generation must be available before we proceed.
		if ( ! supports_embedding_generation() ) {
			return new WP_Error(
				'embeddings_unsupported',
				esc_html__( 'Internal link suggestions require embedding generation support, which is not available with the current AI provider configuration.', 'ai' )
			);
		}

		// Convert HTML to plain text for embedding and anchor text matching.
		$plain_text = normalize_content( wp_strip_all_tags( $post_content ) );

		if ( empty( trim( $plain_text ) ) ) {
			return array( 'suggestions' => array() );
		}

		// Generate an embedding for the current post content.
		$content_embedding = $this->get_embedding_for_text( $plain_text );

		if ( is_wp_error( $content_embedding ) ) {
			return $content_embedding;
		}

		// Find the most semantically similar published posts using cosine similarity.
		$candidates = $this->find_similar_posts( $content_embedding, $post_id );

		if ( empty( $candidates ) ) {
			return array( 'suggestions' => array() );
		}

		$prompt         = $this->create_prompt( $plain_text, $candidates, $max_suggestions, $excluded_anchors );
		$prompt         = $this->filter_prompt( $prompt, $plain_text, $max_suggestions );
		$prompt_builder = $this->get_prompt_builder( $prompt );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$raw = $prompt_builder->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		if ( empty( $raw ) ) {
			return array( 'suggestions' => array() );
		}

		$suggestions = $this->parse_and_validate_response( (string) $raw, $plain_text, $candidates, $max_suggestions, $excluded_anchors );

		return array( 'suggestions' => $suggestions );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return bool|\WP_Error True if the user has permission, WP_Error otherwise.
	 */
	protected function permission_callback( $input ) {
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		if ( ! $post_id ) {
			if ( ! current_user_can( 'edit_posts' ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to use AI internal link suggestions.', 'ai' )
				);
			}

			return true;
		}

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
				esc_html__( 'You do not have permission to run AI internal link suggestions on this post.', 'ai' )
			);
		}

		$post_type     = get_post_type( $post_id );
		$post_type_obj = $post_type ? get_post_type_object( $post_type ) : null;

		return $post_type_obj && ! empty( $post_type_obj->show_in_rest );
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
	 * Generates an embedding vector for a plain-text string.
	 *
	 * @since x.x.x
	 *
	 * @param string $text The text to embed.
	 * @return list<float>|\WP_Error The embedding vector, or WP_Error on failure.
	 */
	public function get_embedding_for_text( string $text ) {
		$result = generate_embeddings( $text );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result->getEmbedding()->getValues();
	}

	/**
	 * Computes the cosine similarity between two equal-length vectors.
	 *
	 * Returns a value in [−1, 1]. Returns 0.0 when either vector has zero
	 * magnitude (degenerate case).
	 *
	 * @since x.x.x
	 *
	 * @param list<float> $a First vector.
	 * @param list<float> $b Second vector.
	 * @return float Cosine similarity score.
	 */
	public function cosine_similarity( array $a, array $b ): float {
		$dot    = 0.0;
		$mag_a  = 0.0;
		$mag_b  = 0.0;
		$length = count( $a );

		for ( $i = 0; $i < $length; $i++ ) {
			$ai     = (float) ( $a[ $i ] ?? 0.0 );
			$bi     = (float) ( $b[ $i ] ?? 0.0 );
			$dot   += $ai * $bi;
			$mag_a += $ai * $ai;
			$mag_b += $bi * $bi;
		}

		$denom = sqrt( $mag_a ) * sqrt( $mag_b );

		if ( $denom < 1.0e-10 ) {
			return 0.0;
		}

		return $dot / $denom;
	}

	/**
	 * Finds the most semantically similar published posts to the given embedding.
	 *
	 * Fetches all published post IDs (excluding the current post), generates an
	 * embedding for each one, ranks them by cosine similarity, and returns the
	 * top EMBEDDING_CANDIDATE_LIMIT entries with url and title.
	 *
	 * @since x.x.x
	 *
	 * @param list<float> $content_embedding Embedding of the post being edited.
	 * @param int         $exclude_post_id   ID of the post being edited (excluded from candidates).
	 * @return list<array{url: string, title: string}> Top candidates ordered by similarity.
	 */
	public function find_similar_posts( array $content_embedding, int $exclude_post_id ): array {
		$query = new WP_Query(
			array(
				'post_type'              => array( 'post', 'page' ),
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'post__not_in'           => $exclude_post_id ? array( $exclude_post_id ) : array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_post__not_in, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$scored = array();

		foreach ( $query->posts as $post ) {
			// Full post objects are already in the cache — no extra DB round-trips.
			$title = $post->post_title;
			$url   = get_permalink( $post );

			if ( false === $url || '' === $title || '' === $url ) {
				continue;
			}

			// Build the plain-text representation of this post for embedding.
			$post_plain_text = normalize_content(
				wp_strip_all_tags(
					(string) apply_filters( 'the_content', $post->post_content ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				)
			);

			if ( empty( $post_plain_text ) ) {
				// Fall back to the title when the post has no content to embed.
				$post_plain_text = sanitize_text_field( $title );
			}

			$embedding = $this->get_embedding_for_text( $post_plain_text );

			if ( is_wp_error( $embedding ) ) {
				// Skip posts whose embeddings fail; do not abort the whole request.
				continue;
			}

			$scored[] = array(
				'score' => $this->cosine_similarity( $content_embedding, $embedding ),
				'url'   => esc_url_raw( $url ),
				'title' => sanitize_text_field( $title ),
			);
		}

		if ( empty( $scored ) ) {
			return array();
		}

		// Sort descending by similarity score.
		usort(
			$scored,
			static function ( array $a, array $b ): int {
				return $b['score'] <=> $a['score'];
			}
		);

		// Return only the top candidates, without the internal score field.
		$top = array_slice( $scored, 0, self::EMBEDDING_CANDIDATE_LIMIT );

		return array_map(
			static function ( array $entry ): array {
				return array(
					'url'   => $entry['url'],
					'title' => $entry['title'],
				);
			},
			$top
		);
	}

	/**
	 * Returns the JSON schema used for structured output generation.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> JSON schema for an array of suggestions.
	 */
	private function suggestions_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'suggestions' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'anchor_text' => array( 'type' => 'string' ),
							'url'         => array( 'type' => 'string' ),
							'title'       => array( 'type' => 'string' ),
							'context'     => array( 'type' => 'string' ),
						),
						'required'             => array( 'anchor_text', 'url', 'title', 'context' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'suggestions' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Builds the prompt string to send to the AI.
	 *
	 * @since x.x.x
	 *
	 * @param string                                   $plain_text       Plain-text post content.
	 * @param list<array{url: string, title: string}>  $candidates       Semantically similar posts (pre-ranked).
	 * @param int                                      $max_suggestions  Maximum number of suggestions.
	 * @param list<string>                             $excluded_anchors Anchor texts already hyperlinked in the post.
	 * @return string The assembled prompt.
	 */
	private function create_prompt( string $plain_text, array $candidates, int $max_suggestions, array $excluded_anchors = array() ): string {
		$index_lines = array();
		foreach ( $candidates as $entry ) {
			$index_lines[] = sprintf( '- %s <%s>', $entry['title'], $entry['url'] );
		}

		$parts   = array();
		$parts[] = '<post-content>' . $plain_text . '</post-content>';
		$parts[] = '<candidates>' . implode( "\n", $index_lines ) . '</candidates>';
		$parts[] = '<max-suggestions>' . $max_suggestions . '</max-suggestions>';

		if ( ! empty( $excluded_anchors ) ) {
			$anchor_lines = array();
			foreach ( $excluded_anchors as $anchor ) {
				$anchor_lines[] = '- ' . $anchor;
			}
			$parts[] = '<already-linked>' . implode( "\n", $anchor_lines ) . '</already-linked>';
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Gets a configured prompt builder for the internal links suggestion.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt The assembled prompt.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or WP_Error on failure.
	 */
	private function get_prompt_builder( string $prompt ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction() )
			->as_json_response( $this->suggestions_schema() );

		$prompt_builder = $this->filter_prompt_builder( $prompt_builder, Internal_Links_Experiment::class, array(), $prompt );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Internal link suggestions could not be generated. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}

	/**
	 * Parses the raw AI JSON response and validates each suggestion.
	 *
	 * Validation rules:
	 * - anchor_text must exist verbatim in the plain-text content.
	 * - url must be present in the candidates list.
	 * - No duplicate anchor texts or URLs.
	 * - anchor_text must not appear in the excluded_anchors list.
	 * - Capped at max_suggestions.
	 *
	 * @since x.x.x
	 *
	 * @param string                                   $raw              Raw JSON string from the AI.
	 * @param string                                   $plain_text       Plain-text post content.
	 * @param list<array{url: string, title: string}>  $candidates       Semantically similar posts passed to the LLM.
	 * @param int                                      $max_suggestions  Maximum number of suggestions.
	 * @param list<string>                             $excluded_anchors Anchor texts already hyperlinked in the post.
	 * @return list<array{anchor_text: string, url: string, title: string, context: string}>
	 */
	private function parse_and_validate_response( string $raw, string $plain_text, array $candidates, int $max_suggestions, array $excluded_anchors = array() ): array {
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['suggestions'] ) || ! is_array( $decoded['suggestions'] ) ) {
			return array();
		}

		// Build a fast URL lookup set from the candidates list.
		$valid_urls = array();
		foreach ( $candidates as $entry ) {
			$valid_urls[ $entry['url'] ] = true;
		}

		// Build a fast case-insensitive lookup set from the excluded anchors list.
		$excluded_anchors_lower = array();
		foreach ( $excluded_anchors as $excluded ) {
			$excluded_anchors_lower[ mb_strtolower( $excluded ) ] = true;
		}

		$suggestions  = array();
		$seen_anchors = array();
		$seen_urls    = array();

		foreach ( $decoded['suggestions'] as $item ) {
			if ( count( $suggestions ) >= $max_suggestions ) {
				break;
			}

			if (
				! is_array( $item ) ||
				! is_string( $item['anchor_text'] ?? null ) ||
				! is_string( $item['url'] ?? null ) ||
				! is_string( $item['title'] ?? null ) ||
				'' === trim( $item['anchor_text'] ) ||
				'' === trim( $item['url'] ) ||
				'' === trim( $item['title'] )
			) {
				continue;
			}

			$anchor_text = sanitize_text_field( $item['anchor_text'] );
			$url         = esc_url_raw( $item['url'] );
			$title       = sanitize_text_field( $item['title'] );
			$context     = sanitize_text_field( $item['context'] ?? '' );

			// Anchor text must exist verbatim in the post content.
			if ( ! str_contains( $plain_text, $anchor_text ) ) {
				continue;
			}

			// URL must come from the candidates list.
			if ( ! isset( $valid_urls[ $url ] ) ) {
				continue;
			}

			// Anchor text must not be in the already-linked exclusion list.
			if ( isset( $excluded_anchors_lower[ mb_strtolower( $anchor_text ) ] ) ) {
				continue;
			}

			// No duplicate anchor texts.
			if ( isset( $seen_anchors[ $anchor_text ] ) ) {
				continue;
			}

			// No duplicate URLs.
			if ( isset( $seen_urls[ $url ] ) ) {
				continue;
			}

			$seen_anchors[ $anchor_text ] = true;
			$seen_urls[ $url ]            = true;

			$suggestions[] = array(
				'anchor_text' => $anchor_text,
				'url'         => $url,
				'title'       => $title,
				'context'     => $context,
			);
		}

		return $suggestions;
	}
}

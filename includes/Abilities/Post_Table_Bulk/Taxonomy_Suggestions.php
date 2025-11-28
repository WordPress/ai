<?php
/**
 * Taxonomy suggestion WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Post_Table_Bulk;

use WP_Error;
use WP_Post;
use WP_Taxonomy;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI_Client\AI_Client;

use function WordPress\AI\get_post_context;
/**
 * Suggests taxonomy terms for a collection of posts.
 *
 * @since 0.1.0
 */
class Taxonomy_Suggestions extends Abstract_Ability {
	/**
	 * Maximum number of posts that can be processed in a single request.
	 *
	 * @since 0.1.0
	 */
	private const MAX_POSTS = 200;

	/**
	 * Default number of suggestions per taxonomy.
	 *
	 * @since 0.1.0
	 */
	private const DEFAULT_LIMIT = 5;

	/**
	 * Maximum number of suggestions per taxonomy.
	 *
	 * @since 0.1.0
	 */
	private const MAX_LIMIT = 10;

	/**
	 * Returns the input schema of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_ids'   => array(
					'type'        => 'array',
					'description' => esc_html__( 'The post IDs to analyze.', 'ai' ),
					'items'       => array(
						'type' => 'integer',
					),
					'minItems'    => 1,
				),
				'taxonomies' => array(
					'type'        => 'array',
					'description' => esc_html__( 'Taxonomies to classify. Defaults to categories and tags.', 'ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'limit'      => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_LIMIT,
					'default' => self::DEFAULT_LIMIT,
				),
				'locale'     => array(
					'type'        => 'string',
					'description' => esc_html__( 'Locale hint for the AI provider.', 'ai' ),
				),
			),
			'required'   => array( 'post_ids' ),
		);
	}

	/**
	 * Returns the output schema of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'suggestions'  => array(
					'type'                 => 'object',
					'description'          => esc_html__( 'Suggested taxonomy terms per post.', 'ai' ),
					'additionalProperties' => array(
						'type'                 => 'object',
						'additionalProperties' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'term_id'    => array(
										'type' => array( 'integer', 'null' ),
									),
									'name'       => array(
										'type' => 'string',
									),
									'confidence' => array(
										'type' => 'number',
									),
									'is_new'     => array(
										'type' => 'boolean',
									),
								),
							),
						),
					),
				),
				'metadata'     => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomies' => array(
							'type'                 => 'object',
							'description'          => esc_html__( 'Taxonomy metadata used for rendering.', 'ai' ),
							'additionalProperties' => array(
								'type'       => 'object',
								'properties' => array(
									'label' => array(
										'type' => 'string',
									),
								),
							),
						),
					),
				),
				'generated_at' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}

	/**
	 * Executes the ability with the given input arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Input arguments.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'post_ids'   => array(),
				'taxonomies' => array( 'category', 'post_tag' ),
				'limit'      => self::DEFAULT_LIMIT,
				'locale'     => get_user_locale(),
			)
		);

		$post_ids = $this->sanitize_post_ids( $args['post_ids'] );

		if ( empty( $post_ids ) ) {
			return new WP_Error(
				'invalid_post_ids',
				esc_html__( 'At least one valid post ID is required.', 'ai' )
			);
		}

		$taxonomies = $this->sanitize_taxonomies( (array) $args['taxonomies'] );

		if ( empty( $taxonomies ) ) {
			return new WP_Error(
				'invalid_taxonomies',
				esc_html__( 'No valid taxonomies were provided.', 'ai' )
			);
		}

		$limit = (int) max( 1, min( self::MAX_LIMIT, $args['limit'] ) );

		$posts = $this->collect_posts( $post_ids );

		if ( empty( $posts ) ) {
			return new WP_Error(
				'posts_not_found',
				esc_html__( 'None of the requested posts could be loaded.', 'ai' )
			);
		}

		$catalog = $this->build_taxonomy_catalog( $taxonomies );

		if ( empty( $catalog ) ) {
			return new WP_Error(
				'terms_not_found',
				esc_html__( 'No taxonomy terms are available for suggestion.', 'ai' )
			);
		}

		$prompt_payload = array(
			'limit'      => $limit,
			'locale'     => sanitize_text_field( (string) $args['locale'] ),
			'taxonomies' => $this->catalog_to_prompt_payload( $catalog ),
			'posts'      => $this->posts_to_prompt_payload( $posts ),
		);

		$raw_response = $this->request_suggestions( wp_json_encode( $prompt_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

		if ( is_wp_error( $raw_response ) ) {
			return $raw_response;
		}

		$parsed = $this->parse_response( $raw_response );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$normalized = $this->normalize_suggestions( $parsed, $catalog, $limit );

		return array(
			'suggestions'  => $normalized,
			'metadata'     => array(
				'taxonomies' => wp_list_pluck( $catalog, 'label', 'name' ),
			),
			'generated_at' => current_time( 'mysql', true ),
		);
	}

	/**
	 * Permission callback.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $args Raw arguments.
	 * @return bool|WP_Error
	 */
	protected function permission_callback( $args ) {
		$post_ids = $this->sanitize_post_ids( $args['post_ids'] ?? array() );

		if ( empty( $post_ids ) ) {
			return current_user_can( 'edit_posts' )
				? true
				: new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to suggest taxonomy terms.', 'ai' )
				);
		}

		foreach ( $post_ids as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to edit one or more selected posts.', 'ai' )
				);
			}
		}

		return true;
	}

	/**
	 * Ability metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
			'mcp'          => array(
				'public'   => true,
				'type'     => 'tool',
				'category' => 'bulk-edit',
			),
		);
	}

	/**
	 * Sanitize and limit the list of post IDs.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $post_ids Raw post IDs.
	 * @return array<int>
	 */
	private function sanitize_post_ids( $post_ids ): array {
		$ids = array();

		foreach ( (array) $post_ids as $maybe_id ) {
			$maybe_id = absint( $maybe_id );

			if ( $maybe_id > 0 ) {
				$ids[] = $maybe_id;
			}
		}

		return array_slice( array_values( array_unique( $ids ) ), 0, self::MAX_POSTS );
	}

	/**
	 * Filter, sanitize, and validate taxonomy slugs.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string> $taxonomies Raw taxonomy slugs.
	 * @return array<int, string>
	 */
	private function sanitize_taxonomies( array $taxonomies ): array {
		$valid = array();

		foreach ( $taxonomies as $taxonomy ) {
			$taxonomy = sanitize_key( $taxonomy );

			if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$tax_obj = get_taxonomy( $taxonomy );

			if ( ! $tax_obj instanceof WP_Taxonomy ) {
				continue;
			}

			// Ensure the user can assign terms in this taxonomy.
			$assign_cap = $tax_obj->cap->assign_terms ?? 'assign_' . $taxonomy;

			if ( $assign_cap && ! current_user_can( $assign_cap ) ) {
				continue;
			}

			$valid[] = $taxonomy;
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Load WP_Post objects for provided IDs.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int> $post_ids Post IDs.
	 * @return array<int, WP_Post>
	 */
	private function collect_posts( array $post_ids ): array {
		$posts = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( $post instanceof WP_Post ) {
				$posts[ $post_id ] = $post;
			}
		}

		return $posts;
	}

	/**
	 * Builds taxonomy catalog data containing term metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string> $taxonomies Taxonomy slugs.
	 * @return array<string, array<string, mixed>>
	 */
	private function build_taxonomy_catalog( array $taxonomies ): array {
		$catalog = array();

		foreach ( $taxonomies as $taxonomy ) {
			$taxonomy_object = get_taxonomy( $taxonomy );

			if ( ! $taxonomy_object instanceof WP_Taxonomy ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 200,
				)
			);

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$lookup = array();
			$list   = array();

			foreach ( $terms as $term ) {
				$normalized             = $this->normalize_term_key( $term->name );
				$lookup[ $normalized ]  = array(
					'term_id' => (int) $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
				);
				$list[]                 = array(
					'term_id' => (int) $term->term_id,
					'name'    => $term->name,
					'slug'    => $term->slug,
				);
			}

			$catalog[ $taxonomy ] = array(
				'name'   => $taxonomy,
				'label'  => $taxonomy_object->labels->name ?? $taxonomy,
				'terms'  => $list,
				'lookup' => $lookup,
			);
		}

		return $catalog;
	}

	/**
	 * Convert taxonomy catalog into compact payload for prompts.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, mixed>> $catalog Catalog.
	 * @return array<int, array<string, mixed>>
	 */
	private function catalog_to_prompt_payload( array $catalog ): array {
		$payload = array();

		foreach ( $catalog as $taxonomy => $data ) {
			$payload[] = array(
				'taxonomy' => $taxonomy,
				'label'    => $data['label'],
				'terms'    => wp_list_pluck( $data['terms'], 'name' ),
			);
		}

		return $payload;
	}

	/**
	 * Convert post objects into condensed context for prompts.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, WP_Post> $posts Posts.
	 * @return array<int, array<string, mixed>>
	 */
	private function posts_to_prompt_payload( array $posts ): array {
		$payload = array();

		foreach ( $posts as $post_id => $post ) {
			$context = get_post_context( $post_id );

			$content = $context['content'] ?? wp_strip_all_tags( (string) $post->post_content );
			$content = wp_trim_words( $content, 220 );

			$payload[] = array(
				'post_id'        => $post_id,
				'title'          => get_the_title( $post ),
				'status'         => $post->post_status,
				'excerpt'        => wp_trim_words( $post->post_excerpt ?: $content, 55 ),
				'current_terms'  => $this->group_terms_for_prompt( $post_id ),
				'canonical_text' => $content,
			);
		}

		return $payload;
	}

	/**
	 * Group existing terms for a given post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, array<int, string>>
	 */
	private function group_terms_for_prompt( int $post_id ): array {
		$result = array();
		$taxes  = get_object_taxonomies( get_post_type( $post_id ) ?: 'post', 'objects' );

		foreach ( $taxes as $taxonomy => $tax_obj ) {
			$terms = get_the_terms( $post_id, $taxonomy );

			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			$result[ $taxonomy ] = array_map(
				static function ( $term ) {
					return is_object( $term ) ? $term->name : (string) $term;
				},
				$terms
			);
		}

		return $result;
	}

	/**
	 * Perform the AI request.
	 *
	 * @since 0.1.0
	 *
	 * @param string $payload JSON payload string.
	 * @return string|WP_Error
	 */
	private function request_suggestions( string $payload ) {
		$response = AI_Client::prompt_with_wp_error( $payload )
			->using_system_instruction( $this->get_system_instruction() )
			->using_model_preference( ...$this->get_model_preferences() )
			->generate_text();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = is_string( $response ) ? $response : '';

		if ( '' === trim( $text ) ) {
			return new WP_Error(
				'ai_empty_response',
				esc_html__( 'The AI provider returned an empty response.', 'ai' )
			);
		}

		return trim( $text );
	}

	/**
	 * Parse the AI JSON payload.
	 *
	 * @since 0.1.0
	 *
	 * @param string $raw JSON string.
	 * @return array<string, array<string, array<int, array<string, mixed>>>>|WP_Error
	 */
	private function parse_response( string $raw ) {
		$decoded = json_decode( $raw, true );

		if ( null === $decoded ) {
			$start = strpos( $raw, '{' );
			$end   = strrpos( $raw, '}' );

			if ( false !== $start && false !== $end && $end > $start ) {
				$maybe_json = substr( $raw, $start, $end - $start + 1 );
				$decoded    = json_decode( $maybe_json, true );
			}
		}

		if ( ! is_array( $decoded ) || empty( $decoded['suggestions'] ) ) {
			return new WP_Error(
				'ai_invalid_response',
				esc_html__( 'Unable to parse taxonomy suggestions from the AI response.', 'ai' )
			);
		}

		return $decoded['suggestions'];
	}

	/**
	 * Normalize suggestions and map to known term IDs.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, array<int, array<string, mixed>>>> $parsed Parsed suggestions keyed by post ID.
	 * @param array<string, array<string, mixed>>                            $catalog Taxonomy catalog.
	 * @param int                                                            $limit Maximum items per taxonomy.
	 * @return array<string, array<string, array<int, array<string, mixed>>>>
	 */
	private function normalize_suggestions( array $parsed, array $catalog, int $limit ): array {
		$normalized = array();

		foreach ( $parsed as $post_id => $taxonomies ) {
			$post_key = (string) absint( $post_id );
			$normalized[ $post_key ] = array();

			if ( ! is_array( $taxonomies ) ) {
				continue;
			}

			foreach ( $taxonomies as $taxonomy => $suggestions ) {
				if ( empty( $catalog[ $taxonomy ] ) || ! is_array( $suggestions ) ) {
					continue;
				}

				$normalized[ $post_key ][ $taxonomy ] = array();

				foreach ( array_slice( $suggestions, 0, $limit ) as $suggestion ) {
					$name = $this->extract_term_name( $suggestion );

					if ( ! $name ) {
						continue;
					}

					$match = $this->match_term( $catalog[ $taxonomy ], $name );

					$normalized[ $post_key ][ $taxonomy ][] = array(
						'term_id'    => $match['term_id'],
						'name'       => $match['name'],
						'confidence' => $this->extract_confidence( $suggestion ),
						'is_new'     => null === $match['term_id'],
					);
				}
			}
		}

		return $normalized;
	}

	/**
	 * Extract term name from suggestion.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $suggestion Suggestion payload.
	 * @return string
	 */
	private function extract_term_name( $suggestion ): string {
		if ( is_array( $suggestion ) ) {
			if ( isset( $suggestion['term'] ) ) {
				return sanitize_text_field( (string) $suggestion['term'] );
			}

			if ( isset( $suggestion['name'] ) ) {
				return sanitize_text_field( (string) $suggestion['name'] );
			}
		}

		if ( is_string( $suggestion ) ) {
			return sanitize_text_field( $suggestion );
		}

		return '';
	}

	/**
	 * Extract confidence score from suggestion payload.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $suggestion Suggestion payload.
	 * @return float
	 */
	private function extract_confidence( $suggestion ): float {
		if ( is_array( $suggestion ) && isset( $suggestion['confidence'] ) ) {
			$value = (float) $suggestion['confidence'];

			return min( 1, max( 0, $value ) );
		}

		return 0.5;
	}

	/**
	 * Attempt to match a suggestion to a known term.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $catalog_entry Taxonomy catalog entry.
	 * @param string               $suggested_name Suggested name.
	 * @return array{term_id: int|null, name: string}
	 */
	private function match_term( array $catalog_entry, string $suggested_name ): array {
		$key = $this->normalize_term_key( $suggested_name );

		if ( isset( $catalog_entry['lookup'][ $key ] ) ) {
			return array(
				'term_id' => (int) $catalog_entry['lookup'][ $key ]['term_id'],
				'name'    => $catalog_entry['lookup'][ $key ]['name'],
			);
		}

		return array(
			'term_id' => null,
			'name'    => $suggested_name,
		);
	}

	/**
	 * Normalize name for catalog lookups.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Term name.
	 * @return string
	 */
	private function normalize_term_key( string $name ): string {
		return strtolower( sanitize_title( $name ) );
	}
}

<?php
/**
 * Semantic related posts for Query Loop blocks.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

use WP_Block;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Overrides the Related Posts Query Loop variation with semantic RAG results.
 *
 * @since 1.1.0
 */
class Related_Posts {
	/**
	 * Query Loop variation namespace.
	 */
	public const QUERY_NAMESPACE = 'ai/related-posts';

	/**
	 * Default number of related posts to show.
	 */
	private const DEFAULT_POST_COUNT = 3;

	/**
	 * Maximum number of related posts to show.
	 */
	private const DEFAULT_MAX_POST_COUNT = 6;

	/**
	 * Default minimum results required before output is shown.
	 */
	private const DEFAULT_MIN_RESULTS = 3;

	/**
	 * Default search candidate limit.
	 */
	private const DEFAULT_CANDIDATE_LIMIT = 12;

	/**
	 * Default cosine distance threshold for related matches.
	 */
	private const DEFAULT_DISTANCE_THRESHOLD = 0.6;

	/**
	 * Default related posts cache lifetime, matching Jetpack's public cache cadence.
	 */
	private const DEFAULT_CACHE_TTL = 43200;

	/**
	 * Search service.
	 *
	 * @var \WordPress\AI\RAG\Search_Service
	 */
	private Search_Service $search_service;

	/**
	 * Related post IDs for the Related Posts Query Loop currently being rendered.
	 *
	 * @var list<list<int>>
	 */
	private array $active_query_post_ids = array();

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AI\RAG\Search_Service|null $search_service Search service.
	 */
	public function __construct( ?Search_Service $search_service = null ) {
		$this->search_service = $search_service ?? new Search_Service();
	}

	/**
	 * Registers front-end rendering hooks.
	 *
	 * @since 1.1.0
	 */
	public function init(): void {
		add_filter( 'pre_render_block', array( $this, 'prepare_related_query' ), 10, 3 );
		add_filter( 'query_loop_block_query_vars', array( $this, 'filter_query_vars' ), 10, 3 );
		add_filter( 'render_block', array( $this, 'reset_related_query' ), 10, 3 );
	}

	/**
	 * Prepares semantic IDs for a Related Posts Query Loop before it renders.
	 *
	 * Returning an empty string here suppresses the entire Query Loop variation when
	 * there are not enough good related posts.
	 *
	 * @since 1.1.0
	 *
	 * @param string|null     $pre_render   Pre-rendered block content.
	 * @param array<string,mixed> $parsed_block Parsed block.
	 * @param \WP_Block|null  $parent_block Parent block.
	 * @return string|null Pre-rendered block content, or null to continue rendering.
	 */
	public function prepare_related_query( ?string $pre_render, array $parsed_block, ?WP_Block $parent_block ) {
		unset( $parent_block );

		if ( null !== $pre_render || ! $this->is_related_posts_query_block( $parsed_block ) ) {
			return $pre_render;
		}

		$source_post = $this->get_source_post();
		if ( ! $source_post instanceof WP_Post ) {
			return '';
		}

		$count    = $this->get_count_from_query_block( $parsed_block, $source_post );
		$post_ids = $this->get_related_post_ids(
			$source_post,
			array(
				'count'       => $count,
				'min_results' => min( self::DEFAULT_MIN_RESULTS, $count ),
				'post_type'   => $this->get_post_type_from_query_block( $parsed_block, $source_post ),
			)
		);

		if ( empty( $post_ids ) ) {
			return '';
		}

		$this->active_query_post_ids[] = $post_ids;

		return null;
	}

	/**
	 * Overrides the active Related Posts Query Loop with semantic related post IDs.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,mixed> $query Query vars.
	 * @param \WP_Block          $block Query Loop child block.
	 * @param int                $page  Current page.
	 * @return array<string,mixed> Query vars.
	 */
	public function filter_query_vars( array $query, WP_Block $block, int $page ): array {
		unset( $block, $page );

		$post_ids = end( $this->active_query_post_ids );
		if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
			return $query;
		}

		$query['post__in']            = array_values( array_map( 'absint', $post_ids ) );
		$query['orderby']             = 'post__in';
		$query['posts_per_page']      = count( $post_ids );
		$query['ignore_sticky_posts'] = true;
		$query['no_found_rows']       = true;
		unset( $query['post__not_in'] );

		return $query;
	}

	/**
	 * Clears active semantic IDs after the Related Posts Query Loop renders.
	 *
	 * @since 1.1.0
	 *
	 * @param string              $block_content Rendered block content.
	 * @param array<string,mixed> $parsed_block  Parsed block.
	 * @param \WP_Block           $block         Block instance.
	 * @return string Rendered block content.
	 */
	public function reset_related_query( string $block_content, array $parsed_block, WP_Block $block ): string {
		unset( $block );

		if ( $this->is_related_posts_query_block( $parsed_block ) ) {
			array_pop( $this->active_query_post_ids );
		}

		return $block_content;
	}

	/**
	 * Returns semantic related post IDs for a post.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post             $post Source post.
	 * @param array<string, mixed> $args Related posts args.
	 * @return list<int> Related post IDs.
	 */
	public function get_related_post_ids( WP_Post $post, array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'count'           => self::DEFAULT_POST_COUNT,
				'min_results'     => self::DEFAULT_MIN_RESULTS,
				'candidate_limit' => self::DEFAULT_CANDIDATE_LIMIT,
				'post_type'       => (string) $post->post_type,
				'post_status'     => 'publish',
			)
		);

		$count           = $this->get_post_count( (int) $args['count'], $post );
		$min_results     = $this->get_min_results( (int) $args['min_results'], $count, $post );
		$candidate_limit = $this->get_candidate_limit( (int) $args['candidate_limit'], $count, $post );
		$threshold       = $this->get_distance_threshold( $post );
		$cache_key       = $this->get_cache_key( $post, $count, $min_results, $candidate_limit, $threshold, $args );
		$post_ids        = $this->get_cached_related_post_ids( $cache_key, $args );

		if ( null === $post_ids ) {
			$post_ids = $this->search_related_post_ids( $post, $count, $candidate_limit, $threshold, $args );
			$this->set_cached_related_post_ids( $cache_key, $post_ids, $post );
		}

		/**
		 * Filters semantic related post IDs before they are applied to the Query Loop.
		 *
		 * @since 1.1.0
		 *
		 * @param list<int>            $post_ids Related post IDs.
		 * @param \WP_Post            $post     Source post.
		 * @param array<string,mixed> $args     Related posts args.
		 */
		$post_ids = (array) apply_filters( 'wpai_rag_related_posts_results', $post_ids, $post, $args );
		$post_ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );
		$post_ids = array_values( array_diff( array_unique( $post_ids ), array( (int) $post->ID ) ) );

		if ( count( $post_ids ) < $min_results ) {
			return array();
		}

		return array_slice( $post_ids, 0, $count );
	}

	/**
	 * Searches semantic related post IDs.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post             $post            Source post.
	 * @param int                  $count           Number of posts to return.
	 * @param int                  $candidate_limit Search candidate limit.
	 * @param float                $threshold       Distance threshold.
	 * @param array<string, mixed> $args            Related posts args.
	 * @return list<int> Related post IDs.
	 */
	private function search_related_post_ids( WP_Post $post, int $count, int $candidate_limit, float $threshold, array $args ): array {
		$results = $this->search_service->search(
			$this->prepare_query_text( $post ),
			array(
				'per_page'                => $candidate_limit,
				'max_per_page'            => $candidate_limit,
				'candidate_limit'         => $candidate_limit,
				'post_type'               => $args['post_type'],
				'post_status'             => $args['post_status'],
				'enforce_read_permission' => false,
			)
		);

		if ( is_wp_error( $results ) || empty( $results['results'] ) || ! is_array( $results['results'] ) ) {
			return array();
		}

		$post_ids = array();
		$seen_ids = array( (int) $post->ID => true );
		foreach ( $results['results'] as $result ) {
			if ( ! is_array( $result ) || empty( $result['post_id'] ) ) {
				continue;
			}

			$post_id = absint( $result['post_id'] );
			if ( $post_id <= 0 || isset( $seen_ids[ $post_id ] ) ) {
				continue;
			}

			$seen_ids[ $post_id ] = true;

			if ( isset( $result['distance'] ) && (float) $result['distance'] > $threshold ) {
				continue;
			}

			$related_post = get_post( $post_id );
			if ( ! $related_post instanceof WP_Post || ! $this->post_matches_args( $related_post, $args ) ) {
				continue;
			}

			$post_ids[] = $post_id;
			if ( count( $post_ids ) >= $count ) {
				break;
			}
		}

		return $post_ids;
	}

	/**
	 * Checks whether a parsed block is the Related Posts Query Loop variation.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,mixed> $parsed_block Parsed block.
	 * @return bool True when this is the related posts variation.
	 */
	private function is_related_posts_query_block( array $parsed_block ): bool {
		if ( 'core/query' !== ( $parsed_block['blockName'] ?? '' ) ) {
			return false;
		}

		$attrs = $parsed_block['attrs'] ?? array();
		if ( ! is_array( $attrs ) ) {
			return false;
		}

		return self::QUERY_NAMESPACE === ( $attrs['namespace'] ?? '' );
	}

	/**
	 * Returns the source post for the current block render.
	 *
	 * @since 1.1.0
	 *
	 * @return \WP_Post|null Source post.
	 */
	private function get_source_post(): ?WP_Post {
		$post = get_post();
		if ( ! $post instanceof WP_Post || 'publish' !== get_post_status( $post ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Returns related post count from a Query Loop block.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,mixed> $parsed_block Parsed block.
	 * @param \WP_Post           $post         Source post.
	 * @return int Count.
	 */
	private function get_count_from_query_block( array $parsed_block, WP_Post $post ): int {
		$query = $this->get_query_attrs( $parsed_block );
		$count = isset( $query['perPage'] ) ? absint( $query['perPage'] ) : self::DEFAULT_POST_COUNT;

		return $this->get_post_count( $count, $post );
	}

	/**
	 * Returns post type from a Query Loop block.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,mixed> $parsed_block Parsed block.
	 * @param \WP_Post           $post         Source post.
	 * @return string Post type.
	 */
	private function get_post_type_from_query_block( array $parsed_block, WP_Post $post ): string {
		$query     = $this->get_query_attrs( $parsed_block );
		$post_type = isset( $query['postType'] ) && is_scalar( $query['postType'] ) ? sanitize_key( (string) $query['postType'] ) : '';

		return '' !== $post_type ? $post_type : (string) $post->post_type;
	}

	/**
	 * Returns query attributes from a Query Loop block.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,mixed> $parsed_block Parsed block.
	 * @return array<string,mixed> Query attributes.
	 */
	private function get_query_attrs( array $parsed_block ): array {
		$attrs = $parsed_block['attrs'] ?? array();
		if ( ! is_array( $attrs ) ) {
			return array();
		}

		$query = $attrs['query'] ?? array();

		return is_array( $query ) ? $query : array();
	}

	/**
	 * Prepares source post text for semantic lookup.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post $post Source post.
	 * @return string Query text.
	 */
	private function prepare_query_text( WP_Post $post ): string {
		$content = (string) $post->post_content;

		if ( function_exists( 'do_blocks' ) ) {
			$content = do_blocks( $content );
		}

		$content = strip_shortcodes( $content );
		$content = html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );

		$text = trim(
			implode(
				"\n\n",
				array_filter(
					array(
						wp_strip_all_tags( get_the_title( $post ) ),
						implode( ', ', $this->get_term_names( $post ) ),
						$content,
					)
				)
			)
		);

		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;

		/**
		 * Filters the maximum source text length used to find semantic related posts.
		 *
		 * @since 1.1.0
		 *
		 * @param int      $max_chars Maximum character length.
		 * @param \WP_Post $post      Source post.
		 */
		$max_chars = max( 500, min( 8000, (int) apply_filters( 'wpai_rag_related_posts_query_max_chars', 4000, $post ) ) );

		if ( strlen( $text ) > $max_chars ) {
			$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max_chars ) : substr( $text, 0, $max_chars );
		}

		/**
		 * Filters source text used to find semantic related posts.
		 *
		 * @since 1.1.0
		 *
		 * @param string   $text Source text.
		 * @param \WP_Post $post Source post.
		 */
		return (string) apply_filters( 'wpai_rag_related_posts_query_text', trim( $text ), $post );
	}

	/**
	 * Returns configured related post count.
	 *
	 * @since 1.1.0
	 *
	 * @param int      $count Requested count.
	 * @param \WP_Post $post  Source post.
	 * @return int Count.
	 */
	private function get_post_count( int $count, WP_Post $post ): int {
		/**
		 * Filters the number of semantic related posts to display.
		 *
		 * @since 1.1.0
		 *
		 * @param int      $count Number of posts.
		 * @param \WP_Post $post  Source post.
		 */
		$count = (int) apply_filters( 'wpai_rag_related_posts_count', $count, $post );

		return max( 1, min( self::DEFAULT_MAX_POST_COUNT, $count ) );
	}

	/**
	 * Returns minimum results required for output.
	 *
	 * @since 1.1.0
	 *
	 * @param int      $min_results Requested minimum.
	 * @param int      $count       Display count.
	 * @param \WP_Post $post        Source post.
	 * @return int Minimum results.
	 */
	private function get_min_results( int $min_results, int $count, WP_Post $post ): int {
		/**
		 * Filters the minimum semantic related posts required before output is shown.
		 *
		 * @since 1.1.0
		 *
		 * @param int      $min_results Minimum results.
		 * @param \WP_Post $post        Source post.
		 */
		$min_results = (int) apply_filters( 'wpai_rag_related_posts_min_results', $min_results, $post );

		return max( 1, min( $count, $min_results ) );
	}

	/**
	 * Returns search candidate limit.
	 *
	 * @since 1.1.0
	 *
	 * @param int      $candidate_limit Requested candidate limit.
	 * @param int      $count           Display count.
	 * @param \WP_Post $post            Source post.
	 * @return int Candidate limit.
	 */
	private function get_candidate_limit( int $candidate_limit, int $count, WP_Post $post ): int {
		/**
		 * Filters the semantic related posts search candidate limit.
		 *
		 * @since 1.1.0
		 *
		 * @param int      $candidate_limit Candidate limit.
		 * @param \WP_Post $post            Source post.
		 */
		$candidate_limit = (int) apply_filters( 'wpai_rag_related_posts_candidate_limit', $candidate_limit, $post );

		return max( $count + 1, min( 100, $candidate_limit ) );
	}

	/**
	 * Returns maximum distance for related matches.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post $post Source post.
	 * @return float Distance threshold.
	 */
	private function get_distance_threshold( WP_Post $post ): float {
		/**
		 * Filters the cosine distance threshold for semantic related posts.
		 *
		 * @since 1.1.0
		 *
		 * @param float    $threshold Distance threshold.
		 * @param \WP_Post $post      Source post.
		 */
		$threshold = (float) apply_filters( 'wpai_rag_related_posts_distance_threshold', self::DEFAULT_DISTANCE_THRESHOLD, $post );

		return max( 0.0, min( 2.0, $threshold ) );
	}

	/**
	 * Gets cached related post IDs.
	 *
	 * @since 1.1.0
	 *
	 * @param string               $cache_key Cache key.
	 * @param array<string, mixed> $args      Related posts args.
	 * @return list<int>|null Cached related post IDs, or null when uncached.
	 */
	private function get_cached_related_post_ids( string $cache_key, array $args ): ?array {
		$cached = get_transient( $cache_key );
		if ( ! is_array( $cached ) ) {
			return null;
		}

		$post_ids = array_values( array_filter( array_map( 'absint', $cached ) ) );
		if ( empty( $post_ids ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$post_ids,
				function ( int $post_id ) use ( $args ): bool {
					$post = get_post( $post_id );

					return $post instanceof WP_Post && $this->post_matches_args( $post, $args );
				}
			)
		);
	}

	/**
	 * Sets cached related post IDs.
	 *
	 * @since 1.1.0
	 *
	 * @param string    $cache_key Cache key.
	 * @param list<int> $post_ids  Related post IDs.
	 * @param \WP_Post  $post      Source post.
	 */
	private function set_cached_related_post_ids( string $cache_key, array $post_ids, WP_Post $post ): void {
		/**
		 * Filters the semantic related posts cache lifetime in seconds.
		 *
		 * @since 1.1.0
		 *
		 * @param int      $ttl  Cache lifetime in seconds.
		 * @param \WP_Post $post Source post.
		 */
		$ttl = max( 0, (int) apply_filters( 'wpai_rag_related_posts_cache_ttl', self::DEFAULT_CACHE_TTL, $post ) );
		if ( 0 === $ttl ) {
			return;
		}

		set_transient( $cache_key, array_values( array_filter( array_map( 'absint', $post_ids ) ) ), $ttl );
	}

	/**
	 * Returns a related posts cache key.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post             $post            Source post.
	 * @param int                  $count           Count.
	 * @param int                  $min_results     Minimum results.
	 * @param int                  $candidate_limit Candidate limit.
	 * @param float                $threshold       Distance threshold.
	 * @param array<string, mixed> $args            Related posts args.
	 * @return string Cache key.
	 */
	private function get_cache_key( WP_Post $post, int $count, int $min_results, int $candidate_limit, float $threshold, array $args ): string {
		$encoded = wp_json_encode(
			array(
				'modified'        => $post->post_modified_gmt,
				'content'         => $post->post_content,
				'count'           => $count,
				'min_results'     => $min_results,
				'candidate_limit' => $candidate_limit,
				'post_type'       => $args['post_type'],
				'post_status'     => $args['post_status'],
				'threshold'       => $threshold,
			)
		);

		return 'wpai_rag_rel_' . (int) $post->ID . '_' . substr( md5( is_string( $encoded ) ? $encoded : '' ), 0, 12 );
	}

	/**
	 * Checks whether a post matches related posts args.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post             $post Post to check.
	 * @param array<string, mixed> $args Related posts args.
	 * @return bool True when the post matches.
	 */
	private function post_matches_args( WP_Post $post, array $args ): bool {
		$post_types    = $this->sanitize_list_arg( $args['post_type'] );
		$post_statuses = $this->sanitize_list_arg( $args['post_status'] );

		return ( empty( $post_types ) || in_array( (string) $post->post_type, $post_types, true ) )
			&& ( empty( $post_statuses ) || in_array( (string) $post->post_status, $post_statuses, true ) );
	}

	/**
	 * Returns term names for related-post matching.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post $post Post.
	 * @return list<string> Term names.
	 */
	private function get_term_names( WP_Post $post ): array {
		$names = array();
		foreach ( $this->get_terms( $post ) as $term ) {
			$names[] = $term->name;
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Returns public category and tag terms for a post.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post $post Post.
	 * @return list<\WP_Term> Terms.
	 */
	private function get_terms( WP_Post $post ): array {
		$taxonomies = array_values( array_intersect( array( 'category', 'post_tag' ), get_object_taxonomies( (string) $post->post_type ) ) );
		if ( empty( $taxonomies ) ) {
			return array();
		}

		$terms = wp_get_post_terms( (int) $post->ID, $taxonomies );
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$terms,
				static fn( $term ): bool => $term instanceof \WP_Term
			)
		);
	}

	/**
	 * Sanitizes comma-separated or array list arguments.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $value Raw value.
	 * @return list<string> Sanitized list.
	 */
	private function sanitize_list_arg( $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = sanitize_key( (string) $item );
			if ( '' === $item ) {
				continue;
			}

			$items[] = $item;
		}

		return array_values( array_unique( $items ) );
	}
}

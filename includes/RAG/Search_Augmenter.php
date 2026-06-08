<?php
/**
 * Augments WordPress search with semantic RAG candidates.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Merges semantic candidates into the main search query.
 *
 * @since 1.1.0
 */
class Search_Augmenter {
	/**
	 * Default cosine distance threshold for promoted semantic matches.
	 */
	private const DEFAULT_STRONG_DISTANCE_THRESHOLD = 0.6;

	/**
	 * Query var containing semantic IDs.
	 */
	private const QUERY_VAR_IDS = '_wpai_rag_post_ids';

	/**
	 * Query var containing strong semantic IDs.
	 */
	private const QUERY_VAR_STRONG_IDS = '_wpai_rag_strong_post_ids';

	/**
	 * Search service.
	 *
	 * @var \WordPress\AI\RAG\Search_Service
	 */
	private Search_Service $search_service;

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
	 * Registers hooks.
	 *
	 * @since 1.1.0
	 */
	public function init(): void {
		add_action( 'pre_get_posts', array( $this, 'prepare_semantic_candidates' ) );
		add_filter( 'posts_search', array( $this, 'merge_semantic_search_clause' ), 10, 2 );
		add_filter( 'posts_orderby', array( $this, 'promote_semantic_results' ), 10, 2 );
	}

	/**
	 * Finds semantic candidates for the main search query.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Query $query Query object.
	 */
	public function prepare_semantic_candidates( WP_Query $query ): void {
		if ( ! $this->should_augment_query( $query ) ) {
			return;
		}

		$search = (string) $query->get( 's' );
		$limit  = max( 1, min( 100, (int) apply_filters( 'wpai_rag_search_candidate_limit', 50 ) ) );

		$results = $this->search_service->search(
			$search,
			array(
				'per_page'                => $limit,
				'max_per_page'            => $limit,
				'candidate_limit'         => $limit,
				'enforce_read_permission' => false,
			)
		);

		if ( is_wp_error( $results ) || empty( $results['results'] ) || ! is_array( $results['results'] ) ) {
			return;
		}

		$threshold  = (float) apply_filters( 'wpai_rag_search_distance_threshold', self::DEFAULT_STRONG_DISTANCE_THRESHOLD );
		$post_ids   = array();
		$strong_ids = array();

		foreach ( $results['results'] as $result ) {
			if ( ! is_array( $result ) || empty( $result['post_id'] ) ) {
				continue;
			}

			$post_id    = (int) $result['post_id'];
			$post_ids[] = $post_id;

			if ( ! isset( $result['distance'] ) || (float) $result['distance'] > $threshold ) {
				continue;
			}

			$strong_ids[] = $post_id;
		}

		if ( empty( $post_ids ) ) {
			return;
		}

		// phpcs:disable WordPressVIPMinimum.Hooks.PreGetPosts.PreGetPosts -- should_augment_query() verifies this is the main search query before these mutations.
		$query->set( self::QUERY_VAR_IDS, array_values( array_unique( $post_ids ) ) );
		$query->set( self::QUERY_VAR_STRONG_IDS, array_values( array_unique( $strong_ids ) ) );
		// phpcs:enable WordPressVIPMinimum.Hooks.PreGetPosts.PreGetPosts
	}

	/**
	 * Adds semantic IDs to the search condition.
	 *
	 * @since 1.1.0
	 *
	 * @param string    $search Search SQL.
	 * @param \WP_Query $query  Query object.
	 * @return string Search SQL.
	 */
	public function merge_semantic_search_clause( string $search, WP_Query $query ): string {
		$post_ids = $this->get_query_ids( $query, self::QUERY_VAR_IDS );
		if ( empty( $post_ids ) || '' === trim( $search ) ) {
			return $search;
		}

		global $wpdb;

		$body = preg_replace( '/^\s*AND\s*/i', '', $search );
		$ids  = implode( ',', $post_ids );

		return " AND ( {$body} OR {$wpdb->posts}.ID IN ({$ids}) )";
	}

	/**
	 * Promotes strong semantic matches while retaining normal ordering.
	 *
	 * @since 1.1.0
	 *
	 * @param string    $orderby Order by SQL.
	 * @param \WP_Query $query   Query object.
	 * @return string Order by SQL.
	 */
	public function promote_semantic_results( string $orderby, WP_Query $query ): string {
		$strong_ids = $this->get_query_ids( $query, self::QUERY_VAR_STRONG_IDS );
		if ( empty( $strong_ids ) ) {
			return $orderby;
		}

		global $wpdb;

		$ids       = implode( ',', $strong_ids );
		$promotion = "CASE WHEN {$wpdb->posts}.ID IN ({$ids}) THEN 0 ELSE 1 END ASC, FIELD({$wpdb->posts}.ID, {$ids}) ASC";

		if ( '' === trim( $orderby ) ) {
			return $promotion;
		}

		return $promotion . ', ' . $orderby;
	}

	/**
	 * Checks whether a query should be augmented.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Query $query Query object.
	 * @return bool True when query should be augmented.
	 */
	private function should_augment_query( WP_Query $query ): bool {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() || '' === trim( (string) $query->get( 's' ) ) ) {
			return false;
		}

		if ( $query->is_feed() ) {
			return false;
		}

		/**
		 * Filters whether the current search query should be augmented with RAG results.
		 *
		 * @since 1.1.0
		 *
		 * @param bool      $augment Whether to augment.
		 * @param \WP_Query $query   Query object.
		 */
		return (bool) apply_filters( 'wpai_rag_search_augment_enabled', true, $query );
	}

	/**
	 * Gets integer IDs from a query var.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Query $query Query object.
	 * @param string    $key   Query var key.
	 * @return list<int> IDs.
	 */
	private function get_query_ids( WP_Query $query, string $key ): array {
		$value = $query->get( $key );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array_filter( array_map( 'absint', $value ) );

		return array_values( array_unique( $ids ) );
	}
}

<?php
/**
 * Reads and writes post embeddings stored in post meta.
 *
 * TODO: Replace this class once WordPress/ai#683 (native VECTOR INDEX) lands.
 *       At that point, write to the VECTOR column instead of postmeta and
 *       run retrieval via a MariaDB dot-product query instead of PHP iteration.
 *
 * @package WordPress\AI\Experiments\Semantic_Search
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Semantic_Search;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Postmeta-backed embedding store.
 *
 * Persists embedding vectors as serialised float arrays in wp_postmeta.
 * Each indexed post receives two meta keys: one for the embedding vector
 * and one for the model that produced it.
 *
 * @internal
 * @since x.x.x
 */
class Embedding_Store {

	/**
	 * Post meta key for the serialised float[] embedding vector.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const META_EMBEDDING = '_wpai_semantic_search_embedding';

	/**
	 * Post meta key for the model identifier that produced the embedding.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const META_MODEL = '_wpai_semantic_search_embedding_model';

	/**
	 * Persists an embedding vector and the model that produced it for a post.
	 *
	 * @since x.x.x
	 *
	 * @param int      $post_id   Post ID to index.
	 * @param float[]  $embedding Float vector returned by the embedding API.
	 * @param string   $model     Model identifier (e.g. 'gemini-embedding-001').
	 * @return void
	 */
	public function save( int $post_id, array $embedding, string $model ): void {
		update_post_meta( $post_id, self::META_EMBEDDING, $embedding );
		update_post_meta( $post_id, self::META_MODEL, $model );
	}

	/**
	 * Retrieves the stored embedding vector for a post.
	 *
	 * Returns null when the post has not been indexed yet or when the stored
	 * value cannot be cast to a non-empty float array.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id Post ID to look up.
	 * @return float[]|null Float vector on success, null if not indexed.
	 */
	public function get( int $post_id ): ?array {
		$value = get_post_meta( $post_id, self::META_EMBEDDING, true );

		if ( ! is_array( $value ) || empty( $value ) ) {
			return null;
		}

		return array_map( 'floatval', $value );
	}

	/**
	 * Returns the IDs of published posts that have not yet been indexed.
	 *
	 * Uses a LEFT JOIN between wp_posts and wp_postmeta so that the check is
	 * performed in SQL rather than loading all post IDs into PHP memory.
	 *
	 * @since x.x.x
	 *
	 * @param string[] $post_types Post type slugs to include (e.g. ['post', 'page']).
	 * @param int      $limit      Maximum number of IDs to return. Default 5.
	 * @return int[] Post IDs that have no stored embedding.
	 */
	public function get_unindexed_ids( array $post_types, int $limit = 5 ): array {
		global $wpdb;

		if ( empty( $post_types ) ) {
			return array();
		}

		$types_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm
				ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_status = 'publish'
			  AND p.post_type IN ( {$types_placeholders} )
			  AND pm.meta_value IS NULL
			LIMIT %d";

		$values = array_merge(
			array( self::META_EMBEDDING ),
			array_values( $post_types ),
			array( $limit )
		);

		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from a fixed template with generated placeholders; all values are passed through prepare().

		return array_map( 'intval', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Returns IDs of published posts that already have a stored embedding.
	 *
	 * Lets the vector search score only indexed content instead of loading
	 * every published post into memory.
	 *
	 * @since x.x.x
	 *
	 * @param string[] $post_types Post type slugs to include (e.g. ['post', 'page']).
	 * @param int      $limit      Maximum number of IDs to return.
	 * @return int[] Post IDs that have a stored embedding, newest first.
	 */
	public function get_indexed_ids( array $post_types, int $limit ): array {
		global $wpdb;

		if ( empty( $post_types ) || $limit <= 0 ) {
			return array();
		}

		$types_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

		$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm
				ON p.ID = pm.post_id AND pm.meta_key = %s
			WHERE p.post_status = 'publish'
			  AND p.post_type IN ( {$types_placeholders} )
			ORDER BY p.ID DESC
			LIMIT %d";

		$values = array_merge(
			array( self::META_EMBEDDING ),
			array_values( $post_types ),
			array( $limit )
		);

		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from a fixed template with generated placeholders; all values are passed through prepare().

		return array_map( 'intval', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Returns total and indexed post/page counts for the current site.
	 *
	 * @since x.x.x
	 *
	 * @return array{total:int, indexed:int} Counts of total published posts and indexed posts.
	 */
	public function get_stats(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_status = 'publish'
			  AND post_type IN ('post', 'page')"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$indexed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
				WHERE meta_key = %s",
				self::META_EMBEDDING
			)
		);

		return array(
			'total'   => $total,
			'indexed' => $indexed,
		);
	}

	/**
	 * Removes the stored embedding and model meta for a post.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id Post ID whose index entry should be removed.
	 * @return void
	 */
	public function delete( int $post_id ): void {
		delete_post_meta( $post_id, self::META_EMBEDDING );
		delete_post_meta( $post_id, self::META_MODEL );
	}
}

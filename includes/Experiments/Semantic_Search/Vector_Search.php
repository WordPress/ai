<?php
/**
 * Semantic search via cosine similarity over stored embeddings.
 *
 * TODO: Replace the PHP cosine loop with a MariaDB VECTOR dot-product query
 *       once WordPress/ai#683 ships the VECTOR INDEX.
 *
 * @package WordPress\AI\Experiments\Semantic_Search
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Semantic_Search;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Runs a semantic search against stored post embeddings.
 *
 * Generates a query embedding via Embedding_Generator, then iterates over all
 * published posts that have a stored embedding, computing cosine similarity
 * against each. Results below the configured score threshold are excluded.
 * Surviving results are sorted by score descending and sliced to the limit.
 *
 * @internal
 * @since x.x.x
 */
class Vector_Search {

	/**
	 * Embedding generator instance used to generate the query vector.
	 *
	 * @since x.x.x
	 * @var \WordPress\AI\Experiments\Semantic_Search\Embedding_Generator
	 */
	private Embedding_Generator $api;

	/**
	 * Embedding store instance used to retrieve stored post vectors.
	 *
	 * @since x.x.x
	 * @var \WordPress\AI\Experiments\Semantic_Search\Embedding_Store
	 */
	private Embedding_Store $store;

	/**
	 * Initialises the API and store dependencies.
	 *
	 * @since x.x.x
	 */
	public function __construct() {
		$this->api   = new Embedding_Generator();
		$this->store = new Embedding_Store();
	}

	/**
	 * Returns whether embedding generation is available and ready to search.
	 *
	 * Delegates to Embedding_Generator::is_available(). When this returns false,
	 * callers should fall back to default WordPress search rather than calling
	 * search() and receiving an empty result.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when embeddings can be generated.
	 */
	public function is_available(): bool {
		return $this->api->is_available();
	}

	/**
	 * Returns posts ranked by cosine similarity to the query string.
	 *
	 * Generates an embedding for $query, then scores every published post that
	 * has a stored embedding. Posts scoring below the provider threshold are
	 * excluded. The remaining results are sorted by score descending and the top
	 * $args['limit'] entries are returned.
	 *
	 * @since x.x.x
	 *
	 * @param string                                $query Search query string.
	 * @param array{limit?: int, post_type?: list<string>} $args  Optional. Accepts `limit` (maximum
	 *                                                     results, default 10) and `post_type`
	 *                                                     (post types to search, default
	 *                                                     `['post', 'page']`).
	 * @return list<array{id: int, title: string, type: string, url: string, excerpt: string, score: float}>
	 *         Ranked result entries, or an empty array if the query embedding failed.
	 */
	public function search( string $query, array $args = array() ): array {
		$query_embedding = $this->api->generate( $query );

		if ( null === $query_embedding ) {
			return array();
		}

		$limit           = $args['limit'] ?? 10;
		$post_types      = $args['post_type'] ?? array( 'post', 'page' );
		$score_threshold = $this->api->get_score_threshold();

		/**
		 * Filters the maximum number of indexed posts scored per semantic search.
		 *
		 * The vector search compares the query embedding against each candidate
		 * in memory, so this bounds the work (and memory) on large sites. Only
		 * posts that already have a stored embedding are considered.
		 *
		 * @since x.x.x
		 *
		 * @param int      $max_candidates Maximum posts to score. Default 2000.
		 * @param string[] $post_types     Post types being searched.
		 */
		$max_candidates = (int) apply_filters( 'wpai_semantic_search_max_candidates', 2000, $post_types );

		$candidate_ids = $this->store->get_indexed_ids( $post_types, $max_candidates );

		$results = array();

		foreach ( $candidate_ids as $post_id ) {
			$embedding = $this->store->get( $post_id );

			if ( null === $embedding ) {
				continue;
			}

			$score = $this->cosine_similarity( $query_embedding, $embedding );

			if ( $score < $score_threshold ) {
				continue;
			}

			$post = get_post( $post_id );

			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$edit_link = (string) get_edit_post_link( $post_id, '' );

			$results[] = array(
				'id'      => $post_id,
				'title'   => $post->post_title,
				'type'    => $post->post_type,
				'url'     => '' !== $edit_link ? $edit_link : '#',
				'excerpt' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 20 ),
				'score'   => round( $score, 4 ),
			);
		}

		usort(
			$results,
			static function ( array $a, array $b ): int {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $results, 0, $limit );
	}

	/**
	 * Computes the cosine similarity between two float vectors.
	 *
	 * Returns a value in the range [-1, 1] where 1 means the vectors point in
	 * the same direction (identical semantics) and -1 means opposite directions.
	 * Returns 0.0 when either vector has zero magnitude to avoid division by zero.
	 *
	 * If the two vectors differ in length, only the shorter length is used so
	 * that mismatched dimensions (e.g. from a model change) don't cause errors.
	 *
	 * @since x.x.x
	 *
	 * @param float[] $a First embedding vector.
	 * @param float[] $b Second embedding vector.
	 * @return float Cosine similarity score in [-1, 1].
	 */
	private function cosine_similarity( array $a, array $b ): float {
		$dot    = 0.0;
		$norm_a = 0.0;
		$norm_b = 0.0;
		$len    = min( count( $a ), count( $b ) );

		for ( $i = 0; $i < $len; $i++ ) {
			$dot    += $a[ $i ] * $b[ $i ];
			$norm_a += $a[ $i ] ** 2;
			$norm_b += $b[ $i ] ** 2;
		}

		if ( 0.0 === $norm_a || 0.0 === $norm_b ) {
			return 0.0;
		}

		return $dot / ( sqrt( $norm_a ) * sqrt( $norm_b ) );
	}
}

<?php
/**
 * Two-pass similarity search over stored embeddings.
 *
 * @package WordPress\AI\Embeddings
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings;

use SplPriorityQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the stored objects most similar to a query vector.
 *
 * The search runs in two passes so it never loads every full vector. The first streams the
 * one-bit-per-component coarse codes and keeps a bounded shortlist of the rows nearest the query
 * by Hamming distance. The second loads only the shortlisted vectors and reranks them by exact
 * cosine similarity, then collapses chunks so each object is scored by its best chunk.
 *
 * @since x.x.x
 */
final class Similarity_Search {

	/**
	 * Default number of rows the coarse pass keeps for the exact rerank.
	 */
	public const DEFAULT_SHORTLIST = 200;

	/**
	 * Repository the vectors are read from.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Repository_Interface
	 */
	private Embedding_Repository_Interface $repository;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Embedding_Repository_Interface|null $repository Optional. Repository to search.
	 *                                                                                 Default the database-backed repository.
	 */
	public function __construct( ?Embedding_Repository_Interface $repository = null ) {
		$this->repository = $repository ?? new Embedding_Repository();
	}

	/**
	 * Returns the objects whose stored vectors are most similar to a query.
	 *
	 * @since x.x.x
	 *
	 * @param list<float>  $query       The query vector, from the same provider and model as the stored vectors.
	 * @param string       $provider    Provider ID.
	 * @param string       $model       Model ID.
	 * @param string       $object_type Object type, e.g. `term`.
	 * @param list<string> $subtypes    Object subtypes to search, e.g. `post_tag`. Empty returns nothing.
	 * @param int          $limit       Maximum number of objects to return. Below 1 returns nothing.
	 * @param list<int>|null $object_ids Optional. Only objects with these IDs are searched; others are skipped
	 *                                   in the coarse pass so they never take shortlist slots. Empty returns
	 *                                   nothing. Default null, no restriction.
	 * @return array<int, float> Object ID => best cosine score over its chunks, best first, at most $limit.
	 *
	 * @throws \InvalidArgumentException If the query is not a valid vector.
	 * @throws \RuntimeException         If the stored coarse codes could not be read.
	 */
	public function search( array $query, string $provider, string $model, string $object_type, array $subtypes, int $limit, ?array $object_ids = null ): array {
		$code = Vector_Codec::pack_coarse( $query );

		if ( $limit < 1 || array() === $subtypes || array() === $object_ids ) {
			return array();
		}

		/**
		 * Filters how many rows the coarse pass of a similarity search keeps
		 * for the exact rerank.
		 *
		 * Larger shortlists trade speed for recall. The value is never allowed
		 * below the requested limit.
		 *
		 * @since x.x.x
		 *
		 * @param int          $shortlist   Rows to rerank. Default 200.
		 * @param string       $object_type Object type being searched.
		 * @param list<string> $subtypes    Object subtypes being searched.
		 */
		$shortlist = max( $limit, (int) apply_filters( 'wpai_embedding_search_shortlist_size', self::DEFAULT_SHORTLIST, $object_type, $subtypes ) );

		$allowed = null === $object_ids ? null : array_flip( $object_ids );
		$row_ids = $this->shortlist( $code, $provider, $model, $object_type, $subtypes, $shortlist, $allowed );

		if ( array() === $row_ids ) {
			return array();
		}

		return $this->rerank( $query, $row_ids, $limit );
	}

	/**
	 * Returns the IDs of the rows whose coarse codes are nearest the query's.
	 *
	 * @since x.x.x
	 *
	 * @param string       $code        The query's coarse code.
	 * @param string       $provider    Provider ID.
	 * @param string       $model       Model ID.
	 * @param string       $object_type Object type.
	 * @param list<string> $subtypes    Object subtypes.
	 * @param int          $size        Maximum number of row IDs to keep.
	 * @param array<int, int>|null $allowed Optional. Allowed object IDs as keys, or null for no restriction. Default null.
	 * @return list<int> Row IDs, in no particular order.
	 *
	 * @throws \RuntimeException If the coarse codes could not be read.
	 */
	private function shortlist( string $code, string $provider, string $model, string $object_type, array $subtypes, int $size, ?array $allowed = null ): array {
		$length = strlen( $code );
		$heap   = new SplPriorityQueue();
		$heap->setExtractFlags( SplPriorityQueue::EXTR_PRIORITY );

		$count = 0;
		$worst = PHP_INT_MAX;

		foreach ( $this->repository->iterate_coarse( $provider, $model, $object_type, $subtypes ) as $row ) {
			// A code of another length belongs to a vector with another dimension count.
			if ( strlen( $row['coarse'] ) !== $length ) {
				continue;
			}

			if ( null !== $allowed && ! isset( $allowed[ (int) $row['object_id'] ] ) ) {
				continue;
			}

			$distance = Vector_Codec::hamming( $code, $row['coarse'] );

			if ( $count >= $size ) {
				if ( $distance >= $worst ) {
					continue;
				}

				$heap->extract();
				--$count;
			}

			$heap->insert( $row['id'], $distance );
			++$count;

			$worst = $heap->top();
		}

		$heap->setExtractFlags( SplPriorityQueue::EXTR_DATA );

		$row_ids = array();
		foreach ( $heap as $row_id ) {
			$row_ids[] = (int) $row_id;
		}

		return $row_ids;
	}

	/**
	 * Reranks shortlisted rows by exact cosine similarity and collapses them to objects.
	 *
	 * @since x.x.x
	 *
	 * @param list<float> $query   The query vector.
	 * @param list<int>   $row_ids Shortlisted row IDs.
	 * @param int         $limit   Maximum number of objects to return.
	 * @return array<int, float> Object ID => best cosine score, best first, at most $limit.
	 */
	private function rerank( array $query, array $row_ids, int $limit ): array {
		$dimensions = count( $query );
		$candidates = array();
		$objects    = array();

		foreach ( $this->repository->get_by_ids( $row_ids ) as $record ) {
			// The ranker throws on a dimension mismatch, so such records are skipped.
			if ( $record->get_dimensions() !== $dimensions ) {
				continue;
			}

			$candidates[ $record->get_id() ] = $record->get_vector();
			$objects[ $record->get_id() ]    = $record->get_object_id();
		}

		if ( array() === $candidates ) {
			return array();
		}

		$scores = array();

		foreach ( Vector_Ranker::rank( $query, $candidates ) as $row_id => $score ) {
			$object_id = $objects[ $row_id ];

			if ( isset( $scores[ $object_id ] ) && $scores[ $object_id ] >= $score ) {
				continue;
			}

			$scores[ $object_id ] = $score;
		}

		arsort( $scores );

		return array_slice( $scores, 0, $limit, true );
	}
}

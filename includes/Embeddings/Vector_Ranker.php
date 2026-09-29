<?php
/**
 * In-memory ranking of embedding vectors against a query.
 *
 * @package WordPress\AI\Embeddings
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Scores a set of candidate vectors against a query and returns them best first.
 *
 * Callers are responsible for passing comparable vectors: same provider, same model, same
 * dimension count. A mismatched candidate is an error, not a low score.
 *
 * @since x.x.x
 */
final class Vector_Ranker {

	/**
	 * Ranks candidate vectors against a query.
	 *
	 * @since x.x.x
	 *
	 * @param list<int|float>                    $query      The vector to compare against.
	 * @param array<int|string, list<int|float>> $candidates Candidate vectors, keyed by whatever the
	 *                                                       caller wants back (row ID, object ID, label).
	 * @param string                             $metric     One of the `Vector_Math::METRIC_*` constants.
	 *                                                       Default cosine similarity.
	 * @param int|null                           $limit      Maximum number of results, or null for all.
	 * @return array<int|string, float> Scores keyed like $candidates, best first: descending for
	 *                                  cosine and dot product, ascending for Euclidean distance.
	 *
	 * @throws \InvalidArgumentException If the metric is unknown, the limit is not positive, the query
	 *                                  is invalid, or any candidate is not a vector comparable to the query.
	 */
	public static function rank( array $query, array $candidates, string $metric = Vector_Math::METRIC_COSINE, ?int $limit = null ): array {
		if ( ! in_array( $metric, self::metrics(), true ) ) {
			throw new InvalidArgumentException(
				esc_html( sprintf( 'Unknown similarity metric: %s.', $metric ) )
			);
		}

		if ( null !== $limit && $limit < 1 ) {
			throw new InvalidArgumentException( 'The limit must be a positive integer or null.' );
		}

		Vector_Codec::validate( $query );

		$scores = array();
		foreach ( $candidates as $key => $candidate ) {
			if ( ! is_array( $candidate ) ) {
				throw new InvalidArgumentException(
					esc_html( sprintf( 'Candidate at key %s is not a vector.', (string) $key ) )
				);
			}

			$scores[ $key ] = self::score( $query, $candidate, $metric );
		}

		if ( Vector_Math::METRIC_EUCLIDEAN === $metric ) {
			asort( $scores );
		} else {
			arsort( $scores );
		}

		if ( null !== $limit ) {
			$scores = array_slice( $scores, 0, $limit, true );
		}

		return $scores;
	}

	/**
	 * Returns the metrics this ranker understands.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> Metric identifiers.
	 */
	private static function metrics(): array {
		return array(
			Vector_Math::METRIC_COSINE,
			Vector_Math::METRIC_DOT_PRODUCT,
			Vector_Math::METRIC_EUCLIDEAN,
		);
	}

	/**
	 * Scores one candidate against the query with the given metric.
	 *
	 * @since x.x.x
	 *
	 * @param list<int|float> $query     The query vector.
	 * @param list<int|float> $candidate The candidate vector.
	 * @param string          $metric    A validated `Vector_Math::METRIC_*` constant.
	 * @return float The score.
	 *
	 * @throws \InvalidArgumentException If the vectors are not comparable or the metric is unknown.
	 */
	private static function score( array $query, array $candidate, string $metric ): float {
		switch ( $metric ) {
			case Vector_Math::METRIC_COSINE:
				return Vector_Math::cosine_similarity( $query, $candidate );
			case Vector_Math::METRIC_DOT_PRODUCT:
				return Vector_Math::dot_product( $query, $candidate );
			case Vector_Math::METRIC_EUCLIDEAN:
				return Vector_Math::euclidean_distance( $query, $candidate );
			default:
				throw new InvalidArgumentException(
					esc_html( sprintf( 'Unknown similarity metric: %s.', $metric ) )
				);
		}
	}
}

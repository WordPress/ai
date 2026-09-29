<?php
/**
 * Tests for the embedding vector ranker.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings;

use InvalidArgumentException;
use WP_UnitTestCase;
use WordPress\AI\Embeddings\Vector_Codec;
use WordPress\AI\Embeddings\Vector_Math;
use WordPress\AI\Embeddings\Vector_Ranker;

/**
 * Vector_Ranker test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Vector_Ranker
 */
class Vector_RankerTest extends WP_UnitTestCase {

	/**
	 * Candidates whose similarity to the query (1, 0) is unambiguous.
	 *
	 * @since x.x.x
	 *
	 * @return array<int|string, list<float>> Keyed candidate vectors.
	 */
	private function candidates(): array {
		return array(
			'opposite'   => array( -1.0, 0.0 ),
			'same'       => array( 2.0, 0.0 ),
			'orthogonal' => array( 0.0, 1.0 ),
			'close'      => array( 1.0, 0.5 ),
		);
	}

	/**
	 * Tests that cosine ranking returns candidates best first with keys preserved.
	 *
	 * @since x.x.x
	 */
	public function test_rank_by_cosine_orders_most_similar_first(): void {
		$ranked = Vector_Ranker::rank( array( 1.0, 0.0 ), $this->candidates() );

		$this->assertSame( array( 'same', 'close', 'orthogonal', 'opposite' ), array_keys( $ranked ) );
		$this->assertEqualsWithDelta( 1.0, $ranked['same'], 1.0e-9 );
		$this->assertEqualsWithDelta( 0.0, $ranked['orthogonal'], 1.0e-9 );
		$this->assertEqualsWithDelta( -1.0, $ranked['opposite'], 1.0e-9 );
	}

	/**
	 * Tests that cosine is the default metric.
	 *
	 * @since x.x.x
	 */
	public function test_rank_defaults_to_cosine(): void {
		$query      = array( 1.0, 0.0 );
		$candidates = $this->candidates();

		$this->assertSame(
			Vector_Ranker::rank( $query, $candidates, Vector_Math::METRIC_COSINE ),
			Vector_Ranker::rank( $query, $candidates )
		);
	}

	/**
	 * Tests that dot product ranking is magnitude-sensitive and sorts descending.
	 *
	 * @since x.x.x
	 */
	public function test_rank_by_dot_product_sorts_descending(): void {
		$ranked = Vector_Ranker::rank( array( 1.0, 0.0 ), $this->candidates(), Vector_Math::METRIC_DOT_PRODUCT );

		// 'same' is (2, 0) so its dot product (2.0) beats 'close' (1.0).
		$this->assertSame( array( 'same', 'close', 'orthogonal', 'opposite' ), array_keys( $ranked ) );
		$this->assertEqualsWithDelta( 2.0, $ranked['same'], 1.0e-9 );
	}

	/**
	 * Tests that Euclidean ranking sorts ascending, nearest first.
	 *
	 * @since x.x.x
	 */
	public function test_rank_by_euclidean_sorts_ascending(): void {
		$ranked = Vector_Ranker::rank( array( 1.0, 0.0 ), $this->candidates(), Vector_Math::METRIC_EUCLIDEAN );

		// Distances from (1, 0): close 0.5, same 1.0, orthogonal sqrt(2), opposite 2.0.
		$this->assertSame( array( 'close', 'same', 'orthogonal', 'opposite' ), array_keys( $ranked ) );
		$this->assertEqualsWithDelta( 0.5, $ranked['close'], 1.0e-9 );
		$this->assertEqualsWithDelta( 2.0, $ranked['opposite'], 1.0e-9 );
	}

	/**
	 * Tests that a limit truncates the result after sorting.
	 *
	 * @since x.x.x
	 */
	public function test_rank_applies_limit_after_sorting(): void {
		$ranked = Vector_Ranker::rank( array( 1.0, 0.0 ), $this->candidates(), Vector_Math::METRIC_COSINE, 2 );

		$this->assertSame( array( 'same', 'close' ), array_keys( $ranked ) );
	}

	/**
	 * Tests that a limit larger than the candidate set returns everything.
	 *
	 * @since x.x.x
	 */
	public function test_rank_limit_larger_than_set_returns_all(): void {
		$ranked = Vector_Ranker::rank( array( 1.0, 0.0 ), $this->candidates(), Vector_Math::METRIC_COSINE, 100 );

		$this->assertCount( 4, $ranked );
	}

	/**
	 * Tests that integer keys survive ranking (row IDs, post IDs).
	 *
	 * @since x.x.x
	 */
	public function test_rank_preserves_integer_keys(): void {
		$ranked = Vector_Ranker::rank(
			array( 1.0, 0.0 ),
			array(
				42 => array( 0.0, 1.0 ),
				7  => array( 1.0, 0.0 ),
			)
		);

		$this->assertSame( array( 7, 42 ), array_keys( $ranked ) );
	}

	/**
	 * Tests that integer keys survive a limit — the slice must not reindex row or post IDs.
	 *
	 * @since x.x.x
	 */
	public function test_rank_preserves_integer_keys_when_limited(): void {
		$ranked = Vector_Ranker::rank(
			array( 1.0, 0.0 ),
			array(
				42 => array( 0.0, 1.0 ),
				7  => array( 1.0, 0.0 ),
				5  => array( -1.0, 0.0 ),
			),
			Vector_Math::METRIC_COSINE,
			2
		);

		$this->assertSame( array( 7, 42 ), array_keys( $ranked ) );
	}

	/**
	 * Tests that an empty candidate set ranks to an empty array.
	 *
	 * @since x.x.x
	 */
	public function test_rank_of_empty_candidates_is_empty(): void {
		$this->assertSame( array(), Vector_Ranker::rank( array( 1.0, 0.0 ), array() ) );
	}

	/**
	 * Tests that an unknown metric is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_rank_rejects_unknown_metric(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/manhattan/' );

		Vector_Ranker::rank( array( 1.0, 0.0 ), $this->candidates(), 'manhattan' );
	}

	/**
	 * Tests that a non-positive limit is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_rank_rejects_non_positive_limit(): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Ranker::rank( array( 1.0, 0.0 ), $this->candidates(), Vector_Math::METRIC_COSINE, 0 );
	}

	/**
	 * Tests that a candidate which is not an array is rejected, naming its key.
	 *
	 * @since x.x.x
	 */
	public function test_rank_rejects_non_array_candidate(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/broken/' );

		Vector_Ranker::rank( array( 1.0, 0.0 ), array( 'broken' => 'not a vector' ) );
	}

	/**
	 * Tests that a candidate with the wrong dimension count is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_rank_rejects_mismatched_candidate_dimensions(): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Ranker::rank( array( 1.0, 0.0 ), array( 'ok' => array( 1.0, 0.0 ), 'bad' => array( 1.0, 0.0, 0.0 ) ) );
	}

	/**
	 * Tests that an invalid query is rejected before any candidate is scored.
	 *
	 * @since x.x.x
	 */
	public function test_rank_rejects_invalid_query(): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Ranker::rank( array(), $this->candidates() );
	}

	/**
	 * Tests nearest-centroid classification: rank label centroids against a new vector.
	 *
	 * @since x.x.x
	 */
	public function test_rank_supports_nearest_centroid_classification(): void {
		$centroids = array(
			'sports'  => Vector_Math::centroid( array( array( 1.0, 0.1 ), array( 0.9, 0.0 ) ) ),
			'cooking' => Vector_Math::centroid( array( array( 0.0, 1.0 ), array( 0.1, 0.9 ) ) ),
		);

		$ranked = Vector_Ranker::rank( array( 0.8, 0.2 ), $centroids, Vector_Math::METRIC_COSINE, 1 );

		$this->assertSame( array( 'sports' ), array_keys( $ranked ) );
	}

	/**
	 * Tests that the exact cosine ranking agrees with the Hamming ranking on the coarse codes.
	 *
	 * This ties the two phases of the planned search together: phase one shortlists by
	 * `Vector_Codec::hamming()`, phase two rescores the shortlist with `Vector_Ranker`. On a fixture
	 * where the answer is unambiguous, both must produce the same order.
	 *
	 * @since x.x.x
	 */
	public function test_rank_by_cosine_agrees_with_hamming_on_coarse_codes(): void {
		mt_srand( 20260902 );
		$query = array();
		for ( $i = 0; $i < 256; $i++ ) {
			$query[] = ( mt_rand() / mt_getrandmax() ) * 2.0 - 1.0;
		}

		$candidates = array();
		foreach ( array( 0, 16, 64, 128 ) as $flips ) {
			$candidate = $query;
			for ( $i = 0; $i < $flips; $i++ ) {
				$candidate[ $i ] = -$candidate[ $i ];
			}
			$candidates[ $flips ] = $candidate;
		}

		$query_code = Vector_Codec::pack_coarse( $query );
		$distances  = array();
		foreach ( $candidates as $flips => $candidate ) {
			$distances[ $flips ] = Vector_Codec::hamming( $query_code, Vector_Codec::pack_coarse( $candidate ) );
		}
		asort( $distances );

		$ranked = Vector_Ranker::rank( $query, $candidates );

		$this->assertSame( array_keys( $distances ), array_keys( $ranked ) );
		$this->assertSame( array( 0, 16, 64, 128 ), array_keys( $ranked ) );
	}
}

<?php
/**
 * Tests for the embedding vector arithmetic.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings;

use InvalidArgumentException;
use WP_UnitTestCase;
use WordPress\AI\Embeddings\Vector_Math;

/**
 * Vector_Math test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Vector_Math
 */
class Vector_MathTest extends WP_UnitTestCase {

	/**
	 * Tests the dot product against a hand-computed value.
	 *
	 * @since x.x.x
	 */
	public function test_dot_product(): void {
		// 1*4 + 2*5 + 3*6 = 32.
		$this->assertEqualsWithDelta( 32.0, Vector_Math::dot_product( array( 1, 2, 3 ), array( 4, 5, 6 ) ), 1.0e-9 );
		$this->assertEqualsWithDelta( 0.0, Vector_Math::dot_product( array( 1.0, 0.0 ), array( 0.0, 1.0 ) ), 1.0e-9 );
	}

	/**
	 * Tests that two-vector functions reject mismatched dimension counts, naming both lengths.
	 *
	 * @since x.x.x
	 */
	public function test_dot_product_rejects_mismatched_dimensions(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/3.*2/' );

		Vector_Math::dot_product( array( 1, 2, 3 ), array( 1, 2 ) );
	}

	/**
	 * Tests that operands are validated the same way the codec validates them.
	 *
	 * @since x.x.x
	 */
	public function test_dot_product_rejects_non_numeric_components(): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Math::dot_product( array( 1, 'two' ), array( 1, 2 ) );
	}

	/**
	 * Tests the norm calculation, including the zero vector.
	 *
	 * @since x.x.x
	 */
	public function test_norm(): void {
		$this->assertEqualsWithDelta( 5.0, Vector_Math::norm( array( 3, 4 ) ), 1.0e-9 );
		$this->assertEqualsWithDelta( 0.0, Vector_Math::norm( array( 0.0, 0.0 ) ), 1.0e-9 );
	}

	/**
	 * Tests that the norm rejects an empty vector.
	 *
	 * @since x.x.x
	 */
	public function test_norm_rejects_empty_vector(): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Math::norm( array() );
	}

	/**
	 * Tests cosine similarity at its three landmark values.
	 *
	 * @since x.x.x
	 */
	public function test_cosine_similarity_landmarks(): void {
		// Orthogonal.
		$this->assertEqualsWithDelta( 0.0, Vector_Math::cosine_similarity( array( 1.0, 0.0 ), array( 0.0, 1.0 ) ), 1.0e-9 );
		// Parallel, different magnitudes — cosine ignores scale.
		$this->assertEqualsWithDelta( 1.0, Vector_Math::cosine_similarity( array( 1.0, 2.0 ), array( 2.0, 4.0 ) ), 1.0e-9 );
		// Antiparallel.
		$this->assertEqualsWithDelta( -1.0, Vector_Math::cosine_similarity( array( 1.0, 2.0 ), array( -1.0, -2.0 ) ), 1.0e-9 );
	}

	/**
	 * Tests a hand-computed cosine that is not a landmark value.
	 *
	 * @since x.x.x
	 */
	public function test_cosine_similarity_hand_computed(): void {
		// (1,2,3)·(4,5,6) = 32; |a| = sqrt(14); |b| = sqrt(77); cos = 32 / sqrt(1078).
		$expected = 32.0 / sqrt( 1078.0 );

		$this->assertEqualsWithDelta( $expected, Vector_Math::cosine_similarity( array( 1, 2, 3 ), array( 4, 5, 6 ) ), 1.0e-9 );
	}

	/**
	 * Tests that a vector's similarity with itself is 1.
	 *
	 * @since x.x.x
	 */
	public function test_cosine_similarity_of_self_is_one(): void {
		$vector = $this->seeded_vector( 1536 );

		$this->assertEqualsWithDelta( 1.0, Vector_Math::cosine_similarity( $vector, $vector ), 1.0e-9 );
	}

	/**
	 * Tests that rounding can never report a similarity above 1.
	 *
	 * Comparing a vector against a scaled copy of itself makes the three sums round differently, and
	 * the raw quotient lands a few ulps above 1.0 (about 1.0000000000000013 for this fixture). The
	 * clamp is what brings it back; without it this assertion fails.
	 *
	 * @since x.x.x
	 */
	public function test_cosine_similarity_is_clamped_to_one(): void {
		$vector = $this->seeded_vector( 1536 );
		$scaled = array();
		foreach ( $vector as $value ) {
			$scaled[] = $value * 1.7;
		}

		$similarity = Vector_Math::cosine_similarity( $vector, $scaled );

		$this->assertLessThanOrEqual( 1.0, $similarity );
		$this->assertEqualsWithDelta( 1.0, $similarity, 1.0e-9 );
	}

	/**
	 * Returns a deterministic pseudo-random vector with components in [-1, 1].
	 *
	 * @since x.x.x
	 *
	 * @param int $dimensions Number of components.
	 * @return list<float> The vector.
	 */
	private function seeded_vector( int $dimensions ): array {
		mt_srand( 20260902 );
		$vector = array();
		for ( $i = 0; $i < $dimensions; $i++ ) {
			$vector[] = ( mt_rand() / mt_getrandmax() ) * 2.0 - 1.0;
		}

		return $vector;
	}

	/**
	 * Tests that cosine similarity refuses a zero vector rather than returning 0.
	 *
	 * @since x.x.x
	 */
	public function test_cosine_similarity_rejects_zero_vector(): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Math::cosine_similarity( array( 0.0, 0.0 ), array( 1.0, 1.0 ) );
	}

	/**
	 * Tests cosine similarity rejects mismatched dimension counts.
	 *
	 * @since x.x.x
	 */
	public function test_cosine_similarity_rejects_mismatched_dimensions(): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Math::cosine_similarity( array( 1.0 ), array( 1.0, 1.0 ) );
	}

	/**
	 * Tests Euclidean distance with a 3-4-5 triangle and with identical vectors.
	 *
	 * @since x.x.x
	 */
	public function test_euclidean_distance(): void {
		$this->assertEqualsWithDelta( 5.0, Vector_Math::euclidean_distance( array( 0.0, 0.0 ), array( 3.0, 4.0 ) ), 1.0e-9 );
		$this->assertEqualsWithDelta( 0.0, Vector_Math::euclidean_distance( array( 1.5, -2.5 ), array( 1.5, -2.5 ) ), 1.0e-9 );
		// Symmetric.
		$this->assertEqualsWithDelta(
			Vector_Math::euclidean_distance( array( 1, 2 ), array( 4, 6 ) ),
			Vector_Math::euclidean_distance( array( 4, 6 ), array( 1, 2 ) ),
			1.0e-9
		);
	}

	/**
	 * Tests Euclidean distance rejects mismatched dimension counts.
	 *
	 * @since x.x.x
	 */
	public function test_euclidean_distance_rejects_mismatched_dimensions(): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Math::euclidean_distance( array( 1.0, 2.0, 3.0 ), array( 1.0, 2.0 ) );
	}

	/**
	 * Tests that the metric constants are distinct strings.
	 *
	 * @since x.x.x
	 */
	public function test_metric_constants_are_distinct(): void {
		$metrics = array( Vector_Math::METRIC_COSINE, Vector_Math::METRIC_DOT_PRODUCT, Vector_Math::METRIC_EUCLIDEAN );

		$this->assertCount( 3, array_unique( $metrics ) );
		foreach ( $metrics as $metric ) {
			$this->assertIsString( $metric );
			$this->assertNotSame( '', $metric );
		}
	}

	/**
	 * Tests that normalize() returns a unit vector pointing the same way as the input.
	 *
	 * @since x.x.x
	 */
	public function test_normalize_returns_unit_vector_in_same_direction(): void {
		$vector = array( 3.0, 4.0 );
		$unit   = Vector_Math::normalize( $vector );

		$this->assertSame( array( 0.6, 0.8 ), $unit );
		$this->assertEqualsWithDelta( 1.0, Vector_Math::norm( $unit ), 1.0e-9 );
		$this->assertEqualsWithDelta( 1.0, Vector_Math::cosine_similarity( $vector, $unit ), 1.0e-9 );
	}

	/**
	 * Tests that normalize() returns floats for integer input and preserves length.
	 *
	 * @since x.x.x
	 */
	public function test_normalize_returns_float_list(): void {
		$unit = Vector_Math::normalize( array( 2, 0, 0 ) );

		$this->assertSame( array( 1.0, 0.0, 0.0 ), $unit );
		$this->assertCount( 3, $unit );
	}

	/**
	 * Tests that normalize() refuses a zero vector, which has no direction.
	 *
	 * @since x.x.x
	 */
	public function test_normalize_rejects_zero_vector(): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Math::normalize( array( 0, 0, 0 ) );
	}

	/**
	 * Tests that cosine on raw vectors equals the dot product of their unit forms.
	 *
	 * This is the identity the ranking fast path relies on: normalize once, then dot.
	 *
	 * @since x.x.x
	 */
	public function test_cosine_equals_dot_product_of_normalized_vectors(): void {
		$a = array( 1.0, -2.0, 3.5, 0.25 );
		$b = array( -0.5, 4.0, 1.0, 2.0 );

		$this->assertEqualsWithDelta(
			Vector_Math::cosine_similarity( $a, $b ),
			Vector_Math::dot_product( Vector_Math::normalize( $a ), Vector_Math::normalize( $b ) ),
			1.0e-9
		);
	}

	/**
	 * Tests the centroid is the component-wise mean.
	 *
	 * @since x.x.x
	 */
	public function test_centroid_is_component_wise_mean(): void {
		$centroid = Vector_Math::centroid(
			array(
				array( 0.0, 0.0 ),
				array( 2.0, 4.0 ),
				array( 4.0, 2.0 ),
			)
		);

		$this->assertSame( array( 2.0, 2.0 ), $centroid );
	}

	/**
	 * Tests that the centroid of one vector is that vector, as floats.
	 *
	 * @since x.x.x
	 */
	public function test_centroid_of_single_vector_is_itself(): void {
		$this->assertSame( array( 1.0, 2.0, 3.0 ), Vector_Math::centroid( array( array( 1, 2, 3 ) ) ) );
	}

	/**
	 * Tests that the centroid accepts string-keyed input (e.g. vectors keyed by label or post ID).
	 *
	 * @since x.x.x
	 */
	public function test_centroid_ignores_outer_keys(): void {
		$centroid = Vector_Math::centroid(
			array(
				'first'  => array( 1.0, 1.0 ),
				'second' => array( 3.0, 3.0 ),
			)
		);

		$this->assertSame( array( 2.0, 2.0 ), $centroid );
	}

	/**
	 * Tests that the centroid rejects an empty set.
	 *
	 * @since x.x.x
	 */
	public function test_centroid_rejects_empty_set(): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Math::centroid( array() );
	}

	/**
	 * Tests that the centroid rejects vectors of differing lengths.
	 *
	 * @since x.x.x
	 */
	public function test_centroid_rejects_mismatched_dimensions(): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Math::centroid( array( array( 1.0, 2.0 ), array( 1.0, 2.0, 3.0 ) ) );
	}

	/**
	 * Tests that the centroid rejects a member that is not a vector.
	 *
	 * @since x.x.x
	 */
	public function test_centroid_rejects_non_array_member(): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Math::centroid( array( array( 1.0, 2.0 ), 'not a vector' ) );
	}
}

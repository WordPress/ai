<?php
/**
 * Tests for the embedding vector codec.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings;

use InvalidArgumentException;
use WP_UnitTestCase;
use WordPress\AI\Embeddings\Vector_Codec;

/**
 * Vector_Codec test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Vector_Codec
 */
class Vector_CodecTest extends WP_UnitTestCase {

	/**
	 * Tests that packing produces four bytes per component.
	 *
	 * @since x.x.x
	 */
	public function test_pack_uses_four_bytes_per_component(): void {
		$packed = Vector_Codec::pack( array( 0.1, -0.2, 0.3 ) );

		$this->assertSame( 3 * Vector_Codec::BYTES_PER_COMPONENT, strlen( $packed ) );
	}

	/**
	 * Tests that packing is little-endian float32, the layout MariaDB's VECTOR type uses.
	 *
	 * @since x.x.x
	 */
	public function test_pack_is_little_endian_float32(): void {
		$this->assertSame( "\x00\x00\x80\x3f", Vector_Codec::pack( array( 1.0 ) ) );
		$this->assertSame( "\x00\x00\x00\xc0", Vector_Codec::pack( array( -2 ) ) );
	}

	/**
	 * Tests that a vector survives a round trip within float32 precision.
	 *
	 * @since x.x.x
	 */
	public function test_round_trip_preserves_values_within_float32_precision(): void {
		$vector = array( 0.123456789, -0.987654321, 42.0, 1.0e-5, 0 );

		$decoded = Vector_Codec::unpack( Vector_Codec::pack( $vector ), count( $vector ) );

		$this->assertCount( count( $vector ), $decoded );
		foreach ( $vector as $index => $value ) {
			$this->assertIsFloat( $decoded[ $index ] );
			$this->assertEqualsWithDelta( (float) $value, $decoded[ $index ], 1.0e-6 );
		}
	}

	/**
	 * Tests that unpacking rejects a byte string of the wrong length.
	 *
	 * @since x.x.x
	 */
	public function test_unpack_rejects_wrong_length(): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Codec::unpack( Vector_Codec::pack( array( 1.0, 2.0 ) ), 3 );
	}

	/**
	 * Tests that unpacking rejects non-positive dimensions.
	 *
	 * @since x.x.x
	 */
	public function test_unpack_rejects_zero_dimensions(): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Codec::unpack( '', 0 );
	}

	/**
	 * Tests the norm calculation.
	 *
	 * @since x.x.x
	 */
	public function test_norm(): void {
		$this->assertEqualsWithDelta( 5.0, Vector_Codec::norm( array( 3, 4 ) ), 1.0e-9 );
		$this->assertEqualsWithDelta( 0.0, Vector_Codec::norm( array( 0.0, 0.0 ) ), 1.0e-9 );
	}

	/**
	 * Tests that validation rejects values that are not usable vectors.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_invalid_vectors
	 *
	 * @param mixed $vector The candidate vector.
	 */
	public function test_validate_rejects_invalid_vectors( $vector ): void {
		$this->expectException( InvalidArgumentException::class );

		Vector_Codec::validate( $vector );
	}

	/**
	 * Provides values that are not usable vectors.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: mixed}> Test cases.
	 */
	public function data_invalid_vectors(): array {
		return array(
			'not an array'   => array( 'abc' ),
			'empty'          => array( array() ),
			'associative'    => array( array( 'x' => 1.0 ) ),
			'non-sequential' => array(
				array(
					1 => 1.0,
					2 => 2.0,
				),
			),
			'string value'   => array( array( 1.0, '2' ) ),
			'null value'     => array( array( 1.0, null ) ),
			'NAN'            => array( array( 1.0, NAN ) ),
			'INF'            => array( array( INF ) ),
		);
	}

	/**
	 * Tests that validation accepts integer and float components.
	 *
	 * @since x.x.x
	 */
	public function test_validate_accepts_numeric_list(): void {
		Vector_Codec::validate( array( 1, 2.5, -3 ) );

		$this->assertTrue( true );
	}

	/**
	 * Tests that values beyond float32 range are rejected rather than packed to INF.
	 *
	 * These are finite as PHP float64 values, so an `is_finite()` check passes them, but they
	 * round to `INF` on the way into four bytes. The write used to report success and the row was
	 * then unreadable on every subsequent read.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_out_of_float32_range
	 *
	 * @param int|float $value The offending component.
	 */
	public function test_validate_rejects_values_outside_float32_range( $value ): void {
		$this->assertTrue( is_finite( (float) $value ), 'The fixture must be finite as a float64.' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'outside the range representable as float32' );

		Vector_Codec::validate( array( 0.1, $value ) );
	}

	/**
	 * Data provider for out-of-range components.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{int|float}> Test cases.
	 */
	public function data_out_of_float32_range(): array {
		return array(
			'just above float32 max' => array( 3.5e38 ),
			'just below float32 min' => array( -3.5e38 ),
			'far above'              => array( 1.0e300 ),
			'far below'              => array( -1.0e300 ),
		);
	}

	/**
	 * Tests that the float32 boundary itself is still accepted and round-trips.
	 *
	 * @since x.x.x
	 */
	public function test_float32_boundary_is_accepted_and_round_trips(): void {
		$vector = array( Vector_Codec::MAX_MAGNITUDE, -Vector_Codec::MAX_MAGNITUDE );

		$unpacked = Vector_Codec::unpack( Vector_Codec::pack( $vector ), 2 );

		$this->assertTrue( is_finite( $unpacked[0] ), 'The boundary value must not pack to INF.' );
		$this->assertTrue( is_finite( $unpacked[1] ), 'The boundary value must not pack to INF.' );
	}

	/**
	 * Tests that a rejected vector never reaches the packed representation.
	 *
	 * @since x.x.x
	 */
	public function test_pack_rejects_values_outside_float32_range(): void {
		$this->expectException( \InvalidArgumentException::class );

		Vector_Codec::pack( array( 1.0e300 ) );
	}

	/**
	 * Tests that a coarse code is one bit per component, most significant bit first.
	 *
	 * @since x.x.x
	 */
	public function test_pack_coarse_uses_one_bit_per_component(): void {
		// Signs positive, negative, positive, negative, zero, negative, negative, positive give
		// the bit pattern 10100001, which is 0xA1.
		$code = Vector_Codec::pack_coarse( array( 0.5, -0.5, 2.0, -2.0, 0.0, -0.1, -9.0, 0.001 ) );

		$this->assertSame( 1, strlen( $code ) );
		$this->assertSame( 'a1', bin2hex( $code ) );
	}

	/**
	 * Tests that the tail of the final byte is zero-padded.
	 *
	 * @since x.x.x
	 */
	public function test_pack_coarse_zero_pads_the_final_byte(): void {
		// Three positive components fill the top three bits and pad the rest, giving 0xE0.
		$this->assertSame( 'e0', bin2hex( Vector_Codec::pack_coarse( array( 1.0, 1.0, 1.0 ) ) ) );

		// Nine components spill into a second byte whose single bit is the high one.
		$code = Vector_Codec::pack_coarse( array_fill( 0, 9, 1.0 ) );
		$this->assertSame( 2, strlen( $code ) );
		$this->assertSame( 'ff80', bin2hex( $code ) );
	}

	/**
	 * Tests the coarse code size for realistic embedding dimensions.
	 *
	 * The 32x reduction against the float32 form is the entire reason the column exists, and the
	 * result has to fit the column that keeps it inline in the clustered index.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_realistic_dimensions
	 *
	 * @param int $dimensions   Vector dimensions.
	 * @param int $coarse_bytes Expected coarse code size in bytes.
	 */
	public function test_pack_coarse_is_thirty_two_times_smaller( int $dimensions, int $coarse_bytes ): void {
		$vector = array_fill( 0, $dimensions, 0.1 );

		$this->assertSame( $coarse_bytes, strlen( Vector_Codec::pack_coarse( $vector ) ) );
		$this->assertSame( $dimensions * 4, strlen( Vector_Codec::pack( $vector ) ) );
		$this->assertLessThanOrEqual( Vector_Codec::MAX_COARSE_BYTES, $coarse_bytes );
	}

	/**
	 * Data provider for realistic embedding dimensions.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{int, int}> Test cases.
	 */
	public function data_realistic_dimensions(): array {
		return array(
			'768 dims'  => array( 768, 96 ),
			'1536 dims' => array( 1536, 192 ),
			'3072 dims' => array( 3072, 384 ),
			'4096 dims' => array( 4096, 512 ),
		);
	}

	/**
	 * Tests the Hamming distance between codes.
	 *
	 * @since x.x.x
	 */
	public function test_hamming(): void {
		$a = Vector_Codec::pack_coarse( array( 1.0, 1.0, 1.0, 1.0 ) );
		$b = Vector_Codec::pack_coarse( array( 1.0, 1.0, 1.0, 1.0 ) );
		$c = Vector_Codec::pack_coarse( array( -1.0, -1.0, -1.0, -1.0 ) );
		$d = Vector_Codec::pack_coarse( array( 1.0, -1.0, 1.0, 1.0 ) );

		$this->assertSame( 0, Vector_Codec::hamming( $a, $b ) );
		$this->assertSame( 4, Vector_Codec::hamming( $a, $c ) );
		$this->assertSame( 1, Vector_Codec::hamming( $a, $d ) );
	}

	/**
	 * Tests that Hamming distance rejects mismatched or empty codes.
	 *
	 * `^` on two strings truncates to the shorter operand, so an unchecked mismatch would
	 * understate the distance instead of failing.
	 *
	 * @since x.x.x
	 */
	public function test_hamming_rejects_mismatched_lengths(): void {
		$this->expectException( \InvalidArgumentException::class );

		Vector_Codec::hamming(
			Vector_Codec::pack_coarse( array_fill( 0, 16, 1.0 ) ),
			Vector_Codec::pack_coarse( array_fill( 0, 8, 1.0 ) )
		);
	}

	/**
	 * Tests that the coarse code preserves the ordering exact cosine would produce.
	 *
	 * This is the property the two-phase search depends on: the shortlist has to actually contain
	 * the nearest vectors. Packing the right number of bytes is worth nothing if the distances it
	 * yields do not track the exact scores.
	 *
	 * @since x.x.x
	 */
	public function test_hamming_ranking_tracks_cosine_ranking(): void {
		$dimensions = 256;

		// Deterministic rather than seeded-random: the test has to be reproducible, and cos() over an
		// irrational step gives a well-spread mix of signs and magnitudes with no component at zero,
		// which matters because flipping the sign of a zero would not change the code.
		$query = array();
		for ( $i = 0; $i < $dimensions; $i++ ) {
			$query[] = cos( $i * 1.7 );
		}

		$this->assertNotContains( 0.0, $query, 'No component may be zero for a sign flip to be observable.' );

		// Build candidates that are progressively less like the query by flipping the sign of an
		// increasing share of its components.
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

		// Distance must rise monotonically with the number of flipped components.
		$this->assertSame( 0, $distances[0] );
		$this->assertSame( 16, $distances[16] );
		$this->assertSame( 64, $distances[64] );
		$this->assertSame( 128, $distances[128] );

		// And the ordering must match what exact cosine gives.
		$cosines = array();
		foreach ( $candidates as $flips => $candidate ) {
			$dot = 0.0;
			foreach ( $query as $index => $value ) {
				$dot += $value * $candidate[ $index ];
			}
			$cosines[ $flips ] = $dot / ( Vector_Codec::norm( $query ) * Vector_Codec::norm( $candidate ) );
		}

		arsort( $cosines );
		asort( $distances );

		$this->assertSame( array_keys( $cosines ), array_keys( $distances ) );
	}

	/**
	 * Tests that a coarse code can be recomputed from the stored float32 bytes.
	 *
	 * The code is a disposable index rather than a second copy of the data, so it has to be
	 * derivable from the blob — that is what makes it safe to add, drop or rebuild.
	 *
	 * @since x.x.x
	 */
	public function test_coarse_code_is_derivable_from_the_packed_vector(): void {
		$vector = array( 0.25, -0.75, 1.5, -0.001, 0.0, 3.0, -2.5, 0.125 );

		$this->assertSame(
			bin2hex( Vector_Codec::pack_coarse( $vector ) ),
			bin2hex( Vector_Codec::pack_coarse( Vector_Codec::unpack( Vector_Codec::pack( $vector ), 8 ) ) )
		);
	}
}

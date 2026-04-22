<?php
/**
 * Tests for the CBOR_Encoder class.
 *
 * Test vectors from RFC 8949 Appendix A and CTAP2 canonical CBOR.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\C2PA\CBOR_Encoder;

/**
 * CBOR_Encoder test case.
 *
 * @since 0.7.0
 */
class CBOR_EncoderTest extends WP_UnitTestCase {

	/**
	 * Test encoding unsigned integer 0.
	 */
	public function test_encode_unsigned_int_zero(): void {
		$this->assertSame( "\x00", CBOR_Encoder::encode( 0 ) );
	}

	/**
	 * Test encoding unsigned integer 1.
	 */
	public function test_encode_unsigned_int_one(): void {
		$this->assertSame( "\x01", CBOR_Encoder::encode( 1 ) );
	}

	/**
	 * Test encoding unsigned integer 23 (max single-byte).
	 */
	public function test_encode_unsigned_int_23(): void {
		$this->assertSame( "\x17", CBOR_Encoder::encode( 23 ) );
	}

	/**
	 * Test encoding unsigned integer 24 (first two-byte).
	 */
	public function test_encode_unsigned_int_24(): void {
		$this->assertSame( "\x18\x18", CBOR_Encoder::encode( 24 ) );
	}

	/**
	 * Test encoding unsigned integer 255.
	 */
	public function test_encode_unsigned_int_255(): void {
		$this->assertSame( "\x18\xff", CBOR_Encoder::encode( 255 ) );
	}

	/**
	 * Test encoding unsigned integer 256 (first three-byte).
	 */
	public function test_encode_unsigned_int_256(): void {
		$this->assertSame( "\x19\x01\x00", CBOR_Encoder::encode( 256 ) );
	}

	/**
	 * Test encoding unsigned integer 65535.
	 */
	public function test_encode_unsigned_int_65535(): void {
		$this->assertSame( "\x19\xff\xff", CBOR_Encoder::encode( 65535 ) );
	}

	/**
	 * Test encoding unsigned integer 65536 (first five-byte).
	 */
	public function test_encode_unsigned_int_65536(): void {
		$this->assertSame( "\x1a\x00\x01\x00\x00", CBOR_Encoder::encode( 65536 ) );
	}

	/**
	 * Test encoding negative integer -1.
	 */
	public function test_encode_negative_int_minus_1(): void {
		$this->assertSame( "\x20", CBOR_Encoder::encode( -1 ) );
	}

	/**
	 * Test encoding negative integer -7 (ES256 algorithm identifier).
	 */
	public function test_encode_negative_int_minus_7(): void {
		$this->assertSame( "\x26", CBOR_Encoder::encode( -7 ) );
	}

	/**
	 * Test encoding negative integer -24.
	 */
	public function test_encode_negative_int_minus_24(): void {
		$this->assertSame( "\x37", CBOR_Encoder::encode( -24 ) );
	}

	/**
	 * Test encoding negative integer -25 (first two-byte negative).
	 */
	public function test_encode_negative_int_minus_25(): void {
		$this->assertSame( "\x38\x18", CBOR_Encoder::encode( -25 ) );
	}

	/**
	 * Test encoding negative integer -100.
	 */
	public function test_encode_negative_int_minus_100(): void {
		$this->assertSame( "\x38\x63", CBOR_Encoder::encode( -100 ) );
	}

	/**
	 * Test encoding empty byte string.
	 */
	public function test_encode_empty_byte_string(): void {
		$this->assertSame( "\x40", CBOR_Encoder::encode_byte_string( '' ) );
	}

	/**
	 * Test encoding 4-byte byte string.
	 */
	public function test_encode_byte_string_4_bytes(): void {
		$this->assertSame( "\x44\x01\x02\x03\x04", CBOR_Encoder::encode_byte_string( "\x01\x02\x03\x04" ) );
	}

	/**
	 * Test encoding empty text string.
	 */
	public function test_encode_empty_text_string(): void {
		$this->assertSame( "\x60", CBOR_Encoder::encode( '' ) );
	}

	/**
	 * Test encoding text string "IETF".
	 */
	public function test_encode_text_string_ietf(): void {
		$this->assertSame( "\x64IETF", CBOR_Encoder::encode( 'IETF' ) );
	}

	/**
	 * Test encoding text string "Signature1" (COSE context string).
	 */
	public function test_encode_text_string_signature1(): void {
		$expected = "\x6a" . 'Signature1';
		$this->assertSame( $expected, CBOR_Encoder::encode( 'Signature1' ) );
	}

	/**
	 * Test encoding empty array.
	 */
	public function test_encode_empty_array(): void {
		$this->assertSame( "\x80", CBOR_Encoder::encode( array() ) );
	}

	/**
	 * Test encoding array [1, 2, 3].
	 */
	public function test_encode_array_1_2_3(): void {
		$this->assertSame( "\x83\x01\x02\x03", CBOR_Encoder::encode( array( 1, 2, 3 ) ) );
	}

	/**
	 * Test encoding nested array [1, [2, 3]].
	 */
	public function test_encode_nested_array(): void {
		$expected = "\x82\x01\x82\x02\x03";
		$this->assertSame( $expected, CBOR_Encoder::encode( array( 1, array( 2, 3 ) ) ) );
	}

	/**
	 * Test encoding map {1: -7} (COSE ES256 protected header).
	 */
	public function test_encode_map_cose_protected_header(): void {
		$map = new \stdClass();
		$map->{1} = -7;
		$this->assertSame( "\xa1\x01\x26", CBOR_Encoder::encode_map( array( 1 => -7 ) ) );
	}

	/**
	 * Test CTAP2 canonical ordering: integer keys sorted by encoded length.
	 * Key 1 (encoded as 0x01, 1 byte) sorts before key 33 (encoded as 0x1821, 2 bytes).
	 */
	public function test_encode_map_canonical_ordering(): void {
		// Input with keys in non-canonical order.
		$map = array(
			33 => CBOR_Encoder::encode_byte_string( 'cert' ),
			1  => -7,
		);

		$result = CBOR_Encoder::encode_map( $map );

		// Key 1 must come first (shorter encoding).
		$this->assertSame( "\xa2", $result[0], 'Map header for 2 entries.' );
		$this->assertSame( "\x01", $result[1], 'First key should be 1 (shorter encoding).' );
	}

	/**
	 * Test encoding boolean true.
	 */
	public function test_encode_true(): void {
		$this->assertSame( "\xf5", CBOR_Encoder::encode( true ) );
	}

	/**
	 * Test encoding boolean false.
	 */
	public function test_encode_false(): void {
		$this->assertSame( "\xf4", CBOR_Encoder::encode( false ) );
	}

	/**
	 * Test encoding null.
	 */
	public function test_encode_null(): void {
		$this->assertSame( "\xf6", CBOR_Encoder::encode( null ) );
	}

	/**
	 * Test encoding tagged value (tag 18 = COSE_Sign1).
	 */
	public function test_encode_tagged_cose_sign1(): void {
		// Tag 18 wrapping an empty array.
		$tagged = CBOR_Encoder::encode_tagged( 18, array() );
		$this->assertSame( "\xd2\x80", $tagged );
	}

	/**
	 * Test encoding a complete COSE_Sign1 protected header map.
	 * {1: -7} where 1=alg, -7=ES256.
	 */
	public function test_encode_cose_es256_protected_header_bytes(): void {
		$protected = CBOR_Encoder::encode_map( array( 1 => -7 ) );
		$this->assertSame( "\xa1\x01\x26", $protected );
	}

	/**
	 * Test that sequential integer-keyed arrays encode as CBOR arrays, not maps.
	 */
	public function test_sequential_array_encodes_as_array_not_map(): void {
		$arr    = array( 'a', 'b', 'c' );
		$result = CBOR_Encoder::encode( $arr );
		// Should start with array header 0x83 (3 items), not map header 0xa3.
		$this->assertSame( "\x83", $result[0], 'Sequential arrays must encode as CBOR arrays.' );
	}

	/**
	 * Test that string-keyed arrays encode as CBOR maps.
	 */
	public function test_string_keyed_array_encodes_as_map(): void {
		$map    = array( 'key' => 'value' );
		$result = CBOR_Encoder::encode( $map );
		// Should start with map header 0xa1 (1 entry).
		$this->assertSame( "\xa1", $result[0], 'String-keyed arrays must encode as CBOR maps.' );
	}

	/**
	 * Test encoding large text string (>23 bytes, requires 2-byte length).
	 */
	public function test_encode_long_text_string(): void {
		$text   = str_repeat( 'A', 30 );
		$result = CBOR_Encoder::encode( $text );
		// Major type 3 with 1-byte length: 0x78 0x1e.
		$this->assertSame( "\x78\x1e", substr( $result, 0, 2 ) );
		$this->assertSame( $text, substr( $result, 2 ) );
	}

}

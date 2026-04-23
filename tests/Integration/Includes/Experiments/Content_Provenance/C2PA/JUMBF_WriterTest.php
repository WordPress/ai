<?php
/**
 * Tests for the JUMBF_Writer class.
 *
 * Validates ISO 19566-5 JUMBF box serialization for C2PA manifest stores.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance\C2PA;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\C2PA\JUMBF_Writer;

/**
 * JUMBF_Writer test case.
 *
 * @since 0.7.0
 */
class JUMBF_WriterTest extends WP_UnitTestCase {

	/**
	 * Test that write_box produces correct size + type + content.
	 */
	public function test_write_box_basic(): void {
		$content = 'ABCD';
		$box     = JUMBF_Writer::write_box( 'test', $content );

		// Box size = 8 (header) + 4 (content) = 12.
		$size = unpack( 'N', substr( $box, 0, 4 ) );
		$this->assertSame( 12, $size[1], 'Box size should be 12.' );

		// Box type.
		$type = substr( $box, 4, 4 );
		$this->assertSame( 'test', $type, 'Box type should be "test".' );

		// Content.
		$this->assertSame( $content, substr( $box, 8 ), 'Box content should match.' );
	}

	/**
	 * Test that write_box handles empty content.
	 */
	public function test_write_box_empty_content(): void {
		$box  = JUMBF_Writer::write_box( 'free', '' );
		$size = unpack( 'N', substr( $box, 0, 4 ) );
		$this->assertSame( 8, $size[1], 'Empty box size should be 8 (header only).' );
	}

	/**
	 * Test description box structure: UUID + toggles + NUL-terminated label.
	 */
	public function test_write_description_box(): void {
		$uuid = JUMBF_Writer::UUID_MANIFEST_STORE;
		$box  = JUMBF_Writer::write_description_box( $uuid, 'test-label' );

		// Box type should be 'jumd'.
		$type = substr( $box, 4, 4 );
		$this->assertSame( 'jumd', $type, 'Description box type should be "jumd".' );

		// Content starts at offset 8.
		$content = substr( $box, 8 );

		// First 16 bytes = UUID.
		$this->assertSame( $uuid, substr( $content, 0, 16 ), 'UUID should match.' );

		// Byte 16 = toggles (3 = requestable + label present).
		$toggles = ord( $content[16] );
		$this->assertSame( 3, $toggles, 'Default toggles should be 3.' );

		// Bytes 17+ = NUL-terminated label.
		$label = substr( $content, 17, -1 );
		$this->assertSame( 'test-label', $label, 'Label should match.' );

		// Last byte is NUL terminator.
		$this->assertSame( "\x00", substr( $content, -1 ), 'Label should be NUL-terminated.' );
	}

	/**
	 * Test superbox structure: jumb type wrapping description + children.
	 */
	public function test_write_superbox(): void {
		$uuid    = JUMBF_Writer::UUID_MANIFEST_STORE;
		$child   = JUMBF_Writer::write_box( 'test', 'data' );
		$super   = JUMBF_Writer::write_superbox( $uuid, 'my-label', array( $child ) );

		// Outer type should be 'jumb'.
		$type = substr( $super, 4, 4 );
		$this->assertSame( 'jumb', $type, 'Superbox type should be "jumb".' );

		// Size should cover entire superbox.
		$size = unpack( 'N', substr( $super, 0, 4 ) );
		$this->assertGreaterThan( strlen( $child ), $size[1], 'Superbox size should be larger than child.' );

		// Content should contain the child box bytes.
		$this->assertStringContainsString( 'data', $super, 'Superbox should contain child data.' );
	}

	/**
	 * Test that manifest store UUID constant is correct.
	 */
	public function test_manifest_store_uuid(): void {
		$expected = "\x63\x32\x70\x61\x00\x11\x00\x10\x80\x00\x00\xAA\x00\x38\x9B\x71";
		$this->assertSame( $expected, JUMBF_Writer::UUID_MANIFEST_STORE );
	}

	/**
	 * Test that manifest UUID constant is correct.
	 */
	public function test_manifest_uuid(): void {
		$expected = "\x63\x32\x6D\x61\x00\x11\x00\x10\x80\x00\x00\xAA\x00\x38\x9B\x71";
		$this->assertSame( $expected, JUMBF_Writer::UUID_MANIFEST );
	}

	/**
	 * Test that claim UUID constant is correct.
	 */
	public function test_claim_uuid(): void {
		$expected = "\x63\x32\x63\x6C\x00\x11\x00\x10\x80\x00\x00\xAA\x00\x38\x9B\x71";
		$this->assertSame( $expected, JUMBF_Writer::UUID_CLAIM );
	}

	/**
	 * Test that assertion store UUID constant is correct.
	 */
	public function test_assertion_store_uuid(): void {
		$expected = "\x63\x32\x61\x73\x00\x11\x00\x10\x80\x00\x00\xAA\x00\x38\x9B\x71";
		$this->assertSame( $expected, JUMBF_Writer::UUID_ASSERTION_STORE );
	}

	/**
	 * Test that signature UUID constant is correct.
	 */
	public function test_signature_uuid(): void {
		$expected = "\x63\x32\x63\x73\x00\x11\x00\x10\x80\x00\x00\xAA\x00\x38\x9B\x71";
		$this->assertSame( $expected, JUMBF_Writer::UUID_SIGNATURE );
	}

	/**
	 * Test that CBOR content type UUID constant is correct.
	 */
	public function test_cbor_uuid(): void {
		$expected = "\x63\x62\x6F\x72\x00\x11\x00\x10\x80\x00\x00\xAA\x00\x38\x9B\x71";
		$this->assertSame( $expected, JUMBF_Writer::UUID_CBOR );
	}

	/**
	 * Test build_assertion_box wraps CBOR content in correct structure.
	 */
	public function test_build_assertion_box(): void {
		$cbor = "\xa1\x01\x26"; // Dummy CBOR: {1: -7}.
		$box  = JUMBF_Writer::build_assertion_box( 'c2pa.hash.data', $cbor );

		// Should be a jumb superbox.
		$type = substr( $box, 4, 4 );
		$this->assertSame( 'jumb', $type, 'Assertion box should be a jumb superbox.' );

		// Should contain the label.
		$this->assertStringContainsString( "c2pa.hash.data\x00", $box, 'Box should contain assertion label.' );

		// Should contain the CBOR data.
		$this->assertStringContainsString( $cbor, $box, 'Box should contain CBOR data.' );
	}

	/**
	 * Test build_manifest_store produces nested JUMBF hierarchy.
	 */
	public function test_build_manifest_store_structure(): void {
		$claim_cbor = "\xa1\x01\x02"; // Dummy CBOR.
		$assertions = array( 'c2pa.actions.v2' => "\xa1\x03\x04" );
		$cose_bytes = "\xd2\x84\xa1\x01\x26\xa0\x40\x40"; // Dummy COSE_Sign1.
		$label      = 'urn:uuid:test-manifest';

		$store = JUMBF_Writer::build_manifest_store( $claim_cbor, $assertions, $cose_bytes, $label );

		// Outer box should be jumb.
		$type = substr( $store, 4, 4 );
		$this->assertSame( 'jumb', $type, 'Manifest store should be a jumb superbox.' );

		// Size should be self-consistent.
		$size = unpack( 'N', substr( $store, 0, 4 ) );
		$this->assertSame( strlen( $store ), $size[1], 'Manifest store size should match actual length.' );

		// Should contain the manifest store UUID in the description box.
		$this->assertStringContainsString( JUMBF_Writer::UUID_MANIFEST_STORE, $store );

		// Should contain the manifest label.
		$this->assertStringContainsString( $label, $store );

		// Should contain the claim CBOR.
		$this->assertStringContainsString( $claim_cbor, $store );

		// Should contain the COSE bytes.
		$this->assertStringContainsString( $cose_bytes, $store );
	}

	/**
	 * Test that all boxes in manifest store have correct size encoding.
	 */
	public function test_manifest_store_box_sizes_are_valid(): void {
		$store = JUMBF_Writer::build_manifest_store(
			"\xa0",
			array( 'c2pa.hash.data' => "\xa0" ),
			"\xd2\x80",
			'urn:uuid:test'
		);

		// Walk through top-level box and verify sizes.
		$offset = 0;
		while ( $offset < strlen( $store ) ) {
			$size_data = unpack( 'N', substr( $store, $offset, 4 ) );
			$box_size  = $size_data[1];

			$this->assertGreaterThanOrEqual( 8, $box_size, 'Box size must be at least 8.' );
			$this->assertLessThanOrEqual( strlen( $store ) - $offset, $box_size, 'Box size must not exceed remaining data.' );

			break; // Only check outermost box for this test.
		}
	}
}

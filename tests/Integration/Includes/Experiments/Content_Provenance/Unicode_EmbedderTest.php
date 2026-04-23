<?php
/**
 * Integration tests for Unicode_Embedder edge cases.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Content_Provenance
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Content_Provenance;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Provenance\Unicode_Embedder;

/**
 * Unicode_Embedder edge-case test case.
 *
 * Complements the roundtrip tests in Content_ProvenanceTest by exercising
 * all error and boundary branches of extract() and strip().
 *
 * @since 0.5.0
 */
class Unicode_EmbedderTest extends WP_UnitTestCase {

	/**
	 * Test that embed() appends the wrapper after the original text.
	 *
	 * @since 0.5.0
	 */
	public function test_embed_appends_wrapper_to_text(): void {
		$original = 'Hello, world.';
		$embedded = Unicode_Embedder::embed( $original, '{"data":1}' );

		// The embedded string must start with the original text.
		$this->assertStringStartsWith( $original, $embedded );
		// The embedded string must be longer than the original.
		$this->assertGreaterThan( strlen( $original ), strlen( $embedded ) );
	}

	/**
	 * Test that embed() includes the U+FEFF prefix in the wrapper.
	 *
	 * @since 0.5.0
	 */
	public function test_embed_contains_feff_marker(): void {
		$embedded = Unicode_Embedder::embed( 'Text.', '{"x":1}' );
		$this->assertStringContainsString( Unicode_Embedder::PREFIX, $embedded );
	}

	/**
	 * Test that extract() returns null when no U+FEFF marker is present.
	 *
	 * @since 0.5.0
	 */
	public function test_extract_returns_null_for_no_feff_marker(): void {
		$this->assertNull( Unicode_Embedder::extract( 'Plain text, no marker.' ) );
	}

	/**
	 * Test that extract() returns null when U+FEFF is present but not followed
	 * by variation-selector bytes (fewer than HEADER_SIZE decoded bytes).
	 *
	 * @since 0.5.0
	 */
	public function test_extract_returns_null_for_feff_without_vs_bytes(): void {
		// U+FEFF followed by plain ASCII — no variation selectors.
		$text = 'Hello' . Unicode_Embedder::PREFIX . 'World';
		$this->assertNull( Unicode_Embedder::extract( $text ) );
	}

	/**
	 * Test that extract() returns null when fewer than HEADER_SIZE (13) bytes
	 * are encoded after the U+FEFF marker.
	 *
	 * @since 0.5.0
	 */
	public function test_extract_returns_null_for_insufficient_bytes(): void {
		// Only 5 VS bytes encoded — less than the 13-byte header requirement.
		$bytes = array( 0, 1, 2, 3, 4 );
		$text  = 'Hello' . $this->build_vs_string( $bytes );
		$this->assertNull( Unicode_Embedder::extract( $text ) );
	}

	/**
	 * Test that extract() returns null when magic bytes are wrong.
	 *
	 * @since 0.5.0
	 */
	public function test_extract_returns_null_for_wrong_magic(): void {
		// Header with all-zero magic (should be C2PATXT\0 = 0x43,0x32,0x50,0x41,0x54,0x58,0x54,0x00).
		$bytes = array_merge(
			array_fill( 0, 8, 0 ),         // wrong magic: all zeros
			array( 1 ),                    // version = 1
			array( 0, 0, 0, 5 ),           // length = 5
			array( 65, 66, 67, 68, 69 )    // payload: 'ABCDE'
		);
		$text  = 'Hello' . $this->build_vs_string( $bytes );
		$this->assertNull( Unicode_Embedder::extract( $text ) );
	}

	/**
	 * Test that extract() returns null when the version byte is wrong.
	 *
	 * @since 0.5.0
	 */
	public function test_extract_returns_null_for_wrong_version(): void {
		// Correct magic, but version = 2 (spec requires version = 1).
		$bytes = array_merge(
			$this->get_magic_bytes(),      // correct C2PATXT\0 magic
			array( 2 ),                    // wrong version
			array( 0, 0, 0, 5 ),           // length = 5
			array( 65, 66, 67, 68, 69 )    // payload
		);
		$text  = 'Hello' . $this->build_vs_string( $bytes );
		$this->assertNull( Unicode_Embedder::extract( $text ) );
	}

	/**
	 * Test that extract() returns null when manifest length is zero.
	 *
	 * @since 0.5.0
	 */
	public function test_extract_returns_null_for_zero_length_manifest(): void {
		// embed() with an empty manifest string produces a wrapper with length=0.
		$embedded = Unicode_Embedder::embed( 'Hello', '' );
		$this->assertNull( Unicode_Embedder::extract( $embedded ) );
	}

	/**
	 * Test that strip() leaves plain text without selectors unchanged.
	 *
	 * @since 0.5.0
	 */
	public function test_strip_leaves_plain_text_unchanged(): void {
		$this->assertSame( 'Plain text.', Unicode_Embedder::strip( 'Plain text.' ) );
	}

	/**
	 * Test that strip() removes the U+FEFF byte from text.
	 *
	 * @since 0.5.0
	 */
	public function test_strip_removes_feff_marker(): void {
		// Text with a bare U+FEFF marker and no VS selectors.
		$text     = 'Hello' . Unicode_Embedder::PREFIX . 'World';
		$stripped = Unicode_Embedder::strip( $text );
		$this->assertSame( 'HelloWorld', $stripped );
	}

	/**
	 * Test that strip() removes VS1–VS16 (U+FE00–U+FE0F) selectors.
	 *
	 * @since 0.5.0
	 */
	public function test_strip_removes_vs1_to_vs16_selectors(): void {
		// Manually construct a string with a VS1 selector (U+FE00 = EF B8 80).
		$text     = 'Hello' . "\xEF\xB8\x80" . 'World';
		$stripped = Unicode_Embedder::strip( $text );
		$this->assertSame( 'HelloWorld', $stripped );
	}

	/**
	 * Test that strip() removes VS17–VS256 (U+E0100–U+E01EF) selectors.
	 *
	 * @since 0.5.0
	 */
	public function test_strip_removes_vs17_to_vs256_selectors(): void {
		// U+E0100 = F3 A0 84 80 (first VS17 selector).
		$text     = 'Hello' . "\xF3\xA0\x84\x80" . 'World';
		$stripped = Unicode_Embedder::strip( $text );
		$this->assertSame( 'HelloWorld', $stripped );
	}

	/**
	 * Test that compute_wrapper_byte_length returns correct count for empty manifest.
	 *
	 * @since x.x.x
	 */
	public function test_compute_wrapper_byte_length_empty_manifest(): void {
		$length = Unicode_Embedder::compute_wrapper_byte_length( '' );

		// BOM: 3 bytes.
		// Header bytes: C(0x43) 2(0x32) P(0x50) A(0x41) T(0x54) X(0x58) T(0x54) \0(0x00) = 7*4 + 1*3 = 31.
		// Version 0x01: 3 bytes. Length 0x00000000: 4*3 = 12.
		// Total: 3 + 31 + 3 + 12 = 49.
		$this->assertSame( 49, $length );
	}

	/**
	 * Test that compute_wrapper_byte_length matches actual embed() output size.
	 *
	 * @since x.x.x
	 */
	public function test_compute_wrapper_byte_length_matches_embed(): void {
		$content  = 'Hello, World!';
		$manifest = str_repeat( "\xFF", 50 );

		$embedded       = Unicode_Embedder::embed( $content, $manifest );
		$actual_wrapper = strlen( $embedded ) - strlen( $content );
		$computed       = Unicode_Embedder::compute_wrapper_byte_length( $manifest );

		$this->assertSame( $actual_wrapper, $computed );
	}

	/**
	 * Test that compute_wrapper_byte_length handles mixed byte values correctly.
	 *
	 * @since x.x.x
	 */
	public function test_compute_wrapper_byte_length_mixed_bytes(): void {
		// Manifest with bytes spanning both VS ranges (< 16 and >= 16).
		$manifest = '';
		for ( $i = 0; $i < 32; $i++ ) {
			$manifest .= chr( $i * 8 ); // 0, 8, 16, 24, ..., 248.
		}

		$embedded       = Unicode_Embedder::embed( 'Test.', $manifest );
		$actual_wrapper = strlen( $embedded ) - strlen( 'Test.' );
		$computed       = Unicode_Embedder::compute_wrapper_byte_length( $manifest );

		$this->assertSame( $actual_wrapper, $computed );
	}

	/**
	 * Build a VS-encoded string with U+FEFF prefix from raw byte values.
	 *
	 * Mirrors the encoding logic in Unicode_Embedder::embed() for test construction.
	 *
	 * @since 0.5.0
	 *
	 * @param array<int> $bytes Raw byte values (0–255).
	 * @return string U+FEFF prefix followed by VS-encoded bytes.
	 */
	private function build_vs_string( array $bytes ): string {
		$result = Unicode_Embedder::PREFIX;
		foreach ( $bytes as $byte ) {
			if ( $byte < 16 ) {
				$result .= "\xEF\xB8" . chr( 0x80 + $byte );
			} else {
				$n       = $byte - 16;
				$result .= "\xF3\xA0" . chr( 0x84 + intdiv( $n, 64 ) ) . chr( 0x80 + ( $n % 64 ) );
			}
		}
		return $result;
	}

	/**
	 * Return the C2PA wrapper magic bytes as an integer array.
	 *
	 * @since 0.5.0
	 * @return array<int>
	 */
	private function get_magic_bytes(): array {
		$unpacked = unpack( 'C*', "\x43\x32\x50\x41\x54\x58\x54\x00" );
		return array_values( $unpacked ? $unpacked : array() );
	}
}

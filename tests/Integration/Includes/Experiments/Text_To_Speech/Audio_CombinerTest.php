<?php
/**
 * Integration tests for the Audio_Combiner class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Text_To_Speech\Audio_Combiner;

/**
 * Audio_Combiner test case.
 *
 * @since x.x.x
 */
class Audio_CombinerTest extends WP_UnitTestCase {

	/**
	 * Builds a fake ID3v2 header followed by the given payload.
	 *
	 * The ID3v2 header is 10 bytes: "ID3", version (2), flags (1), and a
	 * 4-byte syncsafe size describing the tag body length that follows.
	 *
	 * @since x.x.x
	 *
	 * @param string $payload  The audio payload that follows the tag.
	 * @param int    $tag_size The tag body size in bytes.
	 * @return string The full byte string.
	 */
	private function with_id3v2( string $payload, int $tag_size = 20 ): string {
		$header = 'ID3' . "\x04\x00" . "\x00" . self::syncsafe( $tag_size );

		return $header . str_repeat( "\x00", $tag_size ) . $payload;
	}

	/**
	 * Encodes an integer as a 4-byte syncsafe integer.
	 *
	 * @since x.x.x
	 *
	 * @param int $value The value to encode.
	 * @return string The 4 syncsafe bytes.
	 */
	private static function syncsafe( int $value ): string {
		return chr( ( $value >> 21 ) & 0x7F ) . chr( ( $value >> 14 ) & 0x7F ) . chr( ( $value >> 7 ) & 0x7F ) . chr( $value & 0x7F );
	}

	/**
	 * Test that strip_id3v2 removes a leading ID3v2 tag.
	 *
	 * @since x.x.x
	 */
	public function test_strip_id3v2_removes_leading_tag(): void {
		$payload = "\xFF\xFBAUDIOFRAMES";

		$this->assertSame( $payload, Audio_Combiner::strip_id3v2( $this->with_id3v2( $payload ) ) );
	}

	/**
	 * Test that strip_id3v2 leaves untagged bytes alone.
	 *
	 * @since x.x.x
	 */
	public function test_strip_id3v2_ignores_untagged_bytes(): void {
		$payload = "\xFF\xFBAUDIOFRAMES";

		$this->assertSame( $payload, Audio_Combiner::strip_id3v2( $payload ) );
	}

	/**
	 * Test that strip_id3v1 removes a trailing 128-byte TAG block.
	 *
	 * @since x.x.x
	 */
	public function test_strip_id3v1_removes_trailing_tag(): void {
		$payload = str_repeat( "\xFF\xFB", 100 );
		$tagged  = $payload . 'TAG' . str_repeat( "\x00", 125 );

		$this->assertSame( $payload, Audio_Combiner::strip_id3v1( $tagged ) );
		$this->assertSame( $payload, Audio_Combiner::strip_id3v1( $payload ) );
	}

	/**
	 * Test that prepare_chunk applies the position-dependent strip rules.
	 *
	 * @since x.x.x
	 */
	public function test_prepare_chunk_strips_by_position(): void {
		$tagged = $this->with_id3v2( 'BODY' ) . 'TAG' . str_repeat( "\x00", 125 );

		// First chunk: keeps its ID3v2 header, loses its ID3v1 trailer.
		$this->assertSame( $this->with_id3v2( 'BODY' ), Audio_Combiner::prepare_chunk( $tagged, true, false ) );

		// Middle chunk: loses both.
		$this->assertSame( 'BODY', Audio_Combiner::prepare_chunk( $tagged, false, false ) );

		// Last chunk: loses only the leading ID3v2 header.
		$this->assertSame( 'BODY' . 'TAG' . str_repeat( "\x00", 125 ), Audio_Combiner::prepare_chunk( $tagged, false, true ) );
	}

	/**
	 * Test that append_chunk builds a combined file, stripping inner tags.
	 *
	 * @since x.x.x
	 */
	public function test_append_chunk_combines_stripped_chunks(): void {
		$file = wp_tempnam( 'wpai-tts-test' );

		$first  = $this->with_id3v2( 'FIRST' );
		$middle = $this->with_id3v2( 'MIDDLE' ) . 'TAG' . str_repeat( "\x00", 125 );
		$last   = $this->with_id3v2( 'LAST' );

		$this->assertTrue( Audio_Combiner::append_chunk( $file, $first, true, false ) );
		$this->assertTrue( Audio_Combiner::append_chunk( $file, $middle, false, false ) );
		$this->assertTrue( Audio_Combiner::append_chunk( $file, $last, false, true ) );

		// First chunk keeps its ID3v2 header (players read it); middle loses
		// both blocks; last keeps only its tail.
		$expected = $this->with_id3v2( 'FIRST' ) . 'MIDDLE' . 'LAST';

		$this->assertSame( $expected, file_get_contents( $file ) );

		wp_delete_file( $file );
	}

	/**
	 * Test that the first append truncates leftover file contents.
	 *
	 * @since x.x.x
	 */
	public function test_first_append_truncates_existing_file(): void {
		$file = wp_tempnam( 'wpai-tts-test' );
		file_put_contents( $file, 'LEFTOVER' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		$this->assertTrue( Audio_Combiner::append_chunk( $file, 'FRESH', true, true ) );
		$this->assertSame( 'FRESH', file_get_contents( $file ) );

		wp_delete_file( $file );
	}
}

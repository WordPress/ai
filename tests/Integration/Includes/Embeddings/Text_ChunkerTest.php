<?php
/**
 * Tests for the text chunker.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings;

use InvalidArgumentException;
use WP_UnitTestCase;
use WordPress\AI\Embeddings\Text_Chunker;

/**
 * Text_Chunker test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Text_Chunker
 */
class Text_ChunkerTest extends WP_UnitTestCase {

	/**
	 * Tests that empty or whitespace-only text yields no chunks.
	 *
	 * @since x.x.x
	 */
	public function test_empty_text_yields_no_chunks(): void {
		$chunker = new Text_Chunker();

		$this->assertSame( array(), $chunker->chunk( '' ) );
		$this->assertSame( array(), $chunker->chunk( "  \n\t " ) );
	}

	/**
	 * Tests that text within one window is returned trimmed as a single chunk.
	 *
	 * @since x.x.x
	 */
	public function test_short_text_is_a_single_trimmed_chunk(): void {
		$this->assertSame( array( 'Hello world.' ), ( new Text_Chunker() )->chunk( '  Hello world.  ' ) );
	}

	/**
	 * Tests that multibyte text is chunked on character, not byte, boundaries.
	 *
	 * @since x.x.x
	 */
	public function test_multibyte_text_chunks_on_character_boundaries(): void {
		$text   = str_repeat( '日本語のテキスト🙂。', 300 );
		$chunks = ( new Text_Chunker() )->chunk( $text );

		$this->assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			$this->assertTrue( mb_check_encoding( $chunk, 'UTF-8' ), 'Every chunk must be valid UTF-8.' );
			$this->assertLessThanOrEqual( Text_Chunker::DEFAULT_SIZE, mb_strlen( $chunk ) );
		}

		// Chunking is deterministic, which the content hash relies on.
		$this->assertSame( $chunks, ( new Text_Chunker() )->chunk( $text ) );
	}

	/**
	 * Tests that custom sizes are honoured.
	 *
	 * @since x.x.x
	 */
	public function test_custom_size_and_overlap(): void {
		$chunks = ( new Text_Chunker( 10, 2 ) )->chunk( 'aaaa bbbb cccc dddd' );

		$this->assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 10, mb_strlen( $chunk ) );
		}
	}

	/**
	 * Tests that an overlap not smaller than the size is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_rejects_overlap_not_smaller_than_size(): void {
		$this->expectException( InvalidArgumentException::class );

		new Text_Chunker( 10, 10 );
	}

	/**
	 * Tests that a non-positive size is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_rejects_non_positive_size(): void {
		$this->expectException( InvalidArgumentException::class );

		new Text_Chunker( 0, 0 );
	}
}

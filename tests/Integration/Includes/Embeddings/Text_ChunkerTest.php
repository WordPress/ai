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
	 * Tests that an invalid size or overlap is rejected.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_invalid_sizes
	 *
	 * @param int $size    Chunk size.
	 * @param int $overlap Chunk overlap.
	 */
	public function test_rejects_invalid_sizes( int $size, int $overlap ): void {
		$this->expectException( InvalidArgumentException::class );

		new Text_Chunker( $size, $overlap );
	}

	/**
	 * Returns invalid size and overlap pairs.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: int, 1: int}> Test cases.
	 */
	public function data_invalid_sizes(): array {
		return array(
			'non-positive size'             => array( 0, 0 ),
			'overlap not smaller than size' => array( 10, 10 ),
		);
	}
}

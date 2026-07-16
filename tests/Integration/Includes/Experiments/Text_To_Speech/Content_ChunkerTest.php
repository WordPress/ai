<?php
/**
 * Integration tests for the Content_Chunker class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Text_To_Speech;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Text_To_Speech\Content_Chunker;

/**
 * Content_Chunker test case.
 *
 * @since x.x.x
 */
class Content_ChunkerTest extends WP_UnitTestCase {

	/**
	 * Test that short content returns a single chunk.
	 *
	 * @since x.x.x
	 */
	public function test_short_content_returns_single_chunk(): void {
		$content = 'This is a short sentence.';

		$this->assertSame( array( $content ), Content_Chunker::chunk( $content, 100 ) );
	}

	/**
	 * Test that empty content returns no chunks.
	 *
	 * @since x.x.x
	 */
	public function test_empty_content_returns_no_chunks(): void {
		$this->assertSame( array(), Content_Chunker::chunk( '', 100 ) );
		$this->assertSame( array(), Content_Chunker::chunk( '   ', 100 ) );
	}

	/**
	 * Test that long content splits on sentence boundaries under the limit.
	 *
	 * @since x.x.x
	 */
	public function test_long_content_splits_on_sentence_boundaries(): void {
		$sentence = 'The quick brown fox jumps over the lazy dog.';
		$content  = trim( str_repeat( $sentence . ' ', 10 ) );

		$chunks = Content_Chunker::chunk( $content, 100 );

		$this->assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 100, mb_strlen( $chunk ) );
			// Each chunk should end at a sentence boundary.
			$this->assertMatchesRegularExpression( '/[.!?]$/', $chunk );
		}

		// No content lost: rejoining chunks reproduces the original words.
		$this->assertSame(
			preg_replace( '/\s+/', ' ', $content ),
			preg_replace( '/\s+/', ' ', implode( ' ', $chunks ) )
		);
	}

	/**
	 * Test that a single over-long sentence is hard-split at the limit.
	 *
	 * @since x.x.x
	 */
	public function test_overlong_sentence_is_hard_split(): void {
		$content = str_repeat( 'a', 250 );

		$chunks = Content_Chunker::chunk( $content, 100 );

		$this->assertSame( array( str_repeat( 'a', 100 ), str_repeat( 'a', 100 ), str_repeat( 'a', 50 ) ), $chunks );
	}

	/**
	 * Test that multibyte content is split without corrupting characters.
	 *
	 * @since x.x.x
	 */
	public function test_multibyte_content_is_not_corrupted(): void {
		$content = str_repeat( 'こんにちは世界', 30 ); // 210 chars, no sentence punctuation.

		$chunks = Content_Chunker::chunk( $content, 100 );

		$this->assertSame( $content, implode( '', $chunks ) );

		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 100, mb_strlen( $chunk ) );
			// Valid UTF-8 (no split mid-character).
			$this->assertTrue( (bool) preg_match( '//u', $chunk ) );
		}
	}
}

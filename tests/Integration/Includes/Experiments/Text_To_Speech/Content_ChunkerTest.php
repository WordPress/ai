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
 * @since 1.5.0
 */
class Content_ChunkerTest extends WP_UnitTestCase {

	/**
	 * Test that short content returns a single chunk.
	 *
	 * @since 1.5.0
	 */
	public function test_short_content_returns_single_chunk(): void {
		$content = 'This is a short sentence.';

		$this->assertSame( array( $content ), Content_Chunker::chunk( $content, 100 ) );
	}

	/**
	 * Test that empty content returns no chunks.
	 *
	 * @since 1.5.0
	 */
	public function test_empty_content_returns_no_chunks(): void {
		$this->assertSame( array(), Content_Chunker::chunk( '', 100 ) );
		$this->assertSame( array(), Content_Chunker::chunk( '   ', 100 ) );
	}

	/**
	 * Test that long content splits on sentence boundaries under the limit.
	 *
	 * @since 1.5.0
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
	 * @since 1.5.0
	 */
	public function test_overlong_sentence_is_hard_split(): void {
		$content = str_repeat( 'a', 250 );

		$chunks = Content_Chunker::chunk( $content, 100 );

		$this->assertSame( array( str_repeat( 'a', 100 ), str_repeat( 'a', 100 ), str_repeat( 'a', 50 ) ), $chunks );
	}

	/**
	 * Test that sentence ends of other scripts are chunk boundaries.
	 *
	 * @dataProvider data_non_latin_sentences
	 *
	 * @since x.x.x
	 *
	 * @param string $sentence  A sentence that ends with the script's own sentence mark.
	 * @param string $separator The text between two sentences in that script.
	 */
	public function test_non_latin_sentence_ends_are_chunk_boundaries( string $sentence, string $separator ): void {
		$content = implode( $separator, array_fill( 0, 12, $sentence ) );
		$mark    = mb_substr( $sentence, -1 );

		$chunks = Content_Chunker::chunk( $content, 100 );

		$this->assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 100, mb_strlen( $chunk ) );
			$this->assertSame( $mark, mb_substr( $chunk, -1 ), 'Each chunk should end at a sentence mark.' );
		}

		// No content lost, and no space added between sentences.
		$this->assertSame( $content, implode( $separator, $chunks ) );
	}

	/**
	 * Data provider for test_non_latin_sentence_ends_are_chunk_boundaries().
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string, string}>
	 */
	public function data_non_latin_sentences(): array {
		return array(
			'Hindi danda'           => array( 'यह परीक्षण के लिए एक छोटा वाक्य है।', ' ' ),
			'Hindi double danda'    => array( 'यह एक दोहा है॥', ' ' ),
			'Urdu full stop'        => array( 'یہ جانچ کے لیے ایک جملہ ہے۔', ' ' ),
			'Arabic question mark'  => array( 'هل هذه جملة للاختبار؟', ' ' ),
			'Japanese full stop'    => array( 'これはテスト用の短い文です。', '' ),
			'Japanese with spaces'  => array( 'これはテスト用の短い文です。', ' ' ),
			'Japanese with newline' => array( 'これはテスト用の短い文です。', "\n" ),
			'Chinese question mark' => array( '这是一个用于测试的短句吗？', '' ),
			'Chinese exclamation'   => array( '这是一个用于测试的短句！', '' ),
		);
	}

	/**
	 * Test that whitespace after a full-width mark is kept in mixed text.
	 *
	 * @since x.x.x
	 */
	public function test_whitespace_after_full_width_marks_is_kept(): void {
		$content = trim( str_repeat( 'これは「日本語の文。」です。 This is an English sentence. ', 8 ) );

		$chunks = Content_Chunker::chunk( $content, 100 );

		$this->assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 100, mb_strlen( $chunk ) );
			$this->assertStringNotContainsString( '。This', $chunk );
			$this->assertDoesNotMatchRegularExpression( '/^[\p{Pe}\p{Pf}"\']/u', $chunk, 'A chunk starts with a closing quote.' );
		}

		$this->assertSame( $content, implode( ' ', $chunks ) );
	}

	/**
	 * Test that a closing quote or bracket stays with the full-width mark before it.
	 *
	 * @dataProvider data_full_width_quotes
	 *
	 * @since x.x.x
	 *
	 * @param string $sentence   A sentence with a full-width mark inside a quote.
	 * @param int    $max_length The maximum chunk length.
	 */
	public function test_closing_quote_stays_with_full_width_mark( string $sentence, int $max_length ): void {
		$content = str_repeat( $sentence, 6 );

		$chunks = Content_Chunker::chunk( $content, $max_length );

		$this->assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( $max_length, mb_strlen( $chunk ) );
			$this->assertDoesNotMatchRegularExpression( '/^[\p{Pe}\p{Pf}"\']/u', $chunk, 'A chunk starts with a closing quote.' );
		}

		$this->assertSame( $content, implode( '', $chunks ) );
	}

	/**
	 * Data provider for test_closing_quote_stays_with_full_width_mark().
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string, int}>
	 */
	public function data_full_width_quotes(): array {
		return array(
			'corner brackets'            => array( 'これは「短い文。」次です。', 21 ),
			'quote followed by particle' => array( '彼は「これは。次です。」と言った。', 11 ),
			'white corner brackets'      => array( '題は『本です。』と書いた。', 21 ),
			'curly quotes'               => array( '“これは短い文です。”と書いた。', 26 ),
			'ASCII single quotes'        => array( "これは'短い文。'次です。", 21 ),
			'ASCII double quotes'        => array( 'これは"短い文。"次です。', 21 ),
		);
	}

	/**
	 * Test that an over-long sentence is split between words, not inside one.
	 *
	 * @since x.x.x
	 */
	public function test_overlong_sentence_is_split_between_words(): void {
		$content = trim( str_repeat( 'alpha beta gamma delta epsilon ', 10 ) ); // 309 chars, no sentence end.
		$words   = explode( ' ', $content );

		$chunks = Content_Chunker::chunk( $content, 100 );

		$this->assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( 100, mb_strlen( $chunk ) );

			foreach ( explode( ' ', $chunk ) as $word ) {
				$this->assertContains( $word, $words, 'A word was cut in two.' );
			}
		}

		$this->assertSame( $content, implode( ' ', $chunks ) );
	}

	/**
	 * Test that chunk edges are trimmed of Unicode whitespace, such as the ideographic space.
	 *
	 * @since x.x.x
	 */
	public function test_chunk_edges_are_trimmed_of_unicode_whitespace(): void {
		$space    = "\u{3000}";
		$contents = array(
			str_repeat( 'これは長い説明文' . $space, 20 ), // No sentence marks, split at the spaces.
			str_repeat( 'これは文です。' . $space, 12 ),   // A space after each full stop.
		);

		foreach ( $contents as $content ) {
			$chunks = Content_Chunker::chunk( $content, 40 );

			$this->assertGreaterThan( 1, count( $chunks ) );

			foreach ( $chunks as $chunk ) {
				$this->assertLessThanOrEqual( 40, mb_strlen( $chunk ) );
				$this->assertDoesNotMatchRegularExpression( '/^\s|\s$/u', $chunk );
			}
		}
	}

	/**
	 * Test that multibyte content is split without corrupting characters.
	 *
	 * @since 1.5.0
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

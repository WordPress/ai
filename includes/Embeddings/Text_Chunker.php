<?php
/**
 * Splits text into overlapping chunks for embedding.
 *
 * @package WordPress\AI\Embeddings
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Splits text into overlapping character chunks.
 *
 * @since x.x.x
 */
class Text_Chunker {

	/**
	 * Version of the chunking rules.
	 *
	 * @since x.x.x
	 */
	public const VERSION = '1';

	/**
	 * Default maximum characters per chunk.
	 *
	 * @since x.x.x
	 */
	public const DEFAULT_SIZE = 750;

	/**
	 * Default characters of overlap between consecutive chunks.
	 *
	 * @since x.x.x
	 */
	public const DEFAULT_OVERLAP = 125;

	/**
	 * Maximum characters per chunk.
	 *
	 * @var int
	 */
	private int $size;

	/**
	 * Characters of overlap between consecutive chunks.
	 *
	 * @var int
	 */
	private int $overlap;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param int $size    Optional. Maximum characters per chunk. Default 750.
	 * @param int $overlap Optional. Characters of overlap between chunks; must be smaller than $size. Default 125.
	 *
	 * @throws \InvalidArgumentException If the size or overlap is out of range.
	 */
	public function __construct( int $size = self::DEFAULT_SIZE, int $overlap = self::DEFAULT_OVERLAP ) {
		if ( $size < 1 ) {
			throw new InvalidArgumentException( 'Chunk size must be at least 1.' );
		}

		if ( $overlap < 0 || $overlap >= $size ) {
			throw new InvalidArgumentException( 'Chunk overlap must be zero or more and smaller than the chunk size.' );
		}

		$this->size    = $size;
		$this->overlap = $overlap;
	}

	/**
	 * Splits text into overlapping chunks.
	 *
	 * @since x.x.x
	 *
	 * @param string $text Text to chunk.
	 * @return list<string> Chunks, or an empty list when the text is empty or whitespace-only.
	 */
	public function chunk( string $text ): array {
		$text = trim( $text );

		if ( '' === $text ) {
			return array();
		}

		$length = mb_strlen( $text );

		if ( $length <= $this->size ) {
			return array( $text );
		}

		$chunks = array();
		$start  = 0;

		while ( $start < $length ) {
			if ( $length - $start <= $this->size ) {
				$chunk = trim( mb_substr( $text, $start ) );

				if ( '' !== $chunk ) {
					$chunks[] = $chunk;
				}

				break;
			}

			$end_offset = $this->find_natural_break( mb_substr( $text, $start, $this->size ) );
			$chunk      = trim( mb_substr( $text, $start, $end_offset ) );

			if ( '' !== $chunk ) {
				$chunks[] = $chunk;
			}

			$start += max( 1, $end_offset - $this->overlap );
		}

		return $chunks;
	}

	/**
	 * Finds a preferred end offset within a chunk window.
	 *
	 * @since x.x.x
	 *
	 * @param string $window Candidate chunk window.
	 * @return int End offset relative to the window start, from 1 to the window length.
	 */
	private function find_natural_break( string $window ): int {
		$window_length = mb_strlen( $window );

		if ( $window_length <= 1 ) {
			return max( 1, $window_length );
		}

		$search_from = (int) floor( $window_length * 0.75 );

		for ( $i = $window_length - 1; $i >= $search_from; $i-- ) {
			$char = mb_substr( $window, $i, 1 );

			if ( ctype_space( $char ) || in_array( $char, array( '.', '!', '?', ';', ':' ), true ) ) {
				return $i + 1;
			}
		}

		return $window_length;
	}
}

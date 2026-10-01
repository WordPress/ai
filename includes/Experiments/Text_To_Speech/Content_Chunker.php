<?php
/**
 * Content chunker for text to speech generation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Text_To_Speech;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Splits normalized text content into chunks suitable for text to speech APIs.
 *
 * Providers often enforce per-request content length limits, so
 * long content is packed into sentence-boundary chunks that are each
 * generated separately and combined afterwards.
 *
 * @since x.x.x
 */
final class Content_Chunker {

	/**
	 * Splits content into chunks no longer than the given maximum length.
	 *
	 * Content is split on sentence boundaries and packed greedily. A single
	 * sentence longer than the maximum is hard-split at the limit. All
	 * length calculations are multibyte-aware.
	 *
	 * @since x.x.x
	 *
	 * @param string $content    The (already normalized) content to split.
	 * @param int    $max_length The maximum chunk length in characters.
	 * @return list<string> The list of chunks. Empty if there is no content.
	 */
	public static function chunk( string $content, int $max_length ): array {
		$content = trim( $content );

		if ( '' === $content || $max_length < 1 ) {
			return array();
		}

		if ( mb_strlen( $content ) <= $max_length ) {
			return array( $content );
		}

		$sentences = preg_split( '/(?<=[.!?])\s+/u', $content );

		if ( false === $sentences ) {
			$sentences = array( $content );
		}

		$chunks  = array();
		$current = '';

		foreach ( $sentences as $sentence ) {
			// A single sentence longer than the limit gets hard-split.
			if ( mb_strlen( $sentence ) > $max_length ) {
				if ( '' !== $current ) {
					$chunks[] = $current;
					$current  = '';
				}

				$offset = 0;
				$length = mb_strlen( $sentence );

				while ( $offset < $length ) {
					$chunks[] = mb_substr( $sentence, $offset, $max_length );
					$offset  += $max_length;
				}

				continue;
			}

			$candidate = '' === $current ? $sentence : $current . ' ' . $sentence;

			if ( mb_strlen( $candidate ) > $max_length ) {
				$chunks[] = $current;
				$current  = $sentence;
			} else {
				$current = $candidate;
			}
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return array_values(
			array_filter(
				array_map( 'trim', $chunks ),
				static function ( string $chunk ): bool {
					return '' !== $chunk;
				}
			)
		);
	}
}

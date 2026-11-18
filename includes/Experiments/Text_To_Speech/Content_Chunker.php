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
 * @since 1.5.0
 */
final class Content_Chunker {

	/**
	 * Splits content into chunks no longer than the given maximum length.
	 *
	 * Content is split on sentence boundaries and packed greedily. Besides
	 * `.`, `!` and `?`, sentence ends include the danda, the Urdu full stop,
	 * the Arabic question mark and the full-width marks used in Chinese and
	 * Japanese. A single sentence longer than the maximum is split at its
	 * last whitespace within the limit, or hard-split at the limit when it
	 * has none. All length calculations are multibyte-aware.
	 *
	 * @since 1.5.0
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

		/*
		 * A sentence ends at whitespace after `.`, `!`, `?`, the danda and double
		 * danda (U+0964, U+0965), the Urdu full stop (U+06D4) or the Arabic
		 * question mark (U+061F), or right after `。`, `！` or `？`, which are
		 * usually not followed by a space. A full-width mark followed by a
		 * closing quote or bracket, as in `「…。」`, does not end the sentence.
		 */
		$sentences = preg_split( '/(?<=[.!?\x{0964}\x{0965}\x{06D4}\x{061F}])\s+|(?<=[。！？])(?![\p{Pe}\p{Pf}"\'])/u', $content, -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $sentences ) {
			$sentences = array( $content );
		}

		$chunks  = array();
		$current = '';

		foreach ( $sentences as $sentence ) {
			// A single sentence longer than the limit is split between words.
			if ( mb_strlen( $sentence ) > $max_length ) {
				if ( '' !== $current ) {
					$chunks[] = $current;
					$current  = '';
				}

				while ( mb_strlen( $sentence ) > $max_length ) {
					$piece = mb_substr( $sentence, 0, $max_length );

					// Break at the last whitespace within the limit, if there is one.
					if ( 1 === preg_match( '/^(.*\S)\s/us', $piece, $matches ) ) {
						$piece = $matches[1];
					}

					$chunks[] = $piece;
					$sentence = (string) preg_replace( '/^\s+/u', '', mb_substr( $sentence, mb_strlen( $piece ) ) );
				}

				$chunks[] = $sentence;

				continue;
			}

			// After a full-width mark, the next sentence keeps its own leading whitespace, if any.
			$separator = 1 === preg_match( '/[。！？]$/u', $current ) ? '' : ' ';
			$candidate = '' === $current ? $sentence : $current . $separator . $sentence;

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
				array_map(
					static function ( string $chunk ): string {
						// Unlike trim(), this also removes Unicode whitespace such as the ideographic space.
						return (string) preg_replace( '/^\s+|\s+$/u', '', $chunk );
					},
					$chunks
				),
				static function ( string $chunk ): bool {
					return '' !== $chunk;
				}
			)
		);
	}
}

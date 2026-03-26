<?php
/**
 * Unicode variation selector embedder.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Content_Provenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Embeds and extracts data using Unicode variation selectors per C2PA 2.3 §A.7.
 *
 * Encoding scheme (C2PATextManifestWrapper):
 * - Wrapper is APPENDED to NFC-normalized text.
 * - Wrapper format: U+FEFF + encode(header + manifestBytes)
 * - Header (13 bytes): 8-byte ASCII magic "C2PATXT\0" + 1-byte version + 4-byte big-endian length
 * - Byte-to-VS mapping:
 *   Bytes 0–15:   U+FE00–U+FE0F  (VS1–VS16)   → 3-byte UTF-8: EF B8 80–8F
 *   Bytes 16–255: U+E0100–U+E01EF (VS17–VS256) → 4-byte UTF-8: F3 A0 {84-87} {80-BF}
 *   The 240 supplementary code points split into 4 groups of 64; the 3rd byte cycles
 *   through 0x84–0x87 and the 4th byte cycles through 0x80–0xBF.
 *
 * @since 0.5.0
 */
class Unicode_Embedder {

	/**
	 * UTF-8 byte sequence for U+FEFF (Zero-Width No-Break Space / BOM).
	 *
	 * Used as a unique marker to detect whether a text carries embedded wrapper data.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const PREFIX = "\xEF\xBB\xBF";

	/**
	 * C2PA text magic byte sequence (ASCII "C2PATXT\0").
	 *
	 * First 8 bytes of the C2PATextManifestWrapper header.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const WRAPPER_MAGIC = "\x43\x32\x50\x41\x54\x58\x54\x00";

	/**
	 * C2PATextManifestWrapper format version.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	private const WRAPPER_VERSION = 1;

	/**
	 * Binary header size in bytes: 8 (magic) + 1 (version) + 4 (length).
	 *
	 * @since 0.5.0
	 * @var int
	 */
	private const HEADER_SIZE = 13;

	/**
	 * Embed a JSON string into text using a C2PA-compliant Unicode wrapper.
	 *
	 * Normalizes the text to NFC, builds a binary C2PATextManifestWrapper containing
	 * the magic header and manifest bytes, encodes the wrapper as Unicode variation
	 * selectors (prefixed with U+FEFF), and APPENDS it to the normalized text.
	 *
	 * @since 0.5.0
	 *
	 * @param string $text          Plain text content.
	 * @param string $manifest_json JSON string to embed.
	 * @return string NFC-normalized text with the encoded wrapper appended.
	 */
	public static function embed( string $text, string $manifest_json ): string {
		// NFC normalize text per C2PA 2.3 §A.7.
		if ( class_exists( 'Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $text, \Normalizer::FORM_C );
			if ( false !== $normalized ) {
				$text = $normalized;
			}
		}

		$unpacked       = unpack( 'C*', $manifest_json );
		$manifest_bytes = array_values( $unpacked ? $unpacked : array() );
		$manifest_len   = count( $manifest_bytes );

		// Build binary header: 8-byte magic + 1-byte version + 4-byte big-endian length.
		$unpacked_magic = unpack( 'C*', self::WRAPPER_MAGIC );
		$header_bytes   = array_values( $unpacked_magic ? $unpacked_magic : array() );
		$header_bytes[] = self::WRAPPER_VERSION;
		$header_bytes[] = ( $manifest_len >> 24 ) & 0xFF;
		$header_bytes[] = ( $manifest_len >> 16 ) & 0xFF;
		$header_bytes[] = ( $manifest_len >> 8 ) & 0xFF;
		$header_bytes[] = $manifest_len & 0xFF;

		// Encode header + manifest bytes as variation selectors, prefixed with U+FEFF.
		$wrapper = self::PREFIX;

		foreach ( array_merge( $header_bytes, $manifest_bytes ) as $byte ) {
			if ( $byte < 16 ) {
				// U+FE00–U+FE0F (VS1–VS16): 3-byte sequence EF B8 80+n.
				$wrapper .= "\xEF\xB8" . chr( 0x80 + $byte );
			} else {
				// U+E0100–U+E01EF (VS17–VS256): 4-byte sequence F3 A0 {84-87} {80-BF}.
				// The 240 code points split into 4 groups of 64; the 3rd byte cycles
				// through 0x84–0x87 and the 4th byte cycles through 0x80–0xBF.
				$n        = $byte - 16;
				$wrapper .= "\xF3\xA0" . chr( 0x84 + intdiv( $n, 64 ) ) . chr( 0x80 + ( $n % 64 ) );
			}
		}

		// APPEND wrapper to text per C2PA 2.3 §A.7.
		return $text . $wrapper;
	}

	/**
	 * Extract embedded JSON from text.
	 *
	 * Scans for the U+FEFF marker anywhere in the string, decodes the variation-selector
	 * region, validates the C2PATextManifestWrapper binary header (magic + version +
	 * length), and returns the manifest payload bytes.
	 *
	 * Returns null if no valid wrapper is detected or if the header is invalid.
	 *
	 * @since 0.5.0
	 *
	 * @param string $text Text potentially containing an embedded wrapper.
	 * @return string|null Extracted JSON string, or null if none found.
	 */
	public static function extract( string $text ): ?string {
		// Search for the U+FEFF marker anywhere in the string.
		$pos = strpos( $text, self::PREFIX );
		if ( false === $pos ) {
			return null;
		}

		// Decode variation-selector bytes starting after the U+FEFF marker.
		$bytes = array();
		$i     = $pos + strlen( self::PREFIX );
		$len   = strlen( $text );

		while ( $i < $len ) {
			$b0 = ord( $text[ $i ] );

			// U+FE00–U+FE0F: 3-byte sequence EF B8 80–8F.
			if ( 0xEF === $b0 && isset( $text[ $i + 1 ], $text[ $i + 2 ] ) ) {
				$b1 = ord( $text[ $i + 1 ] );
				$b2 = ord( $text[ $i + 2 ] );
				if ( 0xB8 === $b1 && $b2 >= 0x80 && $b2 <= 0x8F ) {
					$bytes[] = $b2 - 0x80;
					$i      += 3;
					continue;
				}
			}

			// U+E0100–U+E01EF: 4-byte sequence F3 A0 {84-87} {80-BF}.
			if ( 0xF3 === $b0 && isset( $text[ $i + 1 ], $text[ $i + 2 ], $text[ $i + 3 ] ) ) {
				$b1 = ord( $text[ $i + 1 ] );
				$b2 = ord( $text[ $i + 2 ] );
				$b3 = ord( $text[ $i + 3 ] );
				if ( 0xA0 === $b1 && $b2 >= 0x84 && $b2 <= 0x87 && $b3 >= 0x80 && $b3 <= 0xBF ) {
					$bytes[] = ( ( $b2 - 0x84 ) * 64 ) + $b3 - 0x80 + 16;
					$i      += 4;
					continue;
				}
			}

			// Not a variation selector — end of encoded region.
			break;
		}

		// Need at least HEADER_SIZE bytes to validate the wrapper.
		if ( count( $bytes ) < self::HEADER_SIZE ) {
			return null;
		}

		// Validate magic bytes (first 8 bytes = "C2PATXT\0").
		$unpacked_magic = unpack( 'C*', self::WRAPPER_MAGIC );
		$magic          = array_values( $unpacked_magic ? $unpacked_magic : array() );

		for ( $k = 0; $k < 8; $k++ ) {
			if ( $bytes[ $k ] !== $magic[ $k ] ) {
				return null;
			}
		}

		// Validate version byte.
		if ( self::WRAPPER_VERSION !== $bytes[8] ) {
			return null;
		}

		// Read 4-byte big-endian manifest length.
		$manifest_len = ( $bytes[9] << 24 ) | ( $bytes[10] << 16 ) | ( $bytes[11] << 8 ) | $bytes[12];

		if ( 0 === $manifest_len || count( $bytes ) < self::HEADER_SIZE + $manifest_len ) {
			return null;
		}

		$manifest_bytes = array_slice( $bytes, self::HEADER_SIZE, $manifest_len );

		return pack( 'C*', ...$manifest_bytes );
	}

	/**
	 * Strip embedded variation selectors from text, returning clean plain text.
	 *
	 * Removes U+FEFF and all variation-selector byte sequences (VS1–VS256) so that
	 * the remaining string equals the original human-readable content. Safe to call
	 * on text that carries no embedding.
	 *
	 * @since 0.5.0
	 *
	 * @param string $text Text with possible embedded wrapper.
	 * @return string Clean text without the C2PA wrapper.
	 */
	public static function strip( string $text ): string {
		// Remove U+FEFF markers and VS1–VS16 / VS17–VS256 code points anywhere in the text.
		$stripped = preg_replace( '/[\x{FEFF}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]/u', '', $text );
		return null !== $stripped ? $stripped : $text;
	}
}

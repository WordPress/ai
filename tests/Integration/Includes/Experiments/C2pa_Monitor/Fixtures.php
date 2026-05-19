<?php
/**
 * Fixture builder for C2PA Monitor tests.
 *
 * Generates synthetic JPEG / PNG / WebP files that carry the byte patterns
 * Format_Detector looks for. The fixtures are not signed C2PA assets and are
 * not valid for any cryptographic verification - they exist solely to drive
 * presence-detection and raw-byte capture in PR 1.
 *
 * Generating fixtures at runtime keeps binary blobs out of the repo and
 * sidesteps any third-party fixture licensing.
 *
 * @package WordPress\AI\Tests
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds synthetic C2PA-bearing image fixtures for tests.
 *
 * Each builder writes a self-contained file that is just valid enough for
 * Format_Detector to walk its segments without crashing, and embeds a
 * pseudo-JUMBF payload tagged with the literal 'c2pa' so the detector
 * classifies it as present.
 *
 * @since 0.7.0
 */
class Fixtures {
	/**
	 * Writes a JPEG file containing a single APP11 segment carrying a
	 * synthetic JUMBF payload tagged 'c2pa'.
	 *
	 * Layout: SOI, APP11(JUMBF), SOS-with-empty-scan, EOI. Real decoders may
	 * reject this as a renderable image, but it is well-formed at the marker
	 * level for our detector and contains nothing that would hang a parser.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path             Absolute output path.
	 * @param string $manifest_payload Synthetic JUMBF bytes to embed.
	 * @return void
	 */
	public static function write_jpeg_with_c2pa( string $path, string $manifest_payload ): void {
		$header_jp           = 'JP';
		$box_instance_number = pack( 'n', 1 );
		$packet_seq_number   = pack( 'J', 1 );
		$inner               = $header_jp . $box_instance_number . $packet_seq_number . $manifest_payload;

		$marker = "\xFF\xEB";
		$len    = pack( 'n', strlen( $inner ) + 2 );

		$bytes  = "\xFF\xD8";
		$bytes .= $marker . $len . $inner;
		$bytes .= "\xFF\xDA\x00\x02";
		$bytes .= "\xFF\xD9";

		file_put_contents( $path, $bytes );
	}

	/**
	 * Writes a JPEG file whose C2PA manifest is split across N APP11 segments
	 * sharing the same Box Instance Number.
	 *
	 * Mirrors how real C2PA encoders split manifests larger than the JPEG
	 * 64 KiB marker payload limit. Only the first segment carries the JUMBF
	 * box header that identifies the sequence as C2PA; the remaining segments
	 * are pure payload continuation.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path             Absolute output path.
	 * @param string $manifest_payload Synthetic JUMBF bytes to embed.
	 * @param int    $segment_count    Number of APP11 segments to emit.
	 * @return void
	 */
	public static function write_jpeg_with_c2pa_multi_segment(
		string $path,
		string $manifest_payload,
		int $segment_count
	): void {
		$segment_count = max( 1, $segment_count );
		$total         = strlen( $manifest_payload );
		$base_size     = intdiv( $total, $segment_count );
		$remainder     = $total - ( $base_size * $segment_count );

		$bytes  = "\xFF\xD8";
		$cursor = 0;
		for ( $i = 0; $i < $segment_count; $i++ ) {
			$size  = $base_size + ( ( $segment_count - 1 ) === $i ? $remainder : 0 );
			$slice = substr( $manifest_payload, $cursor, $size );
			$cursor += $size;

			$header_jp           = 'JP';
			$box_instance_number = pack( 'n', 1 );
			$packet_seq_number   = pack( 'J', $i + 1 );
			$inner               = $header_jp . $box_instance_number . $packet_seq_number . $slice;

			$marker = "\xFF\xEB";
			$len    = pack( 'n', strlen( $inner ) + 2 );
			$bytes .= $marker . $len . $inner;
		}

		$bytes .= "\xFF\xDA\x00\x02";
		$bytes .= "\xFF\xD9";

		file_put_contents( $path, $bytes );
	}

	/**
	 * Writes a JPEG file containing an APP11 segment whose JUMBF inner bytes
	 * do NOT contain `c2pa` or `jumb` in the first 64 bytes.
	 *
	 * Used to verify Format_Detector ignores generic JUMBF payloads that
	 * happen to ride in APP11 but are not C2PA.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path Absolute output path.
	 * @return void
	 */
	public static function write_jpeg_with_jumbf_non_c2pa( string $path ): void {
		$header_jp           = 'JP';
		$box_instance_number = pack( 'n', 5 );
		$packet_seq_number   = pack( 'J', 1 );
		$inner_payload       = str_repeat( "\x00\x01\x02\x03\x04\x05\x06\x07", 12 );
		$inner               = $header_jp . $box_instance_number . $packet_seq_number . $inner_payload;

		$marker = "\xFF\xEB";
		$len    = pack( 'n', strlen( $inner ) + 2 );

		$bytes  = "\xFF\xD8";
		$bytes .= $marker . $len . $inner;
		$bytes .= "\xFF\xDA\x00\x02";
		$bytes .= "\xFF\xD9";

		file_put_contents( $path, $bytes );
	}

	/**
	 * Writes a JPEG file with the C2PA APP11 segment surrounded by other,
	 * unrelated APP markers (APP0/JFIF, APP1/EXIF, APP2/ICC).
	 *
	 * Mirrors a real-world JPEG that already carries metadata from camera
	 * software before C2PA is attached.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path             Absolute output path.
	 * @param string $manifest_payload Synthetic JUMBF bytes to embed.
	 * @return void
	 */
	public static function write_jpeg_with_app_segments_around_c2pa( string $path, string $manifest_payload ): void {
		$bytes = "\xFF\xD8";

		$app0 = "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
		$bytes .= "\xFF\xE0" . pack( 'n', strlen( $app0 ) + 2 ) . $app0;

		$app1 = "Exif\x00\x00" . str_repeat( "\x00", 32 );
		$bytes .= "\xFF\xE1" . pack( 'n', strlen( $app1 ) + 2 ) . $app1;

		$app11_inner = 'JP' . pack( 'n', 1 ) . pack( 'J', 1 ) . $manifest_payload;
		$bytes .= "\xFF\xEB" . pack( 'n', strlen( $app11_inner ) + 2 ) . $app11_inner;

		$app2 = "ICC_PROFILE\x00\x01\x01" . str_repeat( "\x00", 16 );
		$bytes .= "\xFF\xE2" . pack( 'n', strlen( $app2 ) + 2 ) . $app2;

		$bytes .= "\xFF\xDA\x00\x02";
		$bytes .= "\xFF\xD9";

		file_put_contents( $path, $bytes );
	}

	/**
	 * Writes a JPEG file with $count empty APP10 segments, optionally followed
	 * by a single C2PA APP11 segment.
	 *
	 * Used to verify Format_Detector::JPEG_MAX_SEGMENTS is enforced: when
	 * $count exceeds the cap, the trailing C2PA segment must never be reached.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path                    Absolute output path.
	 * @param int    $count                   Number of APP10 segments to emit.
	 * @param string $trailing_c2pa_payload   Optional C2PA manifest bytes; emits a trailing APP11 when non-empty.
	 * @return void
	 */
	public static function write_jpeg_with_many_app_segments(
		string $path,
		int $count,
		string $trailing_c2pa_payload = ''
	): void {
		$bytes = "\xFF\xD8";

		for ( $i = 0; $i < $count; $i++ ) {
			$bytes .= "\xFF\xEA" . pack( 'n', 2 );
		}

		if ( '' !== $trailing_c2pa_payload ) {
			$inner = 'JP' . pack( 'n', 1 ) . pack( 'J', 1 ) . $trailing_c2pa_payload;
			$bytes .= "\xFF\xEB" . pack( 'n', strlen( $inner ) + 2 ) . $inner;
		}

		$bytes .= "\xFF\xDA\x00\x02";
		$bytes .= "\xFF\xD9";

		file_put_contents( $path, $bytes );
	}

	/**
	 * Writes a JPEG file with no C2PA markers.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path Absolute output path.
	 * @return void
	 */
	public static function write_jpeg_without_c2pa( string $path ): void {
		$bytes  = "\xFF\xD8";
		$bytes .= "\xFF\xE0" . pack( 'n', 16 ) . "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
		$bytes .= "\xFF\xDA\x00\x02";
		$bytes .= "\xFF\xD9";
		file_put_contents( $path, $bytes );
	}

	/**
	 * Writes a JPEG file that is truncated mid-APP11 segment.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path Absolute output path.
	 * @return void
	 */
	public static function write_jpeg_truncated( string $path ): void {
		$bytes = "\xFF\xD8\xFF\xEB\x00\x40JP";
		file_put_contents( $path, $bytes );
	}

	/**
	 * Writes a PNG file with a single `caBX` chunk carrying $manifest_payload.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path             Absolute output path.
	 * @param string $manifest_payload Synthetic manifest bytes.
	 * @return void
	 */
	public static function write_png_with_c2pa( string $path, string $manifest_payload ): void {
		$signature = "\x89PNG\r\n\x1A\n";

		$ihdr_data = pack( 'NN', 1, 1 ) . "\x08\x00\x00\x00\x00";
		$ihdr      = self::png_chunk( 'IHDR', $ihdr_data );

		$cabx = self::png_chunk( 'caBX', $manifest_payload );

		$idat_data = "\x78\x9C\x62\x00\x00\x00\x00\x05\x00\x01\x0D\x0A\x2D\xB4";
		$idat      = self::png_chunk( 'IDAT', $idat_data );

		$iend = self::png_chunk( 'IEND', '' );

		file_put_contents( $path, $signature . $ihdr . $cabx . $idat . $iend );
	}

	/**
	 * Writes a PNG file with no C2PA chunks.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path Absolute output path.
	 * @return void
	 */
	public static function write_png_without_c2pa( string $path ): void {
		$signature = "\x89PNG\r\n\x1A\n";
		$ihdr_data = pack( 'NN', 1, 1 ) . "\x08\x00\x00\x00\x00";
		$ihdr      = self::png_chunk( 'IHDR', $ihdr_data );
		$idat_data = "\x78\x9C\x62\x00\x00\x00\x00\x05\x00\x01\x0D\x0A\x2D\xB4";
		$idat      = self::png_chunk( 'IDAT', $idat_data );
		$iend      = self::png_chunk( 'IEND', '' );
		file_put_contents( $path, $signature . $ihdr . $idat . $iend );
	}

	/**
	 * Writes a WebP file with a single top-level `C2PA` chunk.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path             Absolute output path.
	 * @param string $manifest_payload Synthetic manifest bytes.
	 * @return void
	 */
	public static function write_webp_with_c2pa( string $path, string $manifest_payload ): void {
		$vp8l = self::webp_chunk( 'VP8L', "\x2F\x00\x00\x00\x00\x10\x07\x10\x11\x11\x88\x88\x08" );
		$c2pa = self::webp_chunk( 'C2PA', $manifest_payload );

		$body  = 'WEBP' . $vp8l . $c2pa;
		$riff  = 'RIFF' . pack( 'V', strlen( $body ) ) . $body;
		file_put_contents( $path, $riff );
	}

	/**
	 * Writes a WebP file in extended (VP8X) form carrying an EXIF chunk and a
	 * `C2PA` chunk alongside the image payload.
	 *
	 * Mirrors the layout produced by encoders that emit extended WebP with
	 * metadata. Format_Detector must still find the C2PA chunk despite the
	 * presence of VP8X / EXIF / VP8L siblings.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path             Absolute output path.
	 * @param string $manifest_payload Synthetic manifest bytes.
	 * @return void
	 */
	public static function write_webp_extended_with_c2pa( string $path, string $manifest_payload ): void {
		$vp8x = self::webp_chunk( 'VP8X', "\x10\x00\x00\x00\x00\x00\x00\x00\x00\x00" );
		$exif = self::webp_chunk( 'EXIF', "Exif\x00\x00" . str_repeat( "\x00", 16 ) );
		$c2pa = self::webp_chunk( 'C2PA', $manifest_payload );
		$vp8l = self::webp_chunk( 'VP8L', "\x2F\x00\x00\x00\x00\x10\x07\x10\x11\x11\x88\x88\x08" );

		$body = 'WEBP' . $vp8x . $exif . $c2pa . $vp8l;
		$riff = 'RIFF' . pack( 'V', strlen( $body ) ) . $body;
		file_put_contents( $path, $riff );
	}

	/**
	 * Writes a WebP file with an odd-length C2PA chunk followed by an EXIF
	 * chunk.
	 *
	 * RIFF requires a single pad byte after odd-length chunk data; if the
	 * detector does not consume the pad, the next chunk header will be parsed
	 * from the wrong offset. The trailing EXIF chunk lets us prove the pad
	 * was consumed correctly: it must be reachable.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path                  Absolute output path.
	 * @param string $manifest_payload_odd  Manifest bytes; must have odd length.
	 * @param string $trailing_exif_payload Bytes for the EXIF chunk that follows C2PA.
	 * @return void
	 */
	public static function write_webp_with_c2pa_odd_length(
		string $path,
		string $manifest_payload_odd,
		string $trailing_exif_payload
	): void {
		$vp8l = self::webp_chunk( 'VP8L', "\x2F\x00\x00\x00\x00\x10\x07\x10\x11\x11\x88\x88\x08" );
		$c2pa = self::webp_chunk( 'C2PA', $manifest_payload_odd );
		$exif = self::webp_chunk( 'EXIF', $trailing_exif_payload );

		$body = 'WEBP' . $vp8l . $c2pa . $exif;
		$riff = 'RIFF' . pack( 'V', strlen( $body ) ) . $body;
		file_put_contents( $path, $riff );
	}

	/**
	 * Writes a WebP file with no C2PA chunks.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path Absolute output path.
	 * @return void
	 */
	public static function write_webp_without_c2pa( string $path ): void {
		$vp8l = self::webp_chunk( 'VP8L', "\x2F\x00\x00\x00\x00\x10\x07\x10\x11\x11\x88\x88\x08" );
		$body = 'WEBP' . $vp8l;
		$riff = 'RIFF' . pack( 'V', strlen( $body ) ) . $body;
		file_put_contents( $path, $riff );
	}

	/**
	 * Writes a non-image file with a `.jpg` extension.
	 *
	 * Used to verify Format_Detector returns null when magic bytes do not
	 * match the extension.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path Absolute output path.
	 * @return void
	 */
	public static function write_text_as_jpeg( string $path ): void {
		file_put_contents( $path, "Not actually a JPEG, just text.\n" );
	}

	/**
	 * Generates a synthetic JUMBF-ish manifest payload with the 'c2pa' tag
	 * near the start, padded out to a meaningful length so SHA-256 / length
	 * assertions can be expressed in tests.
	 *
	 * @since 0.7.0
	 *
	 * @param int $size Desired size in bytes (>= 32).
	 * @return string
	 */
	public static function synthetic_manifest_payload( int $size = 256 ): string {
		if ( $size < 32 ) {
			$size = 32;
		}
		$prefix = "jumbc2pa\x00\x00\x00\x00";
		$body   = str_repeat( "AB", (int) ceil( ( $size - strlen( $prefix ) ) / 2 ) );
		return substr( $prefix . $body, 0, $size );
	}

	/**
	 * Builds a single PNG chunk: length, type, data, CRC32.
	 *
	 * @since 0.7.0
	 *
	 * @param string $type Four-byte chunk type.
	 * @param string $data Chunk data bytes.
	 * @return string
	 */
	private static function png_chunk( string $type, string $data ): string {
		$length = pack( 'N', strlen( $data ) );
		$crc    = pack( 'N', crc32( $type . $data ) );
		return $length . $type . $data . $crc;
	}

	/**
	 * Builds a single RIFF (WebP) chunk with even-byte padding.
	 *
	 * @since 0.7.0
	 *
	 * @param string $type Four-byte chunk type.
	 * @param string $data Chunk data bytes.
	 * @return string
	 */
	private static function webp_chunk( string $type, string $data ): string {
		$header = $type . pack( 'V', strlen( $data ) );
		$pad    = ( strlen( $data ) % 2 === 1 ) ? "\x00" : '';
		return $header . $data . $pad;
	}
}

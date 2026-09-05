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
 * @since x.x.x
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
	 * @since x.x.x
	 *
	 * @param string $path             Absolute output path.
	 * @param string $manifest_payload Synthetic JUMBF bytes to embed.
	 * @return void
	 */
	public static function write_jpeg_with_c2pa( string $path, string $manifest_payload ): void {
		// CI(2) + En(2) + Z(4, uint32 BE, 1 = first) + JUMBF superbox.
		$inner = 'JP' . pack( 'n', 1 ) . pack( 'N', 1 ) . self::build_c2pa_jumbf_store( $manifest_payload );

		$bytes  = "\xFF\xD8";
		$bytes .= "\xFF\xEB" . pack( 'n', strlen( $inner ) + 2 ) . $inner;
		$bytes .= "\xFF\xDA\x00\x02";
		$bytes .= "\xFF\xD9";

		file_put_contents( $path, $bytes );
	}

	/**
	 * Writes a JPEG file whose C2PA manifest is split across N APP11 segments
	 * sharing the same Box Instance Number.
	 *
	 * Mirrors how real C2PA encoders split manifests larger than the JPEG
	 * 64 KiB marker payload limit. The first segment (Z=1) carries the opening
	 * bytes of the JUMBF superbox (LBox+TBox+jumd+content). Each continuation
	 * segment (Z>1) repeats the LBox+TBox header before its content slice,
	 * matching the layout confirmed against real signed assets.
	 *
	 * @since x.x.x
	 *
	 * @param string $path             Absolute output path.
	 * @param string $manifest_payload Content bytes to embed inside the JUMBF store.
	 * @param int    $segment_count    Number of APP11 segments to emit.
	 * @return void
	 */
	public static function write_jpeg_with_c2pa_multi_segment(
		string $path,
		string $manifest_payload,
		int $segment_count
	): void {
		$segment_count = max( 1, $segment_count );
		$jumbf_store   = self::build_c2pa_jumbf_store( $manifest_payload );
		$store_len     = strlen( $jumbf_store );
		// Repeated LBox+TBox header placed at the start of every continuation segment.
		$lbox_tbox     = substr( $jumbf_store, 0, 8 );

		// Split the jumbf_store across $segment_count pieces.
		// Segment 1 inner content: jumbf_store[0..$cut].
		// Segment N inner content (for N>1): $lbox_tbox + jumbf_store[$prev_cut..$cut].
		$base_size     = max( 1, intdiv( $store_len, $segment_count ) );
		// First segment must carry enough bytes for the validator (>= 32 inner bytes).
		$first_cut     = max( 32, $base_size );

		$bytes = "\xFF\xD8";

		// Segment 1 (Z=1): first portion of the JUMBF store.
		$seg1_inner = 'JP' . pack( 'n', 1 ) . pack( 'N', 1 ) . substr( $jumbf_store, 0, $first_cut );
		$bytes     .= "\xFF\xEB" . pack( 'n', strlen( $seg1_inner ) + 2 ) . $seg1_inner;

		// Continuation segments (Z>1): repeated header + content slice.
		$cursor = $first_cut;
		for ( $i = 2; $i <= $segment_count; $i++ ) {
			$is_last  = ( $i === $segment_count );
			$size     = $is_last ? ( $store_len - $cursor ) : $base_size;
			$seg_inner = 'JP' . pack( 'n', 1 ) . pack( 'N', $i )
				. $lbox_tbox
				. substr( $jumbf_store, $cursor, $size );
			$bytes    .= "\xFF\xEB" . pack( 'n', strlen( $seg_inner ) + 2 ) . $seg_inner;
			$cursor   += $size;
		}

		$bytes .= "\xFF\xDA\x00\x02";
		$bytes .= "\xFF\xD9";

		file_put_contents( $path, $bytes );
	}

	/**
	 * Writes a JPEG file containing an APP11 segment with a genuine JUMBF
	 * superbox that carries a non-C2PA type UUID.
	 *
	 * Used to verify Format_Detector rejects APP11 segments whose jumd UUID
	 * does not match the C2PA type UUID, even though the segment is otherwise
	 * structurally valid (jumb+jumd correctly framed).
	 *
	 * @since x.x.x
	 *
	 * @param string $path Absolute output path.
	 * @return void
	 */
	public static function write_jpeg_with_jumbf_non_c2pa( string $path ): void {
		// Build a valid JUMBF superbox with a non-C2PA type UUID.
		$non_c2pa_uuid = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F";
		$jumd_data     = $non_c2pa_uuid . "\x00" . "test\x00";
		$jumd_box      = pack( 'N', 4 + 4 + strlen( $jumd_data ) ) . 'jumd' . $jumd_data;
		$content       = str_repeat( "\x00\x01\x02\x03", 8 );
		$store_size    = 4 + 4 + strlen( $jumd_box ) + strlen( $content );
		$jumbf_store   = pack( 'N', $store_size ) . 'jumb' . $jumd_box . $content;

		$inner = 'JP' . pack( 'n', 5 ) . pack( 'N', 1 ) . $jumbf_store;

		$bytes  = "\xFF\xD8";
		$bytes .= "\xFF\xEB" . pack( 'n', strlen( $inner ) + 2 ) . $inner;
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
	 * @since x.x.x
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

		$app11_inner = 'JP' . pack( 'n', 1 ) . pack( 'N', 1 ) . self::build_c2pa_jumbf_store( $manifest_payload );
		$bytes      .= "\xFF\xEB" . pack( 'n', strlen( $app11_inner ) + 2 ) . $app11_inner;

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
	 * @since x.x.x
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
			$inner  = 'JP' . pack( 'n', 1 ) . pack( 'N', 1 ) . self::build_c2pa_jumbf_store( $trailing_c2pa_payload );
			$bytes .= "\xFF\xEB" . pack( 'n', strlen( $inner ) + 2 ) . $inner;
		}

		$bytes .= "\xFF\xDA\x00\x02";
		$bytes .= "\xFF\xD9";

		file_put_contents( $path, $bytes );
	}

	/**
	 * Writes a JPEG file with no C2PA markers.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * Wraps $content in a spec-correct C2PA JUMBF superbox.
	 *
	 * The layout matches the structure confirmed against real signed assets:
	 *
	 *   LBox(4) + "jumb"(4)                        <- superbox header (at slice +0)
	 *   LBox(4) + "jumd"(4) + C2PA UUID(16) + ...  <- type declaration box
	 *   $content                                    <- nested JUMBF boxes / payload
	 *
	 * The bytes returned are exactly what should appear in the inner slice of a
	 * single-segment APP11 (starting at payload_offset + 8) or across segments.
	 *
	 * @since x.x.x
	 *
	 * @param string $content Payload bytes to embed inside the JUMBF store.
	 * @return string Bytes of the complete JUMBF superbox.
	 */
	public static function build_c2pa_jumbf_store( string $content ): string {
		$c2pa_uuid = "\x63\x32\x70\x61\x00\x11\x00\x10\x80\x00\x00\xAA\x00\x38\x9B\x71";
		$jumd_data = $c2pa_uuid . "\x03" . "c2pa\x00";
		$jumd_box  = pack( 'N', 4 + 4 + strlen( $jumd_data ) ) . 'jumd' . $jumd_data;

		$store_size = 4 + 4 + strlen( $jumd_box ) + strlen( $content );
		return pack( 'N', $store_size ) . 'jumb' . $jumd_box . $content;
	}

	/**
	 * Builds a single PNG chunk: length, type, data, CRC32.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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

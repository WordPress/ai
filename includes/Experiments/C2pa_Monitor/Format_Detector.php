<?php
/**
 * Streaming binary detector for C2PA manifest stores.
 *
 * Pure PHP, read-only. Walks JPEG segments / PNG chunks / WebP RIFF chunks
 * looking for the embedding container that holds a C2PA JUMBF manifest store.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );
// phpcs:disable WordPress.WP.AlternativeFunctions -- See project PHPCS: streaming fopen/fread/fseek for C2PA detection (paths from wp_get_original_image_path), not replaceable with WP_Filesystem::get_contents without full-file memory.
// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions -- VIP Go: reads/writes use paths under wp_upload_dir() (sidecar) or the attachment’s source file only.
namespace WordPress\AI\Experiments\C2pa_Monitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locates a C2PA manifest store inside a supported image container.
 *
 * Returns either an absolute byte offset/length pair pointing at the raw
 * JUMBF payload, or null when none is found or the file is malformed.
 *
 * @since 0.7.0
 */
class Format_Detector {
	/**
	 * Maximum number of JPEG segments to walk before giving up. Realistic
	 * files have well under 1000.
	 *
	 * @var int
	 */
	private const JPEG_MAX_SEGMENTS = 5000;

	/**
	 * Maximum number of PNG chunks to walk before giving up.
	 *
	 * @var int
	 */
	private const PNG_MAX_CHUNKS = 5000;

	/**
	 * Maximum number of WebP RIFF chunks to walk before giving up.
	 *
	 * @var int
	 */
	private const WEBP_MAX_CHUNKS = 5000;

	/**
	 * Maximum number of contiguous JPEG APP11 segments to merge into a single
	 * JUMBF payload. C2PA splits long manifests across many segments.
	 *
	 * @var int
	 */
	private const JPEG_APP11_MAX_SEGMENTS = 4096;

	/**
	 * Detects the image format from magic bytes only.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path Absolute path to the image file.
	 * @return string|null One of 'jpeg', 'png', 'webp', or null when unsupported.
	 */
	public function detect_format( string $path ): ?string {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$fh = fopen( $path, 'rb' );
		if ( false === $fh ) {
			return null;
		}

		try {
			$head = (string) fread( $fh, 12 );
		} finally {
			fclose( $fh );
		}

		if ( strlen( $head ) < 4 ) {
			return null;
		}

		// JPEG: FF D8 FF.
		if ( "\xFF\xD8\xFF" === substr( $head, 0, 3 ) ) {
			return 'jpeg';
		}

		// PNG: 89 50 4E 47 0D 0A 1A 0A.
		if ( "\x89PNG\r\n\x1A\n" === substr( $head, 0, 8 ) ) {
			return 'png';
		}

		// WebP: RIFF....WEBP.
		if ( strlen( $head ) >= 12 && 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			return 'webp';
		}

		return null;
	}

	/**
	 * Locates the manifest payload for the given format.
	 *
	 * Returns an array describing where the JUMBF manifest store lives inside
	 * the source file:
	 *
	 *  - `container`:    short label (e.g. 'APP11/JUMBF', 'PNG/caBX', 'WebP/C2PA').
	 *  - `format`:       'jpeg'|'png'|'webp'.
	 *  - `segments`:     list of [offset, length] byte ranges that, concatenated
	 *                    in order, yield the raw manifest store bytes.
	 *  - `total_length`: sum of segment lengths.
	 *
	 * @since 0.7.0
	 *
	 * @param string $path   Absolute path.
	 * @param string $format Format string returned by detect_format().
	 * @return array<string, mixed>|null Location descriptor or null.
	 */
	public function find_manifest_location( string $path, string $format ): ?array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$fh = fopen( $path, 'rb' );
		if ( false === $fh ) {
			return null;
		}

		try {
			switch ( $format ) {
				case 'jpeg':
					return $this->find_jpeg( $fh );
				case 'png':
					return $this->find_png( $fh );
				case 'webp':
					return $this->find_webp( $fh );
				default:
					return null;
			}
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			fclose( $fh );
		}
	}

	/**
	 * Walks a JPEG file looking for contiguous APP11 / JUMBF segments tagged
	 * with the C2PA box type.
	 *
	 * Per ISO 19566-5 / C2PA 2.x, a JUMBF payload that exceeds the 64 KiB JPEG
	 * marker payload limit is split across multiple APP11 segments sharing the
	 * same `Box Instance Number`. We collect the inner JUMBF bytes from each
	 * such segment and concatenate them.
	 *
	 * @since 0.7.0
	 *
	 * @param resource $fh Open file handle.
	 * @return array<string, mixed>|null
	 */
	private function find_jpeg( $fh ): ?array {
		$soi = (string) fread( $fh, 2 );
		if ( "\xFF\xD8" !== $soi ) {
			return null;
		}

		$segments           = array();
		$total_length       = 0;
		$count              = 0;
		$approved_instances = array();

		while ( $count < self::JPEG_MAX_SEGMENTS ) {
			++$count;

			$marker = $this->read_jpeg_marker( $fh );
			if ( null === $marker ) {
				break;
			}

			// SOS (start of scan): everything after this is entropy-coded; stop.
			if ( 0xDA === $marker ) {
				break;
			}

			// Standalone markers (RST*, SOI, EOI, TEM) carry no payload.
			if ( 0xD0 === ( $marker & 0xF8 ) || 0xD8 === $marker || 0xD9 === $marker || 0x01 === $marker ) {
				if ( 0xD9 === $marker ) {
					break;
				}
				continue;
			}

			$length_bytes = (string) fread( $fh, 2 );
			if ( strlen( $length_bytes ) < 2 ) {
				break;
			}
			$unpacked = unpack( 'n', $length_bytes );
			if ( false === $unpacked ) {
				break;
			}
			$payload_length = (int) $unpacked[1] - 2;
			if ( $payload_length < 0 ) {
				break;
			}

			$payload_offset = (int) ftell( $fh );

			if ( 0xEB === $marker ) {
				$slice = $this->extract_jpeg_app11_jumbf_slice( $fh, $payload_length, $payload_offset, $approved_instances );
				if ( null !== $slice ) {
					$segments[]    = $slice;
					$total_length += $slice[1];
				} elseif ( -1 === fseek( $fh, $payload_offset + $payload_length, SEEK_SET ) ) {
					break;
				}
				continue;
			}

			if ( -1 === fseek( $fh, $payload_offset + $payload_length, SEEK_SET ) ) {
				break;
			}
		}

		if ( empty( $segments ) || count( $segments ) > self::JPEG_APP11_MAX_SEGMENTS ) {
			return null;
		}

		return array(
			'format'       => 'jpeg',
			'container'    => 'APP11/JUMBF',
			'segments'     => $segments,
			'total_length' => $total_length,
		);
	}

	/**
	 * Reads the next JPEG marker byte, skipping any 0xFF padding.
	 *
	 * @since 0.7.0
	 *
	 * @param resource $fh File handle.
	 * @return int|null Marker byte (without the leading 0xFF) or null on EOF.
	 */
	private function read_jpeg_marker( $fh ): ?int {
		$byte = fread( $fh, 1 );
		if ( false === $byte || '' === $byte ) {
			return null;
		}
		if ( "\xFF" !== $byte ) {
			return null;
		}

		while ( true ) {
			$next = fread( $fh, 1 );
			if ( false === $next || '' === $next ) {
				return null;
			}
			if ( "\xFF" === $next ) {
				continue;
			}
			$ord = ord( $next );
			if ( 0x00 === $ord ) {
				continue;
			}
			return $ord;
		}
	}

	/**
	 * Extracts the JUMBF payload bytes from a single JPEG APP11 segment if it
	 * carries C2PA data.
	 *
	 * The APP11 segment layout (per ISO 19566-5) is:
	 *
	 *   2 bytes   Common Identifier ("JP")
	 *   2 bytes   Box Instance Number (uint16 BE)
	 *   8 bytes   Packet Sequence Number (uint64 BE)
	 *   N bytes   JUMBF box bytes (or fragment thereof)
	 *
	 * For long manifests the JUMBF payload is split across multiple APP11
	 * segments that share the same Box Instance Number. Only the first segment
	 * in such a sequence carries the JUMBF box header that identifies it as
	 * C2PA. We therefore track which Box Instance Numbers we have already
	 * approved and accept later segments with the same instance number as
	 * continuation without re-scanning their inner bytes for the C2PA marker.
	 *
	 * @since 0.7.0
	 *
	 * @param resource              $fh                  File handle, positioned at the segment payload.
	 * @param int                   $payload_length      Length of this segment's payload, in bytes.
	 * @param int                   $payload_offset      Absolute offset of the segment payload.
	 * @param array<int, true>      $approved_instances  Set of Box Instance Numbers already classified as C2PA. Updated by reference when a new sequence is approved.
	 * @return array{0:int,1:int}|null [offset, length] of inner JUMBF bytes, or null.
	 */
	private function extract_jpeg_app11_jumbf_slice( $fh, int $payload_length, int $payload_offset, array &$approved_instances ): ?array {
		if ( $payload_length < 12 ) {
			return null;
		}

		$header = (string) fread( $fh, 12 );
		if ( strlen( $header ) < 12 ) {
			return null;
		}

		if ( 'JP' !== substr( $header, 0, 2 ) ) {
			return null;
		}

		$instance_unpack = unpack( 'n', substr( $header, 2, 2 ) );
		if ( false === $instance_unpack ) {
			return null;
		}
		$instance = (int) $instance_unpack[1];

		$inner_length = $payload_length - 12;
		if ( $inner_length <= 0 ) {
			return null;
		}

		if ( isset( $approved_instances[ $instance ] ) ) {
			if ( -1 === fseek( $fh, $payload_offset + $payload_length, SEEK_SET ) ) {
				return null;
			}
			return array( $payload_offset + 12, $inner_length );
		}

		$peek_size = min( 64, $inner_length );
		$peek      = (string) fread( $fh, $peek_size );

		$looks_like_c2pa = false !== strpos( $peek, 'c2pa' ) || false !== strpos( $peek, 'jumb' );

		if ( -1 === fseek( $fh, $payload_offset + $payload_length, SEEK_SET ) ) {
			return null;
		}

		if ( ! $looks_like_c2pa ) {
			return null;
		}

		$approved_instances[ $instance ] = true;
		return array( $payload_offset + 12, $inner_length );
	}

	/**
	 * Walks a PNG file looking for a `caBX` chunk (C2PA storage chunk per
	 * C2PA 2.x §11.5).
	 *
	 * @since 0.7.0
	 *
	 * @param resource $fh File handle positioned at the start of the file.
	 * @return array<string, mixed>|null
	 */
	private function find_png( $fh ): ?array {
		if ( -1 === fseek( $fh, 8, SEEK_SET ) ) {
			return null;
		}

		$count = 0;
		while ( $count < self::PNG_MAX_CHUNKS ) {
			++$count;

			$header = (string) fread( $fh, 8 );
			if ( strlen( $header ) < 8 ) {
				break;
			}

			$unpacked = unpack( 'Nlength/a4type', $header );
			if ( false === $unpacked ) {
				break;
			}

			$length = (int) $unpacked['length'];
			$type   = (string) $unpacked['type'];

			if ( $length < 0 || $length > self::png_max_chunk_size() ) {
				break;
			}

			$data_offset = (int) ftell( $fh );

			if ( 'caBX' === $type ) {
				if ( $length <= 0 ) {
					break;
				}
				return array(
					'format'       => 'png',
					'container'    => 'PNG/caBX',
					'segments'     => array( array( $data_offset, $length ) ),
					'total_length' => $length,
				);
			}

			if ( 'IEND' === $type ) {
				break;
			}

			// Skip data + 4-byte CRC.
			if ( -1 === fseek( $fh, $data_offset + $length + 4, SEEK_SET ) ) {
				break;
			}
		}

		return null;
	}

	/**
	 * Maximum allowable PNG chunk data size. The PNG spec caps chunk length
	 * at 2^31 - 1 bytes; we apply a saner ceiling.
	 *
	 * @since 0.7.0
	 *
	 * @return int
	 */
	private static function png_max_chunk_size(): int {
		return 64 * 1024 * 1024;
	}

	/**
	 * Walks a WebP RIFF container looking for a top-level `C2PA` chunk
	 * (C2PA 2.x §11.6).
	 *
	 * @since 0.7.0
	 *
	 * @param resource $fh File handle.
	 * @return array<string, mixed>|null
	 */
	private function find_webp( $fh ): ?array {
		$riff = (string) fread( $fh, 12 );
		if ( strlen( $riff ) < 12 ) {
			return null;
		}
		if ( 'RIFF' !== substr( $riff, 0, 4 ) || 'WEBP' !== substr( $riff, 8, 4 ) ) {
			return null;
		}

		$count = 0;
		while ( $count < self::WEBP_MAX_CHUNKS ) {
			++$count;

			$header = (string) fread( $fh, 8 );
			if ( strlen( $header ) < 8 ) {
				break;
			}

			$type     = substr( $header, 0, 4 );
			$unpacked = unpack( 'V', substr( $header, 4, 4 ) );
			if ( false === $unpacked ) {
				break;
			}
			$length = (int) $unpacked[1];
			if ( $length < 0 ) {
				break;
			}

			$data_offset = (int) ftell( $fh );

			if ( 'C2PA' === $type ) {
				return array(
					'format'       => 'webp',
					'container'    => 'WebP/C2PA',
					'segments'     => array( array( $data_offset, $length ) ),
					'total_length' => $length,
				);
			}

			$padded = $data_offset + $length + ( $length % 2 );
			if ( -1 === fseek( $fh, $padded, SEEK_SET ) ) {
				break;
			}
		}

		return null;
	}
}

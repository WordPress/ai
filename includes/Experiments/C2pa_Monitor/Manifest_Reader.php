<?php
/**
 * Reads raw C2PA manifest store bytes from a located image segment.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );
// phpcs:disable WordPress.WP.AlternativeFunctions -- Manifests are read with streaming fopen/fread/fseek so the bytes can be hashed in flight; WP_Filesystem::get_contents() would pull the whole file into memory, which MAX_MANIFEST_BYTES exists to avoid.
// phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions -- VIP Go: the only paths read here come from wp_get_original_image_path() for the attachment being processed.
namespace WordPress\AI\Experiments\C2pa_Monitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streaming reader that materializes the bytes pointed to by a Format_Detector
 * location descriptor into a Raw_Manifest value object.
 *
 * Uses streamed SHA-256 hashing so the full manifest is never held in memory
 * twice. The bytes themselves are returned (we need them to write the sidecar
 * file), but bounded by Format_Detector caps and MAX_MANIFEST_BYTES.
 *
 * @since x.x.x
 */
class Manifest_Reader {
	/**
	 * Largest manifest payload to ever materialize. C2PA manifests in the
	 * wild are typically well under 1 MB; cap is a defensive ceiling.
	 *
	 * @var int
	 */
	public const MAX_MANIFEST_BYTES = 16 * 1024 * 1024;

	/**
	 * Streaming read buffer size.
	 *
	 * @var int
	 */
	private const READ_BUFFER = 65536;

	/**
	 * Reads the manifest payload described by $location into a Raw_Manifest.
	 *
	 * @since x.x.x
	 *
	 * @param string                $path     Absolute path to the source image.
	 * @param array<string, mixed>  $location Descriptor returned by Format_Detector.
	 * @return \WordPress\AI\Experiments\C2pa_Monitor\Raw_Manifest|null
	 */
	public function read( string $path, array $location ): ?Raw_Manifest {
		$format       = isset( $location['format'] ) ? (string) $location['format'] : '';
		$container    = isset( $location['container'] ) ? (string) $location['container'] : '';
		$segments     = isset( $location['segments'] ) && is_array( $location['segments'] ) ? $location['segments'] : array();
		$total_length = isset( $location['total_length'] ) ? (int) $location['total_length'] : 0;

		if ( '' === $format || empty( $segments ) || $total_length <= 0 ) {
			return null;
		}

		if ( $total_length > self::MAX_MANIFEST_BYTES ) {
			return null;
		}

		$sum = 0;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $segment ) || count( $segment ) < 2 ) {
				return null;
			}
			$seg_len = (int) $segment[1];
			if ( $seg_len <= 0 ) {
				return null;
			}
			$sum += $seg_len;
		}
		if ( $sum !== $total_length ) {
			return null;
		}

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$fh = fopen( $path, 'rb' );
		if ( false === $fh ) {
			return null;
		}

		$buffer  = '';
		$hashctx = hash_init( 'sha256' );

		try {
			foreach ( $segments as $segment ) {
				if ( count( $segment ) < 2 ) {
					return null;
				}
				$offset = (int) $segment[0];
				$length = (int) $segment[1];
				if ( $offset < 0 || $length <= 0 ) {
					return null;
				}

				if ( -1 === fseek( $fh, $offset, SEEK_SET ) ) {
					return null;
				}

				$remaining = $length;
				while ( $remaining > 0 ) {
					$want  = $remaining < self::READ_BUFFER ? $remaining : self::READ_BUFFER;
					$chunk = fread( $fh, $want );
					if ( false === $chunk || '' === $chunk ) {
						return null;
					}
					$buffer    .= $chunk;
					$remaining -= strlen( $chunk );
					hash_update( $hashctx, $chunk );

					if ( strlen( $buffer ) > self::MAX_MANIFEST_BYTES ) {
						return null;
					}
				}
			}
		} catch ( \Throwable $e ) {
			return null;
		} finally {
			fclose( $fh );
		}

		$sha256 = hash_final( $hashctx );
		$length = strlen( $buffer );
		if ( 0 === $length ) {
			return null;
		}

		return new Raw_Manifest( $format, $container, $sha256, $length, $buffer );
	}
}

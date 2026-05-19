<?php
/**
 * C2PA Monitor experiment.
 *
 * Read-only capture of C2PA Content Credentials presence and the raw
 * JUMBF manifest bytes at attachment upload. Stores a structured record
 * in postmeta and writes the raw manifest to a sidecar file.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\C2pa_Monitor;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C2PA Monitor experiment class.
 *
 * Hooks into add_attachment and captures a structured `_wpai_monitor_record`
 * for every uploaded image. The capture is read-only, fail-open, and never
 * blocks the upload pipeline.
 *
 * @since 0.7.0
 */
class C2pa_Monitor extends Abstract_Feature {
	/**
	 * Postmeta key used to store the structured monitor record.
	 *
	 * @since 0.7.0
	 *
	 * @var string
	 */
	public const POSTMETA_KEY = '_wpai_monitor_record';

	/**
	 * Schema version for the postmeta record. Increment on breaking changes.
	 *
	 * @since 0.7.0
	 *
	 * @var int
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Hard cap on a single image scan. Files larger than this are skipped.
	 *
	 * @since 0.7.0
	 *
	 * @var int
	 */
	public const MAX_SCAN_BYTES = 67108864; // 64 MB.

	/**
	 * JSON-LD context URL embedded in every stored postmeta record.
	 *
	 * Interim URL resolving via raw.githubusercontent.com. Migrate to
	 * https://w3id.org/openverifiable/v1 once the w3id.org PR is merged
	 * and the openverifiable/contexts repo is live (Phase 3 of the OVE
	 * vocabulary hosting plan). Bump SCHEMA_VERSION when this changes.
	 *
	 * @todo Migrate to https://w3id.org/openverifiable/v1 (Phase 3).
	 *
	 * @since 0.7.0
	 *
	 * @var string
	 */
	public const CONTEXT_URL = 'https://raw.githubusercontent.com/decentralized-identity/credential-schemas/main/community-schemas/WordPress/schemas/wpai-monitor-record/context.json';

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'c2pa-monitor';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'C2PA Monitor', 'ai' ),
			'description' => __( 'Detects C2PA Content Credentials in uploaded images and stores the raw manifest plus a structured record in postmeta. Read-only and fail-open; never blocks an upload.', 'ai' ),
			'category'    => Experiment_Category::ADMIN,
			'stability'   => 'experimental',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'add_attachment', array( $this, 'capture_for_attachment' ), 20, 1 );
	}

	/**
	 * Captures C2PA presence and raw manifest for a freshly created attachment.
	 *
	 * Wrapped in a fail-open boundary: issues are recorded in the `errors`
	 * array inside the persisted postmeta (when this experiment applies to the
	 * attachment) alongside whatever partial data was collected. This handler
	 * never throws, never returns an error, and never blocks the upload.
	 * Unsupported MIME types are left untouched: no postmeta is written.
	 *
	 * @since 0.7.0
	 *
	 * @param int $attachment_id The newly created attachment ID.
	 * @return void
	 */
	public function capture_for_attachment( int $attachment_id ): void {
		$started_at     = microtime( true );
		$should_persist = true;
		$errors         = array();
		$source         = array(
			'attachment_id'          => $attachment_id,
			'original_path_relative' => '',
			'size_bytes'             => 0,
			'mime'                   => '',
		);
		$c2pa           = array(
			'present' => false,
			'format'  => null,
		);

		try {
			$mime           = (string) get_post_mime_type( $attachment_id );
			$source['mime'] = $mime;

			if ( ! self::is_supported_mime( $mime ) ) {
				$should_persist = false;
				return;
			}

			$path = self::get_original_path( $attachment_id );
			if ( '' === $path || ! is_readable( $path ) ) {
				$errors[] = array(
					'stage'   => 'resolve_path',
					'message' => 'Attachment file is not readable.',
				);
				return;
			}

			$size = filesize( $path );
			if ( false === $size ) {
				$errors[] = array(
					'stage'   => 'stat',
					'message' => 'filesize() returned false.',
				);
				return;
			}

			$source['size_bytes']             = (int) $size;
			$source['original_path_relative'] = self::relative_to_uploads( $path );

			if ( $size > self::MAX_SCAN_BYTES ) {
				$errors[] = array(
					'stage'   => 'size_cap',
					'message' => sprintf( 'File exceeds MAX_SCAN_BYTES (%d).', self::MAX_SCAN_BYTES ),
				);
				return;
			}

			$detector       = new Format_Detector();
			$format         = $detector->detect_format( $path );
			$c2pa['format'] = $format;

			if ( null === $format ) {
				return;
			}

			$location = $detector->find_manifest_location( $path, $format );
			if ( null === $location ) {
				return;
			}

			$reader   = new Manifest_Reader();
			$manifest = $reader->read( $path, $location );
			if ( null === $manifest ) {
				$errors[] = array(
					'stage'   => 'read_manifest',
					'message' => 'Manifest_Reader returned null.',
				);
				return;
			}

			$writer = new Sidecar_Writer();
			$rel    = $writer->write( $attachment_id, $manifest );

			$c2pa = array(
				'present'               => true,
				'format'                => $manifest->format,
				'container'             => $manifest->container,
				'manifest_sha256'       => $manifest->sha256,
				'manifest_length'       => $manifest->bytes_length,
				'sidecar_path_relative' => $rel,
				'decoded'               => null,
			);
		} catch ( \RuntimeException $e ) {
			$errors[] = array(
				'stage'   => 'sidecar_write',
				'message' => $e->getMessage(),
			);
		} catch ( \Throwable $e ) {
			$errors[] = array(
				'stage'   => 'unexpected',
				'message' => $e->getMessage(),
			);
		} finally {
			if ( $should_persist ) {
				$duration_ms = (int) round( ( microtime( true ) - $started_at ) * 1000 );
				Record::store(
					$attachment_id,
					array(
						'@context'       => array( 'https://schema.org/', self::CONTEXT_URL ),
						'schema_version' => self::SCHEMA_VERSION,
						'captured_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
						'duration_ms'    => $duration_ms,
						'source'         => $source,
						'traditional'    => array(
							'exif' => array(),
							'iptc' => array(),
							'xmp'  => array(),
						),
						'c2pa'           => $c2pa,
						'errors'         => $errors,
					)
				);
			}
		}
	}

	/**
	 * Returns true for image MIME types this experiment knows how to inspect.
	 *
	 * @since 0.7.0
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public static function is_supported_mime( string $mime ): bool {
		return in_array(
			$mime,
			array( 'image/jpeg', 'image/png', 'image/webp' ),
			true
		);
	}

	/**
	 * Resolves the absolute path to the original uploaded file.
	 *
	 * Falls back to get_attached_file() when wp_get_original_image_path() does
	 * not return a usable path (non-image attachments, edited media, etc.).
	 *
	 * @since 0.7.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Absolute filesystem path, or empty string when unresolved.
	 */
	private static function get_original_path( int $attachment_id ): string {
		if ( function_exists( 'wp_get_original_image_path' ) ) {
			$path = wp_get_original_image_path( $attachment_id );
			if ( is_string( $path ) && '' !== $path ) {
				return $path;
			}
		}

		$path = get_attached_file( $attachment_id );
		return is_string( $path ) ? $path : '';
	}

	/**
	 * Returns the path relative to the uploads basedir, or the absolute path
	 * if it lives outside uploads.
	 *
	 * @since 0.7.0
	 *
	 * @param string $absolute Absolute path.
	 * @return string Relative path or original absolute path.
	 */
	private static function relative_to_uploads( string $absolute ): string {
		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
			return $absolute;
		}

		$basedir = trailingslashit( (string) $uploads['basedir'] );
		if ( 0 === strpos( $absolute, $basedir ) ) {
			return substr( $absolute, strlen( $basedir ) );
		}

		return $absolute;
	}
}

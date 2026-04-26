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
 * Layer 1: experiment metadata and registration only; no capture hook.
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
		// Intake hook is registered in a later layer.
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
}

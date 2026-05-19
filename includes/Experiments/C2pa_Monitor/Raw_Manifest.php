<?php
/**
 * Raw_Manifest value object.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\C2pa_Monitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable descriptor for an extracted C2PA manifest store.
 *
 * Carries the raw bytes alongside size + content hash. PR 1 does not decode
 * JUMBF or CBOR; downstream consumers receive `bytes` verbatim and may parse
 * them out of band.
 *
 * @since 0.7.0
 */
final class Raw_Manifest {
	/**
	 * Image container format (jpeg, png, webp).
	 *
	 * @since 0.7.0
	 *
	 * @var string
	 */
	public string $format;

	/**
	 * Container label, e.g. 'APP11/JUMBF', 'PNG/caBX', 'WebP/C2PA'.
	 *
	 * @since 0.7.0
	 *
	 * @var string
	 */
	public string $container;

	/**
	 * SHA-256 of the raw manifest bytes (lowercase hex).
	 *
	 * @since 0.7.0
	 *
	 * @var string
	 */
	public string $sha256;

	/**
	 * Length of the raw manifest bytes in octets.
	 *
	 * @since 0.7.0
	 *
	 * @var int
	 */
	public int $bytes_length;

	/**
	 * Raw manifest store bytes.
	 *
	 * @since 0.7.0
	 *
	 * @var string
	 */
	public string $bytes;

	/**
	 * @param string $format       Image format string.
	 * @param string $container    Container label.
	 * @param string $sha256       Lowercase hex SHA-256 of $bytes.
	 * @param int    $bytes_length Length of $bytes in octets.
	 * @param string $bytes        Raw manifest store bytes.
	 */
	public function __construct(
		string $format,
		string $container,
		string $sha256,
		int $bytes_length,
		string $bytes
	) {
		$this->format       = $format;
		$this->container    = $container;
		$this->sha256       = $sha256;
		$this->bytes_length = $bytes_length;
		$this->bytes        = $bytes;
	}
}

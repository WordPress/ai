<?php
/**
 * Unit tests for the C2PA Monitor Manifest_Reader.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor;

use WP_UnitTestCase;
use WordPress\AI\Experiments\C2pa_Monitor\Format_Detector;
use WordPress\AI\Experiments\C2pa_Monitor\Manifest_Reader;
use WordPress\AI\Experiments\C2pa_Monitor\Raw_Manifest;

require_once __DIR__ . '/Fixtures.php';

/**
 * Verifies Manifest_Reader returns a Raw_Manifest with a stable hash and that
 * the reported byte length matches the input payload.
 *
 * @since 0.7.0
 */
class Manifest_ReaderTest extends WP_UnitTestCase {
	/**
	 * Temp dir per test.
	 *
	 * @var string
	 */
	private string $tmp_dir = '';

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();
		$this->tmp_dir = sys_get_temp_dir() . '/wpai-c2pa-reader-' . uniqid( '', true );
		mkdir( $this->tmp_dir, 0700, true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		if ( '' !== $this->tmp_dir && is_dir( $this->tmp_dir ) ) {
			foreach ( glob( $this->tmp_dir . '/*' ) ?: array() as $f ) {
				@unlink( $f );
			}
			@rmdir( $this->tmp_dir );
		}
		parent::tearDown();
	}

	/**
	 * Reading a JPEG/APP11 manifest yields the exact payload bytes.
	 */
	public function test_read_returns_exact_payload_for_jpeg(): void {
		$path    = $this->tmp_dir . '/with.jpg';
		$payload = Fixtures::synthetic_manifest_payload( 512 );
		Fixtures::write_jpeg_with_c2pa( $path, $payload );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'jpeg' );
		$this->assertIsArray( $location );

		$reader   = new Manifest_Reader();
		$manifest = $reader->read( $path, $location );

		$this->assertInstanceOf( Raw_Manifest::class, $manifest );
		$this->assertSame( 'jpeg', $manifest->format );
		$this->assertSame( strlen( $payload ), $manifest->bytes_length );
		$this->assertSame( hash( 'sha256', $payload ), $manifest->sha256 );
		$this->assertSame( $payload, $manifest->bytes );
	}

	/**
	 * SHA-256 is stable across repeat reads of the same file.
	 */
	public function test_read_is_deterministic(): void {
		$path    = $this->tmp_dir . '/with.png';
		$payload = Fixtures::synthetic_manifest_payload( 1024 );
		Fixtures::write_png_with_c2pa( $path, $payload );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'png' );

		$reader = new Manifest_Reader();
		$first  = $reader->read( $path, $location );
		$second = $reader->read( $path, $location );

		$this->assertNotNull( $first );
		$this->assertNotNull( $second );
		$this->assertSame( $first->sha256, $second->sha256 );
		$this->assertSame( $first->bytes_length, $second->bytes_length );
	}

	/**
	 * Locations with zero total length are rejected.
	 */
	public function test_read_returns_null_for_zero_length(): void {
		$path = $this->tmp_dir . '/zero.png';
		Fixtures::write_png_without_c2pa( $path );

		$reader = new Manifest_Reader();
		$this->assertNull(
			$reader->read(
				$path,
				array(
					'format'       => 'png',
					'container'    => 'PNG/caBX',
					'segments'     => array( array( 0, 0 ) ),
					'total_length' => 0,
				)
			)
		);
	}

	/**
	 * Bogus offsets do not throw and return null.
	 */
	public function test_read_handles_bad_offsets(): void {
		$path    = $this->tmp_dir . '/with.webp';
		$payload = Fixtures::synthetic_manifest_payload( 256 );
		Fixtures::write_webp_with_c2pa( $path, $payload );

		$reader = new Manifest_Reader();
		$result = $reader->read(
			$path,
			array(
				'format'       => 'webp',
				'container'    => 'WebP/C2PA',
				'segments'     => array( array( 999999999, 256 ) ),
				'total_length' => 256,
			)
		);

		$this->assertNull( $result );
	}

	/**
	 * PNG/caBX read produces a Raw_Manifest whose bytes are byte-exact and
	 * whose sha256 matches the synthetic payload.
	 */
	public function test_read_returns_exact_payload_for_png(): void {
		$path    = $this->tmp_dir . '/with.png';
		$payload = Fixtures::synthetic_manifest_payload( 768 );
		Fixtures::write_png_with_c2pa( $path, $payload );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'png' );
		$this->assertIsArray( $location );

		$reader   = new Manifest_Reader();
		$manifest = $reader->read( $path, $location );

		$this->assertInstanceOf( Raw_Manifest::class, $manifest );
		$this->assertSame( 'png', $manifest->format );
		$this->assertSame( strlen( $payload ), $manifest->bytes_length );
		$this->assertSame( $payload, $manifest->bytes );
		$this->assertSame( hash( 'sha256', $payload ), $manifest->sha256 );
	}

	/**
	 * WebP/C2PA read produces a Raw_Manifest whose bytes are byte-exact and
	 * whose sha256 matches the synthetic payload.
	 */
	public function test_read_returns_exact_payload_for_webp(): void {
		$path    = $this->tmp_dir . '/with.webp';
		$payload = Fixtures::synthetic_manifest_payload( 384 );
		Fixtures::write_webp_with_c2pa( $path, $payload );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'webp' );
		$this->assertIsArray( $location );

		$reader   = new Manifest_Reader();
		$manifest = $reader->read( $path, $location );

		$this->assertInstanceOf( Raw_Manifest::class, $manifest );
		$this->assertSame( 'webp', $manifest->format );
		$this->assertSame( strlen( $payload ), $manifest->bytes_length );
		$this->assertSame( $payload, $manifest->bytes );
		$this->assertSame( hash( 'sha256', $payload ), $manifest->sha256 );
	}

	/**
	 * Multi-segment JPEG: reader must reassemble the manifest bytes exactly,
	 * concatenating slices in the order returned by the detector.
	 */
	public function test_read_reassembles_multi_segment_jpeg(): void {
		$path           = $this->tmp_dir . '/multi.jpg';
		$payload        = Fixtures::synthetic_manifest_payload( 1000 );
		$segment_count  = 5;
		Fixtures::write_jpeg_with_c2pa_multi_segment( $path, $payload, $segment_count );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'jpeg' );
		$this->assertIsArray( $location );
		$this->assertCount( $segment_count, $location['segments'] );

		$reader   = new Manifest_Reader();
		$manifest = $reader->read( $path, $location );

		$this->assertInstanceOf( Raw_Manifest::class, $manifest );
		$this->assertSame( $payload, $manifest->bytes );
		$this->assertSame( hash( 'sha256', $payload ), $manifest->sha256 );
	}

	/**
	 * Locations whose total_length exceeds the configured cap are rejected.
	 */
	public function test_read_rejects_oversize_total_length(): void {
		$path    = $this->tmp_dir . '/with.jpg';
		$payload = Fixtures::synthetic_manifest_payload( 256 );
		Fixtures::write_jpeg_with_c2pa( $path, $payload );

		$reader = new Manifest_Reader();
		$result = $reader->read(
			$path,
			array(
				'format'       => 'jpeg',
				'container'    => 'APP11/JUMBF',
				'segments'     => array( array( 0, 256 ) ),
				'total_length' => Manifest_Reader::MAX_MANIFEST_BYTES + 1,
			)
		);

		$this->assertNull( $result );
	}

	/**
	 * Mismatch between the sum of segment lengths and `total_length` is
	 * rejected so a bad detector cannot cause over-reads or bad hashes.
	 */
	public function test_read_returns_null_when_segment_sum_mismatches_total_length(): void {
		$path    = $this->tmp_dir . '/with.jpg';
		$payload = Fixtures::synthetic_manifest_payload( 128 );
		Fixtures::write_jpeg_with_c2pa( $path, $payload );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'jpeg' );
		$this->assertIsArray( $location );
		$location['total_length'] = $location['total_length'] + 1;

		$reader   = new Manifest_Reader();
		$manifest = $reader->read( $path, $location );
		$this->assertNull( $manifest );
	}

	/**
	 * Reading from a missing file path returns null without throwing.
	 */
	public function test_read_returns_null_for_missing_file(): void {
		$reader = new Manifest_Reader();
		$result = $reader->read(
			'/nonexistent/path/does-not-exist.jpg',
			array(
				'format'       => 'jpeg',
				'container'    => 'APP11/JUMBF',
				'segments'     => array( array( 0, 16 ) ),
				'total_length' => 16,
			)
		);

		$this->assertNull( $result );
	}

	/**
	 * Empty segment list with zero total_length returns null (no manifest to
	 * reassemble).
	 */
	public function test_read_returns_null_for_empty_segments(): void {
		$path    = $this->tmp_dir . '/with.jpg';
		$payload = Fixtures::synthetic_manifest_payload( 64 );
		Fixtures::write_jpeg_with_c2pa( $path, $payload );

		$reader = new Manifest_Reader();
		$result = $reader->read(
			$path,
			array(
				'format'       => 'jpeg',
				'container'    => 'APP11/JUMBF',
				'segments'     => array(),
				'total_length' => 0,
			)
		);

		$this->assertNull( $result );
	}
}

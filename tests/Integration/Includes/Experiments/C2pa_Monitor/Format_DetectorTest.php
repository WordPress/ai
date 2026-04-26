<?php
/**
 * Unit tests for the C2PA Monitor Format_Detector.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor;

use WP_UnitTestCase;
use WordPress\AI\Experiments\C2pa_Monitor\Format_Detector;

require_once __DIR__ . '/Fixtures.php';

/**
 * Exercises Format_Detector across each supported container, plus negatives.
 *
 * @since 0.7.0
 */
class Format_DetectorTest extends WP_UnitTestCase {
	/**
	 * Temporary directory created per test.
	 *
	 * @var string
	 */
	private string $tmp_dir = '';

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();
		$this->tmp_dir = sys_get_temp_dir() . '/wpai-c2pa-detector-' . uniqid( '', true );
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
	 * Format detection returns the expected magic-bytes label.
	 */
	public function test_detect_format_recognizes_supported_containers(): void {
		$jpeg = $this->tmp_dir . '/sample.jpg';
		$png  = $this->tmp_dir . '/sample.png';
		$webp = $this->tmp_dir . '/sample.webp';
		$text = $this->tmp_dir . '/sample-text.jpg';

		Fixtures::write_jpeg_without_c2pa( $jpeg );
		Fixtures::write_png_without_c2pa( $png );
		Fixtures::write_webp_without_c2pa( $webp );
		Fixtures::write_text_as_jpeg( $text );

		$detector = new Format_Detector();

		$this->assertSame( 'jpeg', $detector->detect_format( $jpeg ) );
		$this->assertSame( 'png', $detector->detect_format( $png ) );
		$this->assertSame( 'webp', $detector->detect_format( $webp ) );
		$this->assertNull( $detector->detect_format( $text ) );
		$this->assertNull( $detector->detect_format( $this->tmp_dir . '/missing.jpg' ) );
	}

	/**
	 * JPEG/APP11 manifest is located and returns a non-empty segment list.
	 */
	public function test_find_manifest_jpeg_app11(): void {
		$path    = $this->tmp_dir . '/with-c2pa.jpg';
		$payload = Fixtures::synthetic_manifest_payload( 256 );
		Fixtures::write_jpeg_with_c2pa( $path, $payload );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'jpeg' );

		$this->assertIsArray( $location );
		$this->assertSame( 'jpeg', $location['format'] );
		$this->assertSame( 'APP11/JUMBF', $location['container'] );
		$this->assertSame( strlen( $payload ), $location['total_length'] );
		$this->assertCount( 1, $location['segments'] );
	}

	/**
	 * PNG/caBX manifest is located.
	 */
	public function test_find_manifest_png_cabx(): void {
		$path    = $this->tmp_dir . '/with-c2pa.png';
		$payload = Fixtures::synthetic_manifest_payload( 512 );
		Fixtures::write_png_with_c2pa( $path, $payload );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'png' );

		$this->assertIsArray( $location );
		$this->assertSame( 'PNG/caBX', $location['container'] );
		$this->assertSame( strlen( $payload ), $location['total_length'] );
	}

	/**
	 * WebP/C2PA manifest is located.
	 */
	public function test_find_manifest_webp(): void {
		$path    = $this->tmp_dir . '/with-c2pa.webp';
		$payload = Fixtures::synthetic_manifest_payload( 384 );
		Fixtures::write_webp_with_c2pa( $path, $payload );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'webp' );

		$this->assertIsArray( $location );
		$this->assertSame( 'WebP/C2PA', $location['container'] );
		$this->assertSame( strlen( $payload ), $location['total_length'] );
	}

	/**
	 * Container files without C2PA segments return null.
	 */
	public function test_find_manifest_returns_null_when_no_c2pa(): void {
		$jpeg = $this->tmp_dir . '/clean.jpg';
		$png  = $this->tmp_dir . '/clean.png';
		$webp = $this->tmp_dir . '/clean.webp';
		Fixtures::write_jpeg_without_c2pa( $jpeg );
		Fixtures::write_png_without_c2pa( $png );
		Fixtures::write_webp_without_c2pa( $webp );

		$detector = new Format_Detector();

		$this->assertNull( $detector->find_manifest_location( $jpeg, 'jpeg' ) );
		$this->assertNull( $detector->find_manifest_location( $png, 'png' ) );
		$this->assertNull( $detector->find_manifest_location( $webp, 'webp' ) );
	}

	/**
	 * Truncated JPEG never throws and returns null.
	 */
	public function test_find_manifest_handles_truncated_jpeg(): void {
		$path = $this->tmp_dir . '/trunc.jpg';
		Fixtures::write_jpeg_truncated( $path );

		$detector = new Format_Detector();
		$this->assertNull( $detector->find_manifest_location( $path, 'jpeg' ) );
	}

	/**
	 * Multi-segment JPEG: APP11 segments sharing a Box Instance Number must
	 * all be collected, and the total length must equal the sum of payload
	 * slices.
	 */
	public function test_find_manifest_jpeg_app11_spans_multiple_segments(): void {
		$path           = $this->tmp_dir . '/multi.jpg';
		$payload        = Fixtures::synthetic_manifest_payload( 600 );
		$segment_count  = 3;
		Fixtures::write_jpeg_with_c2pa_multi_segment( $path, $payload, $segment_count );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'jpeg' );

		$this->assertIsArray( $location );
		$this->assertSame( 'APP11/JUMBF', $location['container'] );
		$this->assertCount( $segment_count, $location['segments'] );
		$this->assertSame( strlen( $payload ), $location['total_length'] );
	}

	/**
	 * APP11 segments carrying generic JUMBF (not C2PA) must be ignored.
	 */
	public function test_find_manifest_jpeg_ignores_non_c2pa_jumbf(): void {
		$path = $this->tmp_dir . '/jumbf-other.jpg';
		Fixtures::write_jpeg_with_jumbf_non_c2pa( $path );

		$detector = new Format_Detector();
		$this->assertNull( $detector->find_manifest_location( $path, 'jpeg' ) );
	}

	/**
	 * C2PA must be located even when surrounded by other APP segments
	 * (APP0/JFIF, APP1/EXIF, APP2/ICC).
	 */
	public function test_find_manifest_jpeg_with_other_app_segments_present(): void {
		$path    = $this->tmp_dir . '/interleaved.jpg';
		$payload = Fixtures::synthetic_manifest_payload( 256 );
		Fixtures::write_jpeg_with_app_segments_around_c2pa( $path, $payload );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'jpeg' );

		$this->assertIsArray( $location );
		$this->assertSame( strlen( $payload ), $location['total_length'] );
	}

	/**
	 * Detection must bail when the JPEG segment cap is exceeded, even if a
	 * legitimate C2PA segment lives past the cap.
	 */
	public function test_find_manifest_bails_when_jpeg_max_segments_exceeded(): void {
		$path    = $this->tmp_dir . '/many.jpg';
		$payload = Fixtures::synthetic_manifest_payload( 128 );
		Fixtures::write_jpeg_with_many_app_segments( $path, 5050, $payload );

		$detector = new Format_Detector();
		$this->assertNull( $detector->find_manifest_location( $path, 'jpeg' ) );
	}

	/**
	 * Detection succeeds when the C2PA segment is preceded by many APP
	 * segments under the JPEG_MAX_SEGMENTS cap. Sanity check on the cap test
	 * above so the failure mode is clearly attributable to the cap, not to
	 * any APP-walking bug.
	 */
	public function test_find_manifest_succeeds_with_many_app_segments_under_cap(): void {
		$path    = $this->tmp_dir . '/many-ok.jpg';
		$payload = Fixtures::synthetic_manifest_payload( 128 );
		Fixtures::write_jpeg_with_many_app_segments( $path, 100, $payload );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'jpeg' );

		$this->assertIsArray( $location );
		$this->assertSame( strlen( $payload ), $location['total_length'] );
	}

	/**
	 * Extended WebP (VP8X + EXIF + C2PA + VP8L) must still surface the C2PA
	 * chunk.
	 */
	public function test_find_manifest_webp_extended_container(): void {
		$path    = $this->tmp_dir . '/ext.webp';
		$payload = Fixtures::synthetic_manifest_payload( 384 );
		Fixtures::write_webp_extended_with_c2pa( $path, $payload );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'webp' );

		$this->assertIsArray( $location );
		$this->assertSame( 'WebP/C2PA', $location['container'] );
		$this->assertSame( strlen( $payload ), $location['total_length'] );
	}

	/**
	 * Odd-length C2PA payload requires a single pad byte to be consumed
	 * before the next chunk header is read. Detection must report the
	 * correct unpadded length and the trailing chunk must remain reachable
	 * (verified indirectly by reading the C2PA bytes back via Manifest_Reader
	 * in the reader tests).
	 */
	public function test_find_manifest_webp_handles_odd_length_padding(): void {
		$path    = $this->tmp_dir . '/odd.webp';
		$payload = str_repeat( 'X', 257 );
		Fixtures::write_webp_with_c2pa_odd_length( $path, $payload, 'EXIFTRAILING' );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'webp' );

		$this->assertIsArray( $location );
		$this->assertSame( 257, $location['total_length'] );
	}
}

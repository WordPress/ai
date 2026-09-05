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
 * @since x.x.x
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
		$this->assertSame( strlen( Fixtures::build_c2pa_jumbf_store( $payload ) ), $location['total_length'] );
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
		$this->assertSame( strlen( Fixtures::build_c2pa_jumbf_store( $payload ) ), $location['total_length'] );
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
		$this->assertSame( strlen( Fixtures::build_c2pa_jumbf_store( $payload ) ), $location['total_length'] );
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
		$this->assertSame( strlen( Fixtures::build_c2pa_jumbf_store( $payload ) ), $location['total_length'] );
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

	/**
	 * Real signed JPEG (XCA.jpg, two APP11 segments): the reassembled total
	 * matches the LBox declared in the file's own JUMBF superbox header.
	 *
	 * This is a self-validating assertion — it does not hardcode a magic
	 * number but reads the declared length from the file itself, so it proves
	 * the slicer is correctly preserving the LBox/TBox prefix.
	 */
	public function test_find_manifest_real_jpeg_matches_declared_lbox(): void {
		$path = dirname( __DIR__, 4 ) . '/fixtures/c2pa/XCA.jpg';
		if ( ! is_file( $path ) ) {
			$this->markTestSkipped( 'XCA.jpg fixture not found.' );
		}

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'jpeg' );

		$this->assertIsArray( $location );
		$this->assertSame( 'APP11/JUMBF', $location['container'] );

		// Read LBox from the file's first JUMBF superbox (segment 1, payload+8, 4 bytes uint32 BE).
		$fh = fopen( $path, 'rb' );
		$this->assertNotFalse( $fh );
		$seg1_offset = $location['segments'][0][0];
		fseek( $fh, $seg1_offset, SEEK_SET );
		$lbox_raw = fread( $fh, 4 );
		fclose( $fh );
		$this->assertIsString( $lbox_raw );
		$this->assertSame( 4, strlen( $lbox_raw ) );
		$unpacked = unpack( 'N', $lbox_raw );
		$lbox     = (int) $unpacked[1];

		$this->assertSame( $lbox, $location['total_length'] );
		$this->assertSame( 126523, $location['total_length'] );
	}

	/**
	 * Real JPEG with no APP11 segments (A.jpg) must return null.
	 */
	public function test_real_jpeg_without_manifest_returns_null(): void {
		$path = dirname( __DIR__, 4 ) . '/fixtures/c2pa/A.jpg';
		if ( ! is_file( $path ) ) {
			$this->markTestSkipped( 'A.jpg fixture not found.' );
		}

		$detector = new Format_Detector();
		$this->assertNull( $detector->find_manifest_location( $path, 'jpeg' ) );
	}

	/**
	 * A continuation segment whose payload_length is below 16 bytes must be
	 * rejected, covering the new >= 16 length guard on continuation segments.
	 */
	public function test_find_manifest_rejects_continuation_segment_too_short(): void {
		// Build a JPEG with a valid first segment followed by a continuation
		// segment that is too short to hold the 16-byte prefix (CI+En+Z+LBox+TBox).
		$path = $this->tmp_dir . '/short-cont.jpg';

		$jumbf  = Fixtures::build_c2pa_jumbf_store( str_repeat( 'A', 64 ) );
		$lbox_tbox = substr( $jumbf, 0, 8 );

		// Segment 1 (Z=1): full first segment.
		$seg1_inner = 'JP' . pack( 'n', 1 ) . pack( 'N', 1 ) . $jumbf;

		// Segment 2 (Z=2): only 12 bytes of payload (too short; minimum is 16).
		// CI+En+Z (8 bytes) + 4 bytes of LBox = 12 bytes total.
		$seg2_inner = 'JP' . pack( 'n', 1 ) . pack( 'N', 2 ) . substr( $lbox_tbox, 0, 4 );

		$bytes  = "\xFF\xD8";
		$bytes .= "\xFF\xEB" . pack( 'n', strlen( $seg1_inner ) + 2 ) . $seg1_inner;
		$bytes .= "\xFF\xEB" . pack( 'n', strlen( $seg2_inner ) + 2 ) . $seg2_inner;
		$bytes .= "\xFF\xDA\x00\x02";
		$bytes .= "\xFF\xD9";

		file_put_contents( $path, $bytes );

		$detector = new Format_Detector();
		$location = $detector->find_manifest_location( $path, 'jpeg' );

		// The first segment must be found; the second is too short and must be skipped.
		$this->assertIsArray( $location );
		$this->assertCount( 1, $location['segments'] );
	}
}

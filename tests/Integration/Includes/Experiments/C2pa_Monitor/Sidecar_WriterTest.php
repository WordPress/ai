<?php
/**
 * Integration tests for the C2PA Monitor Sidecar_Writer.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor;

use WP_UnitTestCase;
use WordPress\AI\Experiments\C2pa_Monitor\Raw_Manifest;
use WordPress\AI\Experiments\C2pa_Monitor\Sidecar_Writer;

/**
 * Verifies the sidecar layout, hardening files, and round-trip of the bytes.
 *
 * @since 0.7.0
 */
class Sidecar_WriterTest extends WP_UnitTestCase {
	/**
	 * Removes any sidecar files this test may have left behind.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$uploads = wp_upload_dir( null, false );
		if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
			$dir = trailingslashit( (string) $uploads['basedir'] ) . Sidecar_Writer::SUBDIR;
			if ( is_dir( $dir ) ) {
				foreach ( glob( $dir . '/*' ) ?: array() as $f ) {
					@unlink( $f );
				}
				foreach ( glob( $dir . '/.*' ) ?: array() as $f ) {
					if ( '.' === basename( $f ) || '..' === basename( $f ) ) {
						continue;
					}
					@unlink( $f );
				}
				@rmdir( $dir );
			}
		}
		parent::tearDown();
	}

	/**
	 * Writing a manifest produces a sidecar file with identical bytes and
	 * a relative path under uploads/ai-c2pa.
	 */
	public function test_write_persists_bytes_and_returns_relative_path(): void {
		$payload  = str_repeat( "\x01\x02\x03\x04", 256 );
		$manifest = new Raw_Manifest( 'jpeg', 'APP11/JUMBF', hash( 'sha256', $payload ), strlen( $payload ), $payload );

		$writer   = new Sidecar_Writer();
		$relative = $writer->write( 4242, $manifest );

		$this->assertSame( 'ai-c2pa/4242.jpeg.c2pa', $relative );

		$uploads = wp_upload_dir( null, false );
		$this->assertNotEmpty( $uploads['basedir'] );

		$absolute = trailingslashit( (string) $uploads['basedir'] ) . $relative;
		$this->assertFileExists( $absolute );
		$this->assertSame( $payload, file_get_contents( $absolute ) );
	}

	/**
	 * Hardening files are written into the sidecar directory.
	 */
	public function test_ensure_dir_writes_hardening_files(): void {
		$writer  = new Sidecar_Writer();
		$basedir = $writer->ensure_dir();

		$this->assertDirectoryExists( $basedir );
		$this->assertFileExists( $basedir . '/index.php' );
		$this->assertFileExists( $basedir . '/.htaccess' );

		$htaccess = file_get_contents( $basedir . '/.htaccess' );
		$this->assertStringContainsString( 'Require all denied', $htaccess );
	}

	/**
	 * Filenames sanitize odd format strings.
	 */
	public function test_safe_format_falls_back_when_format_is_blank(): void {
		$payload  = "\x00\x01\x02";
		$manifest = new Raw_Manifest( '', 'unknown', hash( 'sha256', $payload ), strlen( $payload ), $payload );

		$writer   = new Sidecar_Writer();
		$relative = $writer->write( 99, $manifest );

		$this->assertSame( 'ai-c2pa/99.bin.c2pa', $relative );
	}

	/**
	 * Writing a second manifest for the same attachment ID overwrites the
	 * first one. Re-evaluation must replace the on-disk bytes deterministically
	 * rather than appending or producing a stale file.
	 */
	public function test_write_overwrites_existing_sidecar(): void {
		$payload_a  = str_repeat( "A", 128 );
		$payload_b  = str_repeat( "B", 64 );
		$manifest_a = new Raw_Manifest( 'jpeg', 'APP11/JUMBF', hash( 'sha256', $payload_a ), strlen( $payload_a ), $payload_a );
		$manifest_b = new Raw_Manifest( 'jpeg', 'APP11/JUMBF', hash( 'sha256', $payload_b ), strlen( $payload_b ), $payload_b );

		$writer = new Sidecar_Writer();
		$writer->write( 555, $manifest_a );
		$relative = $writer->write( 555, $manifest_b );

		$uploads  = wp_upload_dir( null, false );
		$absolute = trailingslashit( (string) $uploads['basedir'] ) . $relative;

		$this->assertFileExists( $absolute );
		$this->assertSame( $payload_b, file_get_contents( $absolute ) );
		$this->assertSame( strlen( $payload_b ), filesize( $absolute ) );
	}

	/**
	 * Sidecars for distinct attachment IDs coexist in the directory and never
	 * collide with each other.
	 */
	public function test_writes_for_multiple_attachments_coexist(): void {
		$payload_100 = str_repeat( "1", 32 );
		$payload_200 = str_repeat( "2", 96 );
		$manifest_100 = new Raw_Manifest( 'png', 'PNG/caBX', hash( 'sha256', $payload_100 ), strlen( $payload_100 ), $payload_100 );
		$manifest_200 = new Raw_Manifest( 'webp', 'WebP/C2PA', hash( 'sha256', $payload_200 ), strlen( $payload_200 ), $payload_200 );

		$writer  = new Sidecar_Writer();
		$rel_100 = $writer->write( 100, $manifest_100 );
		$rel_200 = $writer->write( 200, $manifest_200 );

		$this->assertSame( 'ai-c2pa/100.png.c2pa', $rel_100 );
		$this->assertSame( 'ai-c2pa/200.webp.c2pa', $rel_200 );

		$uploads  = wp_upload_dir( null, false );
		$basedir  = trailingslashit( (string) $uploads['basedir'] );
		$abs_100  = $basedir . $rel_100;
		$abs_200  = $basedir . $rel_200;

		$this->assertFileExists( $abs_100 );
		$this->assertFileExists( $abs_200 );
		$this->assertSame( $payload_100, file_get_contents( $abs_100 ) );
		$this->assertSame( $payload_200, file_get_contents( $abs_200 ) );
	}

	/**
	 * If a custom .htaccess exists, ensure_dir() must not clobber it. The
	 * writer uses `! file_exists` guards so administrators can adjust the
	 * directory's deny rules without each evaluation reverting them.
	 */
	public function test_ensure_dir_preserves_existing_hardening_files(): void {
		$writer  = new Sidecar_Writer();
		$basedir = $writer->ensure_dir();

		$custom = "# custom administrator policy\nDeny from all\n";
		file_put_contents( $basedir . '/.htaccess', $custom );

		$writer->ensure_dir();

		$this->assertSame( $custom, file_get_contents( $basedir . '/.htaccess' ) );
	}
}

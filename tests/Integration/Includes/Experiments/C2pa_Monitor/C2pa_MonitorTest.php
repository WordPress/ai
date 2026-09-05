<?php
/**
 * Integration tests for the C2pa_Monitor experiment as a whole.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor;

use WP_UnitTestCase;
use WordPress\AI\Experiments\C2pa_Monitor\C2pa_Monitor;
use WordPress\AI\Experiments\C2pa_Monitor\Record;
use WordPress\AI\Experiments\C2pa_Monitor\Sidecar_Writer;

require_once __DIR__ . '/Fixtures.php';

/**
 * Drives capture_for_attachment() against fixture-built attachments and
 * asserts the postmeta record + sidecar file shape.
 *
 * @since x.x.x
 */
class C2pa_MonitorTest extends WP_UnitTestCase {
	/**
	 * Working directory for fixture files (outside uploads).
	 *
	 * @var string
	 */
	private string $tmp_dir = '';

	/**
	 * The feature instance under test.
	 *
	 * @var C2pa_Monitor
	 */
	private C2pa_Monitor $feature;

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();
		// Synthetic fixtures are not renderable images; suppress WordPress's image
		// subsize generation so GD never tries to decode them (GD's WebP codec
		// fatals on invalid compressed payloads).
		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
		$this->tmp_dir = sys_get_temp_dir() . '/wpai-c2pa-monitor-' . uniqid( '', true );
		mkdir( $this->tmp_dir, 0700, true );
		$this->feature = new C2pa_Monitor();
	}

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		// The sort tests switch to the admin `upload` screen, which flips
		// is_admin() for anything that runs afterwards.
		set_current_screen( 'front' );
		remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
		if ( '' !== $this->tmp_dir && is_dir( $this->tmp_dir ) ) {
			foreach ( glob( $this->tmp_dir . '/*' ) ?: array() as $f ) {
				@unlink( $f );
			}
			@rmdir( $this->tmp_dir );
		}

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
	 * Creates an attachment that wp_attachment_is_image() recognises.
	 *
	 * The C2PA UI is gated to images, and wp_attachment_is() returns false
	 * unless get_attached_file() resolves, so an image mime type alone is not
	 * enough — the _wp_attached_file row has to exist too.
	 *
	 * @param array<string, mixed> $args Optional overrides for wp_insert_post().
	 * @return int Attachment ID.
	 */
	private function create_image_attachment( array $args = array() ): int {
		$attachment_id = (int) $this->factory->post->create(
			array_merge(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'image/jpeg',
				),
				$args
			)
		);
		update_post_meta( $attachment_id, '_wp_attached_file', "2026/08/{$attachment_id}.jpg" );

		return $attachment_id;
	}

	/**
	 * Points get_current_screen() at the Media Library list table.
	 *
	 * sort_by_c2pa_column() requires a real WP_Screen whose base is `upload`,
	 * and is_admin() reads through the same global.
	 */
	private function set_upload_screen(): void {
		set_current_screen( 'upload' );
	}

	/**
	 * Capture for an image carrying a synthetic JUMBF payload records
	 * present=true, the correct hash + length, and writes a sidecar.
	 */
	public function test_capture_records_present_for_jpeg_with_c2pa(): void {
		$payload = Fixtures::synthetic_manifest_payload( 256 );
		$path    = $this->tmp_dir . '/with.jpg';
		Fixtures::write_jpeg_with_c2pa( $path, $payload );

		$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create attachment from fixture.' );
		}

		$this->feature->capture_for_attachment( (int) $attachment_id );

		$record = Record::load( (int) $attachment_id );
		$this->assertIsArray( $record );
		$this->assertSame( C2pa_Monitor::SCHEMA_VERSION, $record['schema_version'] );
		// JPEG: manifest bytes are the full JUMBF store (LBox+TBox+jumd+content).
		$expected_store = Fixtures::build_c2pa_jumbf_store( $payload );

		$this->assertTrue( $record['c2pa']['present'] );
		$this->assertSame( 'jpeg', $record['c2pa']['format'] );
		$this->assertSame( 'APP11/JUMBF', $record['c2pa']['container'] );
		$this->assertSame( hash( 'sha256', $expected_store ), $record['c2pa']['manifest_sha256'] );
		$this->assertSame( strlen( $expected_store ), $record['c2pa']['manifest_length'] );
		$this->assertNull( $record['c2pa']['decoded'] );
		$this->assertSame( array(), $record['errors'] );

		$uploads  = wp_upload_dir( null, false );
		$absolute = trailingslashit( (string) $uploads['basedir'] ) . $record['c2pa']['sidecar_path_relative'];
		$this->assertFileExists( $absolute );
		$this->assertSame( $expected_store, file_get_contents( $absolute ) );

		$this->assertArrayHasKey( '@context', $record, 'Stored record must embed @context for JSON-LD linkability.' );
		$this->assertIsArray( $record['@context'] );
		$this->assertContains( 'https://schema.org/', $record['@context'] );
		$this->assertContains( C2pa_Monitor::CONTEXT_URL, $record['@context'] );
	}

	/**
	 * Capture for an image with no C2PA segments records present=false and
	 * does not write a sidecar.
	 */
	public function test_capture_records_absent_for_jpeg_without_c2pa(): void {
		$path = $this->tmp_dir . '/without.jpg';
		Fixtures::write_jpeg_without_c2pa( $path );

		$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create attachment from fixture.' );
		}

		$this->feature->capture_for_attachment( (int) $attachment_id );

		$record = Record::load( (int) $attachment_id );
		$this->assertIsArray( $record );
		$this->assertFalse( $record['c2pa']['present'] );
		$this->assertSame( 'jpeg', $record['c2pa']['format'] );
		$this->assertArrayNotHasKey( 'sidecar_path_relative', $record['c2pa'] );
	}

	/**
	 * Capture for unsupported MIME types is a no-op (no postmeta written).
	 */
	public function test_capture_skips_unsupported_mime(): void {
		$attachment_id = $this->factory->attachment->create_object(
			'fake.txt',
			0,
			array(
				'post_mime_type' => 'text/plain',
				'post_status'    => 'inherit',
			)
		);

		$this->feature->capture_for_attachment( (int) $attachment_id );
		$this->assertNull( Record::load( (int) $attachment_id ) );
	}

	/**
	 * Capture must not throw or block when handed a non-existent attachment.
	 */
	public function test_capture_is_fail_open(): void {
		$bogus_id = 999999;

		$exception = null;
		try {
			$this->feature->capture_for_attachment( $bogus_id );
		} catch ( \Throwable $e ) {
			$exception = $e;
		}
		$this->assertNull( $exception, 'capture_for_attachment must never throw.' );
	}

	/**
	 * Truncated JPEG produces a record with present=false and does not throw.
	 */
	public function test_capture_handles_truncated_jpeg(): void {
		$path = $this->tmp_dir . '/trunc.jpg';
		Fixtures::write_jpeg_truncated( $path );

		$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create attachment from truncated fixture.' );
		}

		$this->feature->capture_for_attachment( (int) $attachment_id );

		$record = Record::load( (int) $attachment_id );
		$this->assertIsArray( $record );
		$this->assertFalse( $record['c2pa']['present'] );
	}

	/**
	 * Captures complete in well under 500 ms on a synthetic image.
	 *
	 * Logged via duration_ms in the postmeta record.
	 */
	public function test_capture_records_duration_ms(): void {
		$payload = Fixtures::synthetic_manifest_payload( 1024 );
		$path    = $this->tmp_dir . '/perf.jpg';
		Fixtures::write_jpeg_with_c2pa( $path, $payload );

		$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create attachment for perf assertion.' );
		}

		$this->feature->capture_for_attachment( (int) $attachment_id );

		$record = Record::load( (int) $attachment_id );
		$this->assertIsArray( $record );
		$this->assertGreaterThanOrEqual( 0, $record['duration_ms'] );
		$this->assertLessThan( 500, $record['duration_ms'], 'capture should complete in under 500ms for a small fixture.' );
	}

	/**
	 * Feature metadata is well-formed.
	 */
	public function test_feature_metadata(): void {
		$this->assertSame( 'c2pa-monitor', C2pa_Monitor::get_id() );
		$this->assertNotEmpty( $this->feature->get_label() );
		$this->assertNotEmpty( $this->feature->get_description() );
		$this->assertSame( 'none', $this->feature->get_capability() );
	}

	/**
	 * End-to-end PNG: capture for a synthetic PNG with caBX must record
	 * present=true with PNG/caBX container and a sidecar that round-trips
	 * the bytes.
	 */
	public function test_capture_records_present_for_png_with_c2pa(): void {
		$payload = Fixtures::synthetic_manifest_payload( 384 );
		$path    = $this->tmp_dir . '/with.png';
		Fixtures::write_png_with_c2pa( $path, $payload );

		$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create attachment from PNG fixture.' );
		}

		$this->feature->capture_for_attachment( (int) $attachment_id );

		$record = Record::load( (int) $attachment_id );
		$this->assertIsArray( $record );
		$this->assertTrue( $record['c2pa']['present'] );
		$this->assertSame( 'png', $record['c2pa']['format'] );
		$this->assertSame( 'PNG/caBX', $record['c2pa']['container'] );
		$this->assertSame( hash( 'sha256', $payload ), $record['c2pa']['manifest_sha256'] );
		$this->assertSame( strlen( $payload ), $record['c2pa']['manifest_length'] );

		$uploads  = wp_upload_dir( null, false );
		$absolute = trailingslashit( (string) $uploads['basedir'] ) . $record['c2pa']['sidecar_path_relative'];
		$this->assertFileExists( $absolute );
		$this->assertSame( $payload, file_get_contents( $absolute ) );
	}

	/**
	 * End-to-end WebP: capture for a synthetic WebP with a `C2PA` chunk must
	 * record present=true with WebP/C2PA container and a sidecar that
	 * round-trips the bytes.
	 */
	public function test_capture_records_present_for_webp_with_c2pa(): void {
		$payload = Fixtures::synthetic_manifest_payload( 256 );
		$path    = $this->tmp_dir . '/with.webp';
		Fixtures::write_webp_with_c2pa( $path, $payload );

		$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create attachment from WebP fixture.' );
		}

		$this->feature->capture_for_attachment( (int) $attachment_id );

		$record = Record::load( (int) $attachment_id );
		$this->assertIsArray( $record );
		$this->assertTrue( $record['c2pa']['present'] );
		$this->assertSame( 'webp', $record['c2pa']['format'] );
		$this->assertSame( 'WebP/C2PA', $record['c2pa']['container'] );
		$this->assertSame( hash( 'sha256', $payload ), $record['c2pa']['manifest_sha256'] );
		$this->assertSame( strlen( $payload ), $record['c2pa']['manifest_length'] );

		$uploads  = wp_upload_dir( null, false );
		$absolute = trailingslashit( (string) $uploads['basedir'] ) . $record['c2pa']['sidecar_path_relative'];
		$this->assertFileExists( $absolute );
		$this->assertSame( $payload, file_get_contents( $absolute ) );
	}

	/**
	 * register() wires capture_for_attachment to the add_attachment hook so
	 * a real upload (not a direct method call) produces the postmeta record.
	 *
	 * Catches typos / arity bugs that would silently break the production
	 * flow while leaving the direct-call tests passing.
	 */
	public function test_register_wires_add_attachment_hook(): void {
		$this->feature->register();

		try {
			$payload = Fixtures::synthetic_manifest_payload( 256 );
			$path    = $this->tmp_dir . '/hook.jpg';
			Fixtures::write_jpeg_with_c2pa( $path, $payload );

			$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
			if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
				$this->markTestSkipped( 'Could not create attachment to test hook firing.' );
			}

			$record = Record::load( (int) $attachment_id );
			$this->assertIsArray( $record, 'Expected add_attachment to fire and produce a record.' );
			$this->assertTrue( $record['c2pa']['present'] );
			$this->assertSame( hash( 'sha256', Fixtures::build_c2pa_jumbf_store( $payload ) ), $record['c2pa']['manifest_sha256'] );
		} finally {
			remove_action( 'add_attachment', array( $this->feature, 'capture_for_attachment' ), 20 );
		}
	}

	/**
	 * If the original image file is missing on disk by the time capture
	 * runs, the record must report present=false with errors[0].stage =
	 * 'resolve_path'. capture_for_attachment must not throw.
	 */
	public function test_capture_logs_resolve_path_error_when_file_missing(): void {
		$payload = Fixtures::synthetic_manifest_payload( 128 );
		$path    = $this->tmp_dir . '/will-be-deleted.jpg';
		Fixtures::write_jpeg_with_c2pa( $path, $payload );

		$attachment_id = $this->factory->attachment->create_upload_object( $path, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create attachment for missing-file scenario.' );
		}
		$attachment_id = (int) $attachment_id;

		$resolved = wp_get_original_image_path( $attachment_id );
		if ( ! is_string( $resolved ) || '' === $resolved ) {
			$resolved = get_attached_file( $attachment_id );
		}
		if ( ! is_string( $resolved ) || ! is_readable( $resolved ) ) {
			$this->markTestSkipped( 'Could not resolve attachment file for deletion.' );
		}

		$this->assertTrue( @unlink( $resolved ) );

		$this->feature->capture_for_attachment( $attachment_id );

		$record = Record::load( $attachment_id );
		$this->assertIsArray( $record );
		$this->assertFalse( $record['c2pa']['present'] );
		$this->assertNotEmpty( $record['errors'] );
		$this->assertSame( 'resolve_path', $record['errors'][0]['stage'] );
	}

	/**
	 * add_media_column() always appends the C2PA column (guard removed; the
	 * feature loader skips registration when disabled).
	 */
	public function test_add_media_column_when_enabled_and_disabled(): void {
		$base = array( 'title' => 'File' );

		$with = $this->feature->add_media_column( $base );
		$this->assertArrayHasKey( 'wpai_c2pa', $with );
		$this->assertSame( 'Content Credentials', $with['wpai_c2pa'] );
	}

	/**
	 * render_media_column() outputs the correct markup for each record state.
	 */
	public function test_render_media_column_states(): void {
		$global_opt  = 'wpai_features_enabled';
		$feature_opt = 'wpai_feature_c2pa-monitor_enabled';
		update_option( $global_opt, true );
		update_option( $feature_opt, true );
		$feature = new C2pa_Monitor();

		$attachment_id = $this->create_image_attachment();

		// No record yet: should show dash.
		ob_start();
		$feature->render_media_column( 'wpai_c2pa', (int) $attachment_id );
		$out = ob_get_clean();
		$this->assertStringContainsString( '—', $out );

		// present=true: wp_get_attachment_url() always returns a URL for a valid
		// attachment post (even without _wp_attached_file it uses ?attachment_id=X),
		// so the verify link is always rendered.
		$record_present = array(
			'@context'       => array( 'https://schema.org/' ),
			'schema_version' => 1,
			'captured_at'    => '2026-01-01T00:00:00Z',
			'duration_ms'    => 0,
			'source'         => array(),
			'traditional'    => array(),
			'c2pa'           => array( 'present' => true, 'format' => 'jpeg' ),
			'errors'         => array(),
		);
		update_post_meta( (int) $attachment_id, C2pa_Monitor::POSTMETA_KEY, wp_json_encode( $record_present ) );
		ob_start();
		$feature->render_media_column( 'wpai_c2pa', (int) $attachment_id );
		$out = ob_get_clean();
		$this->assertStringContainsString( 'Credentials', $out );
		$this->assertStringContainsString( '&#10003;', $out );
		$this->assertStringContainsString( 'verify.contentauthenticity.org', $out );
		$this->assertStringContainsString( 'target="_blank"', $out );
		// The column always renders with the CSS tooltip.
		$this->assertStringContainsString( 'data-wpai-tooltip', $out );

		// present=false.
		$record_absent         = $record_present;
		$record_absent['c2pa'] = array( 'present' => false, 'format' => 'jpeg' );
		update_post_meta( (int) $attachment_id, C2pa_Monitor::POSTMETA_KEY, wp_json_encode( $record_absent ) );
		ob_start();
		$feature->render_media_column( 'wpai_c2pa', (int) $attachment_id );
		$out = ob_get_clean();
		$this->assertStringContainsString( 'No credentials', $out );
		$this->assertStringContainsString( 'data-wpai-tooltip', $out );

		delete_option( $global_opt );
		delete_option( $feature_opt );
	}

	/**
	 * render_media_column() is a no-op for unrelated column names.
	 */
	public function test_render_media_column_ignores_other_columns(): void {
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_c2pa-monitor_enabled', true );
		$feature = new C2pa_Monitor();

		$attachment_id = $this->factory->post->create( array( 'post_type' => 'attachment' ) );

		ob_start();
		$feature->render_media_column( 'title', (int) $attachment_id );
		$out = ob_get_clean();
		$this->assertSame( '', $out );

		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_c2pa-monitor_enabled' );
	}

	/**
	 * render_media_column() shows the dash when the stored meta is malformed JSON.
	 */
	public function test_render_media_column_handles_malformed_meta(): void {
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_c2pa-monitor_enabled', true );
		$feature = new C2pa_Monitor();

		$attachment_id = $this->create_image_attachment();
		update_post_meta( (int) $attachment_id, C2pa_Monitor::POSTMETA_KEY, 'not-valid-json' );

		ob_start();
		$feature->render_media_column( 'wpai_c2pa', (int) $attachment_id );
		$out = ob_get_clean();
		$this->assertStringContainsString( '—', $out );

		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_c2pa-monitor_enabled' );
	}

	/**
	 * register_sortable_column() always adds the C2PA column to the sortable
	 * map (guard removed; the feature loader skips registration when disabled).
	 */
	public function test_register_sortable_column_when_enabled_and_disabled(): void {
		$base = array( 'title' => array( 'title', false ) );

		$with = $this->feature->register_sortable_column( $base );
		$this->assertArrayHasKey( 'wpai_c2pa', $with );
		$this->assertSame( array( 'wpai_c2pa', true ), $with['wpai_c2pa'] );
	}

	/**
	 * sort_by_c2pa_column() injects a LEFT JOIN and COALESCE orderby into the
	 * SQL clause array when on the attachment list screen sorting by wpai_c2pa.
	 */
	public function test_sort_by_c2pa_column_modifies_clauses(): void {
		global $wp_the_query;
		$this->set_upload_screen();
		$wp_the_query->set( 'orderby', 'wpai_c2pa' );

		$clauses = $this->feature->sort_by_c2pa_column(
			array( 'join' => '', 'orderby' => '' ),
			$wp_the_query
		);

		$this->assertStringContainsString( 'wpai_c2pa_sort', $clauses['join'] );
		$this->assertStringContainsString( C2pa_Monitor::SORT_META_KEY, $clauses['join'] );
		$this->assertStringContainsString( 'COALESCE', $clauses['orderby'] );
		$this->assertStringContainsString( 'DESC', $clauses['orderby'] );
	}

	/**
	 * sort_by_c2pa_column() must not append its LEFT JOIN twice.
	 *
	 * A duplicate `wpai_c2pa_sort` alias makes MySQL reject the entire query
	 * with "Not unique table/alias", which empties the Media Library rather
	 * than merely mis-sorting it.
	 */
	public function test_sort_by_c2pa_column_is_idempotent(): void {
		global $wp_the_query;
		$this->set_upload_screen();
		$wp_the_query->set( 'orderby', 'wpai_c2pa' );

		$first  = $this->feature->sort_by_c2pa_column( array( 'join' => '', 'orderby' => '' ), $wp_the_query );
		$second = $this->feature->sort_by_c2pa_column( $first, $wp_the_query );

		$this->assertSame(
			1,
			substr_count( $second['join'], 'wpai_c2pa_sort ON' ),
			'The sort join must only ever be appended once.'
		);
	}

	/**
	 * sort_by_c2pa_column() is a no-op when ordering by a different column.
	 */
	public function test_sort_by_c2pa_column_ignores_other_orderbys(): void {
		global $wp_the_query;
		$this->set_upload_screen();
		$wp_the_query->set( 'orderby', 'date' );

		$original_clauses = array( 'join' => 'ORIGINAL_JOIN', 'orderby' => 'ORIGINAL_ORDER' );
		$result           = $this->feature->sort_by_c2pa_column( $original_clauses, $wp_the_query );

		$this->assertSame( $original_clauses, $result );
	}

	/**
	 * sort_by_c2pa_column() must return ALL attachments — including those that
	 * have never been scanned (no sort meta row) — and must order them correctly
	 * even when the attachments have many other postmeta rows.
	 *
	 * Real attachments carry _wp_attached_file and _wp_attachment_metadata rows
	 * which the previous meta_query EXISTS+NOT EXISTS approach would cause to
	 * sort above '1' as strings, making credentialed items disappear. This test
	 * seeds that realistic postmeta to guard against regression.
	 */
	public function test_sort_includes_unscanned_attachments(): void {
		// Three attachments: credentials present, absent, and never scanned.
		$id_present = $this->factory->post->create( array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
		$id_absent  = $this->factory->post->create( array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
		$id_unscan  = $this->factory->post->create( array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) );

		// Seed realistic postmeta on all three so the old EXISTS+NOT EXISTS
		// approach — which reads meta_value from an arbitrary row — would fail.
		foreach ( array( $id_present, $id_absent, $id_unscan ) as $aid ) {
			update_post_meta( $aid, '_wp_attached_file', "2026/08/{$aid}.jpg" );
			update_post_meta( $aid, '_wp_attachment_metadata', array( 'width' => 800, 'height' => 600 ) );
		}

		update_post_meta( $id_present, C2pa_Monitor::SORT_META_KEY, '1' );
		update_post_meta( $id_absent, C2pa_Monitor::SORT_META_KEY, '0' );
		// $id_unscan intentionally gets no sort meta.

		// Drive the real production filter end to end rather than a copy of its
		// SQL, so a regression in sort_by_c2pa_column() actually fails here.
		// It bails unless the screen is `upload` and the query is the main one,
		// hence the screen setup and the wp_the_query swap below.
		$this->set_upload_screen();
		add_filter( 'posts_clauses', array( $this->feature, 'sort_by_c2pa_column' ), 10, 2 );

		global $wp_the_query;
		$previous_main = $wp_the_query;
		$query         = new \WP_Query();
		$wp_the_query  = $query;

		$query->query(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post__in'    => array( $id_present, $id_absent, $id_unscan ),
				'orderby'     => 'wpai_c2pa',
				'order'       => 'DESC',
			)
		);

		$wp_the_query = $previous_main;
		remove_filter( 'posts_clauses', array( $this->feature, 'sort_by_c2pa_column' ), 10 );

		$ids = wp_list_pluck( $query->posts, 'ID' );

		// All three must be returned — unscanned must not be dropped.
		$this->assertCount( 3, $ids, 'Unscanned attachments must not be omitted from the sorted result.' );

		// DESC order: $id_present (1) > $id_absent (0) > $id_unscan (NULL → -1).
		$this->assertSame( $id_present, $ids[0] );
		$this->assertSame( $id_absent, $ids[1] );
		$this->assertSame( $id_unscan, $ids[2] );
	}

	/**
	 * print_admin_styles() always outputs the style block (guard removed;
	 * the feature loader skips registration when disabled).
	 */
	public function test_print_column_styles_enabled_and_disabled(): void {
		// enqueue_admin_styles() uses wp_add_inline_style, which appends to the
		// registered handle's inline list. Verify the CSS string contains both
		// the tooltip rule and the compat-field alignment fix.
		$this->feature->enqueue_admin_styles();

		$inline = wp_styles()->get_data( 'wpai-c2pa-monitor', 'after' );
		$css    = is_array( $inline ) ? implode( '', $inline ) : '';

		$this->assertStringContainsString( 'data-wpai-tooltip', $css );
		$this->assertStringContainsString( 'compat-field-wpai_c2pa', $css );
	}

	/**
	 * add_attachment_fields() always appends the Content Credentials field
	 * (guard removed; the feature loader skips registration when disabled).
	 */
	public function test_add_attachment_fields_when_enabled_and_disabled(): void {
		$attachment_id = $this->create_image_attachment();
		$post          = get_post( (int) $attachment_id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		$fields = $this->feature->add_attachment_fields( array(), $post );
		$this->assertArrayHasKey( 'wpai_c2pa', $fields );
		$this->assertSame( 'Content Credentials', $fields['wpai_c2pa']['label'] );
		$this->assertSame( 'html', $fields['wpai_c2pa']['input'] );
		// show_in_edit must be false to prevent duplication with the meta box on Edit Media.
		$this->assertFalse( $fields['wpai_c2pa']['show_in_edit'] );
		// helps key must be set (may be empty string for not-scanned state).
		$this->assertArrayHasKey( 'helps', $fields['wpai_c2pa'] );
		$this->assertStringContainsString( '—', $fields['wpai_c2pa']['html'] );
		// Tooltip attribute must NOT appear in the field html (reserved for the column).
		$this->assertStringNotContainsString( 'data-wpai-tooltip', $fields['wpai_c2pa']['html'] );
	}

	/**
	 * add_attachment_fields() shows the correct status HTML for each record state.
	 */
	public function test_add_attachment_fields_status_states(): void {
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_c2pa-monitor_enabled', true );
		$feature = new C2pa_Monitor();

		$attachment_id = $this->create_image_attachment();
		$post          = get_post( (int) $attachment_id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		// No record: dash.
		$fields = $feature->add_attachment_fields( array(), $post );
		$this->assertStringContainsString( '—', $fields['wpai_c2pa']['html'] );

		// present=true: verify link, no tooltip, help text present.
		$record = array(
			'@context'       => array( 'https://schema.org/' ),
			'schema_version' => 1,
			'captured_at'    => '2026-01-01T00:00:00Z',
			'duration_ms'    => 0,
			'source'         => array(),
			'traditional'    => array(),
			'c2pa'           => array( 'present' => true, 'format' => 'jpeg' ),
			'errors'         => array(),
		);
		update_post_meta( (int) $attachment_id, C2pa_Monitor::POSTMETA_KEY, wp_json_encode( $record ) );
		$fields = $feature->add_attachment_fields( array(), $post );
		$html   = $fields['wpai_c2pa']['html'];
		$this->assertStringContainsString( 'Credentials', $html );
		$this->assertStringContainsString( 'verify.contentauthenticity.org', $html );
		$this->assertStringNotContainsString( 'data-wpai-tooltip', $html );
		$this->assertNotEmpty( $fields['wpai_c2pa']['helps'] );
		$this->assertStringContainsString( 'verify', $fields['wpai_c2pa']['helps'] );

		// present=false: "No credentials", no tooltip, help text present.
		$record['c2pa'] = array( 'present' => false, 'format' => 'jpeg' );
		update_post_meta( (int) $attachment_id, C2pa_Monitor::POSTMETA_KEY, wp_json_encode( $record ) );
		$fields = $feature->add_attachment_fields( array(), $post );
		$this->assertStringContainsString( 'No credentials', $fields['wpai_c2pa']['html'] );
		$this->assertStringNotContainsString( 'data-wpai-tooltip', $fields['wpai_c2pa']['html'] );
		$this->assertNotEmpty( $fields['wpai_c2pa']['helps'] );

		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_c2pa-monitor_enabled' );
	}

	/**
	 * add_attachment_meta_box() always registers the meta box (guard removed;
	 * the feature loader skips registration when disabled).
	 */
	public function test_add_attachment_meta_box_when_enabled_and_disabled(): void {
		global $wp_meta_boxes;

		$attachment_id = $this->create_image_attachment();
		$post          = get_post( (int) $attachment_id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		$this->feature->add_attachment_meta_box( $post );
		$this->assertArrayHasKey( 'wpai-c2pa-monitor', $wp_meta_boxes['attachment']['side']['default'] ?? array() );
	}

	/**
	 * render_attachment_meta_box() outputs the status badge (no tooltip) plus
	 * a visible help text paragraph for states that have one.
	 */
	public function test_render_attachment_meta_box_output(): void {
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_c2pa-monitor_enabled', true );
		$feature = new C2pa_Monitor();

		$attachment_id = $this->create_image_attachment();
		$post          = get_post( (int) $attachment_id );
		$this->assertInstanceOf( \WP_Post::class, $post );

		// No record: dash inside <p>, no description paragraph, no tooltip.
		ob_start();
		$feature->render_attachment_meta_box( $post );
		$out = ob_get_clean();
		$this->assertStringContainsString( '<p>', $out );
		$this->assertStringContainsString( '—', $out );
		$this->assertStringNotContainsString( 'data-wpai-tooltip', $out );
		$this->assertStringNotContainsString( 'class="description"', $out );

		// present=true: verify link, help text paragraph, no tooltip.
		$record = array(
			'@context'       => array( 'https://schema.org/' ),
			'schema_version' => 1,
			'captured_at'    => '2026-01-01T00:00:00Z',
			'duration_ms'    => 0,
			'source'         => array(),
			'traditional'    => array(),
			'c2pa'           => array( 'present' => true, 'format' => 'jpeg' ),
			'errors'         => array(),
		);
		update_post_meta( (int) $attachment_id, C2pa_Monitor::POSTMETA_KEY, wp_json_encode( $record ) );
		ob_start();
		$feature->render_attachment_meta_box( $post );
		$out = ob_get_clean();
		$this->assertStringContainsString( 'verify.contentauthenticity.org', $out );
		$this->assertStringContainsString( 'Credentials', $out );
		$this->assertStringNotContainsString( 'data-wpai-tooltip', $out );
		$this->assertStringContainsString( 'class="description"', $out );

		// present=false: "No credentials", help text paragraph, no tooltip.
		$record['c2pa'] = array( 'present' => false, 'format' => 'jpeg' );
		update_post_meta( (int) $attachment_id, C2pa_Monitor::POSTMETA_KEY, wp_json_encode( $record ) );
		ob_start();
		$feature->render_attachment_meta_box( $post );
		$out = ob_get_clean();
		$this->assertStringContainsString( 'No credentials', $out );
		$this->assertStringNotContainsString( 'data-wpai-tooltip', $out );
		$this->assertStringContainsString( 'class="description"', $out );

		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_c2pa-monitor_enabled' );
	}

	/**
	 * The verify link points at the bare CAI tool URL with no query parameters.
	 * WordPress attachment URLs are not reachable by the verify tool's fetcher
	 * from outside the admin session, so no ?source= pre-fill is added.
	 */
	public function test_verify_link_has_no_source_param(): void {
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_c2pa-monitor_enabled', true );
		$feature = new C2pa_Monitor();

		$attachment_id = $this->create_image_attachment();
		$record        = array(
			'@context'       => array( 'https://schema.org/' ),
			'schema_version' => 1,
			'captured_at'    => '2026-01-01T00:00:00Z',
			'duration_ms'    => 0,
			'source'         => array(),
			'traditional'    => array(),
			'c2pa'           => array( 'present' => true, 'format' => 'jpeg' ),
			'errors'         => array(),
		);
		update_post_meta( (int) $attachment_id, C2pa_Monitor::POSTMETA_KEY, wp_json_encode( $record ) );

		ob_start();
		$feature->render_media_column( 'wpai_c2pa', (int) $attachment_id );
		$out = ob_get_clean();
		$this->assertStringContainsString( 'verify.contentauthenticity.org', $out );
		$this->assertStringNotContainsString( 'source=', $out );

		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_c2pa-monitor_enabled' );
	}
}

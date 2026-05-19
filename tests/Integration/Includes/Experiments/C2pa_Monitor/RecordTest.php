<?php
/**
 * Unit tests for the C2PA Monitor Record.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Includes\Experiments\C2pa_Monitor;

use WP_UnitTestCase;
use WordPress\AI\Experiments\C2pa_Monitor\C2pa_Monitor;
use WordPress\AI\Experiments\C2pa_Monitor\Record;

/**
 * Verifies the Record contract: roundtrip integrity, default-fill behavior on
 * missing keys, JSON storage format, and graceful handling of corrupt or
 * absent postmeta.
 *
 * @since 0.7.0
 */
class RecordTest extends WP_UnitTestCase {
	/**
	 * Creates a fresh attachment post and returns its ID.
	 *
	 * Using a real post (rather than an arbitrary integer) keeps the
	 * postmeta operations behaving the way they do in production.
	 *
	 * @return int
	 */
	private function make_attachment_id(): int {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_title'  => 'c2pa-monitor-record-fixture',
			)
		);
		return (int) $post_id;
	}

	/**
	 * Storing a fully populated record and loading it returns the same shape.
	 */
	public function test_store_then_load_roundtrip(): void {
		$id     = $this->make_attachment_id();
		$record = array(
			'schema_version' => C2pa_Monitor::SCHEMA_VERSION,
			'captured_at'    => '2026-04-22T10:00:00Z',
			'duration_ms'    => 42,
			'source'         => array(
				'attachment_id'          => $id,
				'original_path_relative' => '2026/04/sample.jpg',
				'size_bytes'             => 12345,
				'mime'                   => 'image/jpeg',
			),
			'traditional'    => array(
				'exif' => array( 'Make' => 'Canon' ),
				'iptc' => array(),
				'xmp'  => array(),
			),
			'c2pa'           => array(
				'present'         => true,
				'format'          => 'jpeg',
				'container'       => 'APP11/JUMBF',
				'sha256'          => str_repeat( 'a', 64 ),
				'bytes_length'    => 1024,
				'sidecar_path'    => 'ai-c2pa/' . $id . '.jpeg.c2pa',
			),
			'errors'         => array(),
		);

		$this->assertTrue( Record::store( $id, $record ) );

		$loaded = Record::load( $id );
		$this->assertIsArray( $loaded );
		$this->assertSame( $record['schema_version'], $loaded['schema_version'] );
		$this->assertSame( $record['captured_at'], $loaded['captured_at'] );
		$this->assertSame( $record['duration_ms'], $loaded['duration_ms'] );
		$this->assertSame( $record['source'], $loaded['source'] );
		$this->assertSame( $record['traditional'], $loaded['traditional'] );
		$this->assertSame( $record['c2pa'], $loaded['c2pa'] );
		$this->assertSame( $record['errors'], $loaded['errors'] );

		$this->assertArrayHasKey( '@context', $loaded, 'Record must embed @context for JSON-LD linkability.' );
		$this->assertIsArray( $loaded['@context'] );
		$this->assertContains( 'https://schema.org/', $loaded['@context'] );
		$this->assertContains( C2pa_Monitor::CONTEXT_URL, $loaded['@context'] );
	}

	/**
	 * Storing an empty array fills every required key with its documented
	 * default.
	 */
	public function test_store_with_empty_array_fills_defaults(): void {
		$id = $this->make_attachment_id();

		$this->assertTrue( Record::store( $id, array() ) );

		$loaded = Record::load( $id );
		$this->assertIsArray( $loaded );

		$this->assertSame( C2pa_Monitor::SCHEMA_VERSION, $loaded['schema_version'] );
		$this->assertIsString( $loaded['captured_at'] );
		$this->assertNotEmpty( $loaded['captured_at'] );
		$this->assertSame( 0, $loaded['duration_ms'] );

		$this->assertIsArray( $loaded['source'] );
		$this->assertSame( 0, $loaded['source']['attachment_id'] );
		$this->assertSame( '', $loaded['source']['original_path_relative'] );
		$this->assertSame( 0, $loaded['source']['size_bytes'] );
		$this->assertSame( '', $loaded['source']['mime'] );

		$this->assertIsArray( $loaded['traditional'] );
		$this->assertSame( array(), $loaded['traditional']['exif'] );
		$this->assertSame( array(), $loaded['traditional']['iptc'] );
		$this->assertSame( array(), $loaded['traditional']['xmp'] );

		$this->assertIsArray( $loaded['c2pa'] );
		$this->assertFalse( $loaded['c2pa']['present'] );
		$this->assertNull( $loaded['c2pa']['format'] );

		$this->assertSame( array(), $loaded['errors'] );

		$this->assertArrayHasKey( '@context', $loaded, 'Default-filled record must embed @context.' );
		$this->assertIsArray( $loaded['@context'] );
		$this->assertContains( C2pa_Monitor::CONTEXT_URL, $loaded['@context'] );
	}

	/**
	 * The persisted postmeta value is a JSON string, not a serialize() blob.
	 * REST APIs and downstream tooling rely on JSON shape.
	 */
	public function test_stored_value_is_json_not_serialized(): void {
		$id = $this->make_attachment_id();

		Record::store(
			$id,
			array(
				'duration_ms' => 7,
				'source'      => array(
					'attachment_id' => $id,
					'mime'          => 'image/png',
				),
			)
		);

		$raw = get_post_meta( $id, C2pa_Monitor::POSTMETA_KEY, true );
		$this->assertIsString( $raw );
		$this->assertNotEmpty( $raw );

		$unslashed = wp_unslash( $raw );
		$this->assertSame( '{', substr( $unslashed, 0, 1 ) );
		$decoded = json_decode( $unslashed, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 7, $decoded['duration_ms'] );
		$this->assertSame( 'image/png', $decoded['source']['mime'] );
	}

	/**
	 * Loading a corrupt postmeta value (not a JSON object) returns null
	 * instead of throwing or returning a partial array.
	 */
	public function test_load_returns_null_for_corrupt_json(): void {
		$id = $this->make_attachment_id();

		update_post_meta( $id, C2pa_Monitor::POSTMETA_KEY, wp_slash( 'not json at all' ) );

		$this->assertNull( Record::load( $id ) );
	}

	/**
	 * Loading from an attachment with no record returns null.
	 */
	public function test_load_returns_null_when_no_record(): void {
		$id = $this->make_attachment_id();

		$this->assertNull( Record::load( $id ) );
	}
}

<?php
/**
 * Integration tests for the Resolves_Image_Reference trait.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use ReflectionClass;
use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Image\Resolves_Image_Reference;

/**
 * Test consumer that exposes the trait under test.
 *
 * The trait methods are protected; this stub gives the test class a concrete
 * instance whose methods can be invoked via reflection.
 *
 * @since x.x.x
 */
class Test_Resolves_Image_Reference_Consumer {
	use Resolves_Image_Reference;
}

/**
 * Resolves_Image_Reference trait test case.
 *
 * @since x.x.x
 */
class Resolves_Image_ReferenceTest extends WP_UnitTestCase {

	/**
	 * Path to the bundled 1x1 PNG fixture.
	 *
	 * @var string
	 */
	private const SAMPLE_PNG = TESTS_REPO_ROOT_DIR . '/tests/data/sample.png';

	/**
	 * Stub instance the trait methods are invoked against.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Resolves_Image_Reference_Consumer
	 */
	private $consumer;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->consumer = new Test_Resolves_Image_Reference_Consumer();
	}

	/**
	 * Invokes a protected method on the stub via reflection.
	 *
	 * @since x.x.x
	 *
	 * @param string            $method_name Method name to invoke.
	 * @param array<int, mixed> $args        Positional arguments.
	 * @return mixed The return value.
	 */
	private function invoke( string $method_name, array $args = array() ) {
		$reflection = new ReflectionClass( $this->consumer );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );

		return $method->invokeArgs( $this->consumer, $args );
	}

	/**
	 * Test that resolve_image_reference() errors when no input is supplied.
	 *
	 * @since x.x.x
	 */
	public function test_resolve_image_reference_returns_error_when_empty(): void {
		$result = $this->invoke( 'resolve_image_reference', array( array() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'no_image_provided', $result->get_error_code() );
	}

	/**
	 * Test that resolve_image_reference() prefers attachment_id over image_url.
	 *
	 * @since x.x.x
	 */
	public function test_resolve_image_reference_prefers_attachment_id(): void {
		// Bogus attachment id; we only want to confirm dispatch landed on the attachment path.
		$result = $this->invoke(
			'resolve_image_reference',
			array(
				array(
					'attachment_id' => 99999,
					'image_url'     => 'https://example.com/image.jpg',
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_attachment', $result->get_error_code() );
	}

	/**
	 * Test that resolve_image_reference() falls back to image_url when no attachment is supplied.
	 *
	 * @since x.x.x
	 */
	public function test_resolve_image_reference_falls_back_to_image_url(): void {
		$data_uri = 'data:image/png;base64,iVBORw0KGgo=';

		$result = $this->invoke( 'resolve_image_reference', array( array( 'image_url' => $data_uri ) ) );

		$this->assertSame( $data_uri, $result );
	}

	/**
	 * Test that resolve_attachment_to_data_uri() errors for a missing attachment.
	 *
	 * @since x.x.x
	 */
	public function test_resolve_attachment_returns_invalid_for_missing_post(): void {
		$result = $this->invoke( 'resolve_attachment_to_data_uri', array( 99999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_attachment', $result->get_error_code() );
	}

	/**
	 * Test that resolve_attachment_to_data_uri() rejects non-image attachments.
	 *
	 * @since x.x.x
	 */
	public function test_resolve_attachment_returns_not_an_image_for_non_image_post(): void {
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Plain text attachment',
				'post_mime_type' => 'text/plain',
			),
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create non-image attachment for test' );
			return;
		}

		$result = $this->invoke( 'resolve_attachment_to_data_uri', array( $attachment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_an_image', $result->get_error_code() );
	}

	/**
	 * Test that resolve_attachment_to_data_uri() returns a data URI for a valid image.
	 *
	 * @since x.x.x
	 */
	public function test_resolve_attachment_returns_data_uri_for_image(): void {
		if ( ! is_readable( self::SAMPLE_PNG ) ) {
			$this->markTestSkipped( 'tests/data/sample.png missing.' );
			return;
		}

		$attachment_id = $this->factory->attachment->create_upload_object( self::SAMPLE_PNG, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create image attachment for test.' );
			return;
		}

		$result = $this->invoke( 'resolve_attachment_to_data_uri', array( $attachment_id ) );

		$this->assertIsString( $result );
		$this->assertStringStartsWith( 'data:image/png;base64,', $result );
	}

	/**
	 * Test that resolve_url_to_data_uri() returns data URIs unchanged.
	 *
	 * @since x.x.x
	 */
	public function test_resolve_url_passes_through_data_uri(): void {
		$data_uri = 'data:image/png;base64,iVBORw0KGgo=';

		$result = $this->invoke( 'resolve_url_to_data_uri', array( $data_uri ) );

		$this->assertSame( $data_uri, $result );
	}

	/**
	 * Test that resolve_url_to_data_uri() reads from local uploads when available.
	 *
	 * @since x.x.x
	 */
	public function test_resolve_url_reads_local_uploads_path(): void {
		if ( ! is_readable( self::SAMPLE_PNG ) ) {
			$this->markTestSkipped( 'tests/data/sample.png missing.' );
			return;
		}

		$attachment_id = $this->factory->attachment->create_upload_object( self::SAMPLE_PNG, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create image attachment for test.' );
			return;
		}

		$src = wp_get_attachment_image_src( $attachment_id, 'full' );
		$this->assertIsArray( $src );

		$result = $this->invoke( 'resolve_url_to_data_uri', array( $src[0] ) );

		$this->assertIsString( $result );
		$this->assertStringStartsWith( 'data:image/png;base64,', $result );
	}

	/**
	 * Test that image_file_to_data_uri() returns null for a path with no detectable mime.
	 *
	 * Production callers gate this method behind file_exists(), so the missing-file
	 * branch is best exercised by a path whose extension wp_check_filetype rejects.
	 *
	 * @since x.x.x
	 */
	public function test_image_file_to_data_uri_returns_null_when_mime_undetectable(): void {
		$result = $this->invoke( 'image_file_to_data_uri', array( '/nonexistent/path/to/file-without-extension' ) );

		$this->assertNull( $result );
	}

	/**
	 * Test that image_file_to_data_uri() encodes a real file as a data URI.
	 *
	 * @since x.x.x
	 */
	public function test_image_file_to_data_uri_encodes_real_image(): void {
		if ( ! is_readable( self::SAMPLE_PNG ) ) {
			$this->markTestSkipped( 'tests/data/sample.png missing.' );
			return;
		}

		$result = $this->invoke( 'image_file_to_data_uri', array( self::SAMPLE_PNG ) );

		$this->assertIsString( $result );
		$this->assertStringStartsWith( 'data:image/png;base64,', $result );

		// Round-trip: decoded payload should match the file contents.
		$expected = base64_encode( file_get_contents( self::SAMPLE_PNG ) );
		$this->assertStringEndsWith( $expected, $result );
	}

	/**
	 * Test that maybe_map_image_url_to_local_path() returns null for URLs outside uploads.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_map_returns_null_for_non_uploads_url(): void {
		$this->assertNull(
			$this->invoke( 'maybe_map_image_url_to_local_path', array( 'https://example.com/some/file.jpg' ) )
		);
	}

	/**
	 * Test that maybe_map_image_url_to_local_path() resolves a real uploads URL.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_map_resolves_real_uploads_url(): void {
		if ( ! is_readable( self::SAMPLE_PNG ) ) {
			$this->markTestSkipped( 'tests/data/sample.png missing.' );
			return;
		}

		$attachment_id = $this->factory->attachment->create_upload_object( self::SAMPLE_PNG, 0 );
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create image attachment for test.' );
			return;
		}

		$src = wp_get_attachment_image_src( $attachment_id, 'full' );
		$this->assertIsArray( $src );

		$path = $this->invoke( 'maybe_map_image_url_to_local_path', array( $src[0] ) );

		$this->assertIsString( $path );
		$this->assertFileExists( $path );
	}

	/**
	 * Test that maybe_map_image_url_to_local_path() rejects path-traversal attempts.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_map_rejects_path_traversal(): void {
		$uploads = wp_get_upload_dir();
		$this->assertNotEmpty( $uploads['baseurl'] );

		$traversal_url = trailingslashit( $uploads['baseurl'] ) . '../../../wp-config.php';

		$this->assertNull(
			$this->invoke( 'maybe_map_image_url_to_local_path', array( $traversal_url ) )
		);
	}

	/**
	 * Test that maybe_map_image_url_to_local_path() returns null when the file does not exist.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_map_returns_null_for_missing_uploads_file(): void {
		$uploads      = wp_get_upload_dir();
		$missing_url  = trailingslashit( $uploads['baseurl'] ) . 'this-file-does-not-exist-' . wp_generate_password( 8, false ) . '.png';

		$this->assertNull(
			$this->invoke( 'maybe_map_image_url_to_local_path', array( $missing_url ) )
		);
	}

	/**
	 * Test that normalize_image_upload_url() strips scheme and trailing slash.
	 *
	 * @since x.x.x
	 */
	public function test_normalize_image_upload_url_strips_scheme_and_trailing_slash(): void {
		$this->assertSame(
			'example.com/uploads',
			$this->invoke( 'normalize_image_upload_url', array( 'https://example.com/uploads/' ) )
		);
		$this->assertSame(
			'example.com/uploads',
			$this->invoke( 'normalize_image_upload_url', array( 'http://example.com/uploads' ) )
		);
	}

	/**
	 * Test that sanitize_image_reference_input() returns an empty string for non-strings.
	 *
	 * @since x.x.x
	 */
	public function test_sanitize_image_reference_input_rejects_non_strings(): void {
		$this->assertSame( '', $this->invoke( 'sanitize_image_reference_input', array( 42 ) ) );
		$this->assertSame( '', $this->invoke( 'sanitize_image_reference_input', array( null ) ) );
		$this->assertSame( '', $this->invoke( 'sanitize_image_reference_input', array( array( 'foo' ) ) ) );
	}

	/**
	 * Test that sanitize_image_reference_input() trims and returns empty for whitespace-only input.
	 *
	 * @since x.x.x
	 */
	public function test_sanitize_image_reference_input_returns_empty_for_whitespace(): void {
		$this->assertSame( '', $this->invoke( 'sanitize_image_reference_input', array( "  \t\n" ) ) );
	}

	/**
	 * Test that sanitize_image_reference_input() preserves data URIs intact.
	 *
	 * @since x.x.x
	 */
	public function test_sanitize_image_reference_input_preserves_data_uri(): void {
		$data_uri = 'data:image/png;base64,iVBORw0KGgo=';

		$this->assertSame( $data_uri, $this->invoke( 'sanitize_image_reference_input', array( $data_uri ) ) );
	}

	/**
	 * Test that sanitize_image_reference_input() runs URLs through esc_url_raw.
	 *
	 * @since x.x.x
	 */
	public function test_sanitize_image_reference_input_escapes_url(): void {
		$result = $this->invoke(
			'sanitize_image_reference_input',
			array( 'https://example.com/path with spaces/image.jpg' )
		);

		$this->assertSame( 'https://example.com/path%20with%20spaces/image.jpg', $result );
	}
}

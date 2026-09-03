<?php
/**
 * Integration tests for the Alt_Text_Generation Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Image\Alt_Text_Generation;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Alt_Text_Generation Ability tests.
 *
 * @since 0.3.0
 */
class Test_Alt_Text_Generation_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'alt-text-generation';
	}

	/**
	 * Loads experiment metadata.
	 *
	 * @since 0.3.0
	 *
	 * @return array{label: string, description: string} Experiment metadata.
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Alt Text Generation',
			'description' => 'Generates accessible alternative text for images using AI vision models.',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 0.3.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Testable alt text generation ability.
 *
 * @since 1.0.2
 */
class Testable_Alt_Text_Generation extends Alt_Text_Generation {
	/**
	 * Mock generated alt text.
	 *
	 * @var string
	 */
	private string $generated_alt_text;

	/**
	 * Last image reference arguments.
	 *
	 * @var array<string, mixed>
	 */
	private array $last_image_reference_args = array();

	/**
	 * Constructor.
	 *
	 * @param string $generated_alt_text Mock generated alt text.
	 */
	public function __construct( string $generated_alt_text ) {
		$this->generated_alt_text = $generated_alt_text;

		parent::__construct(
			'ai/alt-text-generation',
			array(
				'label'       => 'Alt Text Generation',
				'description' => 'Generates accessible alternative text for images using AI vision models.',
			)
		);
	}

	/**
	 * Returns a mock image reference.
	 *
	 * @param array<string, mixed> $args The input arguments.
	 * @return array{reference: string} Mock image reference.
	 */
	protected function get_image_reference( array $args ) {
		$this->last_image_reference_args = $args;

		return array( 'reference' => 'data:image/png;base64,dGVzdA==' );
	}

	/**
	 * Returns mock generated alt text.
	 *
	 * @param array{reference: string} $image_reference Prepared image reference.
	 * @param string                   $context         Optional context.
	 * @param string                   $image_meta      Optional image metadata.
	 * @return string Mock generated alt text.
	 */
	protected function generate_alt_text( array $image_reference, string $context = '', string $image_meta = '' ) {
		return $this->generated_alt_text;
	}

	/**
	 * Gets the last image reference arguments.
	 *
	 * @return array<string, mixed> Last image reference arguments.
	 */
	public function get_last_image_reference_args(): array {
		return $this->last_image_reference_args;
	}
}

/**
 * Alt text generation ability exposing the image fetching internals.
 *
 * @since x.x.x
 */
class Fetch_Testable_Alt_Text_Generation extends Alt_Text_Generation {
	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			'ai/alt-text-generation',
			array(
				'label'       => 'Alt Text Generation',
				'description' => 'Generates accessible alternative text for images using AI vision models.',
			)
		);
	}

	/**
	 * Exposes is_public_ip() for testing.
	 *
	 * @param string $ip The address to check.
	 * @return bool True when the address is public.
	 */
	public function public_is_public_ip( string $ip ): bool {
		return $this->is_public_ip( $ip );
	}

	/**
	 * Exposes addresses_are_requestable() for testing.
	 *
	 * @param list<string> $ips  Addresses the host resolved to.
	 * @param string       $host Host name or IP literal.
	 * @param string       $url  The full URL.
	 * @return bool True when every address may be requested.
	 */
	public function public_addresses_are_requestable( array $ips, string $host, string $url ): bool {
		return $this->addresses_are_requestable( $ips, $host, $url );
	}

	/**
	 * Exposes validate_remote_image_url() for testing.
	 *
	 * @param string $url The URL to validate.
	 * @return list<string>|\WP_Error Addresses to pin, or WP_Error otherwise.
	 */
	public function public_validate_remote_image_url( string $url ) {
		return $this->validate_remote_image_url( $url );
	}

	/**
	 * Exposes build_pin_entry() for testing.
	 *
	 * @param string       $url The URL being requested.
	 * @param list<string> $ips Validated addresses.
	 * @return string|null The resolve entry, or null when there is nothing to pin.
	 */
	public function public_build_pin_entry( string $url, array $ips ): ?string {
		return $this->build_pin_entry( $url, $ips );
	}

	/**
	 * Exposes get_redirect_target() for testing.
	 *
	 * @param array<string, mixed> $response    The response to inspect.
	 * @param string               $current_url The URL that produced the response.
	 * @param int                  $status      The response status code.
	 * @return string|null The absolute redirect target, or null when there is not one.
	 */
	public function public_get_redirect_target( array $response, string $current_url, int $status ): ?string {
		return $this->get_redirect_target( $response, $current_url, $status );
	}

	/**
	 * Exposes validate_image_data_uri() for testing.
	 *
	 * @param string $value The data URI to validate.
	 * @return true|\WP_Error True when supported, WP_Error otherwise.
	 */
	public function public_validate_image_data_uri( string $value ) {
		return $this->validate_image_data_uri( $value );
	}

	/**
	 * Exposes file_to_data_uri() for testing.
	 *
	 * @param string $file_path Path to the file.
	 * @return string|null Data URI or null on failure.
	 */
	public function public_file_to_data_uri( string $file_path ): ?string {
		return $this->file_to_data_uri( $file_path );
	}

	/**
	 * Exposes download_remote_image_to_temp_file() for testing.
	 *
	 * @param string $url Remote image URL.
	 * @return string|\WP_Error Temporary file path or WP_Error on failure.
	 */
	public function public_download_remote_image_to_temp_file( string $url ) {
		return $this->download_remote_image_to_temp_file( $url );
	}

	/**
	 * Exposes remote_url_to_reference() for testing.
	 *
	 * @param string $url The image URL to fetch.
	 * @return array{reference: string}|\WP_Error Reference array or WP_Error on failure.
	 */
	public function public_remote_url_to_reference( string $url ) {
		return $this->remote_url_to_reference( $url );
	}
}

/**
 * Alt_Text_Generation Ability test case.
 *
 * @since 0.3.0
 */
class Alt_Text_GenerationTest extends WP_UnitTestCase {

	/**
	 * Alt_Text_Generation ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Image\Alt_Text_Generation
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Alt_Text_Generation_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since 0.3.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Alt_Text_Generation_Experiment();
		$this->ability    = new Alt_Text_Generation(
			'ai/alt-text-generation',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.3.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that guideline_categories() returns site and images.
	 *
	 * @since 0.8.0
	 */
	public function test_guideline_categories_returns_site_and_images(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'guideline_categories' );
		$method->setAccessible( true );

		$this->assertSame(
			array( 'site', 'images' ),
			$method->invoke( $this->ability )
		);
	}

	/**
	 * Test that category() returns the correct category.
	 *
	 * @since 0.3.0
	 */
	public function test_category_returns_correct_category() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'category' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability );

		$this->assertEquals( 'ai-experiments', $result, 'Category should be ai-experiments' );
	}

	/**
	 * Test that input_schema() returns the expected schema structure.
	 *
	 * @since 0.3.0
	 */
	public function test_input_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'input_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Input schema should be an array' );
		$this->assertEquals( 'object', $schema['type'], 'Schema type should be object' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'attachment_id', $schema['properties'], 'Schema should have attachment_id property' );
		$this->assertArrayHasKey( 'image_url', $schema['properties'], 'Schema should have image_url property' );
		$this->assertArrayHasKey( 'context', $schema['properties'], 'Schema should have context property' );
		$this->assertArrayHasKey( 'image_meta', $schema['properties'], 'Schema should have image_meta property' );

		$this->assertEquals( 'integer', $schema['properties']['attachment_id']['type'], 'attachment_id should be integer type' );
		$this->assertEquals( 'absint', $schema['properties']['attachment_id']['sanitize_callback'], 'attachment_id should use absint' );

		$this->assertEquals( 'string', $schema['properties']['image_url']['type'], 'image_url should be string type' );
		$this->assertArrayNotHasKey( 'sanitize_callback', $schema['properties']['image_url'], 'image_url should not expose an object-bound sanitize callback.' );

		$this->assertEquals( 'string', $schema['properties']['context']['type'], 'context should be string type' );
		$this->assertEquals( 'sanitize_textarea_field', $schema['properties']['context']['sanitize_callback'], 'context should use sanitize_textarea_field' );

		$this->assertEquals( 'string', $schema['properties']['image_meta']['type'], 'image_meta should be string type' );
	}

	/**
	 * Test that output_schema() returns the expected schema structure.
	 *
	 * @since 0.3.0
	 */
	public function test_output_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'output_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Output schema should be an array' );
		$this->assertEquals( 'object', $schema['type'], 'Schema type should be object' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'alt_text', $schema['properties'], 'Schema should have alt_text property' );
		$this->assertEquals( 'string', $schema['properties']['alt_text']['type'], 'alt_text should be string type' );
		$this->assertArrayHasKey( 'is_decorative', $schema['properties'], 'Schema should have is_decorative property' );
		$this->assertEquals( 'boolean', $schema['properties']['is_decorative']['type'], 'is_decorative should be boolean type' );
	}

	/**
	 * Test that get_system_instruction() returns the system instruction.
	 *
	 * @since 0.3.0
	 */
	public function test_get_system_instruction_returns_system_instruction() {
		$system_instruction = $this->ability->get_system_instruction( 'alt-text-system-instruction.php' );

		$this->assertIsString( $system_instruction, 'System instruction should be a string' );
		$this->assertNotEmpty( $system_instruction, 'System instruction should not be empty' );
	}

	/**
	 * Test that execute_callback() returns error when neither attachment_id nor image_url provided.
	 *
	 * @since 0.3.0
	 */
	public function test_execute_callback_returns_no_image_provided() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array();
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'no_image_provided', $result->get_error_code(), 'Error code should be no_image_provided' );
	}

	/**
	 * Test that execute_callback() returns error when attachment_id points to non-existent attachment.
	 *
	 * @since 0.3.0
	 */
	public function test_execute_callback_returns_invalid_attachment() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array(
			'attachment_id' => 99999,
		);
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'invalid_attachment', $result->get_error_code(), 'Error code should be invalid_attachment' );
	}

	/**
	 * Test that execute_callback() returns error when attachment is not an image.
	 *
	 * @since 0.3.0
	 */
	public function test_execute_callback_returns_not_an_image() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create an attachment post with non-image mime type (no file needed).
		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Test non-image attachment',
				'post_mime_type' => 'text/plain',
			),
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create non-image attachment for test' );
			return;
		}

		$input  = array(
			'attachment_id' => $attachment_id,
		);
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'not_an_image', $result->get_error_code(), 'Error code should be not_an_image' );
	}

	/**
	 * Test that execute_callback() returns false for non-decorative generated alt text.
	 *
	 * @since 1.0.2
	 */
	public function test_execute_callback_returns_decorative_flag_false_for_generated_alt_text() {
		$ability    = new Testable_Alt_Text_Generation( 'A person writing in a notebook' );
		$reflection = new \ReflectionClass( $ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$ability,
			array(
				'image_url' => 'https://example.com/image.png',
			)
		);

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertSame( 'A person writing in a notebook', $result['alt_text'], 'Alt text should be returned.' );
		$this->assertArrayHasKey( 'is_decorative', $result, 'Result should include is_decorative.' );
		$this->assertFalse( $result['is_decorative'], 'Non-decorative generated alt text should return is_decorative as false.' );
	}

	/**
	 * Test that execute_callback() sanitizes image_url before resolving the image reference.
	 *
	 * @since 1.0.2
	 */
	public function test_execute_callback_sanitizes_image_url_before_resolving_reference(): void {
		$ability    = new Testable_Alt_Text_Generation( 'A person writing in a notebook' );
		$reflection = new \ReflectionClass( $ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$method->invoke(
			$ability,
			array(
				'image_url' => ' data:image/png;base64,dGVzdA== ',
			)
		);

		$args = $ability->get_last_image_reference_args();

		$this->assertSame( 'data:image/png;base64,dGVzdA==', $args['image_url'], 'image_url should be sanitized before image resolution.' );
	}

	/**
	 * Test that permission_callback() returns true for user with upload_files when using image_url only.
	 *
	 * @since 0.3.0
	 */
	public function test_permission_callback_with_image_url_and_upload_files() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'image_url' => 'https://example.com/image.jpg' ) );

		$this->assertTrue( $result, 'Permission should be granted for user with upload_files when using image_url' );
	}

	/**
	 * Test that permission_callback() returns error for user without upload_files when using image_url only.
	 *
	 * @since 0.3.0
	 */
	public function test_permission_callback_with_image_url_without_upload_files() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'image_url' => 'https://example.com/image.jpg' ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code(), 'Error code should be insufficient_capabilities' );
	}

	/**
	 * Test that permission_callback() returns true for user with edit_post for the attachment.
	 *
	 * @since 0.3.0
	 */
	public function test_permission_callback_with_attachment_id_and_edit_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$attachment_id = $this->factory->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'attachment_id' => $attachment_id ) );

		$this->assertTrue( $result, 'Permission should be granted for user with edit_post on attachment' );
	}

	/**
	 * Test that permission_callback() returns error for user without edit_post on attachment.
	 *
	 * @since 0.3.0
	 */
	public function test_permission_callback_with_attachment_id_without_edit_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$attachment_id = $this->factory->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'attachment_id' => $attachment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code(), 'Error code should be insufficient_capabilities' );
	}

	/**
	 * Test that permission_callback() returns error for non-existent attachment.
	 *
	 * @since 0.3.0
	 */
	public function test_permission_callback_with_nonexistent_attachment_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'attachment_id' => 99999 ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'attachment_not_found', $result->get_error_code(), 'Error code should be attachment_not_found' );
	}

	/**
	 * Test that meta() returns the expected meta structure.
	 *
	 * @since 0.3.0
	 */
	public function test_meta_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'meta' );
		$method->setAccessible( true );

		$meta = $method->invoke( $this->ability );

		$this->assertIsArray( $meta, 'Meta should be an array' );
		$this->assertArrayHasKey( 'show_in_rest', $meta, 'Meta should have show_in_rest' );
		$this->assertTrue( $meta['show_in_rest'], 'show_in_rest should be true' );
		$this->assertArrayHasKey( 'mcp', $meta, 'Meta should have mcp' );
		$this->assertIsArray( $meta['mcp'], 'mcp should be an array' );
	}

	/**
	 * Test that execute_callback() with valid image attachment returns alt_text (or skips if AI unavailable).
	 *
	 * @since 0.3.0
	 */
	public function test_execute_callback_with_attachment_id() {
		$data_dir = __DIR__ . '/../../../data';
		$png_path = $data_dir . '/sample.png';

		if ( ! is_readable( $png_path ) ) {
			$this->markTestSkipped( 'Test data file tests/data/sample.png not found; skipping execute_callback with real image.' );
			return;
		}

		$attachment_id = $this->factory->attachment->create_upload_object( $png_path, 0 );

		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->markTestSkipped( 'Could not create image attachment for test' );
			return;
		}

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'attachment_id' => $attachment_id,
		);

		try {
			$result = $method->invoke( $this->ability, $input );
		} catch ( \Throwable $e ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $e->getMessage() );
			return;
		}

		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $result->get_error_message() );
			return;
		}

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'alt_text', $result, 'Result should have alt_text key' );
		$this->assertIsString( $result['alt_text'], 'alt_text should be a string' );
	}

	/**
	 * Test that execute_callback() accepts optional context.
	 *
	 * @since 0.3.0
	 */
	public function test_execute_callback_with_context() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array(
			'image_url' => 'https://example.com/nonexistent.jpg',
			'context'   => 'This image appears in the hero section.',
		);
		$result = $method->invoke( $this->ability, $input );

		// Will typically be WP_Error (download failed or no_results) but context should be accepted.
		$this->assertTrue(
			is_array( $result ) || $result instanceof WP_Error,
			'Result should be array or WP_Error'
		);
	}

	/**
	 * Returns the bytes of a valid 1x1 PNG image.
	 *
	 * @since x.x.x
	 *
	 * @return string PNG image bytes.
	 */
	private function get_png_bytes(): string {
		return (string) base64_decode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Inline test fixture.
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
		);
	}

	/**
	 * Builds a pre_http_request mock that stands in for the cURL transport.
	 *
	 * The HTTP API only fires http_api_curl when a request actually goes out over cURL, so
	 * dispatching it here is what separates a mocked cURL request from a mocked request on
	 * a transport that cannot be pinned.
	 *
	 * @since x.x.x
	 *
	 * @param callable $respond Receives the request URL and arguments, returns the response.
	 * @return callable A pre_http_request callback accepting three arguments.
	 */
	private function curl_transport_mock( callable $respond ): callable {
		return static function ( $preempt, $parsed_args, $url ) use ( $respond ) {
			$handle = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- Standing in for the transport the pin relies on.

			do_action_ref_array( 'http_api_curl', array( &$handle, $parsed_args, $url ) );

			curl_close( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close -- Releases the stand-in handle.

			return $respond( $url, $parsed_args );
		};
	}

	/**
	 * Data provider for addresses that must never be requested.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string}> Test cases.
	 */
	public function data_non_public_ips(): array {
		return array(
			'loopback'          => array( '127.0.0.1' ),
			'private class a'   => array( '10.1.2.3' ),
			'private class b'   => array( '172.16.0.1' ),
			'private class c'   => array( '192.168.1.1' ),
			'cloud metadata'    => array( '169.254.169.254' ),
			'link local'        => array( '169.254.1.1' ),
			'this network'      => array( '0.0.0.0' ),
			'cgnat'             => array( '100.64.0.1' ),
			'protocol assigned' => array( '192.0.0.1' ),
			'benchmarking'      => array( '198.18.0.1' ),
			'multicast'         => array( '224.0.0.1' ),
			'reserved'          => array( '240.0.0.1' ),
			'ipv6 loopback'     => array( '::1' ),
			'ipv6 unique local' => array( 'fd00::1' ),
			'ipv6 link local'   => array( 'fe80::1' ),
			'ipv4 mapped ipv6'  => array( '::ffff:127.0.0.1' ),
			'ipv4 mapped hex'   => array( '::ffff:7f00:1' ),
			'ipv4 compatible'   => array( '::127.0.0.1' ),
			'ipv4 compat hex'   => array( '::7f00:1' ),
			'ipv4 mapped cgnat' => array( '::ffff:100.64.0.1' ),
		);
	}

	/**
	 * Test that is_public_ip() rejects loopback, private, link-local and reserved addresses.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_non_public_ips
	 *
	 * @param string $ip The address to check.
	 */
	public function test_is_public_ip_rejects_non_public_addresses( string $ip ): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		$this->assertFalse(
			$ability->public_is_public_ip( $ip ),
			sprintf( '%s should not be treated as a public address.', $ip )
		);
	}

	/**
	 * Test that is_public_ip() accepts publicly routable addresses.
	 *
	 * @since x.x.x
	 */
	public function test_is_public_ip_accepts_public_addresses(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		$this->assertTrue( $ability->public_is_public_ip( '8.8.8.8' ), 'A public IPv4 address should be allowed.' );
		$this->assertTrue( $ability->public_is_public_ip( '2606:4700::1111' ), 'A public IPv6 address should be allowed.' );
	}

	/**
	 * Test that a host resolving to a private address is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_host_resolving_to_private_address_is_rejected(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		$this->assertFalse(
			$ability->public_addresses_are_requestable(
				array( '169.254.169.254' ),
				'metadata.example.com',
				'https://metadata.example.com/a.png'
			),
			'A host resolving to the cloud metadata address should be rejected.'
		);
	}

	/**
	 * Test that a host resolving to any private address is rejected, even alongside public ones.
	 *
	 * @since x.x.x
	 */
	public function test_host_resolving_to_mixed_addresses_is_rejected(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		$this->assertFalse(
			$ability->public_addresses_are_requestable(
				array( '93.184.216.34', '127.0.0.1' ),
				'mixed.example.com',
				'https://mixed.example.com/a.png'
			),
			'A host with any non-public address should be rejected.'
		);
	}

	/**
	 * Test that an unresolvable host is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_unresolvable_host_is_rejected(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		$this->assertFalse(
			$ability->public_addresses_are_requestable( array(), 'nope.example.com', 'https://nope.example.com/a.png' ),
			'A host that cannot be resolved should be rejected.'
		);
	}

	/**
	 * Test that the core host allowance filter can permit an internal host.
	 *
	 * @since x.x.x
	 */
	public function test_host_allowance_filter_permits_internal_host(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		add_filter( 'http_request_host_is_external', '__return_true' );

		$allowed = $ability->public_addresses_are_requestable(
			array( '10.0.0.5' ),
			'internal.example.com',
			'https://internal.example.com/a.png'
		);

		remove_filter( 'http_request_host_is_external', '__return_true' );

		$this->assertTrue( $allowed, 'Sites opting an internal host in should keep working.' );
	}

	/**
	 * Data provider for URLs that must be rejected before any request is made.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{string}> Test cases.
	 */
	public function data_disallowed_image_urls(): array {
		return array(
			'loopback literal'     => array( 'http://127.0.0.1/image.png' ),
			'metadata literal'     => array( 'http://169.254.169.254/latest/meta-data/iam/security-credentials/' ),
			'private literal'      => array( 'http://10.0.0.1/image.png' ),
			'non standard port'    => array( 'http://93.184.216.34:22/image.png' ),
			'file scheme'          => array( 'file:///etc/passwd' ),
			'ftp scheme'           => array( 'ftp://93.184.216.34/image.png' ),
			'gopher scheme'        => array( 'gopher://93.184.216.34/image.png' ),
			'embedded credentials' => array( 'http://user:pass@93.184.216.34/image.png' ),
			'no host'              => array( 'https:///image.png' ),
			'not a url'            => array( 'image.png' ),
		);
	}

	/**
	 * Test that validate_remote_image_url() rejects unsafe URLs.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_disallowed_image_urls
	 *
	 * @param string $url The URL to validate.
	 */
	public function test_validate_remote_image_url_rejects_unsafe_urls( string $url ): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();
		$result  = $ability->public_validate_remote_image_url( $url );

		$this->assertInstanceOf( WP_Error::class, $result, sprintf( '%s should be rejected.', $url ) );
		$this->assertSame( 'invalid_image_url', $result->get_error_code(), 'Rejections should use a single generic error code.' );
	}

	/**
	 * Test that the site's own host remains allowed.
	 *
	 * @since x.x.x
	 */
	public function test_validate_remote_image_url_allows_site_host(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		$this->assertSame(
			array(),
			$ability->public_validate_remote_image_url( home_url( '/wp-content/uploads/image.png' ) ),
			"The site's own host should remain fetchable and exempt from pinning."
		);
	}

	/**
	 * Test that data URIs for unsupported media types are rejected.
	 *
	 * @since x.x.x
	 */
	public function test_validate_image_data_uri_rejects_unsupported_types(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		$disallowed = array(
			'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
			'data:application/pdf;base64,JVBERi0xLjQK',
			'data:image/svg+xml;base64,PHN2Zy8+',
			'data:image/png,notbase64',
			'data:image/png;base64,',
			'data:image/png;base64,!!!!',
		);

		foreach ( $disallowed as $value ) {
			$result = $ability->public_validate_image_data_uri( $value );

			$this->assertInstanceOf( WP_Error::class, $result, sprintf( '%s should be rejected.', $value ) );
			$this->assertSame( 'unsupported_image_type', $result->get_error_code(), 'Rejections should use the unsupported type error code.' );
		}
	}

	/**
	 * Test that data URIs for supported image types are accepted.
	 *
	 * @since x.x.x
	 */
	public function test_validate_image_data_uri_accepts_supported_types(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		$this->assertTrue(
			$ability->public_validate_image_data_uri( 'data:image/png;base64,' . base64_encode( $this->get_png_bytes() ) ),
			'A PNG data URI should be accepted.'
		);
	}

	/**
	 * Test that file_to_data_uri() rejects non-image content regardless of file name.
	 *
	 * @since x.x.x
	 */
	public function test_file_to_data_uri_rejects_non_image_content(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		// An image extension on a file that is not an image must not be believed.
		$file = get_temp_dir() . 'wpai-fake-' . wp_generate_password( 8, false ) . '.png';

		file_put_contents( $file, 'DB_PASSWORD=hunter2' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$result = $ability->public_file_to_data_uri( $file );

		wp_delete_file( $file );

		$this->assertNull( $result, 'A file whose contents are not an image must never be sent to a provider.' );
	}

	/**
	 * Test that file_to_data_uri() reads the media type from the file contents.
	 *
	 * @since x.x.x
	 */
	public function test_file_to_data_uri_uses_sniffed_media_type(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		// A real PNG stored with the arbitrary extension that downloads receive.
		$file = wp_tempnam( 'download' );

		file_put_contents( $file, $this->get_png_bytes() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$result = $ability->public_file_to_data_uri( $file );

		wp_delete_file( $file );

		$this->assertIsString( $result, 'A real image should produce a data URI even without a matching extension.' );
		$this->assertStringStartsWith( 'data:image/png;base64,', $result, 'The sniffed media type should be used.' );
	}

	/**
	 * Test that a failed download does not disclose the upstream response.
	 *
	 * @since x.x.x
	 */
	public function test_failed_download_does_not_disclose_upstream_response(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();
		$secret  = 'DB_PASSWORD=hunter2';

		$mock = static function () use ( $secret ) {
			return array(
				'headers'  => array(),
				'body'     => $secret,
				'response' => array(
					'code'    => 500,
					'message' => 'Internal Server Error',
				),
			);
		};

		add_filter( 'pre_http_request', $mock );

		$result = $ability->public_download_remote_image_to_temp_file( home_url( '/image.png' ) );

		remove_filter( 'pre_http_request', $mock );

		$this->assertInstanceOf( WP_Error::class, $result, 'A non-200 response should be an error.' );
		$this->assertSame( 'invalid_image_url', $result->get_error_code(), 'The error should be generic.' );

		$serialized = wp_json_encode( array( $result->get_error_message(), $result->get_error_data() ) );

		$this->assertStringNotContainsString( $secret, (string) $serialized, 'The upstream response body must not reach the caller.' );
		$this->assertStringNotContainsString( '500', (string) $serialized, 'The upstream status code must not reach the caller.' );
	}

	/**
	 * Test that a successful download of non-image content is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_successful_download_of_non_image_content_is_rejected(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		$mock = static function ( $preempt, $parsed_args ) {
			if ( ! empty( $parsed_args['filename'] ) ) {
				file_put_contents( $parsed_args['filename'], '{"AccessKeyId":"AKIA","SecretAccessKey":"s3cr3t"}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			}

			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $mock, 10, 2 );

		$result = $ability->public_remote_url_to_reference( home_url( '/image.png' ) );

		remove_filter( 'pre_http_request', $mock, 10 );

		$this->assertInstanceOf( WP_Error::class, $result, 'Non-image content should not produce a reference.' );
		$this->assertSame( 'invalid_image_url', $result->get_error_code(), 'Non-image content should fail the image check.' );
	}

	/**
	 * Test that every remote failure mode reports the same error.
	 *
	 * A caller that could tell a rejected URL from a refused connection, or either from a
	 * host that answered with something other than an image, could use the ability to probe
	 * hosts it cannot otherwise reach.
	 *
	 * @since x.x.x
	 */
	public function test_remote_failures_are_indistinguishable(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		$refused = static function () {
			return new WP_Error( 'http_request_failed', 'Connection refused.' );
		};

		$not_found = static function () {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 404,
					'message' => 'Not Found',
				),
			);
		};

		$answered_non_image = static function ( $preempt, $parsed_args ) {
			if ( ! empty( $parsed_args['filename'] ) ) {
				file_put_contents( $parsed_args['filename'], '<html>internal admin</html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			}

			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		$codes = array();

		foreach ( array( $refused, $not_found, $answered_non_image ) as $mock ) {
			add_filter( 'pre_http_request', $mock, 10, 2 );

			$result = $ability->public_remote_url_to_reference( home_url( '/image.png' ) );

			remove_filter( 'pre_http_request', $mock, 10 );

			$this->assertInstanceOf( WP_Error::class, $result, 'Each case should fail.' );

			$codes[] = $result->get_error_code();
		}

		// A rejected URL never reaches the network, so it is compared against the same error.
		$rejected = $ability->public_remote_url_to_reference( 'http://127.0.0.1/image.png' );

		$this->assertInstanceOf( WP_Error::class, $rejected, 'A disallowed URL should fail.' );

		$codes[] = $rejected->get_error_code();

		$this->assertCount( 1, array_unique( $codes ), 'Every remote failure should report one error code.' );
	}

	/**
	 * Test that the connection is pinned to the address that was validated.
	 *
	 * @since x.x.x
	 */
	public function test_pin_entry_targets_the_validated_address(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		$this->assertSame(
			'cdn.example.com:443:93.184.216.34',
			$ability->public_build_pin_entry( 'https://cdn.example.com/a.png', array( '93.184.216.34' ) ),
			'An https URL should pin the validated address on port 443.'
		);

		$this->assertSame(
			'cdn.example.com:80:93.184.216.34',
			$ability->public_build_pin_entry( 'http://cdn.example.com/a.png', array( '93.184.216.34' ) ),
			'An http URL should pin on port 80.'
		);

		$this->assertSame(
			'cdn.example.com:8080:93.184.216.34',
			$ability->public_build_pin_entry( 'http://cdn.example.com:8080/a.png', array( '93.184.216.34' ) ),
			'An explicit port should be preserved, since a pin only applies to the port it names.'
		);

		$this->assertNull(
			$ability->public_build_pin_entry( 'https://cdn.example.com/a.png', array() ),
			'A host with no validated addresses should not produce a pin.'
		);
	}

	/**
	 * Test that a request to a non-exempt host is pinned for the duration of the request.
	 *
	 * An IP literal is used so the host passes core's validation without a DNS lookup, which
	 * keeps the test hermetic while still taking the pinned path rather than the site-host
	 * exemption every other fetch test uses.
	 *
	 * @since x.x.x
	 */
	public function test_request_to_external_host_is_pinned(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();
		$bytes   = $this->get_png_bytes();
		$pinned  = null;
		$before  = has_action( 'http_api_curl' );

		$mock = $this->curl_transport_mock(
			static function ( $url, $parsed_args ) use ( $bytes, &$pinned ) {
				$pinned = has_action( 'http_api_curl' );

				file_put_contents( $parsed_args['filename'], $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			}
		);

		add_filter( 'pre_http_request', $mock, 10, 3 );

		$result = $ability->public_download_remote_image_to_temp_file( 'http://93.184.216.34/a.png' );

		remove_filter( 'pre_http_request', $mock, 10 );

		$this->assertNotFalse(
			$pinned,
			'An external host should have its address pinned while the request is in flight.'
		);
		$this->assertIsString(
			$result,
			'A request that was pinned should be kept.'
		);
		$this->assertSame(
			$before,
			has_action( 'http_api_curl' ),
			'The pin should not outlive the request it was registered for.'
		);

		if ( is_string( $result ) ) {
			wp_delete_file( $result );
		}
	}

	/**
	 * Test that a response from a request that was never pinned is discarded.
	 *
	 * The HTTP API picks its transport per request, and only the cURL transport can be
	 * pinned. A mock that never fires http_api_curl stands in for one that cannot, where
	 * the host would be resolved a second time at connection time and could answer with an
	 * address that was never validated.
	 *
	 * @since x.x.x
	 */
	public function test_response_from_unpinned_transport_is_rejected(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();
		$bytes   = $this->get_png_bytes();

		$mock = static function ( $preempt, $parsed_args ) use ( $bytes ) {
			if ( ! empty( $parsed_args['filename'] ) ) {
				file_put_contents( $parsed_args['filename'], $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			}

			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $mock, 10, 2 );

		$result = $ability->public_remote_url_to_reference( 'http://93.184.216.34/a.png' );

		remove_filter( 'pre_http_request', $mock, 10 );

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'A response that could not be pinned should be rejected even when it is a valid image.'
		);
		$this->assertSame( 'invalid_image_url', $result->get_error_code(), 'The error should be generic.' );
	}

	/**
	 * Test that the site's own host is fetched without a pin.
	 *
	 * @since x.x.x
	 */
	public function test_request_to_site_host_is_not_pinned(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();
		$pinned  = null;

		$mock = static function () use ( &$pinned ) {
			$pinned = has_action( 'http_api_curl' );

			return new WP_Error( 'http_request_failed', 'Blocked in tests.' );
		};

		add_filter( 'pre_http_request', $mock );

		$ability->public_download_remote_image_to_temp_file( home_url( '/image.png' ) );

		remove_filter( 'pre_http_request', $mock );

		$this->assertFalse( $pinned, "The site's own host is exempt from address checks, so nothing is pinned." );
	}

	/**
	 * Test that a redirect to a disallowed address is not followed.
	 *
	 * Core only re-checks redirect targets by name, so without per-hop validation a single
	 * redirect would reach an address this ability never approved.
	 *
	 * @since x.x.x
	 */
	public function test_redirect_to_disallowed_address_is_not_followed(): void {
		$ability   = new Fetch_Testable_Alt_Text_Generation();
		$requested = array();

		$mock = static function ( $preempt, $parsed_args, $url ) use ( &$requested ) {
			$requested[] = $url;

			return array(
				'headers'  => array( 'location' => 'http://169.254.169.254/latest/meta-data/' ),
				'body'     => '',
				'response' => array(
					'code'    => 302,
					'message' => 'Found',
				),
			);
		};

		add_filter( 'pre_http_request', $mock, 10, 3 );

		$result = $ability->public_download_remote_image_to_temp_file( home_url( '/image.png' ) );

		remove_filter( 'pre_http_request', $mock, 10 );

		$this->assertInstanceOf( WP_Error::class, $result, 'A redirect to a disallowed address should fail.' );
		$this->assertCount( 1, $requested, 'The redirect target should never be requested.' );
	}

	/**
	 * Test that a redirect to an allowed address is followed.
	 *
	 * @since x.x.x
	 */
	public function test_redirect_to_allowed_address_is_followed(): void {
		$ability   = new Fetch_Testable_Alt_Text_Generation();
		$bytes     = $this->get_png_bytes();
		$final_url = home_url( '/final.png' );

		$mock = static function ( $preempt, $parsed_args, $url ) use ( $bytes, $final_url ) {
			if ( $url !== $final_url ) {
				return array(
					'headers'  => array( 'location' => $final_url ),
					'body'     => '',
					'response' => array(
						'code'    => 302,
						'message' => 'Found',
					),
				);
			}

			if ( ! empty( $parsed_args['filename'] ) ) {
				file_put_contents( $parsed_args['filename'], $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			}

			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $mock, 10, 3 );

		$result = $ability->public_remote_url_to_reference( home_url( '/image.png' ) );

		remove_filter( 'pre_http_request', $mock, 10 );

		$this->assertIsArray( $result, 'A redirect to an allowed image should still produce a reference.' );
		$this->assertStringStartsWith( 'data:image/png;base64,', $result['reference'], 'The final hop should supply the image.' );
	}

	/**
	 * Test that a redirect loop terminates.
	 *
	 * @since x.x.x
	 */
	public function test_redirect_loop_is_bounded(): void {
		$ability   = new Fetch_Testable_Alt_Text_Generation();
		$requested = array();

		$mock = static function ( $preempt, $parsed_args, $url ) use ( &$requested ) {
			$requested[] = $url;

			return array(
				'headers'  => array( 'location' => home_url( '/loop.png' ) ),
				'body'     => '',
				'response' => array(
					'code'    => 302,
					'message' => 'Found',
				),
			);
		};

		add_filter( 'pre_http_request', $mock, 10, 3 );

		$result = $ability->public_download_remote_image_to_temp_file( home_url( '/loop.png' ) );

		remove_filter( 'pre_http_request', $mock, 10 );

		$this->assertInstanceOf( WP_Error::class, $result, 'An endless redirect should fail.' );
		$this->assertLessThanOrEqual( 4, count( $requested ), 'The redirect chain should be bounded.' );
	}

	/**
	 * Test that a relative redirect is resolved against the URL that issued it.
	 *
	 * @since x.x.x
	 */
	public function test_relative_redirect_is_resolved(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();

		$this->assertSame(
			home_url( '/final.png' ),
			$ability->public_get_redirect_target(
				array( 'headers' => array( 'location' => '/final.png' ) ),
				home_url( '/start/image.png' ),
				302
			),
			'A relative Location should resolve against the requesting URL.'
		);

		$this->assertNull(
			$ability->public_get_redirect_target(
				array( 'headers' => array( 'location' => '/final.png' ) ),
				home_url( '/image.png' ),
				200
			),
			'A non-redirect status should not produce a target.'
		);

		$this->assertNull(
			$ability->public_get_redirect_target( array( 'headers' => array() ), home_url( '/image.png' ), 302 ),
			'A redirect without a Location should not produce a target.'
		);
	}

	/**
	 * Test that a successful download of a real image produces a data URI reference.
	 *
	 * @since x.x.x
	 */
	public function test_successful_download_of_image_produces_reference(): void {
		$ability = new Fetch_Testable_Alt_Text_Generation();
		$bytes   = $this->get_png_bytes();

		$mock = static function ( $preempt, $parsed_args ) use ( $bytes ) {
			if ( ! empty( $parsed_args['filename'] ) ) {
				file_put_contents( $parsed_args['filename'], $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			}

			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $mock, 10, 2 );

		$result = $ability->public_remote_url_to_reference( home_url( '/image.png' ) );

		remove_filter( 'pre_http_request', $mock, 10 );

		$this->assertIsArray( $result, 'A real image should produce a reference array.' );
		$this->assertStringStartsWith( 'data:image/png;base64,', $result['reference'], 'The reference should be a PNG data URI.' );
	}

	/**
	 * Test that the download request is bounded by a timeout and a response size limit.
	 *
	 * @since x.x.x
	 */
	public function test_download_request_is_bounded(): void {
		$ability  = new Fetch_Testable_Alt_Text_Generation();
		$captured = array();

		$mock = static function ( $preempt, $parsed_args ) use ( &$captured ) {
			$captured = $parsed_args;

			return new WP_Error( 'http_request_failed', 'Blocked in tests.' );
		};

		add_filter( 'pre_http_request', $mock, 10, 2 );

		$ability->public_download_remote_image_to_temp_file( home_url( '/image.png' ) );

		remove_filter( 'pre_http_request', $mock, 10 );

		$this->assertSame( 30, $captured['timeout'], 'The request should use a short explicit timeout.' );
		$this->assertSame( 20971520, $captured['limit_response_size'], 'The response size should be capped.' );
		$this->assertSame(
			0,
			$captured['redirection'],
			'The HTTP API should not follow redirects, since each hop is validated and pinned here instead.'
		);
	}
}

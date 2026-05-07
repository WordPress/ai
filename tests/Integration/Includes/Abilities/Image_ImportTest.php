<?php
/**
 * Integration tests for the Image_Import Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Image\Import_Base64_Image as Import;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Image_Import Ability tests.
 *
 * @since 0.2.0
 */
class Test_Image_Import_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'image-import';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Image Import',
			'description' => 'Imports an image into the media library from a base64 encoded string',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 0.2.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Image_Import Ability test case.
 *
 * @since 0.2.0
 */
class Image_ImportTest extends WP_UnitTestCase {

	/**
	 * Image_Import ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Image\Import_Base64_Image
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Image_Import_Experiment
	 */
	private $experiment;

	/**
	 * Valid base64 encoded 1x1 PNG image for testing.
	 *
	 * @var string
	 */
	private $valid_base64_image = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	/**
	 * Set up test case.
	 *
	 * @since 0.2.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Image_Import_Experiment();
		$this->ability    = new Import(
			'ai/image-import',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.2.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that category() returns the correct category.
	 *
	 * @since 0.2.0
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
	 * @since 0.2.0
	 */
	public function test_input_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'input_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Input schema should be an array' );
		$this->assertEquals( 'object', $schema['type'], 'Schema type should be object' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'data', $schema['properties'], 'Schema should have data property' );
		$this->assertArrayHasKey( 'filename', $schema['properties'], 'Schema should have filename property' );
		$this->assertArrayHasKey( 'title', $schema['properties'], 'Schema should have title property' );
		$this->assertArrayHasKey( 'description', $schema['properties'], 'Schema should have description property' );
		$this->assertArrayHasKey( 'alt_text', $schema['properties'], 'Schema should have alt_text property' );
		$this->assertArrayHasKey( 'mime_type', $schema['properties'], 'Schema should have mime_type property' );
		$this->assertArrayHasKey( 'meta', $schema['properties'], 'Schema should have meta property' );
		$this->assertArrayHasKey( 'required', $schema, 'Schema should have required array' );
		$this->assertContains( 'data', $schema['required'], 'Data should be required' );

		// Verify data property.
		$this->assertEquals( 'string', $schema['properties']['data']['type'], 'Data should be string type' );
		$this->assertEquals( 'sanitize_text_field', $schema['properties']['data']['sanitize_callback'], 'Data should use sanitize_text_field' );

		// Verify optional properties.
		$this->assertEquals( 'string', $schema['properties']['filename']['type'], 'Filename should be string type' );
		$this->assertEquals( 'string', $schema['properties']['title']['type'], 'Title should be string type' );
		$this->assertEquals( 'string', $schema['properties']['description']['type'], 'Description should be string type' );
		$this->assertEquals( 'string', $schema['properties']['alt_text']['type'], 'Alt text should be string type' );
		$this->assertEquals( 'string', $schema['properties']['mime_type']['type'], 'MIME type should be string type' );
		$this->assertEquals( 'array', $schema['properties']['meta']['type'], 'Meta should be array type' );
		$this->assertEquals( 'object', $schema['properties']['meta']['items']['type'], 'Meta items should be object type' );
		$this->assertArrayHasKey( 'key', $schema['properties']['meta']['items']['properties'], 'Meta should have key property' );
		$this->assertArrayHasKey( 'value', $schema['properties']['meta']['items']['properties'], 'Meta should have value property' );
		$this->assertEquals( 'string', $schema['properties']['meta']['items']['properties']['key']['type'], 'Key should be string type' );
		$this->assertEquals( 'string', $schema['properties']['meta']['items']['properties']['value']['type'], 'Value should be string type' );
		$this->assertArrayHasKey( 'required', $schema['properties']['meta']['items'], 'Meta items should have required array' );
		$this->assertContains( 'key', $schema['properties']['meta']['items']['required'], 'Key should be required' );
		$this->assertContains( 'value', $schema['properties']['meta']['items']['required'], 'Value should be required' );
		$this->assertArrayHasKey( 'additionalProperties', $schema['properties']['meta']['items'], 'Meta items should have additionalProperties' );
	}

	/**
	 * Test that output_schema() returns the expected schema structure.
	 *
	 * @since 0.2.0
	 */
	public function test_output_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'output_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Output schema should be an array' );
		$this->assertEquals( 'object', $schema['type'], 'Schema type should be object' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'image', $schema['properties'], 'Schema should have image property' );
		$this->assertEquals( 'object', $schema['properties']['image']['type'], 'Image should be object type' );
		$this->assertArrayHasKey( 'properties', $schema['properties']['image'], 'Image should have properties' );
		$this->assertArrayHasKey( 'id', $schema['properties']['image']['properties'], 'Image should have id property' );
		$this->assertArrayHasKey( 'url', $schema['properties']['image']['properties'], 'Image should have url property' );
		$this->assertArrayHasKey( 'filename', $schema['properties']['image']['properties'], 'Image should have filename property' );
		$this->assertArrayHasKey( 'title', $schema['properties']['image']['properties'], 'Image should have title property' );
		$this->assertArrayHasKey( 'description', $schema['properties']['image']['properties'], 'Image should have description property' );
		$this->assertArrayHasKey( 'alt_text', $schema['properties']['image']['properties'], 'Image should have alt_text property' );
	}

	/**
	 * Test that execute_callback() handles valid base64 data correctly.
	 *
	 * @since 0.2.0
	 */
	public function test_execute_callback_with_valid_data() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create a user with upload_files capability.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$input = array(
			'data'      => $this->valid_base64_image,
			'mime_type' => 'image/png',
		);

		$result = $method->invoke( $this->ability, $input );

		// Result should be an array with image data.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'image', $result, 'Result should have image key' );
		$this->assertIsArray( $result['image'], 'Image should be an array' );
		$this->assertArrayHasKey( 'id', $result['image'], 'Image should have id' );
		$this->assertArrayHasKey( 'url', $result['image'], 'Image should have url' );
		$this->assertArrayHasKey( 'filename', $result['image'], 'Image should have filename' );
		$this->assertArrayHasKey( 'title', $result['image'], 'Image should have title' );
		$this->assertArrayHasKey( 'description', $result['image'], 'Image should have description' );
		$this->assertArrayHasKey( 'alt_text', $result['image'], 'Image should have alt_text' );
		$this->assertIsInt( $result['image']['id'], 'Image ID should be an integer' );
		$this->assertIsString( $result['image']['url'], 'Image URL should be a string' );
		$this->assertNotEmpty( $result['image']['url'], 'Image URL should not be empty' );
	}

	/**
	 * Test that execute_callback() handles custom filename, title, and description.
	 *
	 * @since 0.2.0
	 */
	public function test_execute_callback_with_custom_metadata() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create a user with upload_files capability.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$input = array(
			'data'        => $this->valid_base64_image,
			'filename'    => 'custom-test-image',
			'title'       => 'Custom Test Image',
			'description' => 'This is a custom test image description',
			'alt_text'    => 'Custom Test Image Alt Text',
			'mime_type'   => 'image/png',
			'meta'        => array(
				array(
					'key'   => 'custom_meta_key',
					'value' => 'custom_meta_value',
				),
			),
		);

		$result = $method->invoke( $this->ability, $input );

		// Result should be an array with image data.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'image', $result, 'Result should have image key' );
		$this->assertEquals( 'Custom Test Image', $result['image']['title'], 'Image title should match custom title' );
		$this->assertStringStartsWith( 'custom-test-image', $result['image']['filename'], 'Image filename should match custom filename' );
		$this->assertEquals( 'This is a custom test image description', $result['image']['description'], 'Image description should match custom description' );
		$this->assertEquals( 'Custom Test Image Alt Text', $result['image']['alt_text'], 'Image alt text should match custom alt text' );

		// Verify the attachment was created with the correct title.
		$attachment = get_post( $result['image']['id'] );
		$this->assertEquals( 'Custom Test Image', $attachment->post_title, 'Attachment title should match' );
		$this->assertEquals( 'This is a custom test image description', $attachment->post_content, 'Attachment description should match' );
		$this->assertEquals( 'Custom Test Image Alt Text', get_post_meta( $result['image']['id'], '_wp_attachment_image_alt', true ), 'Attachment alt text should match' );

		// Verify the meta data was saved.
		$this->assertEquals( 'custom_meta_value', get_post_meta( $result['image']['id'], 'custom_meta_key', true ), 'Meta data should be saved' );
	}

	/**
	 * Test that execute_callback() uses default values when optional parameters are not provided.
	 *
	 * @since 0.2.0
	 */
	public function test_execute_callback_uses_defaults() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create a user with upload_files capability.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$input = array(
			'data'      => $this->valid_base64_image,
			'mime_type' => 'image/png',
		);

		$result = $method->invoke( $this->ability, $input );

		// Result should be an array with image data.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'image', $result, 'Result should have image key' );
		$this->assertIsInt( $result['image']['id'], 'Image ID should be an integer' );
		$this->assertGreaterThan( 0, $result['image']['id'], 'Image ID should be greater than 0' );
		$this->assertStringStartsWith(
			'ai-generated-image-',
			$result['image']['filename'],
			'Filename should fall back to the ai-generated-image-<timestamp> format when no filename is provided'
		);
	}

	/**
	 * Test that the wpai_generated_image_filename filter can override the final filename.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_filter_overrides_filename() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create a user with upload_files capability.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$captured_filename = null;
		$captured_args     = null;

		$filter = static function ( $filename, $args ) use ( &$captured_filename, &$captured_args ) {
			$captured_filename = $filename;
			$captured_args     = $args;
			return 'filtered-image-name';
		};

		add_filter( 'wpai_generated_image_filename', $filter, 10, 2 );

		try {
			$input = array(
				'data'      => $this->valid_base64_image,
				'filename'  => 'original-filename',
				'mime_type' => 'image/png',
			);

			$result = $method->invoke( $this->ability, $input );
		} finally {
			remove_filter( 'wpai_generated_image_filename', $filter, 10 );
		}

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertSame(
			'original-filename',
			$captured_filename,
			'Filter should receive the base filename without extension'
		);
		$this->assertStringStartsWith(
			'filtered-image-name',
			$result['image']['filename'],
			'Filter should be able to override the final filename'
		);
		$this->assertStringEndsWith(
			'.png',
			$result['image']['filename'],
			'Extension should be appended after the filter runs'
		);
		$this->assertIsArray( $captured_args, 'Filter should receive args as second parameter' );
		$this->assertSame( 'original-filename', $captured_args['filename'], 'Filter args should expose the original filename' );
		$this->assertSame( 'image/png', $captured_args['mime_type'], 'Filter args should expose the MIME type' );
	}

	/**
	 * Test that values returned from the wpai_generated_image_filename filter are sanitized.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_filter_return_value_is_sanitized() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create a user with upload_files capability.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$filter = static function () {
			return '../../etc/passwd';
		};

		add_filter( 'wpai_generated_image_filename', $filter );

		try {
			$input = array(
				'data'      => $this->valid_base64_image,
				'mime_type' => 'image/png',
			);

			$result = $method->invoke( $this->ability, $input );
		} finally {
			remove_filter( 'wpai_generated_image_filename', $filter );
		}

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertStringNotContainsString( '/', $result['image']['filename'], 'Slashes should be stripped from a filter-returned filename' );
		$this->assertStringNotContainsString( '..', $result['image']['filename'], 'Path traversal sequences should be stripped from a filter-returned filename' );
		$this->assertStringEndsWith( '.png', $result['image']['filename'], 'Extension should still be appended after sanitization' );
	}

	/**
	 * Test that execute_callback() returns error when data is invalid base64.
	 *
	 * @since 0.2.0
	 */
	public function test_execute_callback_with_invalid_base64() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create a user with upload_files capability.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$input = array(
			'data' => 'invalid-base64-data!!!',
		);

		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'invalid_data', $result->get_error_code(), 'Error code should be invalid_data' );
	}

	/**
	 * Test that execute_callback() returns error when data is missing.
	 *
	 * @since 0.2.0
	 */
	public function test_execute_callback_without_data() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create a user with upload_files capability.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$input = array();

		// This should throw an error because File constructor will fail without data.
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
	}

	/**
	 * Test that execute_callback() handles different MIME types.
	 *
	 * @since 0.2.0
	 */
	public function test_execute_callback_with_different_mime_types() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create a user with upload_files capability.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$mime_types = array( 'image/png', 'image/jpeg' );

		foreach ( $mime_types as $mime_type ) {
			$input = array(
				'data'      => $this->valid_base64_image,
				'mime_type' => $mime_type,
			);

			$result = $method->invoke( $this->ability, $input );

			// Result should be an array with image data.
			$this->assertIsArray( $result, 'Result should be an array for MIME type: ' . $mime_type );
			$this->assertArrayHasKey( 'image', $result, 'Result should have image key for MIME type: ' . $mime_type );
			$this->assertIsInt( $result['image']['id'], 'Image ID should be an integer for MIME type: ' . $mime_type );
		}
	}

	/**
	 * Test that permission_callback() returns true for user with upload_files capability.
	 *
	 * @since 0.2.0
	 */
	public function test_permission_callback_with_upload_files_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		// Create a user with upload_files capability.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array() );

		$this->assertTrue( $result, 'Permission should be granted for user with upload_files capability' );
	}

	/**
	 * Test that permission_callback() returns error for user without upload_files capability.
	 *
	 * @since 0.2.0
	 */
	public function test_permission_callback_without_upload_files_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		// Create a user without upload_files capability.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code(), 'Error code should be insufficient_capabilities' );
	}

	/**
	 * Test that permission_callback() returns error for logged out user.
	 *
	 * @since 0.2.0
	 */
	public function test_permission_callback_for_logged_out_user() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code(), 'Error code should be insufficient_capabilities' );
	}

	/**
	 * Test that meta() returns the expected meta structure.
	 *
	 * @since 0.2.0
	 */
	public function test_meta_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'meta' );
		$method->setAccessible( true );

		$meta = $method->invoke( $this->ability );

		$this->assertIsArray( $meta, 'Meta should be an array' );
		$this->assertArrayHasKey( 'show_in_rest', $meta, 'Meta should have show_in_rest' );
		$this->assertTrue( $meta['show_in_rest'], 'show_in_rest should be true' );
	}
}

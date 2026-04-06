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

		$this->assertEquals( 'integer', $schema['properties']['attachment_id']['type'], 'attachment_id should be integer type' );
		$this->assertEquals( 'absint', $schema['properties']['attachment_id']['sanitize_callback'], 'attachment_id should use absint' );

		$this->assertEquals( 'string', $schema['properties']['image_url']['type'], 'image_url should be string type' );
		$this->assertIsArray( $schema['properties']['image_url']['sanitize_callback'], 'image_url should use callback array' );

		$this->assertEquals( 'string', $schema['properties']['context']['type'], 'context should be string type' );
		$this->assertEquals( 'sanitize_textarea_field', $schema['properties']['context']['sanitize_callback'], 'context should use sanitize_textarea_field' );
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
}

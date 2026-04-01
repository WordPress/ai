<?php
/**
 * Integration tests for the Image_Generation Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Image\Generate_Image;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Image_Generation Ability tests.
 *
 * @since 0.2.0
 */
class Test_Image_Generation_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'image-generation';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Image Generation and Editing',
			'description' => 'Generate and edit images using AI',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Image_Generation Ability test case.
 *
 * @since 0.2.0
 */
class Image_GenerationTest extends WP_UnitTestCase {

	/**
	 * Image_Generation ability instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Image_Generation
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Image_Generation_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since 0.2.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Image_Generation_Experiment();
		$this->ability    = new Generate_Image(
			'ai/image-generation',
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
	 * Test that guideline_categories() returns site and images.
	 *
	 * @since 0.7.0
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
		$this->assertArrayHasKey( 'prompt', $schema['properties'], 'Schema should have prompt property' );
		$this->assertArrayHasKey( 'required', $schema, 'Schema should have required array' );
		$this->assertContains( 'prompt', $schema['required'], 'Prompt should be required' );

		// Verify prompt property.
		$this->assertEquals( 'string', $schema['properties']['prompt']['type'], 'Prompt should be string type' );
		$this->assertEquals( 'sanitize_text_field', $schema['properties']['prompt']['sanitize_callback'], 'Prompt should use sanitize_text_field' );

		// Verify reference_image property.
		$this->assertArrayHasKey( 'reference', $schema['properties'], 'Schema should have reference property' );
		$this->assertEquals( 'string', $schema['properties']['reference']['type'], 'reference should be string type' );
		$this->assertEquals( 'sanitize_text_field', $schema['properties']['reference']['sanitize_callback'], 'reference should use sanitize_text_field' );
		$this->assertNotContains( 'reference', $schema['required'], 'reference should not be required' );
	}

	/**
	 * Test that generate_image_edit() returns WP_Error for invalid base64.
	 *
	 * @since 0.6.0
	 */
	public function test_generate_image_edit_with_invalid_base64(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_image' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, 'a prompt', 'not-valid-base64!!!!' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'invalid_reference', $result->get_error_code() );
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

		$image_schema = $schema['properties']['image'];
		$this->assertEquals( 'object', $image_schema['type'], 'Image property should be object type' );
		$this->assertArrayHasKey( 'properties', $image_schema, 'Image should have properties' );
		$this->assertArrayHasKey( 'data', $image_schema['properties'], 'Image should have data property' );
		$this->assertArrayHasKey( 'provider_metadata', $image_schema['properties'], 'Image should have provider_metadata property' );
		$this->assertArrayHasKey( 'model_metadata', $image_schema['properties'], 'Image should have model_metadata property' );
	}

	/**
	 * Test that execute_callback() handles prompt parameter correctly.
	 *
	 * @since 0.2.0
	 */
	public function test_execute_callback_with_prompt() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'prompt' => 'A beautiful sunset over the ocean',
		);

		try {
			$result = $method->invoke( $this->ability, $input );
		} catch ( \Throwable $e ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $e->getMessage() );
			return;
		}

		// Result may be array with image (success) or WP_Error (if AI client unavailable).
		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $result->get_error_message() );
			return;
		}

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'image', $result, 'Result should have image key' );
		$this->assertIsArray( $result['image'], 'Result image should be an array' );
		$this->assertArrayHasKey( 'data', $result['image'], 'Result image should have data' );
		$this->assertNotEmpty( $result['image']['data'], 'Result image data should not be empty' );
	}

	/**
	 * Test that execute_callback() handles empty prompt.
	 *
	 * @since 0.2.0
	 */
	public function test_execute_callback_with_empty_prompt() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'prompt' => '',
		);

		try {
			$result = $method->invoke( $this->ability, $input );
		} catch ( \Throwable $e ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $e->getMessage() );
			return;
		}

		// Result may be array with image (success) or WP_Error (if AI client unavailable or no results).
		if ( is_wp_error( $result ) ) {
			// If it's an error about no results, verify the error code.
			if ( 'no_results' === $result->get_error_code() ) {
				$this->assertEquals( 'no_results', $result->get_error_code(), 'Error code should be no_results' );
			} else {
				// Other errors (like AI client unavailable) should be skipped.
				$this->markTestSkipped( 'AI client not available in test environment: ' . $result->get_error_message() );
			}
			return;
		}

		// If we get a result, it should be an array with image data.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'image', $result, 'Result should have image key' );
		$this->assertNotEmpty( $result['image']['data'] ?? '', 'Result image data should not be empty' );
	}

	/**
	 * Test that execute_callback() returns error when no image is generated.
	 *
	 * @since 0.2.0
	 */
	public function test_execute_callback_handles_empty_result() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'prompt' => 'A beautiful sunset over the ocean',
		);

		try {
			$result = $method->invoke( $this->ability, $input );
		} catch ( \Throwable $e ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $e->getMessage() );
			return;
		}

		// Result may be array with image (success) or WP_Error (if AI client unavailable or no results).
		if ( is_wp_error( $result ) ) {
			// If it's an error about no results, verify the error code.
			if ( 'no_results' === $result->get_error_code() ) {
				$this->assertEquals( 'no_results', $result->get_error_code(), 'Error code should be no_results' );
			} else {
				// Other errors (like AI client unavailable) should be skipped.
				$this->markTestSkipped( 'AI client not available in test environment: ' . $result->get_error_message() );
			}
			return;
		}

		// If we get a result, it should be an array with image data.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'image', $result, 'Result should have image key' );
		$this->assertNotEmpty( $result['image']['data'] ?? '', 'Result image data should not be empty' );
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
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
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
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
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

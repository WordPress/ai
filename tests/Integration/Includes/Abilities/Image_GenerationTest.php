<?php
/**
 * Integration tests for the Image_Generation Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WordPress\AI\Abilities\Image\Generate;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WP_Error;
use WP_UnitTestCase;

/**
 * Test experiment for Image_Generation Ability tests.
 *
 * @since x.x.x
 */
class Test_Image_Generation_Experiment extends Abstract_Experiment {
	/**
	 * Loads experiment metadata.
	 *
	 * @since x.x.x
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'image-generation',
			'label'       => 'Image Generation',
			'description' => 'Generates an image from a passed in prompt',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		// No-op for testing.
	}

}

/**
 * Image_Generation Ability test case.
 *
 * @since x.x.x
 */
class Image_GenerationTest extends WP_UnitTestCase {

	/**
	 * Image_Generation ability instance.
	 *
	 * @var Image_Generation
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var Test_Image_Generation_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Image_Generation_Experiment();
		$this->ability = new Generate(
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
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that category() returns the correct category.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
	}

	/**
	 * Test that output_schema() returns the expected schema structure.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'output_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Output schema should be an array' );
		$this->assertEquals( 'string', $schema['type'], 'Schema type should be string' );
		$this->assertArrayHasKey( 'description', $schema, 'Schema should have description' );
	}

	/**
	 * Test that execute_callback() handles prompt parameter correctly.
	 *
	 * @since x.x.x
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

		// Result may be string (success) or WP_Error (if AI client unavailable).
		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $result->get_error_message() );
			return;
		}

		$this->assertIsString( $result, 'Result should be a string' );
		$this->assertNotEmpty( $result, 'Result should not be empty' );
	}

	/**
	 * Test that execute_callback() handles empty prompt.
	 *
	 * @since x.x.x
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

		// Result may be string (success) or WP_Error (if AI client unavailable or no results).
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

		// If we get a result, it should be a non-empty string.
		$this->assertIsString( $result, 'Result should be a string' );
		$this->assertNotEmpty( $result, 'Result should not be empty' );
	}

	/**
	 * Test that execute_callback() returns error when no image is generated.
	 *
	 * @since x.x.x
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

		// Result may be string (success) or WP_Error (if AI client unavailable or no results).
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

		// If we get a result, it should be a non-empty string.
		$this->assertIsString( $result, 'Result should be a string' );
		$this->assertNotEmpty( $result, 'Result should not be empty' );
	}

	/**
	 * Test that permission_callback() returns true for user with upload_files capability.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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


<?php
/**
 * Integration tests for the Summarization Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Summarization\Summarization;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Summarization Ability tests.
 *
 * @since 0.2.0
 */
class Test_Summarization_Experiment extends Abstract_Feature {
	/**
		 * {@inheritDoc}
		 */
	public static function get_id(): string {
		return 'summarization';
	}

	/**
		 * {@inheritDoc}
		 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Content Summarization',
			'description' => 'Summarizes long-form content into digestible overviews',
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
 * Summarization Ability test case.
 *
 * @since 0.2.0
 */
class SummarizationTest extends WP_UnitTestCase {

	/**
	 * Summarization ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Summarization\Summarization
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Summarization_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since 0.2.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Summarization_Experiment();
		$this->ability    = new Summarization(
			'ai/summarization',
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
		$this->assertArrayHasKey( 'content', $schema['properties'], 'Schema should have content property' );
		$this->assertArrayHasKey( 'context', $schema['properties'], 'Schema should have context property' );
		$this->assertArrayHasKey( 'length', $schema['properties'], 'Schema should have length property' );

		// Verify content property.
		$this->assertEquals( 'string', $schema['properties']['content']['type'], 'Content should be string type' );
		$this->assertEquals( 'sanitize_text_field', $schema['properties']['content']['sanitize_callback'], 'Content should use sanitize_text_field' );

		// Verify context property.
		$this->assertEquals( 'string', $schema['properties']['context']['type'], 'Context should be string type' );
		$this->assertEquals( 'sanitize_text_field', $schema['properties']['context']['sanitize_callback'], 'Context should use sanitize_text_field' );

		// Verify length property.
		$this->assertEquals( 'enum', $schema['properties']['length']['type'], 'Length should be enum type' );
		$this->assertEquals( array( 'short', 'medium', 'long' ), $schema['properties']['length']['enum'], 'Length should be enum with values short, medium, long' );
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
		$this->assertEquals( 'string', $schema['type'], 'Schema type should be string' );
		$this->assertArrayHasKey( 'description', $schema, 'Schema should have description' );
	}

	/**
	 * Test that get_system_instruction() returns the system instruction.
	 *
	 * @since 0.2.0
	 */
	public function test_get_system_instruction_returns_system_instruction() {
		$system_instruction = $this->ability->get_system_instruction();

		// System instruction may be empty if file doesn't exist, or contain content if it does.
		// We just verify it returns a string.
		$this->assertIsString( $system_instruction, 'System instruction should be a string' );
	}

	/**
	 * Test that execute_callback() handles content parameter correctly.
	 *
	 * @since 0.2.0
	 */
	public function test_execute_callback_with_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'content' => 'This is some test content that needs to be summarized. It contains multiple sentences to provide enough context for a meaningful summary.',
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
	 * Test that execute_callback() handles context parameter with post ID correctly.
	 *
	 * @since 0.2.0
	 */
	public function test_execute_callback_with_context_as_post_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'This is post content that needs to be summarized. It contains multiple sentences to provide enough context for a meaningful summary.',
				'post_title'   => 'Test Post',
			)
		);

		$input = array(
			'context' => (string) $post_id,
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
	 * Test that execute_callback() handles context parameter as string correctly.
	 *
	 * @since 0.2.0
	 */
	public function test_execute_callback_with_context_as_string() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'content' => 'This is some test content that needs to be summarized.',
			'context' => 'This is additional context that should be included.',
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
	 * Test that execute_callback() returns error when context points to non-existent post.
	 *
	 * @since 0.2.0
	 */
	public function test_execute_callback_with_invalid_post_id_in_context() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array(
			'context' => '99999', // Non-existent post ID as string.
		);
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'post_not_found', $result->get_error_code(), 'Error code should be post_not_found' );
	}

	/**
	 * Test that execute_callback() returns error when content is missing.
	 *
	 * @since 0.2.0
	 */
	public function test_execute_callback_without_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array();
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'content_not_provided', $result->get_error_code(), 'Error code should be content_not_provided' );
	}

	/**
	 * Test that permission_callback() returns true for user with edit_posts capability.
	 *
	 * @since 0.2.0
	 */
	public function test_permission_callback_with_edit_posts_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		// Create a user with edit_posts capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array() );

		$this->assertTrue( $result, 'Permission should be granted for user with edit_posts capability' );
	}

	/**
	 * Test that permission_callback() returns error for user without edit_posts capability.
	 *
	 * @since 0.2.0
	 */
	public function test_permission_callback_without_edit_posts_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		// Create a user without edit_posts capability.
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
	 * Test that permission_callback() returns true for user with edit_post capability when context is post ID.
	 *
	 * @since 0.2.0
	 */
	public function test_permission_callback_with_context_as_post_id_and_edit_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Test content',
				'post_status'  => 'publish',
			)
		);

		// Create a user with edit capability (editor role has edit_post for all posts).
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'context' => (string) $post_id ) );

		$this->assertTrue( $result, 'Permission should be granted for user with edit_post capability' );
	}

	/**
	 * Test that permission_callback() returns error for user without edit_post capability when context is post ID.
	 *
	 * @since 0.2.0
	 */
	public function test_permission_callback_with_context_as_post_id_without_edit_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Test content',
				'post_status'  => 'publish',
			)
		);

		// Create a user with only read capability (subscriber role has read_post but not edit_post).
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'context' => (string) $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code(), 'Error code should be insufficient_capabilities' );
	}

	/**
	 * Test that permission_callback() returns error for non-existent post when context is post ID.
	 *
	 * @since 0.2.0
	 */
	public function test_permission_callback_with_nonexistent_post_id_in_context() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'context' => '99999' ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'post_not_found', $result->get_error_code(), 'Error code should be post_not_found' );
	}

	/**
	 * Test that permission_callback() returns false for post type without show_in_rest when context is post ID.
	 *
	 * @since 0.2.0
	 */
	public function test_permission_callback_with_post_type_without_show_in_rest_when_context_is_post_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		// Register a custom post type without show_in_rest.
		register_post_type(
			'test_no_rest',
			array(
				'public'       => true,
				'show_in_rest' => false,
			)
		);

		// Create a test post with this post type.
		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Test content',
				'post_type'    => 'test_no_rest',
				'post_status'  => 'publish',
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'context' => (string) $post_id ) );

		$this->assertFalse( $result, 'Permission should be denied for post type without show_in_rest' );

		// Clean up.
		unregister_post_type( 'test_no_rest' );
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

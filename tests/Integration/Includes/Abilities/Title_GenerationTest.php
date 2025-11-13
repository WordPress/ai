<?php
/**
 * Integration tests for the Title_Generation Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WordPress\AI\Abilities\Title_Generation;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WP_Error;
use WP_UnitTestCase;

/**
 * Test experiment for Title_Generation Ability tests.
 *
 * @since 0.1.0
 */
class Test_Title_Generation_Experiment extends Abstract_Experiment {
	/**
	 * Loads experiment metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'title-generation',
			'label'       => 'Title Generation',
			'description' => 'Generates title suggestions from content',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Title_Generation Ability test case.
 *
 * @since 0.1.0
 */
class Title_GenerationTest extends WP_UnitTestCase {

	/**
	 * Title_Generation ability instance.
	 *
	 * @var Title_Generation
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var Test_Title_Generation_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since 0.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Title_Generation_Experiment();
		$this->ability = new Title_Generation(
			'ai/title-generation',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.1.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that category() returns the correct category.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
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
		$this->assertArrayHasKey( 'post_id', $schema['properties'], 'Schema should have post_id property' );
		$this->assertArrayHasKey( 'n', $schema['properties'], 'Schema should have n property' );

		// Verify content property.
		$this->assertEquals( 'string', $schema['properties']['content']['type'], 'Content should be string type' );
		$this->assertEquals( 'sanitize_text_field', $schema['properties']['content']['sanitize_callback'], 'Content should use sanitize_text_field' );

		// Verify post_id property.
		$this->assertEquals( 'integer', $schema['properties']['post_id']['type'], 'Post ID should be integer type' );
		$this->assertEquals( 'absint', $schema['properties']['post_id']['sanitize_callback'], 'Post ID should use absint' );

		// Verify n property.
		$this->assertEquals( 'integer', $schema['properties']['n']['type'], 'n should be integer type' );
		$this->assertEquals( 1, $schema['properties']['n']['minimum'], 'n minimum should be 1' );
		$this->assertEquals( 10, $schema['properties']['n']['maximum'], 'n maximum should be 10' );
	}

	/**
	 * Test that output_schema() returns the expected schema structure.
	 *
	 * @since 0.1.0
	 */
	public function test_output_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'output_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Output schema should be an array' );
		$this->assertEquals( 'object', $schema['type'], 'Schema type should be object' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'titles', $schema['properties'], 'Schema should have titles property' );
		$this->assertEquals( 'array', $schema['properties']['titles']['type'], 'Titles should be array type' );
		$this->assertArrayHasKey( 'items', $schema['properties']['titles'], 'Titles should have items' );
		$this->assertEquals( 'string', $schema['properties']['titles']['items']['type'], 'Title items should be string type' );
	}

	/**
	 * Test that execute_callback() handles content parameter correctly.
	 *
	 * @since 0.1.0
	 */
	public function test_execute_callback_with_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array(
			'content' => 'This is some test content.',
			'n'       => 3,
		);
		$result = $method->invoke( $this->ability, $input );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEquals( 'ai/title-generation', $result['name'], 'Experiment name should match' );
		$this->assertEquals( 'Title Generation', $result['label'], 'Label should match' );
		$this->assertEquals( 'Generates title suggestions from content', $result['description'], 'Description should match' );
		$this->assertEquals( 'This is some test content.', $result['content'], 'Content should match input' );
		$this->assertEquals( 3, $result['n'], 'n should match input' );
	}

	/**
	 * Test that execute_callback() handles post_id parameter correctly.
	 *
	 * @since 0.1.0
	 */
	public function test_execute_callback_with_post_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'This is post content.',
				'post_title'   => 'Test Post',
			)
		);

		$input  = array(
			'post_id' => $post_id,
			'n'       => 2,
		);
		$result = $method->invoke( $this->ability, $input );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEquals( 'This is post content.', $result['content'], 'Content should come from post' );
		$this->assertEquals( $post_id, $result['post_id'], 'Post ID should match' );
	}

	/**
	 * Test that execute_callback() returns error when post_id points to non-existent post.
	 *
	 * @since 0.1.0
	 */
	public function test_execute_callback_with_invalid_post_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array(
			'post_id' => 99999, // Non-existent post ID.
		);
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'post_not_found', $result->get_error_code(), 'Error code should be post_not_found' );
	}

	/**
	 * Test that execute_callback() returns error when content is missing.
	 *
	 * @since 0.1.0
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
	 * Test that execute_callback() uses default values.
	 *
	 * @since 0.1.0
	 */
	public function test_execute_callback_uses_defaults() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array(
			'content' => 'Test content',
		);
		$result = $method->invoke( $this->ability, $input );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEquals( 1, $result['n'], 'n should default to 1' );
	}

	/**
	 * Test that execute_callback() prioritizes post_id over content.
	 *
	 * @since 0.1.0
	 */
	public function test_execute_callback_post_id_overrides_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Post content takes priority.',
				'post_title'   => 'Test Post',
			)
		);

		$input  = array(
			'content' => 'This content should be ignored.',
			'post_id' => $post_id,
		);
		$result = $method->invoke( $this->ability, $input );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertEquals( 'Post content takes priority.', $result['content'], 'Post content should override provided content' );
	}

	/**
	 * Test that permission_callback() returns true for user with edit_posts capability.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
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
	 * @since 0.1.0
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
	 * @since 0.1.0
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


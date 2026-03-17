<?php
/**
 * Integration tests for the Contextual_Tagging Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Contextual_Tagging\Contextual_Tagging;
use WordPress\AI\Abstracts\Abstract_Experiment;

/**
 * Test experiment for Contextual_Tagging Ability tests.
 *
 * @since 0.6.0
 */
class Test_Contextual_Tagging_Experiment extends Abstract_Experiment {
	/**
	 * Loads experiment metadata.
	 *
	 * @since 0.6.0
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'contextual-tagging',
			'label'       => 'Contextual Tagging',
			'description' => 'AI-powered suggestions for post tags and categories.',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 0.6.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Contextual_Tagging Ability test case.
 *
 * @since 0.6.0
 */
class Contextual_TaggingTest extends WP_UnitTestCase {

	/**
	 * Contextual_Tagging ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Contextual_Tagging\Contextual_Tagging
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Contextual_Tagging_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since 0.6.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Contextual_Tagging_Experiment();
		$this->ability    = new Contextual_Tagging(
			'ai/contextual-tagging',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.6.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that category() returns the correct category.
	 *
	 * @since 0.6.0
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
	 * @since 0.6.0
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
		$this->assertArrayHasKey( 'taxonomy', $schema['properties'], 'Schema should have taxonomy property' );
		$this->assertArrayHasKey( 'strategy', $schema['properties'], 'Schema should have strategy property' );
		$this->assertArrayHasKey( 'max_suggestions', $schema['properties'], 'Schema should have max_suggestions property' );

		// Verify taxonomy property.
		$this->assertEquals( 'string', $schema['properties']['taxonomy']['type'], 'Taxonomy should be string type' );
		$this->assertEquals( 'post_tag', $schema['properties']['taxonomy']['default'], 'Taxonomy default should be post_tag' );

		// Verify strategy property.
		$this->assertEquals( 'string', $schema['properties']['strategy']['type'], 'Strategy should be string type' );
		$this->assertEquals( 'existing_only', $schema['properties']['strategy']['default'], 'Strategy default should be existing_only' );

		// Verify max_suggestions property.
		$this->assertEquals( 'integer', $schema['properties']['max_suggestions']['type'], 'max_suggestions should be integer type' );
		$this->assertEquals( 1, $schema['properties']['max_suggestions']['minimum'], 'max_suggestions minimum should be 1' );
		$this->assertEquals( 10, $schema['properties']['max_suggestions']['maximum'], 'max_suggestions maximum should be 10' );
	}

	/**
	 * Test that output_schema() returns the expected schema structure.
	 *
	 * @since 0.6.0
	 */
	public function test_output_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'output_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Output schema should be an array' );
		$this->assertEquals( 'object', $schema['type'], 'Schema type should be object' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'suggestions', $schema['properties'], 'Schema should have suggestions property' );
		$this->assertEquals( 'array', $schema['properties']['suggestions']['type'], 'Suggestions should be array type' );
		$this->assertArrayHasKey( 'items', $schema['properties']['suggestions'], 'Suggestions should have items' );

		// Verify suggestion item properties.
		$item_props = $schema['properties']['suggestions']['items']['properties'];
		$this->assertArrayHasKey( 'term', $item_props, 'Item should have term property' );
		$this->assertArrayHasKey( 'confidence', $item_props, 'Item should have confidence property' );
		$this->assertArrayHasKey( 'is_new', $item_props, 'Item should have is_new property' );
		$this->assertArrayHasKey( 'parent', $item_props, 'Item should have parent property' );
	}

	/**
	 * Test that get_system_instruction() returns the system instruction.
	 *
	 * @since 0.6.0
	 */
	public function test_get_system_instruction_returns_system_instruction() {
		$system_instruction = $this->ability->get_system_instruction(
			null,
			array(
				'strategy'        => 'Only suggest existing terms.',
				'max_suggestions' => 5,
				'taxonomy'        => 'tags',
				'existing_terms'  => 'Existing terms: wordpress, plugins',
			)
		);

		$this->assertIsString( $system_instruction, 'System instruction should be a string' );
		$this->assertNotEmpty( $system_instruction, 'System instruction should not be empty' );
		$this->assertStringContainsString( 'tags', $system_instruction, 'System instruction should contain the taxonomy name' );
	}

	/**
	 * Test that execute_callback() returns error when content is missing.
	 *
	 * @since 0.6.0
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
	 * Test that execute_callback() returns error when post_id points to non-existent post.
	 *
	 * @since 0.6.0
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
	 * Test that execute_callback() returns error for invalid taxonomy.
	 *
	 * @since 0.6.0
	 */
	public function test_execute_callback_with_invalid_taxonomy() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array(
			'content'  => 'Test content for taxonomy suggestions.',
			'taxonomy' => 'nonexistent_taxonomy',
		);
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'invalid_taxonomy', $result->get_error_code(), 'Error code should be invalid_taxonomy' );
	}

	/**
	 * Test that parse_suggestions() handles valid JSON correctly.
	 *
	 * @since 0.6.0
	 */
	public function test_parse_suggestions_with_valid_json() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '[{"term": "development", "confidence": 0.9, "is_new": false}, {"term": "plugins", "confidence": 0.8, "is_new": true}]';

		$result = $method->invoke( $this->ability, $response, array( 'development' ), 5 );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertCount( 2, $result, 'Should have 2 suggestions' );
		$this->assertEquals( 'development', $result[0]['term'], 'First suggestion should be development' );
		$this->assertFalse( $result[0]['is_new'], 'Existing term should not be marked as new' );
		$this->assertTrue( $result[1]['is_new'], 'Non-existing term should be marked as new' );
	}

	/**
	 * Test that parse_suggestions() handles markdown-wrapped JSON.
	 *
	 * @since 0.6.0
	 */
	public function test_parse_suggestions_with_markdown_json() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = "```json\n[{\"term\": \"ai\", \"confidence\": 0.95, \"is_new\": false}]\n```";

		$result = $method->invoke( $this->ability, $response, array( 'ai' ), 5 );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertCount( 1, $result, 'Should have 1 suggestion' );
		$this->assertEquals( 'ai', $result[0]['term'], 'Suggestion should be ai' );
	}

	/**
	 * Test that parse_suggestions() returns error for invalid JSON.
	 *
	 * @since 0.6.0
	 */
	public function test_parse_suggestions_with_invalid_json() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = 'This is not valid JSON';

		$result = $method->invoke( $this->ability, $response, array(), 5 );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'invalid_response', $result->get_error_code(), 'Error code should be invalid_response' );
	}

	/**
	 * Test that parse_suggestions() limits results to max_suggestions.
	 *
	 * @since 0.6.0
	 */
	public function test_parse_suggestions_limits_results() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '[
			{"term": "a", "confidence": 0.9, "is_new": false},
			{"term": "b", "confidence": 0.8, "is_new": false},
			{"term": "c", "confidence": 0.7, "is_new": false},
			{"term": "d", "confidence": 0.6, "is_new": false},
			{"term": "e", "confidence": 0.5, "is_new": false}
		]';

		$result = $method->invoke( $this->ability, $response, array(), 3 );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertCount( 3, $result, 'Should be limited to 3 suggestions' );
		$this->assertEquals( 'a', $result[0]['term'], 'First suggestion should be highest confidence' );
	}

	/**
	 * Test that permission_callback() returns true for user with edit_posts capability.
	 *
	 * @since 0.6.0
	 */
	public function test_permission_callback_with_edit_posts_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array() );

		$this->assertTrue( $result, 'Permission should be granted for user with edit_posts capability' );
	}

	/**
	 * Test that permission_callback() returns error for user without edit_posts capability.
	 *
	 * @since 0.6.0
	 */
	public function test_permission_callback_without_edit_posts_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code(), 'Error code should be insufficient_capabilities' );
	}

	/**
	 * Test that permission_callback() returns error for logged out user.
	 *
	 * @since 0.6.0
	 */
	public function test_permission_callback_for_logged_out_user() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		wp_set_current_user( 0 );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code(), 'Error code should be insufficient_capabilities' );
	}

	/**
	 * Test that meta() returns the expected meta structure.
	 *
	 * @since 0.6.0
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

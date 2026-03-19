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
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Contextual_Tagging Ability tests.
 *
 * @since x.x.x
 */
class Test_Contextual_Tagging_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'contextual-tagging';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Contextual Tagging',
			'description' => 'AI-powered suggestions for post tags and categories.',
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
 * Contextual_Tagging Ability test case.
 *
 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'wpai_contextual_tagging_content' );
		remove_all_filters( 'wpai_contextual_tagging_suggestions' );
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
	 * @since x.x.x
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
	 * Test that execute_callback() returns error when content is missing.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	public function test_parse_suggestions_with_valid_json() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [{"term": "development", "confidence": 0.9, "is_new": false}, {"term": "plugins", "confidence": 0.8, "is_new": true}]}';

		$result = $method->invoke( $this->ability, $response, array( 'development' ), 5 );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertCount( 2, $result, 'Should have 2 suggestions' );
		$this->assertEquals( 'development', $result[0]['term'], 'First suggestion should be development' );
		$this->assertFalse( $result[0]['is_new'], 'Existing term should not be marked as new' );
		$this->assertTrue( $result[1]['is_new'], 'Non-existing term should be marked as new' );
	}

	/**
	 * Test that parse_suggestions() returns error for invalid JSON.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	public function test_parse_suggestions_limits_results() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [
			{"term": "a", "confidence": 0.9, "is_new": false},
			{"term": "b", "confidence": 0.8, "is_new": false},
			{"term": "c", "confidence": 0.7, "is_new": false},
			{"term": "d", "confidence": 0.6, "is_new": false},
			{"term": "e", "confidence": 0.5, "is_new": false}
		]}';

		$result = $method->invoke( $this->ability, $response, array(), 3 );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertCount( 3, $result, 'Should be limited to 3 suggestions' );
		$this->assertEquals( 'a', $result[0]['term'], 'First suggestion should be highest confidence' );
	}

	/**
	 * Test that parse_suggestions() sorts by confidence descending.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_sorts_by_confidence() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [
			{"term": "low", "confidence": 0.3, "is_new": true},
			{"term": "high", "confidence": 0.95, "is_new": true},
			{"term": "mid", "confidence": 0.6, "is_new": true}
		]}';

		$result = $method->invoke( $this->ability, $response, array(), 10 );

		$this->assertEquals( 'high', $result[0]['term'], 'First should be highest confidence' );
		$this->assertEquals( 'mid', $result[1]['term'], 'Second should be mid confidence' );
		$this->assertEquals( 'low', $result[2]['term'], 'Third should be lowest confidence' );
	}

	/**
	 * Test that parse_suggestions() clamps confidence values to 0-1 range.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_clamps_confidence() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [
			{"term": "over", "confidence": 1.5, "is_new": true},
			{"term": "under", "confidence": -0.5, "is_new": true}
		]}';

		$result = $method->invoke( $this->ability, $response, array(), 10 );

		$this->assertEquals( 1.0, $result[0]['confidence'], 'Confidence above 1 should be clamped to 1.0' );
		$this->assertEquals( 0.0, $result[1]['confidence'], 'Confidence below 0 should be clamped to 0.0' );
	}

	/**
	 * Test that parse_suggestions() preserves parent field for hierarchical terms.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_preserves_parent_field() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [
			{"term": "machine learning", "confidence": 0.9, "is_new": true, "parent": "technology"},
			{"term": "finance", "confidence": 0.8, "is_new": false}
		]}';

		$result = $method->invoke( $this->ability, $response, array( 'finance' ), 10 );

		$this->assertArrayHasKey( 'parent', $result[0], 'First suggestion should have parent key' );
		$this->assertEquals( 'technology', $result[0]['parent'], 'Parent should be technology' );
		$this->assertArrayNotHasKey( 'parent', $result[1], 'Second suggestion should not have parent key' );
	}

	/**
	 * Test that parse_suggestions() skips items with empty or missing term.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_skips_invalid_items() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [
			{"term": "valid", "confidence": 0.9, "is_new": true},
			{"confidence": 0.8, "is_new": true},
			{"term": "", "confidence": 0.7, "is_new": true},
			"not an object",
			{"term": "also valid", "confidence": 0.6, "is_new": true}
		]}';

		$result = $method->invoke( $this->ability, $response, array(), 10 );

		$this->assertCount( 2, $result, 'Should only have 2 valid suggestions' );
		$this->assertEquals( 'valid', $result[0]['term'] );
		$this->assertEquals( 'also valid', $result[1]['term'] );
	}

	/**
	 * Test that parse_suggestions() defaults confidence to 0.5 when missing.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_defaults_missing_confidence() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [{"term": "test", "is_new": true}]}';

		$result = $method->invoke( $this->ability, $response, array(), 10 );

		$this->assertEquals( 0.5, $result[0]['confidence'], 'Missing confidence should default to 0.5' );
	}

	/**
	 * Test that parse_suggestions() determines is_new based on existing terms, not AI response.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_overrides_is_new_from_existing_terms() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		// AI says "tech" is new, but it exists in our list as "Tech".
		$response = '{"suggestions": [{"term": "tech", "confidence": 0.9, "is_new": true}]}';

		$result = $method->invoke( $this->ability, $response, array( 'Tech' ), 10 );

		$this->assertFalse( $result[0]['is_new'], 'Should be false because "Tech" exists (case-insensitive match)' );
		$this->assertEquals( 'Tech', $result[0]['term'], 'Should use the original capitalized term name from the existing terms list' );
	}

	/**
	 * Test that build_prompt() wraps content in XML tags for existing_only strategy.
	 *
	 * @since x.x.x
	 */
	public function test_build_prompt_existing_only_strategy() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'build_prompt' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, 'Test content', 'post_tag', 'existing_only', array( 'php', 'javascript' ) );

		$this->assertStringContainsString( '<content>Test content</content>', $result, 'Should wrap content in XML tags' );
		$this->assertStringContainsString( '<strategy>', $result, 'Should include strategy tag' );
		$this->assertStringContainsString( 'Only suggest terms that already exist', $result );
		$this->assertStringContainsString( '<existing-terms>php, javascript</existing-terms>', $result );
	}

	/**
	 * Test that build_prompt() wraps content in XML tags for allow_new strategy.
	 *
	 * @since x.x.x
	 */
	public function test_build_prompt_allow_new_strategy() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'build_prompt' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, 'Test content', 'post_tag', 'allow_new', array() );

		$this->assertStringContainsString( '<content>Test content</content>', $result );
		$this->assertStringContainsString( 'may suggest new terms', $result );
	}

	/**
	 * Test that build_prompt() handles empty terms with existing_only strategy.
	 *
	 * @since x.x.x
	 */
	public function test_build_prompt_empty_terms_existing_only() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'build_prompt' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, 'Test content', 'post_tag', 'existing_only', array() );

		$this->assertStringContainsString( 'empty suggestions array', $result );
	}

	/**
	 * Test that suggestions_schema() returns the expected structure.
	 *
	 * @since x.x.x
	 */
	public function test_suggestions_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'suggestions_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'suggestions', $schema['properties'] );
		$this->assertEquals( 'array', $schema['properties']['suggestions']['type'] );

		$item_props = $schema['properties']['suggestions']['items']['properties'];
		$this->assertArrayHasKey( 'term', $item_props );
		$this->assertArrayHasKey( 'confidence', $item_props );
		$this->assertArrayHasKey( 'is_new', $item_props );
		$this->assertArrayHasKey( 'parent', $item_props );

		$required = $schema['properties']['suggestions']['items']['required'];
		$this->assertContains( 'term', $required );
		$this->assertContains( 'confidence', $required );
		$this->assertContains( 'is_new', $required );
	}

	/**
	 * Test that permission_callback() returns true for user with edit_posts capability.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * Test that permission_callback() returns false for post type without show_in_rest.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_with_post_type_without_show_in_rest() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		register_post_type(
			'test_no_rest',
			array(
				'public'       => true,
				'show_in_rest' => false,
			)
		);

		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Test content',
				'post_type'    => 'test_no_rest',
				'post_status'  => 'publish',
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'post_id' => $post_id ) );

		$this->assertFalse( $result, 'Permission should be denied for post type without show_in_rest' );

		unregister_post_type( 'test_no_rest' );
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

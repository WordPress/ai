<?php
/**
 * Integration tests for the Content_Classification Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Content_Classification\Content_Classification;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Content_Classification Ability tests.
 *
 * @since 0.7.0
 */
class Test_Content_Classification_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'content-classification';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Content Classification',
			'description' => 'AI-powered suggestions for post tags and categories.',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 0.7.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Content_Classification Ability test case.
 *
 * @since 0.7.0
 */
class Content_ClassificationTest extends WP_UnitTestCase {

	/**
	 * Content_Classification ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Content_Classification\Content_Classification
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Content_Classification_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since 0.7.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Content_Classification_Experiment();
		$this->ability    = new Content_Classification(
			'ai/content-classification',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.7.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'wpai_content_classification_content' );
		remove_all_filters( 'wpai_content_classification_suggestions' );
		remove_all_filters( 'wpai_content_classification_min_confidence' );
		remove_all_filters( 'wpai_content_classification_available_terms' );
		remove_all_filters( 'wpai_content_classification_prompt' );
		parent::tearDown();
	}

	/**
	 * Test that category() returns the correct category.
	 *
	 * @since 0.7.0
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
	 * @since 0.7.0
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
	 * @since 0.7.0
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
	 * @since 0.7.0
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
	 * @since 0.7.0
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
	 * @since 0.7.0
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
	 * @since 0.7.0
	 */
	public function test_parse_suggestions_with_valid_json() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [{"term": "development", "confidence": 0.9}, {"term": "plugins", "confidence": 0.8}]}';

		$result = $method->invoke( $this->ability, $response, 'allow_new', array(), 'post_tag', 5 );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertCount( 2, $result, 'Should have 2 suggestions' );
		$this->assertEquals( 'development', $result[0]['term'], 'First suggestion should be development' );
		$this->assertTrue( $result[0]['is_new'], 'Term should be marked as new in allow_new strategy' );
		$this->assertTrue( $result[1]['is_new'], 'Term should be marked as new in allow_new strategy' );
	}

	/**
	 * Test that parse_suggestions() returns error for invalid JSON.
	 *
	 * @since 0.7.0
	 */
	public function test_parse_suggestions_with_invalid_json() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = 'This is not valid JSON';

		$result = $method->invoke( $this->ability, $response, 'allow_new', array(), 'post_tag', 5 );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'invalid_response', $result->get_error_code(), 'Error code should be invalid_response' );
	}

	/**
	 * Test that parse_suggestions() limits results to max_suggestions.
	 *
	 * @since 0.7.0
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

		$result = $method->invoke( $this->ability, $response, 'allow_new', array(), 'post_tag', 3 );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertCount( 3, $result, 'Should be limited to 3 suggestions' );
		$this->assertEquals( 'a', $result[0]['term'], 'First suggestion should be highest confidence' );
	}

	/**
	 * Test that parse_suggestions() sorts by confidence descending.
	 *
	 * @since 0.7.0
	 */
	public function test_parse_suggestions_sorts_by_confidence() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		// Disable the relevance floor for this test so it focuses on sort order.
		add_filter( 'wpai_content_classification_min_confidence', static fn() => 0.0 );

		$response = '{"suggestions": [
			{"term": "low", "confidence": 0.3, "is_new": true},
			{"term": "high", "confidence": 0.95, "is_new": true},
			{"term": "mid", "confidence": 0.6, "is_new": true}
		]}';

		$result = $method->invoke( $this->ability, $response, 'allow_new', array(), 'post_tag', 10 );

		$this->assertEquals( 'high', $result[0]['term'], 'First should be highest confidence' );
		$this->assertEquals( 'mid', $result[1]['term'], 'Second should be mid confidence' );
		$this->assertEquals( 'low', $result[2]['term'], 'Third should be lowest confidence' );
	}

	/**
	 * Test that parse_suggestions() clamps confidence values to 0-1 range.
	 *
	 * @since 0.7.0
	 */
	public function test_parse_suggestions_clamps_confidence() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		// Disable the floor so the lower-clamp branch survives parsing.
		add_filter( 'wpai_content_classification_min_confidence', static fn() => 0.0 );

		$response = '{"suggestions": [
			{"term": "over", "confidence": 1.5, "is_new": true},
			{"term": "under", "confidence": -0.5, "is_new": true}
		]}';

		$result = $method->invoke( $this->ability, $response, 'allow_new', array(), 'post_tag', 10 );

		$this->assertEquals( 1.0, $result[0]['confidence'], 'Confidence above 1 should be clamped to 1.0' );
		$this->assertEquals( 0.0, $result[1]['confidence'], 'Confidence below 0 should be clamped to 0.0' );
	}

	/**
	 * Test that the system instruction contains a category-branch section
	 * (Step 3): explicit guidance for `kind="category"` covering breadth,
	 * hierarchy, and not padding with parent categories.
	 *
	 * @since x.x.x
	 */
	public function test_system_instruction_has_category_branch(): void {
		$instruction = $this->ability->get_system_instruction();

		$this->assertStringContainsString( 'When `kind="category"`', $instruction );
		$this->assertStringContainsString( 'broad, thematic', $instruction );
		$this->assertStringContainsString( 'hierarchy', $instruction );
	}

	/**
	 * Test that the system instruction contains a tag-branch section
	 * (Step 3): explicit guidance for `kind="tag"` and a hard nudge
	 * against generic process-style tags.
	 *
	 * @since x.x.x
	 */
	public function test_system_instruction_has_tag_branch(): void {
		$instruction = $this->ability->get_system_instruction();

		$this->assertStringContainsString( 'When `kind="tag"`', $instruction );
		$this->assertStringContainsString( 'specific, descriptive', $instruction );
		// The instruction must call out the generic-tag noise that the
		// baseline eval flagged as the main FP source.
		$this->assertStringContainsString( 'Tutorial', $instruction );
		$this->assertStringContainsString( 'Guide', $instruction );
	}

	/**
	 * Test that the taxonomy descriptor contains the hierarchical category
	 * branch with label, kind, hierarchical flag, and description.
	 *
	 * @since x.x.x
	 */
	public function test_taxonomy_descriptor_category_branch(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'build_taxonomy_descriptor' );
		$method->setAccessible( true );

		$descriptor = (string) $method->invoke( $this->ability, 'category' );

		$this->assertStringContainsString( 'name="category"', $descriptor );
		$this->assertStringContainsString( 'kind="category"', $descriptor );
		$this->assertStringContainsString( 'hierarchical="true"', $descriptor );
		$this->assertStringContainsString( 'label="Categories"', $descriptor );
	}

	/**
	 * Test that the taxonomy descriptor differentiates tags via
	 * `kind="tag"` and `hierarchical="false"`.
	 *
	 * @since x.x.x
	 */
	public function test_taxonomy_descriptor_tag_branch(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'build_taxonomy_descriptor' );
		$method->setAccessible( true );

		$descriptor = (string) $method->invoke( $this->ability, 'post_tag' );

		$this->assertStringContainsString( 'name="post_tag"', $descriptor );
		$this->assertStringContainsString( 'kind="tag"', $descriptor );
		$this->assertStringContainsString( 'hierarchical="false"', $descriptor );
		$this->assertStringContainsString( 'label="Tags"', $descriptor );
	}

	/**
	 * Test that an unknown taxonomy yields an empty descriptor.
	 *
	 * `execute_callback()` already rejects nonexistent taxonomies with a
	 * WP_Error before the descriptor is built, so this guards the helper
	 * against being misused directly: it returns an empty string rather
	 * than emitting a meaningless `<taxonomy>` block for a slug that does
	 * not resolve to a registered taxonomy.
	 *
	 * @since x.x.x
	 */
	public function test_taxonomy_descriptor_unknown_taxonomy_returns_empty(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'build_taxonomy_descriptor' );
		$method->setAccessible( true );

		$this->assertSame( '', (string) $method->invoke( $this->ability, 'made_up_tax_xyz' ) );
		$this->assertSame( '', (string) $method->invoke( $this->ability, '' ) );
	}

	/**
	 * Test that a custom taxonomy's description is surfaced in the
	 * descriptor body.
	 *
	 * @since x.x.x
	 */
	public function test_taxonomy_descriptor_includes_description(): void {
		register_taxonomy(
			'wpai_cc_test_tax',
			'post',
			array(
				'hierarchical' => true,
				'label'        => 'Test Topics',
				'description'  => 'Editorial themes used to organize site content.',
			)
		);

		try {
			$reflection = new \ReflectionClass( $this->ability );
			$method     = $reflection->getMethod( 'build_taxonomy_descriptor' );
			$method->setAccessible( true );

			$descriptor = (string) $method->invoke( $this->ability, 'wpai_cc_test_tax' );

			$this->assertStringContainsString( 'label="Test Topics"', $descriptor );
			$this->assertStringContainsString( 'kind="category"', $descriptor );
			$this->assertStringContainsString( 'hierarchical="true"', $descriptor );
			$this->assertStringContainsString( 'Editorial themes used to organize site content.', $descriptor );
		} finally {
			unregister_taxonomy( 'wpai_cc_test_tax' );
		}
	}

	/**
	 * Test that parse_suggestions() drops items below the default confidence floor.
	 *
	 * The floor is `Content_Classification::MIN_CONFIDENCE` (0.6 by default).
	 * Suggestions at-or-above pass; below are dropped before sort and slice.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_applies_default_confidence_floor() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [
			{"term": "above", "confidence": 0.9},
			{"term": "at-floor", "confidence": 0.6},
			{"term": "below", "confidence": 0.59},
			{"term": "well-below", "confidence": 0.5}
		]}';

		$result = $method->invoke( $this->ability, $response, 'allow_new', array(), 'post_tag', 10 );

		$terms = wp_list_pluck( $result, 'term' );
		$this->assertContains( 'above', $terms );
		$this->assertContains( 'at-floor', $terms );
		$this->assertNotContains( 'below', $terms );
		$this->assertNotContains( 'well-below', $terms );
	}

	/**
	 * Test that the confidence floor is overridable via filter.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_confidence_floor_is_filterable() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		// Lower the floor to 0.3 so a 0.5 suggestion survives.
		add_filter(
			'wpai_content_classification_min_confidence',
			static fn() => 0.3
		);

		$response = '{"suggestions": [
			{"term": "low", "confidence": 0.5},
			{"term": "very-low", "confidence": 0.2}
		]}';

		$result = $method->invoke( $this->ability, $response, 'allow_new', array(), 'post_tag', 10 );

		$terms = wp_list_pluck( $result, 'term' );
		$this->assertContains( 'low', $terms, 'A 0.5 suggestion should survive when the floor is lowered to 0.3.' );
		$this->assertNotContains( 'very-low', $terms, 'A 0.2 suggestion should still be dropped at floor 0.3.' );
	}

	/**
	 * Test that the filter receives taxonomy and strategy context.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_confidence_floor_filter_receives_context() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$captured = array();
		add_filter(
			'wpai_content_classification_min_confidence',
			static function ( $value, $taxonomy, $strategy ) use ( &$captured ) {
				$captured = compact( 'value', 'taxonomy', 'strategy' );
				return $value;
			},
			10,
			3
		);

		$response = '{"suggestions": [ {"term": "x", "confidence": 0.9} ]}';
		$method->invoke( $this->ability, $response, 'allow_new', array(), 'category', 10 );

		$this->assertSame( Content_Classification::MIN_CONFIDENCE, $captured['value'] );
		$this->assertSame( 'category', $captured['taxonomy'] );
		$this->assertSame( 'allow_new', $captured['strategy'] );
	}

	/**
	 * Test that filter returns outside 0–1 are clamped.
	 *
	 * @since x.x.x
	 */
	public function test_parse_suggestions_confidence_floor_clamped() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		// A filter return >1.0 would otherwise drop every suggestion. The
		// clamp pulls it back to 1.0, which still drops 0.99 but stops
		// short of disabling the system.
		add_filter(
			'wpai_content_classification_min_confidence',
			static fn() => 99.0
		);

		$response = '{"suggestions": [
			{"term": "perfect", "confidence": 1.0},
			{"term": "almost", "confidence": 0.99}
		]}';

		$result = $method->invoke( $this->ability, $response, 'allow_new', array(), 'post_tag', 10 );
		$terms  = wp_list_pluck( $result, 'term' );

		$this->assertContains( 'perfect', $terms );
		$this->assertNotContains( 'almost', $terms );
	}

	/**
	 * Test that parse_suggestions() preserves parent field for hierarchical terms.
	 *
	 * @since 0.7.0
	 */
	public function test_parse_suggestions_preserves_parent_field() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [
			{"term": "Machine Learning", "confidence": 0.9, "parent": "Technology"},
			{"term": "Finance", "confidence": 0.8}
		]}';

		// Use 'category' (hierarchical) so parent is preserved.
		$result = $method->invoke( $this->ability, $response, 'allow_new', array(), 'category', 10 );

		$this->assertArrayHasKey( 'parent', $result[0], 'First suggestion should have parent key' );
		$this->assertEquals( 'Technology', $result[0]['parent'], 'Parent should be Technology' );
		$this->assertArrayNotHasKey( 'parent', $result[1], 'Second suggestion should not have parent key' );
	}

	/**
	 * Test that parse_suggestions() strips parent for non-hierarchical taxonomies.
	 *
	 * @since 0.7.0
	 */
	public function test_parse_suggestions_strips_parent_for_non_hierarchical_taxonomy() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [
			{"term": "Machine Learning", "confidence": 0.9, "parent": "Technology"}
		]}';

		$result = $method->invoke( $this->ability, $response, 'allow_new', array(), 'post_tag', 10 );

		$this->assertArrayNotHasKey( 'parent', $result[0], 'Parent should be stripped for non-hierarchical taxonomy' );
	}

	/**
	 * Test that parse_suggestions() strips parent when it matches the taxonomy slug.
	 *
	 * @since 0.7.0
	 */
	public function test_parse_suggestions_strips_taxonomy_slug_as_parent() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [
			{"term": "Machine Learning", "confidence": 0.9, "parent": "category"}
		]}';

		$result = $method->invoke( $this->ability, $response, 'allow_new', array(), 'category', 10 );

		$this->assertArrayNotHasKey( 'parent', $result[0], 'Parent should be stripped when it matches the taxonomy slug' );
	}

	/**
	 * Test that parse_suggestions() skips items with empty or missing term.
	 *
	 * @since 0.7.0
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

		$result = $method->invoke( $this->ability, $response, 'allow_new', array(), 'post_tag', 10 );

		$this->assertCount( 2, $result, 'Should only have 2 valid suggestions' );
		$this->assertEquals( 'valid', $result[0]['term'] );
		$this->assertEquals( 'also valid', $result[1]['term'] );
	}

	/**
	 * Test that parse_suggestions() defaults confidence to 0.5 when missing.
	 *
	 * @since 0.7.0
	 */
	public function test_parse_suggestions_defaults_missing_confidence() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [{"term": "test", "is_new": true}]}';

		// With floor disabled, the 0.5 default surfaces and we can assert it.
		add_filter( 'wpai_content_classification_min_confidence', static fn() => 0.0 );
		$result = $method->invoke( $this->ability, $response, 'allow_new', array(), 'post_tag', 10 );
		$this->assertEquals( 0.5, $result[0]['confidence'], 'Missing confidence should default to 0.5' );

		// With the default floor active, the 0.5 default is below the floor (0.6) and the
		// suggestion is dropped — confirming missing confidence is treated as below-relevant.
		remove_all_filters( 'wpai_content_classification_min_confidence' );
		$result = $method->invoke( $this->ability, $response, 'allow_new', array(), 'post_tag', 10 );
		$this->assertSame( array(), $result, 'A suggestion with missing confidence should be dropped at the default floor.' );
	}

	/**
	 * Test that parse_suggestions() determines is_new based on existing terms, not AI response.
	 *
	 * @since 0.7.0
	 */
	public function test_parse_suggestions_overrides_is_new_from_existing_terms() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		// Create a real term so parse_suggestions can find it via get_existing_terms().
		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'Tech' ) );

		// AI says "tech" is new, but it exists in the DB as "Tech".
		$response = '{"suggestions": [{"term": "tech", "confidence": 0.9, "is_new": true}]}';

		$result = $method->invoke( $this->ability, $response, 'existing_only', array(), 'post_tag', 10 );

		$this->assertFalse( $result[0]['is_new'], 'Should be false because "Tech" exists (case-insensitive match)' );
		$this->assertEquals( 'Tech', $result[0]['term'], 'Should use the original capitalized term name from the existing terms list' );
	}

	/**
	 * Test that parse_suggestions() filters out assigned terms.
	 *
	 * @since 0.7.0
	 */
	public function test_parse_suggestions_filters_assigned_terms() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		$response = '{"suggestions": [
			{"term": "php", "confidence": 0.9, "is_new": true},
			{"term": "javascript", "confidence": 0.8, "is_new": true},
			{"term": "python", "confidence": 0.7, "is_new": true}
		]}';

		$result = $method->invoke( $this->ability, $response, 'allow_new', array( 'PHP' ), 'post_tag', 10 );

		$this->assertCount( 2, $result, 'Should exclude assigned term' );
		$this->assertEquals( 'javascript', $result[0]['term'] );
		$this->assertEquals( 'python', $result[1]['term'] );
	}

	/**
	 * Test that parse_suggestions() filters new terms for existing_only strategy.
	 *
	 * @since 0.7.0
	 */
	public function test_parse_suggestions_existing_only_filters_new_terms() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'parse_suggestions' );
		$method->setAccessible( true );

		// Create real terms so parse_suggestions can find them via get_existing_terms().
		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'php' ) );
		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'javascript' ) );

		$response = '{"suggestions": [
			{"term": "php", "confidence": 0.9, "is_new": true},
			{"term": "brand new term", "confidence": 0.85, "is_new": true},
			{"term": "javascript", "confidence": 0.8, "is_new": true}
		]}';

		$result = $method->invoke( $this->ability, $response, 'existing_only', array(), 'post_tag', 10 );

		$this->assertCount( 2, $result, 'Should only include existing terms' );
		$this->assertEquals( 'php', $result[0]['term'] );
		$this->assertEquals( 'javascript', $result[1]['term'] );
		$this->assertFalse( $result[0]['is_new'] );
		$this->assertFalse( $result[1]['is_new'] );
	}

	/**
	 * Test that suggestions_schema() returns the expected structure.
	 *
	 * @since 0.7.0
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
		$this->assertArrayNotHasKey( 'is_new', $item_props, 'is_new is determined server-side, not by the LLM' );
		$this->assertArrayNotHasKey( 'parent', $item_props, 'parent is determined server-side from existing term hierarchy, not by the LLM' );

		$required = $schema['properties']['suggestions']['items']['required'];
		$this->assertContains( 'term', $required );
		$this->assertContains( 'confidence', $required );
		$this->assertNotContains( 'is_new', $required );
		$this->assertNotContains( 'parent', $required, 'parent should not be required so the LLM can omit it' );
	}

	/**
	 * Test that get_existing_terms() returns term names for a valid taxonomy.
	 *
	 * @since 0.7.0
	 */
	public function test_get_existing_terms_returns_term_names() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'get_existing_terms' );
		$method->setAccessible( true );

		// Create some terms.
		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'PHP' ) );
		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'JavaScript' ) );

		$result = $method->invoke( $this->ability, 'post_tag' );

		$this->assertIsArray( $result );
		$this->assertContains( 'PHP', $result );
		$this->assertContains( 'JavaScript', $result );
	}

	/**
	 * Test that get_existing_terms() returns empty array for invalid taxonomy.
	 *
	 * @since 0.7.0
	 */
	public function test_get_existing_terms_returns_empty_for_invalid_taxonomy() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'get_existing_terms' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, 'nonexistent_taxonomy' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that get_top_terms() returns terms ordered by count.
	 *
	 * @since 0.7.0
	 */
	public function test_get_top_terms_returns_terms() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'get_top_terms' );
		$method->setAccessible( true );

		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'TopTerm' ) );
		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'AnotherTerm' ) );

		$result = $method->invoke( $this->ability, 'post_tag' );

		$this->assertIsArray( $result );
		$this->assertContains( 'TopTerm', $result );
		$this->assertContains( 'AnotherTerm', $result );
	}

	/**
	 * Test that get_top_terms() respects the limit parameter.
	 *
	 * @since 0.7.0
	 */
	public function test_get_top_terms_respects_limit() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'get_top_terms' );
		$method->setAccessible( true );

		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'Term1' ) );
		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'Term2' ) );
		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'Term3' ) );

		$result = $method->invoke( $this->ability, 'post_tag', 2 );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result, 'Should be limited to 2 terms' );
	}

	/**
	 * Test that get_top_terms() returns empty array for invalid taxonomy.
	 *
	 * @since 0.7.0
	 */
	public function test_get_top_terms_returns_empty_for_invalid_taxonomy() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'get_top_terms' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, 'nonexistent_taxonomy' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that permission_callback() returns true for user with edit_post capability on a specific post.
	 *
	 * @since 0.7.0
	 */
	public function test_permission_callback_with_post_id_and_edit_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Test content',
				'post_status'  => 'publish',
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'post_id' => $post_id ) );

		$this->assertTrue( $result, 'Permission should be granted for user with edit_post capability' );
	}

	/**
	 * Test that permission_callback() returns error for user without edit_post capability on a specific post.
	 *
	 * @since 0.7.0
	 */
	public function test_permission_callback_with_post_id_without_edit_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Test content',
				'post_status'  => 'publish',
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code(), 'Error code should be insufficient_capabilities' );
	}

	/**
	 * Test that permission_callback() returns error for non-existent post.
	 *
	 * @since 0.7.0
	 */
	public function test_permission_callback_with_nonexistent_post_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'post_id' => 99999 ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'post_not_found', $result->get_error_code(), 'Error code should be post_not_found' );
	}

	/**
	 * Test that permission_callback() returns true for user with edit_posts capability.
	 *
	 * @since 0.7.0
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
	 * @since 0.7.0
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
	 * @since 0.7.0
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
	 * @since 0.7.0
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
	 * @since 0.7.0
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

	/**
	 * Test that get_prompt_builder() returns a WP_Error when no text generation model is available.
	 *
	 * @since 0.7.0
	 */
	public function test_get_prompt_builder_returns_error_without_valid_model() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'get_prompt_builder' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, 'Test prompt' );

		// Without configured AI credentials, the builder should indicate no supported model.
		if ( is_wp_error( $result ) ) {
			$this->assertEquals( 'unsupported_model', $result->get_error_code(), 'Error code should be unsupported_model' );
		} else {
			// If a model happens to be available in the test environment, verify it returns a builder.
			$this->assertIsObject( $result, 'Should return a prompt builder object' );
		}
	}

	/**
	 * Test that generate_suggestions() returns a WP_Error when no AI model is available.
	 *
	 * @since 0.7.0
	 */
	public function test_generate_suggestions_returns_error_without_ai() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_suggestions' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->ability,
			array( 'content' => 'Test content for suggestions.' ),
			'post_tag',
			'allow_new',
			5,
			array()
		);

		// Without a configured AI provider, this should return a WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result, 'Should return WP_Error without AI provider' );
	}

	/**
	 * Test that generate_suggestions() builds prompt with assigned terms.
	 *
	 * Verifies the prompt filter receives the expected assigned terms.
	 *
	 * @since 0.7.0
	 */
	public function test_generate_suggestions_passes_assigned_terms_to_prompt_filter() {
		$captured_prompt = '';

		add_filter(
			'wpai_content_classification_prompt',
			static function ( $prompt ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;
				return $prompt;
			}
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_suggestions' );
		$method->setAccessible( true );

		// This will fail at the AI client, but the filter fires before that.
		$method->invoke(
			$this->ability,
			array( 'content' => 'Test content.' ),
			'post_tag',
			'allow_new',
			5,
			array( 'existing-tag' )
		);

		$this->assertStringContainsString( '<assigned-terms>existing-tag</assigned-terms>', $captured_prompt, 'Prompt should contain assigned terms' );
		$this->assertStringContainsString( '<content>', $captured_prompt, 'Prompt should contain content tags' );
		// Taxonomy descriptor block (Step 2): includes name/label/kind/hierarchical attributes.
		$this->assertStringContainsString( '<taxonomy name="post_tag"', $captured_prompt, 'Prompt should contain taxonomy descriptor' );
		$this->assertStringContainsString( 'kind="tag"', $captured_prompt, 'Prompt should mark post_tag as kind="tag"' );
	}

	/**
	 * Test that the available-terms filter receives the default popularity-
	 * ordered list when the existing_only strategy is in use (Step 4).
	 *
	 * @since x.x.x
	 */
	public function test_available_terms_filter_receives_default_pool_for_existing_only(): void {
		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'Alpha' ) );
		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'Beta' ) );

		$captured = array();
		add_filter(
			'wpai_content_classification_available_terms',
			static function ( $terms, $taxonomy, $strategy ) use ( &$captured ) {
				$captured = compact( 'terms', 'taxonomy', 'strategy' );
				return $terms;
			},
			10,
			3
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_suggestions' );
		$method->setAccessible( true );

		// Fails at the AI client, but the filter fires before that.
		$method->invoke(
			$this->ability,
			array( 'content' => 'Anything.' ),
			'post_tag',
			'existing_only',
			5,
			array()
		);

		$this->assertIsArray( $captured['terms'] );
		$this->assertContains( 'Alpha', $captured['terms'] );
		$this->assertContains( 'Beta', $captured['terms'] );
		$this->assertSame( 'post_tag', $captured['taxonomy'] );
		$this->assertSame( 'existing_only', $captured['strategy'] );
	}

	/**
	 * Test that the available-terms filter still fires for the allow_new
	 * strategy, with an empty default pool — so sites can inject candidates
	 * (e.g. via embeddings) without forcing existing_only behavior.
	 *
	 * @since x.x.x
	 */
	public function test_available_terms_filter_fires_for_allow_new_with_empty_default(): void {
		$captured = null;
		add_filter(
			'wpai_content_classification_available_terms',
			static function ( $terms, $taxonomy, $strategy ) use ( &$captured ) {
				$captured = array(
					'terms'    => $terms,
					'strategy' => $strategy,
				);
				return $terms;
			},
			10,
			3
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_suggestions' );
		$method->setAccessible( true );

		$method->invoke(
			$this->ability,
			array( 'content' => 'Anything.' ),
			'post_tag',
			'allow_new',
			5,
			array()
		);

		$this->assertNotNull( $captured, 'Filter should fire even for allow_new.' );
		$this->assertSame( array(), $captured['terms'], 'Default pool for allow_new is empty.' );
		$this->assertSame( 'allow_new', $captured['strategy'] );
	}

	/**
	 * Test that the filter's return value is what reaches the prompt
	 * — i.e. a site-supplied candidate pool replaces the default and
	 * appears verbatim inside the `<available-terms>` block.
	 *
	 * @since x.x.x
	 */
	public function test_available_terms_filter_return_reaches_prompt(): void {
		add_filter(
			'wpai_content_classification_available_terms',
			static fn () => array( 'Custom Term One', 'Custom Term Two' ),
			10,
			3
		);

		$captured_prompt = '';
		add_filter(
			'wpai_content_classification_prompt',
			static function ( $prompt ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;
				return $prompt;
			}
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_suggestions' );
		$method->setAccessible( true );

		$method->invoke(
			$this->ability,
			array( 'content' => 'Anything.' ),
			'post_tag',
			'allow_new',
			5,
			array()
		);

		$this->assertStringContainsString(
			'<available-terms>Custom Term One, Custom Term Two</available-terms>',
			$captured_prompt
		);
	}

	/**
	 * Test that returning an empty array from the filter suppresses the
	 * `<available-terms>` block entirely (Step 4) — so sites can disable
	 * the candidate pool when they prefer the model to rely purely on
	 * content + assigned terms.
	 *
	 * @since x.x.x
	 */
	public function test_available_terms_filter_empty_return_suppresses_block(): void {
		// Seed a couple of terms so the default existing_only pool is non-empty.
		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'Suppressed' ) );

		add_filter( 'wpai_content_classification_available_terms', static fn () => array() );

		$captured_prompt = '';
		add_filter(
			'wpai_content_classification_prompt',
			static function ( $prompt ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;
				return $prompt;
			}
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_suggestions' );
		$method->setAccessible( true );

		$method->invoke(
			$this->ability,
			array( 'content' => 'Anything.' ),
			'post_tag',
			'existing_only',
			5,
			array()
		);

		$this->assertStringNotContainsString( '<available-terms>', $captured_prompt );
		$this->assertStringNotContainsString( 'Suppressed', $captured_prompt );
	}

	/**
	 * Test that generate_suggestions() includes available terms for existing_only strategy.
	 *
	 * @since 0.7.0
	 */
	public function test_generate_suggestions_includes_available_terms_for_existing_only() {
		// Create terms so they appear in the prompt.
		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'AvailableTerm' ) );

		$captured_prompt = '';

		add_filter(
			'wpai_content_classification_prompt',
			static function ( $prompt ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;
				return $prompt;
			}
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_suggestions' );
		$method->setAccessible( true );

		$method->invoke(
			$this->ability,
			array( 'content' => 'Test content.' ),
			'post_tag',
			'existing_only',
			5,
			array()
		);

		$this->assertStringContainsString( '<available-terms>', $captured_prompt, 'Prompt should contain available terms for existing_only strategy' );
		$this->assertStringContainsString( 'AvailableTerm', $captured_prompt, 'Prompt should include the created term' );
	}

	/**
	 * Test that generate_suggestions() omits available terms for allow_new strategy.
	 *
	 * @since 0.7.0
	 */
	public function test_generate_suggestions_omits_available_terms_for_allow_new() {
		$this->factory()->term->create( array( 'taxonomy' => 'post_tag', 'name' => 'SomeTerm' ) );

		$captured_prompt = '';

		add_filter(
			'wpai_content_classification_prompt',
			static function ( $prompt ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;
				return $prompt;
			}
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_suggestions' );
		$method->setAccessible( true );

		$method->invoke(
			$this->ability,
			array( 'content' => 'Test content.' ),
			'post_tag',
			'allow_new',
			5,
			array()
		);

		$this->assertStringNotContainsString( '<available-terms>', $captured_prompt, 'Prompt should not contain available terms for allow_new strategy' );
	}
}

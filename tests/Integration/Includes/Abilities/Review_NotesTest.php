<?php
/**
 * Integration tests for the Review_Notes_Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Review_Notes\Review_Notes;
use WordPress\AI\Abstracts\Abstract_Experiment;

/**
 * Test experiment for Review_Notes_Ability tests.
 *
 * @since x.x.x
 */
class Test_Review_Notes_Experiment extends Abstract_Experiment {
	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'review-notes',
			'label'       => 'Review Notes',
			'description' => 'Reviews post content block-by-block and adds Notes with suggestions.',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Review_Notes_Ability test case.
 *
 * @since x.x.x
 */
class Review_NotesTest extends WP_UnitTestCase {

	/**
	 * Review_Notes_Ability instance.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Abilities\Review_Notes\Review_Notes
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Review_Notes_Experiment
	 */
	private $experiment;

	/**
	 * Sets up the test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Review_Notes_Experiment();
		$this->ability    = new Review_Notes(
			'ai/review-notes',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
	}

	/**
	 * Tears down the test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Tests that input_schema() returns the expected structure.
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
		$this->assertArrayHasKey( 'block_type', $schema['properties'], 'Schema should have block_type property' );
		$this->assertArrayHasKey( 'block_content', $schema['properties'], 'Schema should have block_content property' );
		$this->assertArrayHasKey( 'context', $schema['properties'], 'Schema should have context property' );
		$this->assertArrayHasKey( 'post_id', $schema['properties'], 'Schema should have post_id property' );
		$this->assertArrayHasKey( 'existing_notes', $schema['properties'], 'Schema should have existing_notes property' );
		$this->assertArrayHasKey( 'review_types', $schema['properties'], 'Schema should have review_types property' );
		$this->assertContains( 'block_type', $schema['required'], 'block_type should be required' );
		$this->assertContains( 'block_content', $schema['required'], 'block_content should be required' );
	}

	/**
	 * Tests that output_schema() returns the expected structure.
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
	}

	/**
	 * Tests that execute_callback() returns an error when block_content is empty.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_returns_error_when_block_content_empty() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array(
			'block_type'    => 'core/paragraph',
			'block_content' => '',
		);
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error for empty content' );
		$this->assertEquals( 'block_content_required', $result->get_error_code(), 'Error code should be block_content_required' );
	}

	/**
	 * Tests that execute_callback() attempts AI call with valid input.
	 *
	 * The AI call may fail in test environments without credentials, so the
	 * test accepts both a valid result and an AI-related WP_Error.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_with_valid_input() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'block_type'    => 'core/paragraph',
			'block_content' => 'This is a long paragraph with sufficient content for review.',
			'review_types'  => array( 'readability', 'grammar' ),
		);

		try {
			$result = $method->invoke( $this->ability, $input );
		} catch ( \Throwable $e ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $e->getMessage() );
			return;
		}

		// AI client unavailable in test environment — that's expected.
		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'AI client not available: ' . $result->get_error_message() );
			return;
		}

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'suggestions', $result, 'Result should have suggestions key' );
		$this->assertIsArray( $result['suggestions'], 'Suggestions should be an array' );
	}

	/**
	 * Tests that execute_callback() returns suggestions with proper structure.
	 *
	 * Uses reflection to test the generate_review method with a mocked response.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_returns_empty_suggestions_when_no_issues() {
		// Create a partial mock that overrides generate_review to return empty.
		$mock = $this->getMockBuilder( Review_Notes::class )
			->setConstructorArgs(
				array(
					'ai/review-notes',
					array(
						'label'       => 'AI Review Notes',
						'description' => 'Test',
					),
				)
			)
			->onlyMethods( array( 'generate_review' ) )
			->getMock();

		$mock->method( 'generate_review' )->willReturn( array() );

		$reflection = new \ReflectionClass( $mock );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array(
			'block_type'    => 'core/paragraph',
			'block_content' => 'This content has no issues.',
		);
		$result = $method->invoke( $mock, $input );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'suggestions', $result, 'Result should have suggestions key' );
		$this->assertEmpty( $result['suggestions'], 'Suggestions should be empty when no issues found' );
	}

	/**
	 * Tests that execute_callback() returns properly structured suggestions.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_returns_suggestions_array() {
		$mock_suggestions = array(
			array(
				'review_type' => 'readability',
				'text'        => 'Consider breaking this sentence into two shorter ones.',
			),
			array(
				'review_type' => 'grammar',
				'text'        => 'Subject-verb agreement issue detected.',
			),
		);

		$mock = $this->getMockBuilder( Review_Notes::class )
			->setConstructorArgs(
				array(
					'ai/review-notes',
					array(
						'label'       => 'AI Review Notes',
						'description' => 'Test',
					),
				)
			)
			->onlyMethods( array( 'generate_review' ) )
			->getMock();

		$mock->method( 'generate_review' )->willReturn( $mock_suggestions );

		$reflection = new \ReflectionClass( $mock );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array(
			'block_type'    => 'core/paragraph',
			'block_content' => 'This is a sentence with some issues that need fixing here.',
		);
		$result = $method->invoke( $mock, $input );

		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'suggestions', $result, 'Result should have suggestions key' );
		$this->assertCount( 2, $result['suggestions'], 'Should have 2 suggestions' );
		$this->assertEquals( 'readability', $result['suggestions'][0]['review_type'] );
		$this->assertEquals( 'grammar', $result['suggestions'][1]['review_type'] );
	}

	/**
	 * Tests that create_prompt() sanitizes the block_content input.
	 *
	 * @since x.x.x
	 */
	public function test_create_prompt_sanitizes_block_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'create_prompt' );
		$method->setAccessible( true );

		$prompt = $method->invoke(
			$this->ability,
			'core/paragraph',
			'<script>alert("xss")</script>This is legitimate content.',
			'',
			array(),
			array( 'readability' )
		);

		$this->assertIsString( $prompt, 'Prompt should be a string' );
		$this->assertStringContainsString( '<block-content>', $prompt, 'Prompt should contain the block-content section' );
		$this->assertStringNotContainsString( '<script>', $prompt, 'Script tags should be removed from prompt content' );
	}

	/**
	 * Tests that permission_callback() returns true for users with edit_posts capability.
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

		$this->assertTrue( $result, 'Permission should be granted for editor role' );
	}

	/**
	 * Tests that permission_callback() returns WP_Error for users without edit_posts capability.
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

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error for subscriber' );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Tests that permission_callback() returns WP_Error for logged-out users.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_for_logged_out_user() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		wp_set_current_user( 0 );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error for logged-out user' );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Tests that meta() includes show_in_rest.
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

	/**
	 * Tests that get_system_instruction() returns a non-empty string.
	 *
	 * @since x.x.x
	 */
	public function test_get_system_instruction_returns_string() {
		$instruction = $this->ability->get_system_instruction();

		$this->assertIsString( $instruction, 'System instruction should be a string' );
		$this->assertNotEmpty( $instruction, 'System instruction should not be empty' );
	}

	// -------------------------------------------------------------------------
	// suggestions_schema()
	// -------------------------------------------------------------------------

	/**
	 * Tests that suggestions_schema() returns the OpenAI-required wrapper structure.
	 *
	 * The schema must include 'name', 'strict', and 'schema' keys; the inner
	 * 'schema' must be type 'object' (OpenAI rejects top-level array schemas).
	 *
	 * @since x.x.x
	 */
	public function test_suggestions_schema_has_openai_wrapper_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'suggestions_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertArrayHasKey( 'name', $schema, 'Schema should have a name key' );
		$this->assertEquals( 'suggestions', $schema['name'], 'Schema name should be suggestions' );
		$this->assertArrayHasKey( 'strict', $schema, 'Schema should have a strict key' );
		$this->assertTrue( $schema['strict'], 'Schema strict should be true' );
		$this->assertArrayHasKey( 'schema', $schema, 'Schema should have a schema key' );
		$this->assertEquals( 'object', $schema['schema']['type'], 'Inner schema type must be object (not array)' );
		$this->assertArrayHasKey( 'suggestions', $schema['schema']['properties'], 'Inner schema should have suggestions property' );
		$this->assertEquals( 'array', $schema['schema']['properties']['suggestions']['type'], 'suggestions property should be array type' );
	}

	// -------------------------------------------------------------------------
	// permission_callback() — numeric context (post ID) path
	// -------------------------------------------------------------------------

	/**
	 * Tests that permission_callback() returns true for an editor with a valid post.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_returns_true_for_editor_with_valid_post() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );

		$result = $method->invoke( $this->ability, array( 'post_id' => $post_id ) );

		$this->assertTrue( $result, 'Permission should be granted for editor with a valid post' );
	}

	/**
	 * Tests that permission_callback() returns WP_Error when the context post does not exist.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_returns_error_when_context_post_not_found() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'post_id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error for missing post' );
		$this->assertEquals( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * Tests that permission_callback() returns WP_Error when the user cannot edit the specific post.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_returns_error_when_user_cannot_edit_post() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'post_id' => $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error when user cannot edit the post' );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Tests that permission_callback() returns false for a post type not shown in REST.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_returns_false_for_non_rest_post_type() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		register_post_type(
			'ai_test_private',
			array(
				'public'       => true,
				'show_in_rest' => false,
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( array( 'post_type' => 'ai_test_private' ) );

		$result = $method->invoke( $this->ability, array( 'post_id' => $post_id ) );

		unregister_post_type( 'ai_test_private' );

		$this->assertFalse( $result, 'Permission should be false for post types not shown in REST' );
	}

	// -------------------------------------------------------------------------
	// get_existing_review_types_from_notes() (private, tested via reflection)
	// -------------------------------------------------------------------------

	/**
	 * Returns the get_existing_review_types_from_notes() method via reflection.
	 *
	 * @since x.x.x
	 *
	 * @return \ReflectionMethod
	 */
	private function get_existing_types_method(): \ReflectionMethod {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'get_existing_review_types_from_notes' );
		$method->setAccessible( true );
		return $method;
	}

	/**
	 * Tests that get_existing_review_types_from_notes() extracts bracketed types from note text.
	 *
	 * @since x.x.x
	 */
	public function test_get_existing_review_types_extracts_types_from_notes() {
		$method = $this->get_existing_types_method();

		$notes  = array(
			'[GRAMMAR] Subject-verb agreement issue detected.',
			'[READABILITY] Sentence is too long.',
		);
		$result = $method->invoke( $this->ability, $notes );

		$this->assertArrayHasKey( 'grammar', $result, 'grammar type should be extracted' );
		$this->assertArrayHasKey( 'readability', $result, 'readability type should be extracted' );
		$this->assertCount( 2, $result );
	}

	/**
	 * Tests that get_existing_review_types_from_notes() normalises types to lowercase.
	 *
	 * @since x.x.x
	 */
	public function test_get_existing_review_types_is_case_insensitive() {
		$method = $this->get_existing_types_method();

		$notes  = array( '[Grammar] A suggestion.' );
		$result = $method->invoke( $this->ability, $notes );

		$this->assertArrayHasKey( 'grammar', $result, 'Type key should be lowercase' );
		$this->assertArrayNotHasKey( 'Grammar', $result, 'Original-case key should not exist' );
	}

	/**
	 * Tests that get_existing_review_types_from_notes() ignores notes without bracketed types.
	 *
	 * @since x.x.x
	 */
	public function test_get_existing_review_types_ignores_notes_without_brackets() {
		$method = $this->get_existing_types_method();

		$notes  = array( 'A Note with no bracketed type at all.' );
		$result = $method->invoke( $this->ability, $notes );

		$this->assertEmpty( $result, 'Notes without [TYPE] should produce empty result' );
	}

	/**
	 * Tests that get_existing_review_types_from_notes() handles multiple types in one note.
	 *
	 * @since x.x.x
	 */
	public function test_get_existing_review_types_handles_multiple_types_in_one_note() {
		$method = $this->get_existing_types_method();

		$notes  = array( '[SEO] Title needs a keyword. [ACCESSIBILITY] Image missing alt text.' );
		$result = $method->invoke( $this->ability, $notes );

		$this->assertArrayHasKey( 'seo', $result );
		$this->assertArrayHasKey( 'accessibility', $result );
		$this->assertCount( 2, $result );
	}
}

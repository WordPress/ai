<?php
/**
 * Integration tests for the Refine_Notes Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Refine_Notes\Refine_Notes;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Refine_Notes Ability tests.
 *
 * @since x.x.x
 */
class Test_Refine_Notes_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'refine-notes';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @return array{label: string, description: string} Feature metadata.
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Refine from Notes',
			'description' => 'Refines block content based on editorial notes.',
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
 * Refine_Notes Ability test case.
 *
 * @since x.x.x
 *
 * @group abilities
 * @group refine-notes
 */
class Refine_NotesTest extends WP_UnitTestCase {

	/**
	 * Refine_Notes Ability instance.
	 *
	 * @since x.x.x
	 *
	 * @var Refine_Notes
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @since x.x.x
	 *
	 * @var Test_Refine_Notes_Experiment
	 */
	private $experiment;

	/**
	 * Sets up the test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Refine_Notes_Experiment();
		$this->ability    = new Refine_Notes(
			'ai/refine-notes',
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

	// -------------------------------------------------------------------------
	// input_schema()
	// -------------------------------------------------------------------------

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
		$this->assertArrayHasKey( 'notes', $schema['properties'], 'Schema should have notes property' );
		$this->assertArrayHasKey( 'context', $schema['properties'], 'Schema should have context property' );
		$this->assertArrayHasKey( 'post_id', $schema['properties'], 'Schema should have post_id property' );
	}

	/**
	 * Tests that input_schema() marks the expected fields as required.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_marks_required_fields() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'input_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertArrayHasKey( 'required', $schema, 'Schema should define required keys' );
		$this->assertContains( 'block_type', $schema['required'], 'block_type should be required' );
		$this->assertContains( 'block_content', $schema['required'], 'block_content should be required' );
		$this->assertContains( 'notes', $schema['required'], 'notes should be required' );
	}

	// -------------------------------------------------------------------------
	// output_schema()
	// -------------------------------------------------------------------------

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
		$this->assertEquals( 'string', $schema['type'], 'Schema type should be string (refined text)' );
		$this->assertArrayHasKey( 'description', $schema, 'Schema should have a description' );
	}

	// -------------------------------------------------------------------------
	// meta()
	// -------------------------------------------------------------------------

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

	// -------------------------------------------------------------------------
	// get_system_instruction()
	// -------------------------------------------------------------------------

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
	// permission_callback() — no post_id path
	// -------------------------------------------------------------------------

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

	// -------------------------------------------------------------------------
	// permission_callback() — numeric context (post_id) path
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
	// execute_callback() — validation
	// -------------------------------------------------------------------------

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
			'notes'         => array( 'Fix grammar' ),
		);
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error for empty content' );
		$this->assertEquals( 'block_content_required', $result->get_error_code(), 'Error code should be block_content_required' );
	}

	/**
	 * Tests that execute_callback() returns an error when notes array is empty.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_returns_error_when_notes_empty() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array(
			'block_type'    => 'core/paragraph',
			'block_content' => 'Some content here.',
			'notes'         => array(),
		);
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error for empty notes' );
		$this->assertEquals( 'notes_required', $result->get_error_code(), 'Error code should be notes_required' );
	}

	/**
	 * Tests that execute_callback() filters out non-string notes and returns error when no valid notes remain.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_filters_non_string_notes() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array(
			'block_type'    => 'core/paragraph',
			'block_content' => 'Some content.',
			'notes'         => array( 123, null, true ),
		);
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Should reject notes with only non-string values' );
		$this->assertEquals( 'notes_required', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// execute_callback() — mocked generate_refinement
	// -------------------------------------------------------------------------

	/**
	 * Tests that execute_callback() returns refined text from generate_refinement.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_returns_refined_text() {
		$mock = $this->getMockBuilder( Refine_Notes::class )
			->setConstructorArgs(
				array(
					'ai/refine-notes',
					array(
						'label'       => 'Refine from Notes',
						'description' => 'Test',
					),
				)
			)
			->onlyMethods( array( 'generate_refinement' ) )
			->getMock();

		$mock->method( 'generate_refinement' )->willReturn( 'This is the refined content.' );

		$reflection = new \ReflectionClass( $mock );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array(
			'block_type'    => 'core/paragraph',
			'block_content' => 'Original content with issues.',
			'notes'         => array( 'Fix the grammar issue' ),
		);
		$result = $method->invoke( $mock, $input );

		$this->assertIsString( $result, 'Result should be a string' );
		$this->assertEquals( 'This is the refined content.', $result, 'Should return the refined text' );
	}

	/**
	 * Tests that execute_callback() propagates WP_Error from generate_refinement.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_propagates_wp_error_from_generate_refinement() {
		$mock = $this->getMockBuilder( Refine_Notes::class )
			->setConstructorArgs(
				array(
					'ai/refine-notes',
					array(
						'label'       => 'Refine from Notes',
						'description' => 'Test',
					),
				)
			)
			->onlyMethods( array( 'generate_refinement' ) )
			->getMock();

		$mock->method( 'generate_refinement' )->willReturn(
			new WP_Error( 'ai_error', 'AI service unavailable' )
		);

		$reflection = new \ReflectionClass( $mock );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array(
			'block_type'    => 'core/paragraph',
			'block_content' => 'Some content.',
			'notes'         => array( 'Improve clarity' ),
		);
		$result = $method->invoke( $mock, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Should propagate WP_Error from generate_refinement' );
		$this->assertEquals( 'ai_error', $result->get_error_code() );
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
			'block_content' => 'This is a sentence that needs improvement based on feedback.',
			'notes'         => array( 'Make this more concise' ),
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

		$this->assertIsString( $result, 'Result should be a string' );
		$this->assertNotEmpty( $result, 'Result should not be empty' );
	}

	// -------------------------------------------------------------------------
	// create_prompt() — tested via reflection (private method)
	// -------------------------------------------------------------------------

	/**
	 * Tests that create_prompt() builds the expected XML structure.
	 *
	 * @since x.x.x
	 */
	public function test_create_prompt_includes_xml_tags() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'create_prompt' );
		$method->setAccessible( true );

		$prompt = $method->invoke(
			$this->ability,
			'core/paragraph',
			'Hello world content.',
			array( 'Fix spelling' ),
			''
		);

		$this->assertIsString( $prompt, 'Prompt should be a string' );
		$this->assertStringContainsString( '<block-type>', $prompt, 'Prompt should contain block-type tag' );
		$this->assertStringContainsString( 'core/paragraph', $prompt, 'Prompt should contain the block type' );
		$this->assertStringContainsString( '<block-content>', $prompt, 'Prompt should contain block-content tag' );
		$this->assertStringContainsString( '<notes>', $prompt, 'Prompt should contain notes tag' );
		$this->assertStringContainsString( 'Fix spelling', $prompt, 'Prompt should contain the note text' );
	}

	/**
	 * Tests that create_prompt() sanitizes script injection in block_content.
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
			array( 'Review this' ),
			''
		);

		$this->assertIsString( $prompt, 'Prompt should be a string' );
		$this->assertStringContainsString( '<block-content>', $prompt, 'Prompt should contain the block-content section' );
		$this->assertStringNotContainsString( '<script>', $prompt, 'Script tags should be removed from prompt content' );
	}

	/**
	 * Tests that create_prompt() includes context tag when context is provided.
	 *
	 * @since x.x.x
	 */
	public function test_create_prompt_includes_context_when_provided() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'create_prompt' );
		$method->setAccessible( true );

		$prompt = $method->invoke(
			$this->ability,
			'core/paragraph',
			'Some content.',
			array( 'Note 1' ),
			'This is surrounding context.'
		);

		$this->assertStringContainsString( '<context>', $prompt, 'Prompt should include context tag when context is provided' );
		$this->assertStringContainsString( 'This is surrounding context.', $prompt, 'Prompt should contain the context text' );
	}

	/**
	 * Tests that create_prompt() omits context tag when context is empty.
	 *
	 * @since x.x.x
	 */
	public function test_create_prompt_omits_context_when_empty() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'create_prompt' );
		$method->setAccessible( true );

		$prompt = $method->invoke(
			$this->ability,
			'core/paragraph',
			'Some content.',
			array( 'Note 1' ),
			''
		);

		$this->assertStringNotContainsString( '<context>', $prompt, 'Prompt should not include context tag when context is empty' );
	}

	/**
	 * Tests that create_prompt() includes multiple notes separated by double newlines.
	 *
	 * @since x.x.x
	 */
	public function test_create_prompt_includes_multiple_notes() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'create_prompt' );
		$method->setAccessible( true );

		$prompt = $method->invoke(
			$this->ability,
			'core/paragraph',
			'Content here.',
			array( 'First note', 'Second note' ),
			''
		);

		$this->assertStringContainsString( 'First note', $prompt, 'Prompt should contain first note' );
		$this->assertStringContainsString( 'Second note', $prompt, 'Prompt should contain second note' );
	}
}

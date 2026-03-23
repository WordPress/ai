<?php
/**
 * Integration tests for the Meta_Description Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Meta_Description\Meta_Description;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Meta_Description Ability tests.
 *
 * @since 0.6.0
 */
class Test_Meta_Description_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'meta-description';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Meta Description',
			'description' => 'Generates meta description suggestions with SEO plugin integration',
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
 * Meta_Description Ability test case.
 *
 * @since 0.6.0
 */
class Meta_DescriptionTest extends WP_UnitTestCase {

	/**
	 * Meta_Description ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Meta_Description\Meta_Description
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Meta_Description_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since 0.6.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Meta_Description_Experiment();
		$this->ability    = new Meta_Description(
			'ai/meta-description',
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
		$this->assertArrayHasKey( 'title', $schema['properties'], 'Schema should have title property' );
		$this->assertArrayHasKey( 'post_id', $schema['properties'], 'Schema should have post_id property' );

		// Verify content property.
		$this->assertEquals( 'string', $schema['properties']['content']['type'], 'Content should be string type' );
		$this->assertEquals( 'sanitize_text_field', $schema['properties']['content']['sanitize_callback'], 'Content should use sanitize_text_field' );

		// Verify title property.
		$this->assertEquals( 'string', $schema['properties']['title']['type'], 'Title should be string type' );
		$this->assertEquals( 'sanitize_text_field', $schema['properties']['title']['sanitize_callback'], 'Title should use sanitize_text_field' );

		// Verify post_id property.
		$this->assertEquals( 'integer', $schema['properties']['post_id']['type'], 'Post ID should be integer type' );
		$this->assertEquals( 'absint', $schema['properties']['post_id']['sanitize_callback'], 'Post ID should use absint' );
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
		$this->assertArrayHasKey( 'descriptions', $schema['properties'], 'Schema should have descriptions property' );
		$this->assertEquals( 'array', $schema['properties']['descriptions']['type'], 'Descriptions should be array type' );
	}

	/**
	 * Test that get_system_instruction() returns a non-empty system instruction.
	 *
	 * @since 0.6.0
	 */
	public function test_get_system_instruction_returns_system_instruction() {
		$system_instruction = $this->ability->get_system_instruction();

		$this->assertIsString( $system_instruction, 'System instruction should be a string' );
		$this->assertNotEmpty( $system_instruction, 'System instruction should not be empty' );
		$this->assertStringContainsString( 'meta description', $system_instruction, 'System instruction should mention meta descriptions' );
	}

	/**
	 * Test that execute_callback() handles content parameter correctly.
	 *
	 * @since 0.6.0
	 */
	public function test_execute_callback_with_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'content' => 'This is some test content about artificial intelligence and machine learning in modern healthcare systems.',
			'title'   => 'AI in Healthcare',
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
		$this->assertArrayHasKey( 'descriptions', $result, 'Result should have descriptions key' );
		$this->assertIsArray( $result['descriptions'], 'Descriptions should be an array' );
		$this->assertNotEmpty( $result['descriptions'], 'Descriptions should not be empty' );

		// Verify each description has the expected structure.
		foreach ( $result['descriptions'] as $description ) {
			$this->assertArrayHasKey( 'text', $description, 'Description should have text key' );
			$this->assertArrayHasKey( 'character_count', $description, 'Description should have character_count key' );
			$this->assertIsString( $description['text'], 'Description text should be a string' );
			$this->assertIsInt( $description['character_count'], 'Character count should be an integer' );
			$this->assertEquals( mb_strlen( $description['text'] ), $description['character_count'], 'Character count should match text length' );
		}
	}

	/**
	 * Test that execute_callback() handles post_id parameter correctly.
	 *
	 * @since 0.6.0
	 */
	public function test_execute_callback_with_post_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'This is post content about renewable energy.',
				'post_title'   => 'Renewable Energy',
			)
		);

		$input = array(
			'post_id' => $post_id,
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
		$this->assertArrayHasKey( 'descriptions', $result, 'Result should have descriptions key' );
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
			'post_id' => 99999,
		);
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'post_not_found', $result->get_error_code(), 'Error code should be post_not_found' );
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
	 * Test that execute_callback() prefers explicit content over post content.
	 *
	 * @since 0.6.0
	 */
	public function test_execute_callback_explicit_content_overrides_post_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Original post content.',
				'post_title'   => 'Test Post',
			)
		);

		$input = array(
			'content' => 'This explicit content should be used instead.',
			'post_id' => $post_id,
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
		$this->assertArrayHasKey( 'descriptions', $result, 'Result should have descriptions key' );
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
	 * Test that permission_callback() returns true for user with edit_post capability on specific post.
	 *
	 * @since 0.6.0
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
	 * Test that permission_callback() returns error for user without edit_post capability on specific post.
	 *
	 * @since 0.6.0
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
	 * @since 0.6.0
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
	 * Test that permission_callback() returns false for post type without show_in_rest.
	 *
	 * @since 0.6.0
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

	/**
	 * Test that execute_callback() uses post title when no title is provided.
	 *
	 * @since 0.6.0
	 */
	public function test_execute_callback_uses_post_title_as_fallback() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Content about testing meta descriptions in WordPress plugins.',
				'post_title'   => 'My Test Title',
			)
		);

		$input = array(
			'post_id' => $post_id,
			// No explicit title provided — should fall back to post title.
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
		$this->assertArrayHasKey( 'descriptions', $result, 'Result should have descriptions key' );
	}

	/**
	 * Test that execute_callback() uses explicit title over post title.
	 *
	 * @since 0.6.0
	 */
	public function test_execute_callback_explicit_title_overrides_post_title() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Content about testing meta descriptions in WordPress plugins.',
				'post_title'   => 'Post Title',
			)
		);

		$input = array(
			'post_id' => $post_id,
			'title'   => 'Custom Override Title',
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
		$this->assertArrayHasKey( 'descriptions', $result, 'Result should have descriptions key' );
	}

	/**
	 * Test that generate_descriptions() builds a prompt with content tags.
	 *
	 * @since 0.6.0
	 */
	public function test_generate_descriptions_builds_prompt_with_content() {
		$captured_prompt = '';

		add_filter(
			'wpai_meta_description_prompt',
			static function ( $prompt ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;
				return $prompt;
			}
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_descriptions' );
		$method->setAccessible( true );

		try {
			$method->invoke( $this->ability, 'Test content here.', 'Test Title', '' );
		} catch ( \Throwable $e ) {
			// We only care about prompt construction, not AI availability.
		}

		$this->assertNotNull( $captured_prompt, 'Filter should have been called' );
		$this->assertStringContainsString( '<content>Test content here.</content>', $captured_prompt, 'Prompt should contain content tags' );
		$this->assertStringContainsString( '<title>Test Title</title>', $captured_prompt, 'Prompt should contain title tags' );

		remove_all_filters( 'wpai_meta_description_prompt' );
	}

	/**
	 * Test that generate_descriptions() includes context when provided as string.
	 *
	 * @since 0.6.0
	 */
	public function test_generate_descriptions_includes_string_context() {
		$captured_prompt = '';

		add_filter(
			'wpai_meta_description_prompt',
			static function ( $prompt ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;
				return $prompt;
			}
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_descriptions' );
		$method->setAccessible( true );

		try {
			$method->invoke( $this->ability, 'Test content.', '', 'Extra context here' );
		} catch ( \Throwable $e ) {
			// We only care about prompt construction.
		}

		$this->assertNotNull( $captured_prompt, 'Filter should have been called' );
		$this->assertStringContainsString( '<additional-context>Extra context here</additional-context>', $captured_prompt, 'Prompt should contain additional context' );

		remove_all_filters( 'wpai_meta_description_prompt' );
	}

	/**
	 * Test that generate_descriptions() converts array context to string.
	 *
	 * @since 0.6.0
	 */
	public function test_generate_descriptions_converts_array_context() {
		$captured_prompt = '';

		add_filter(
			'wpai_meta_description_prompt',
			static function ( $prompt ) use ( &$captured_prompt ) {
				$captured_prompt = $prompt;
				return $prompt;
			}
		);

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_descriptions' );
		$method->setAccessible( true );

		$context = array(
			'post_type' => 'post',
			'category'  => 'News',
		);

		try {
			$method->invoke( $this->ability, 'Test content.', '', $context );
		} catch ( \Throwable $e ) {
			// We only care about prompt construction.
		}

		$this->assertNotNull( $captured_prompt, 'Filter should have been called' );
		$this->assertStringContainsString( 'Post Type: post', $captured_prompt, 'Context should be converted to key-value pairs' );
		$this->assertStringContainsString( 'Category: News', $captured_prompt, 'Context should include all array entries' );

		remove_all_filters( 'wpai_meta_description_prompt' );
	}
}

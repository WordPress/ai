<?php
/**
 * Integration tests for the Image_Prompt_Generation Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Image\Generate_Image_Prompt;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Image_Prompt_Generation Ability tests.
 *
 * @since 0.3.0
 */
class Test_Image_Prompt_Generation_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'image-prompt-generation';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Image Prompt Generation',
			'description' => 'Generates a prompt from post content that can be used to generate an image',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 0.3.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Image_Prompt_Generation Ability test case.
 *
 * @since 0.3.0
 */
class Image_Prompt_GenerationTest extends WP_UnitTestCase {

	/**
	 * Image_Prompt_Generation ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Image\Generate_Image_Prompt
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Image_Prompt_Generation_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since 0.3.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Image_Prompt_Generation_Experiment();
		$this->ability    = new Generate_Image_Prompt(
			'ai/image-prompt-generation',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.3.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that guideline_categories() returns site and images.
	 *
	 * @since 0.8.0
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
	 * Test that category() returns the correct category.
	 *
	 * @since 0.3.0
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
	 * @since 0.3.0
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
		$this->assertArrayHasKey( 'style', $schema['properties'], 'Schema should have style property' );
		$this->assertArrayHasKey( 'required', $schema, 'Schema should have required array' );
		$this->assertContains( 'content', $schema['required'], 'Content should be required' );

		// Verify content property.
		$this->assertEquals( 'string', $schema['properties']['content']['type'], 'Content should be string type' );
		$this->assertEquals( 'sanitize_text_field', $schema['properties']['content']['sanitize_callback'], 'Content should use sanitize_text_field' );

		// Verify context property.
		$this->assertEquals( 'string', $schema['properties']['context']['type'], 'Context should be string type' );
		$this->assertEquals( 'sanitize_text_field', $schema['properties']['context']['sanitize_callback'], 'Context should use sanitize_text_field' );

		// Verify style property.
		$this->assertEquals( 'string', $schema['properties']['style']['type'], 'Style should be string type' );
		$this->assertEquals( 'sanitize_text_field', $schema['properties']['style']['sanitize_callback'], 'Style should use sanitize_text_field' );
	}

	/**
	 * Test that output_schema() returns the expected schema structure.
	 *
	 * @since 0.3.0
	 */
	public function test_output_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'output_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Output schema should be an array' );
		$this->assertEquals( 'string', $schema['type'], 'Schema type should be string' );
		$this->assertArrayHasKey( 'description', $schema, 'Schema should have description' );
		$this->assertEquals( 'The image generation prompt.', $schema['description'], 'Description should match' );
	}

	/**
	 * Test that execute_callback() handles content parameter correctly.
	 *
	 * @since 0.3.0
	 */
	public function test_execute_callback_with_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'content' => 'This article discusses the benefits of renewable energy and solar power installations.',
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
	 * Test that execute_callback() handles content with context.
	 *
	 * @since 0.3.0
	 */
	public function test_execute_callback_with_content_and_context() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'content' => 'This article discusses modern office design trends.',
			'context' => 'Title: Modern Office Design\nType: post',
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
	 * Test that execute_callback() handles content with context and style.
	 *
	 * @since 0.3.0
	 */
	public function test_execute_callback_with_content_context_and_style() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'content' => 'This article discusses modern office design trends.',
			'context' => 'Title: Modern Office Design\nType: post',
			'style'   => 'Editorial style, professional photography',
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
	 * Test that execute_callback() handles post ID in context.
	 *
	 * @since 0.3.0
	 */
	public function test_execute_callback_with_post_id_context() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'This article discusses the benefits of renewable energy.',
				'post_title'   => 'Renewable Energy Solutions',
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
	 * Test that execute_callback() returns error when post ID is invalid.
	 *
	 * @since 0.3.0
	 */
	public function test_execute_callback_with_invalid_post_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'context' => '99999', // Non-existent post ID.
		);

		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'post_not_found', $result->get_error_code(), 'Error code should be post_not_found' );
	}

	/**
	 * Test that execute_callback() returns error when content is missing.
	 *
	 * @since 0.3.0
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
	 * Test that execute_callback() returns error when content is empty.
	 *
	 * @since 0.3.0
	 */
	public function test_execute_callback_with_empty_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'content' => '',
		);

		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'content_not_provided', $result->get_error_code(), 'Error code should be content_not_provided' );
	}

	/**
	 * Test that execute_callback() uses default values for optional parameters.
	 *
	 * @since 0.3.0
	 */
	public function test_execute_callback_uses_default_values() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'content' => 'Test content',
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
	}

	/**
	 * Test that execute_callback() prioritizes passed content over post content.
	 *
	 * @since 0.3.0
	 */
	public function test_execute_callback_content_overrides_post_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create a test post.
		$post_id = $this->factory->post->create(
			array(
				'post_content' => 'Post content that should be ignored.',
				'post_title'   => 'Test Post',
			)
		);

		$input = array(
			'content' => 'This content should be used instead of post content.',
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
	 * Test that permission_callback() returns true for user with edit_posts capability.
	 *
	 * @since 0.3.0
	 */
	public function test_permission_callback_returns_true_for_logged_in_user() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		// User must have edit_posts capability when no post context is provided.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array() );

		$this->assertTrue( $result, 'Permission should be granted for user with edit_posts capability' );
	}

	/**
	 * Test that permission_callback() returns WP_Error for logged out user.
	 *
	 * @since 0.3.0
	 */
	public function test_permission_callback_returns_false_for_logged_out_user() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result, 'Permission should be denied with WP_Error for logged out user' );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code(), 'Error code should be insufficient_capabilities' );
	}

	/**
	 * Test that permission_callback() returns WP_Error for user without edit_posts capability.
	 *
	 * @since 0.3.0
	 */
	public function test_permission_callback_returns_error_for_user_without_edit_posts() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		// Subscriber role does not have edit_posts capability.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result, 'Permission should be denied with WP_Error for user without edit_posts' );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code(), 'Error code should be insufficient_capabilities' );
	}

	/**
	 * Test that meta() returns the expected meta structure.
	 *
	 * @since 0.3.0
	 */
	public function test_meta_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'meta' );
		$method->setAccessible( true );

		$meta = $method->invoke( $this->ability );

		$this->assertIsArray( $meta, 'Meta should be an array' );
		$this->assertArrayHasKey( 'show_in_rest', $meta, 'Meta should have show_in_rest' );
		$this->assertTrue( $meta['show_in_rest'], 'show_in_rest should be true' );
		$this->assertArrayHasKey( 'mcp', $meta, 'Meta should have mcp' );
		$this->assertIsArray( $meta['mcp'], 'mcp should be an array' );
		$this->assertArrayHasKey( 'public', $meta['mcp'], 'mcp should have public' );
		$this->assertTrue( $meta['mcp']['public'], 'mcp public should be true' );
		$this->assertArrayHasKey( 'type', $meta['mcp'], 'mcp should have type' );
		$this->assertEquals( 'prompt', $meta['mcp']['type'], 'mcp type should be prompt' );
	}
}

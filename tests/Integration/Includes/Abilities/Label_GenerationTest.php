<?php
/**
 * Integration tests for the Label_Generation Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Label_Generation\Label_Generation;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Label_Generation Ability tests.
 *
 * @since x.x.x
 */
class Test_Label_Generation_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'label-generation';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Label Generation',
			'description' => 'Generates concise labels for content blocks',
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
 * Testable subclass of Label_Generation to mock AI prompt generation.
 *
 * @since x.x.x
 */
class Testable_Label_Generation extends Label_Generation {
	/**
	 * Mock response to return from generate_label().
	 *
	 * @var string|\WP_Error|null
	 */
	public $mock_response = null;

	/**
	 * Last prompt passed to generate_label().
	 *
	 * @var string|null
	 */
	public $last_prompt = null;

	/**
	 * {@inheritDoc}
	 */
	protected function generate_label( string $prompt ) {
		$this->last_prompt = $prompt;
		if ( null !== $this->mock_response ) {
			return $this->mock_response;
		}

		return parent::generate_label( $prompt );
	}
}

/**
 * Label_Generation Ability test case.
 *
 * @since x.x.x
 */
class Label_GenerationTest extends WP_UnitTestCase {

	/**
	 * Label_Generation ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Label_Generation\Label_Generation
	 */
	private $ability;

	/**
	 * Testable Label_Generation ability instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Testable_Label_Generation
	 */
	private $testable_ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Label_Generation_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment       = new Test_Label_Generation_Experiment();
		$this->ability          = new Label_Generation(
			'ai/label-generation',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
		$this->testable_ability = new Testable_Label_Generation(
			'ai/label-generation',
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
		$this->assertArrayHasKey( 'post_id', $schema['properties'], 'Schema should have post_id property' );
		$this->assertArrayHasKey( 'content', $schema['properties'], 'Schema should have content property' );

		// Verify post_id property.
		$this->assertEquals( 'integer', $schema['properties']['post_id']['type'], 'post_id should be integer type' );
		$this->assertEquals( 'absint', $schema['properties']['post_id']['sanitize_callback'], 'post_id should use absint' );

		// Verify content property.
		$this->assertEquals( 'string', $schema['properties']['content']['type'], 'Content should be string type' );
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
		$this->assertArrayHasKey( 'description', $schema, 'Schema should have description property' );
	}

	/**
	 * Test that get_system_instruction() returns the system instruction.
	 *
	 * @since x.x.x
	 */
	public function test_get_system_instruction_returns_system_instruction() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'get_system_instruction' );
		$method->setAccessible( true );

		$instruction = $method->invoke( $this->ability, 'system-instruction.php' );

		$this->assertIsString( $instruction, 'System instruction should be a string' );
		$this->assertStringContainsString( 'List View labels', $instruction );
	}

	/**
	 * Test that permission_callback() returns true for user with edit_posts capability when no post_id is provided.
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
		$this->assertEquals( 'insufficient_permissions', $result->get_error_code() );
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
		$this->assertEquals( 'insufficient_permissions', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() returns true for user with edit_post capability on a specific post.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
		$this->assertEquals( 'insufficient_permissions', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() returns error for non-existent post.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_with_nonexistent_post_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'post_id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'post_not_found', $result->get_error_code() );
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
	 * Test that execute_callback() returns error when content is missing or empty.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_without_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result_empty = $method->invoke( $this->ability, array() );
		$this->assertInstanceOf( WP_Error::class, $result_empty );
		$this->assertEquals( 'content_not_provided', $result_empty->get_error_code() );

		$result_whitespace = $method->invoke( $this->ability, array( 'content' => '   ' ) );
		$this->assertInstanceOf( WP_Error::class, $result_whitespace );
		$this->assertEquals( 'content_not_provided', $result_whitespace->get_error_code() );
	}

	/**
	 * Test that execute_callback() returns error when content is below minimum length.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_with_content_too_short() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input  = array( 'content' => 'Short content' );
		$result = $method->invoke( $this->ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'content_too_short', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback() respects filtered minimum content length.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_with_filtered_min_content_length() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = 'Short Label';

		$filter = static function () {
			return 10;
		};
		add_filter( 'wpai_min_content_length', $filter );

		$input  = array( 'content' => 'Valid length' );
		$result = $method->invoke( $this->testable_ability, $input );

		remove_filter( 'wpai_min_content_length', $filter );

		$this->assertEquals( 'Short Label', $result );
	}

	/**
	 * Test that execute_callback() returns generated label on success.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_success() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = 'Customer Testimonials';

		$input  = array( 'content' => 'This section contains multiple client reviews and customer feedback.' );
		$result = $method->invoke( $this->testable_ability, $input );

		$this->assertEquals( 'Customer Testimonials', $result );
		$this->assertStringContainsString( '<content>This section contains multiple client reviews and customer feedback.</content>', $this->testable_ability->last_prompt );
	}

	/**
	 * Test that execute_callback() returns error when AI generation produces empty output.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_when_ai_returns_empty() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = '';

		$input  = array( 'content' => 'This section contains multiple client reviews and customer feedback.' );
		$result = $method->invoke( $this->testable_ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'no_results', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback() forwards WP_Error returned by generate_label.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_when_ai_returns_error() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = new WP_Error( 'provider_error', 'Failed to reach AI provider.' );

		$input  = array( 'content' => 'This section contains multiple client reviews and customer feedback.' );
		$result = $method->invoke( $this->testable_ability, $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'provider_error', $result->get_error_code() );
		$this->assertEquals( 'Failed to reach AI provider.', $result->get_error_message() );
	}

	/**
	 * Test that execute_callback() sanitizes HTML tags from the AI response.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_sanitizes_html_in_output() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = '<script>alert("xss")</script>Pricing Table';

		$input  = array( 'content' => 'This section lists our product pricing, monthly tiers, and discount details.' );
		$result = $method->invoke( $this->testable_ability, $input );

		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( 'Pricing Table', $result );
	}
}

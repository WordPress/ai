<?php
/**
 * Integration tests for the Content Resizing Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Content_Resizing\Content_Resizing;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Content Resizing Ability tests.
 *
 * @since x.x.x
 */
class Test_Content_Resizing_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'content-resizing';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Content Resizing',
			'description' => 'Shorten, expand, or rephrase selected block content using AI.',
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
 * Content Resizing Ability test case.
 *
 * @since x.x.x
 */
class Content_ResizingTest extends WP_UnitTestCase {

	/**
	 * Content Resizing ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Content_Resizing\Content_Resizing
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Content_Resizing_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Content_Resizing_Experiment();
		$this->ability    = new Content_Resizing(
			'ai/content-resizing',
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
		$this->assertArrayHasKey( 'action', $schema['properties'], 'Schema should have action property' );

		// Verify content property.
		$this->assertEquals( 'string', $schema['properties']['content']['type'], 'Content should be string type' );

		// Verify action property.
		$this->assertEquals( 'string', $schema['properties']['action']['type'], 'Action should be string type' );
		$this->assertEquals( array( 'shorten', 'expand', 'rephrase' ), $schema['properties']['action']['enum'], 'Action should have shorten, expand, rephrase values' );
		$this->assertEquals( 'rephrase', $schema['properties']['action']['default'], 'Action default should be rephrase' );
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
		$this->assertArrayHasKey( 'description', $schema, 'Schema should have description' );
	}

	/**
	 * Test that get_system_instruction() returns the system instruction.
	 *
	 * @since x.x.x
	 */
	public function test_get_system_instruction_returns_system_instruction() {
		$system_instruction = $this->ability->get_system_instruction();

		$this->assertIsString( $system_instruction, 'System instruction should be a string' );
		$this->assertNotEmpty( $system_instruction, 'System instruction should not be empty' );
	}

	/**
	 * Test that get_system_instruction() varies by action.
	 *
	 * @since x.x.x
	 */
	public function test_get_system_instruction_varies_by_action() {
		$shorten  = $this->ability->get_system_instruction( 'system-instruction.php', array( 'action' => 'shorten' ) );
		$expand   = $this->ability->get_system_instruction( 'system-instruction.php', array( 'action' => 'expand' ) );
		$rephrase = $this->ability->get_system_instruction( 'system-instruction.php', array( 'action' => 'rephrase' ) );

		$this->assertStringContainsString( 'Condense', $shorten, 'Shorten instruction should mention condensing' );
		$this->assertStringContainsString( 'Expand', $expand, 'Expand instruction should mention expanding' );
		$this->assertStringContainsString( 'Rephrase', $rephrase, 'Rephrase instruction should mention rephrasing' );

		$this->assertNotEquals( $shorten, $expand, 'Shorten and expand instructions should differ' );
		$this->assertNotEquals( $shorten, $rephrase, 'Shorten and rephrase instructions should differ' );
		$this->assertNotEquals( $expand, $rephrase, 'Expand and rephrase instructions should differ' );
	}

	/**
	 * Test that get_system_instruction() falls back to the rephrase description for unknown actions.
	 *
	 * @since x.x.x
	 */
	public function test_get_system_instruction_unknown_action_falls_back_to_rephrase() {
		$unknown  = $this->ability->get_system_instruction( 'system-instruction.php', array( 'action' => 'invalid_action' ) );
		$rephrase = $this->ability->get_system_instruction( 'system-instruction.php', array( 'action' => 'rephrase' ) );

		$this->assertStringContainsString( 'Rephrase', $unknown, 'Unknown action should fall back to the rephrase description' );
		$this->assertEquals( $rephrase, $unknown, 'Unknown action instruction should match the rephrase instruction' );
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

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'content_not_provided', $result->get_error_code(), 'Error code should be content_not_provided' );
	}

	/**
	 * Test that execute_callback() returns error when content is empty.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_with_empty_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, array( 'content' => '' ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'content_not_provided', $result->get_error_code(), 'Error code should be content_not_provided' );
	}

	/**
	 * Test that execute_callback() returns error when shorten action is used with short content.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_shorten_with_short_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->ability,
			array(
				'content' => 'Too few words.',
				'action'  => 'shorten',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'content_too_short', $result->get_error_code(), 'Error code should be content_too_short' );
	}

	/**
	 * Test that execute_callback() does not return content_too_short for expand action with short content.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_expand_with_short_content_does_not_error() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		try {
			$result = $method->invoke(
				$this->ability,
				array(
					'content' => 'Short text.',
					'action'  => 'expand',
				)
			);
		} catch ( \Throwable $e ) {
			$this->markTestSkipped( 'AI client not available in test environment: ' . $e->getMessage() );
			return;
		}

		if ( is_wp_error( $result ) ) {
			// If it fails, it should not be content_too_short.
			$this->assertNotEquals( 'content_too_short', $result->get_error_code(), 'Expand action should not trigger content_too_short error' );
			$this->markTestSkipped( 'AI client not available in test environment: ' . $result->get_error_message() );
			return;
		}

		$this->assertIsString( $result, 'Result should be a string' );
	}

	/**
	 * Test that execute_callback() with valid content calls the AI client.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_with_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'content' => 'This is some test content that needs to be resized. It contains multiple sentences to provide enough context for a meaningful transformation.',
			'action'  => 'rephrase',
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

		$this->assertIsString( $result, 'Result should be a string' );
		$this->assertNotEmpty( $result, 'Result should not be empty' );
	}

	/**
	 * Test that execute_callback() defaults to rephrase action.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_defaults_to_rephrase_action() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$input = array(
			'content' => 'This is some test content that needs to be resized. It contains multiple sentences to provide enough context for a meaningful transformation.',
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

		$this->assertIsString( $result, 'Result should be a string' );
		$this->assertNotEmpty( $result, 'Result should not be empty' );
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
	 * Test that generate_resized_content() returns a WP_Error when the model is not supported.
	 *
	 * @since x.x.x
	 */
	public function test_generate_resized_content_returns_wp_error_when_model_is_not_supported() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_resized_content' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, '<content>Test content here.</content>', 'rephrase' );

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error' );
		$this->assertEquals( 'unsupported_model', $result->get_error_code(), 'Error code should be unsupported_model' );
		$this->assertEquals( 'Content resizing failed. Please ensure you have a connected provider that supports text generation.', $result->get_error_message(), 'Error message should be passed through' );
	}

	/**
	 * Test that the shorten word count validation strips HTML tags before counting.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_shorten_word_count_ignores_html_tags() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke(
			$this->ability,
			array(
				'content' => '<strong>One</strong> <em>two</em> <a href="#">three</a>.',
				'action'  => 'shorten',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'Result should be WP_Error when HTML-wrapped content has fewer than 5 words' );
		$this->assertEquals( 'content_too_short', $result->get_error_code(), 'Error code should be content_too_short' );
	}
}

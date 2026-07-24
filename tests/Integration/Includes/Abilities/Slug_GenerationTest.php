<?php
/**
 * Integration tests for the Slug_Generation Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Slug_Generation\Slug_Generation;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Slug_Generation Ability tests.
 */
class Test_Slug_Generation_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'slug-generation';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Slug Generation',
			'description' => 'Suggests slug suggestions from content',
		);
	}

	/**
	 * Registers the experiment.
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Testable subclass of Slug_Generation to mock AI prompt generation.
 */
class Testable_Slug_Generation extends Slug_Generation {
	/**
	 * Mock response to return from generate_slugs().
	 *
	 * @var string|\WP_Error|null
	 */
	public $mock_response = null;

	/**
	 * Last prompt passed to generate_slugs().
	 *
	 * @var string|null
	 */
	public $last_prompt = null;

	/**
	 * {@inheritDoc}
	 */
	protected function generate_slugs( string $prompt, $context, int $number_of_suggestions ) {
		$this->last_prompt = $prompt;
		if ( null !== $this->mock_response ) {
			return $this->mock_response;
		}

		return parent::generate_slugs( $prompt, $context, $number_of_suggestions );
	}
}

/**
 * Slug_Generation Ability test case.
 */
class Slug_GenerationTest extends WP_UnitTestCase {

	/**
	 * Slug_Generation ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Slug_Generation\Slug_Generation
	 */
	private $ability;

	/**
	 * Testable Slug_Generation ability instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Testable_Slug_Generation
	 */
	private $testable_ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Slug_Generation_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment       = new Test_Slug_Generation_Experiment();
		$this->ability          = new Slug_Generation(
			'ai/slug-generation',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
		$this->testable_ability = new Testable_Slug_Generation(
			'ai/slug-generation',
			array(
				'label'       => $this->experiment->get_label(),
				'description' => $this->experiment->get_description(),
			)
		);
	}

	/**
	 * Tear down test case.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that guideline_categories() returns site and copy.
	 */
	public function test_guideline_categories_returns_site_and_copy(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'guideline_categories' );
		$method->setAccessible( true );

		$this->assertSame(
			array( 'site', 'copy' ),
			$method->invoke( $this->ability )
		);
	}

	/**
	 * Test that input_schema() returns the expected schema structure.
	 */
	public function test_input_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'input_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Input schema should be an array' );
		$this->assertEquals( 'object', $schema['type'], 'Schema type should be object' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'title', $schema['properties'], 'Schema should have title property' );
		$this->assertArrayHasKey( 'content', $schema['properties'], 'Schema should have content property' );
		$this->assertArrayHasKey( 'context', $schema['properties'], 'Schema should have context property' );
		$this->assertArrayHasKey( 'number_of_suggestions', $schema['properties'], 'Schema should have number_of_suggestions property' );
	}

	/**
	 * Test that output_schema() returns the expected schema structure.
	 */
	public function test_output_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'output_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema, 'Output schema should be an array' );
		$this->assertEquals( 'object', $schema['type'], 'Schema type should be object' );
		$this->assertArrayHasKey( 'properties', $schema, 'Schema should have properties' );
		$this->assertArrayHasKey( 'slugs', $schema['properties'], 'Schema should have slugs property' );
		$this->assertEquals( 'array', $schema['properties']['slugs']['type'], 'slugs should be array type' );
	}

	/**
	 * Test that meta() returns show_in_rest set to true.
	 */
	public function test_meta_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'meta' );
		$method->setAccessible( true );

		$meta = $method->invoke( $this->ability );

		$this->assertIsArray( $meta, 'Meta should be an array' );
		$this->assertTrue( $meta['show_in_rest'] ?? false, 'show_in_rest should be true' );
	}

	/**
	 * Test that permission_callback() returns error for logged out user.
	 */
	public function test_permission_callback_for_logged_out_user() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		wp_set_current_user( 0 );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() returns true for user with edit_posts capability.
	 */
	public function test_permission_callback_with_edit_posts_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array() );

		$this->assertTrue( $result );
	}

	/**
	 * Test that permission_callback() returns error for user without edit_posts capability.
	 */
	public function test_permission_callback_without_edit_posts_capability() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() returns true for valid post ID and edit_post capability.
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

		$result = $method->invoke( $this->ability, array( 'context' => (string) $post_id ) );

		$this->assertTrue( $result );
	}

	/**
	 * Test that permission_callback() returns error for invalid post ID.
	 */
	public function test_permission_callback_with_nonexistent_post_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'context' => '99999' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback() returns error when neither title nor content is provided.
	 */
	public function test_execute_callback_without_title_or_content() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'insufficient_data', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback() returns error when post ID context points to non-existent post.
	 */
	public function test_execute_callback_with_invalid_post_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, array( 'context' => '99999' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback() correctly parses, cleans, and sanitizes multiline output lines into slugs.
	 */
	public function test_execute_callback_parses_multiline_output_into_sanitized_slugs() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = "  my-first-slug  \n  \"My Second Slug!\"  \n\n  third_slug  \n  fourth-slug  ";

		$result = $method->invoke(
			$this->testable_ability,
			array(
				'title'                 => 'How to create WordPress plugins',
				'content'               => 'Detailed guide about plugin creation.',
				'number_of_suggestions' => 3,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'slugs', $result );
		$this->assertCount( 3, $result['slugs'] );
		$this->assertSame(
			array( 'my-first-slug', 'my-second-slug', 'third_slug' ),
			$result['slugs']
		);
	}

	/**
	 * Test that execute_callback() returns no_results WP_Error when prompt output is empty or invalid.
	 */
	public function test_execute_callback_handles_empty_or_error_results() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Case 1: generate_slugs returns empty string.
		$this->testable_ability->mock_response = '';
		$result                                = $method->invoke(
			$this->testable_ability,
			array( 'title' => 'Test title' )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'no_results', $result->get_error_code() );

		// Case 2: generate_slugs returns a WP_Error instance directly.
		$expected_error                        = new WP_Error( 'test_error', 'Test error message' );
		$this->testable_ability->mock_response = $expected_error;
		$result                                = $method->invoke(
			$this->testable_ability,
			array( 'title' => 'Test title' )
		);
		$this->assertSame( $expected_error, $result );
	}

	/**
	 * Test that execute_callback() uses post content and title when a valid post ID is passed.
	 */
	public function test_execute_callback_with_valid_post_id() {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Sample Article',
				'post_content' => 'Sample content body text.',
			)
		);

		$this->testable_ability->mock_response = 'sample-article';

		$result = $method->invoke(
			$this->testable_ability,
			array(
				'context' => (string) $post_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'sample-article' ), $result['slugs'] );
		$this->assertStringContainsString( 'Sample Article', $this->testable_ability->last_prompt );
	}

	/**
	 * Test that get_system_instruction() returns the system instruction with default 3 suggestions count.
	 */
	public function test_get_system_instruction_defaults_to_3_suggestions(): void {
		$system_instruction = $this->ability->get_system_instruction();

		$this->assertIsString( $system_instruction );
		$this->assertStringContainsString( 'Output exactly 3 suggestions, one per line.', $system_instruction );
	}

	/**
	 * Test that get_system_instruction() formats custom number_of_suggestions correctly.
	 */
	public function test_get_system_instruction_with_custom_number_of_suggestions(): void {
		$system_instruction = $this->ability->get_system_instruction( null, array( 'number_of_suggestions' => 5 ) );

		$this->assertIsString( $system_instruction );
		$this->assertStringContainsString( 'Output exactly 5 suggestions, one per line.', $system_instruction );
	}
}

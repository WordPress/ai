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
			array( 'my-first-slug', 'my-second-slug', 'third-slug' ),
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

	/**
	 * Test that system-instruction.php exits when accessed directly without ABSPATH defined.
	 */
	public function test_system_instruction_direct_access_exits(): void {
		$file   = TESTS_REPO_ROOT_DIR . '/includes/Abilities/Slug_Generation/system-instruction.php';
		$output = shell_exec( sprintf( '%s %s 2>&1', escapeshellarg( PHP_BINARY ), escapeshellarg( $file ) ) );

		$this->assertEmpty( trim( (string) $output ) );
	}

	/**
	 * Test that execute_callback() overrides post title when explicit title is passed with numeric context post ID.
	 */
	public function test_execute_callback_overrides_title_with_valid_post_id(): void {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Original Article Title',
				'post_content' => 'Original article content body.',
			)
		);

		$this->testable_ability->mock_response = 'overridden-title-slug';

		$result = $method->invoke(
			$this->testable_ability,
			array(
				'context' => (string) $post_id,
				'title'   => 'Custom Overridden Title',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'overridden-title-slug' ), $result['slugs'] );
		$this->assertStringContainsString( '<title>Custom Overridden Title</title>', $this->testable_ability->last_prompt );
		$this->assertStringNotContainsString( 'Original Article Title', $this->testable_ability->last_prompt );
	}

	/**
	 * Test that execute_callback() overrides post content when explicit content is passed with numeric context post ID.
	 */
	public function test_execute_callback_overrides_content_with_valid_post_id(): void {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Original Article Title',
				'post_content' => 'Original article content body.',
			)
		);

		$this->testable_ability->mock_response = 'overridden-content-slug';

		$result = $method->invoke(
			$this->testable_ability,
			array(
				'context' => (string) $post_id,
				'content' => 'Custom Overridden Content Body',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'overridden-content-slug' ), $result['slugs'] );
		$this->assertStringContainsString( '<content>Custom Overridden Content Body</content>', $this->testable_ability->last_prompt );
		$this->assertStringNotContainsString( 'Original article content body.', $this->testable_ability->last_prompt );
	}

	/**
	 * Test that execute_callback() formats array context with newlines into additional-context tag.
	 */
	public function test_execute_callback_with_array_context(): void {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = 'array-context-slug';

		$result = $method->invoke(
			$this->testable_ability,
			array(
				'title'   => 'Array Context Article',
				'context' => array( 'Context line 1', 'Context line 2' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'array-context-slug' ), $result['slugs'] );
		$this->assertStringContainsString( "<additional-context>Context line 1\nContext line 2</additional-context>", $this->testable_ability->last_prompt );
	}

	/**
	 * Test that execute_callback() formats associative array context as Key: Value lines.
	 */
	public function test_execute_callback_with_associative_array_context(): void {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = 'assoc-context-slug';

		$result = $method->invoke(
			$this->testable_ability,
			array(
				'title'   => 'Assoc Context Article',
				'context' => array(
					'post_type'  => 'post',
					'categories' => array( 'Tech', 'AI' ),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'assoc-context-slug' ), $result['slugs'] );
		$this->assertStringContainsString( "<additional-context>post_type: post\ncategories: Tech, AI</additional-context>", $this->testable_ability->last_prompt );
	}

	/**
	 * Test that execute_callback() uses wp_unique_post_slug to avoid collisions with existing posts.
	 */
	public function test_execute_callback_generates_unique_slug_avoiding_collisions(): void {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create an existing post with the slug 'existing-slug'.
		$this->factory->post->create(
			array(
				'post_name'   => 'existing-slug',
				'post_status' => 'publish',
			)
		);

		// Create a second post to generate a slug for.
		$target_post_id = $this->factory->post->create(
			array(
				'post_name'   => 'new-post',
				'post_status' => 'publish',
			)
		);

		$this->testable_ability->mock_response = 'existing-slug';

		$result = $method->invoke(
			$this->testable_ability,
			array(
				'context' => (string) $target_post_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'existing-slug-2' ), $result['slugs'] );
	}

	/**
	 * Test that execute_callback() avoids collisions for draft posts.
	 *
	 * `wp_unique_post_slug()` short-circuits for draft, pending, and auto-draft posts, which is
	 * the state a post is in while it is being edited in the block editor. The ability maps those
	 * statuses to `publish` so suggestions are still checked against existing content.
	 *
	 * @dataProvider data_unpublished_post_statuses
	 *
	 * @param string $post_status The unpublished post status to test.
	 */
	public function test_execute_callback_generates_unique_slug_for_unpublished_posts( string $post_status ): void {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// Create an existing published post occupying the slug.
		$this->factory->post->create(
			array(
				'post_name'   => 'existing-slug',
				'post_status' => 'publish',
			)
		);

		// The post being edited is not published yet.
		$target_post_id = $this->factory->post->create(
			array(
				'post_name'   => 'new-post',
				'post_status' => $post_status,
			)
		);

		$this->testable_ability->mock_response = 'existing-slug';

		$result = $method->invoke(
			$this->testable_ability,
			array(
				'context' => (string) $target_post_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'existing-slug-2' ),
			$result['slugs'],
			sprintf( 'Slug collisions should be resolved for %s posts.', $post_status )
		);
	}

	/**
	 * Data provider of unpublished post statuses that wp_unique_post_slug() short-circuits on.
	 *
	 * @return array<string, array{string}> The post statuses to test.
	 */
	public function data_unpublished_post_statuses(): array {
		return array(
			'draft'      => array( 'draft' ),
			'pending'    => array( 'pending' ),
			'auto-draft' => array( 'auto-draft' ),
		);
	}

	/**
	 * Test that execute_callback() does not uniquify slugs when no post is in context.
	 *
	 * Without a post there is no post type or parent to resolve uniqueness against, so the
	 * sanitized suggestion is returned as-is rather than being checked against the `post` type.
	 */
	public function test_execute_callback_does_not_uniquify_without_a_post(): void {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->factory->post->create(
			array(
				'post_name'   => 'existing-slug',
				'post_status' => 'publish',
			)
		);

		$this->testable_ability->mock_response = 'existing-slug';

		$result = $method->invoke(
			$this->testable_ability,
			array( 'title' => 'Some standalone title' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'existing-slug' ), $result['slugs'] );
	}

	/**
	 * Test that repeated suggestions from the model do not reduce the number of results.
	 */
	public function test_execute_callback_dedupes_repeated_model_output(): void {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		// The model repeats the first suggestion, in two different but equivalent forms.
		$this->testable_ability->mock_response = "first-slug\nFirst Slug\nsecond-slug\nthird-slug";

		$result = $method->invoke(
			$this->testable_ability,
			array(
				'title'                 => 'Duplicate output test',
				'number_of_suggestions' => 3,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame(
			array( 'first-slug', 'second-slug', 'third-slug' ),
			$result['slugs'],
			'Repeated model output should be collapsed without shrinking the result set.'
		);
	}

	/**
	 * Test that execute_callback() returns no_results WP_Error when generated response produces no valid slugs after sanitization.
	 */
	public function test_execute_callback_returns_no_results_when_sanitization_yields_empty_slugs(): void {
		$reflection = new \ReflectionClass( $this->testable_ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$this->testable_ability->mock_response = "   \n  \"\"  \n   !@#$%^&*()   ";

		$result = $method->invoke(
			$this->testable_ability,
			array(
				'title' => 'Unsanitizable Output Test',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'no_results', $result->get_error_code() );
		$this->assertEquals( 'No slug suggestion was generated.', $result->get_error_message() );
	}

	/**
	 * Test that permission_callback() returns error when post ID is provided but current user cannot edit the post.
	 */
	public function test_permission_callback_with_post_id_without_edit_capability(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create(
			array(
				'post_title'  => 'Protected Post',
				'post_status' => 'publish',
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'context' => (string) $post_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'insufficient_capabilities', $result->get_error_code() );
		$this->assertEquals( 'You do not have permission to generate slugs for this post.', $result->get_error_message() );
	}

	/**
	 * Test that permission_callback() returns false when get_post_type() returns false or empty.
	 */
	public function test_permission_callback_returns_false_when_post_type_is_false(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$post_id = $this->factory->post->create();
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$this->setExpectedIncorrectUsage( 'map_meta_cap' );

		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_type' => '' ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );

		$result = $method->invoke( $this->ability, array( 'context' => (string) $post_id ) );

		$this->assertFalse( $result );
	}

	/**
	 * Test that permission_callback() returns false when post type has show_in_rest set to false.
	 */
	public function test_permission_callback_with_post_type_without_show_in_rest(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		register_post_type(
			'test_no_rest_slug',
			array(
				'public'       => true,
				'show_in_rest' => false,
			)
		);

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'test_no_rest_slug',
				'post_status' => 'publish',
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $method->invoke( $this->ability, array( 'context' => (string) $post_id ) );

		unregister_post_type( 'test_no_rest_slug' );

		$this->assertFalse( $result );
	}

	/**
	 * Test that generate_slugs() returns WP_Error when prompt builder or provider validation fails.
	 */
	public function test_generate_slugs_returns_error_when_prompt_builder_fails(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_slugs' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, '<title>Test Prompt</title>', 'context', 3 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals(
			'Slug generation failed. Please ensure you have a connected provider that supports text generation.',
			$result->get_error_message()
		);
	}

	/**
	 * Test that generate_slugs() and get_prompt_builder() invoke generate_text() on configured prompt builder.
	 */
	public function test_generate_slugs_and_get_prompt_builder_success(): void {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'generate_slugs' );
		$method->setAccessible( true );

		$mock_builder = $this->getMockBuilder( \WP_AI_Client_Prompt_Builder::class )
			->disableOriginalConstructor()
			->addMethods( array( 'is_supported_for_text_generation', 'generate_text' ) )
			->getMock();

		$mock_builder->method( 'is_supported_for_text_generation' )
			->willReturn( true );

		$mock_builder->expects( $this->once() )
			->method( 'generate_text' )
			->willReturn( "generated-slug-1\ngenerated-slug-2" );

		add_filter(
			'wpai_slug_generation_prompt_builder',
			static function () use ( $mock_builder ) {
				return $mock_builder;
			}
		);

		$result = $method->invoke( $this->ability, '<title>Test Prompt</title>', '', 2 );

		remove_all_filters( 'wpai_slug_generation_prompt_builder' );

		$this->assertSame( "generated-slug-1\ngenerated-slug-2", $result );
	}
}

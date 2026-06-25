<?php
/**
 * Integration tests for the Reply_Suggestion Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Suggest_Reply\Reply_Suggestion;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Reply_Suggestion Ability tests.
 *
 * @since x.x.x
 */
class Test_Suggest_Reply_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'suggest-reply';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Suggest Reply',
			'description' => 'Adds a "Suggest Reply" action to the Comments screen, enabling moderators to generate AI-powered reply suggestions.',
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
 * Reply_Suggestion Ability test case.
 *
 * @since x.x.x
 */
class Suggest_ReplyTest extends WP_UnitTestCase {
	/**
	 * Reply_Suggestion ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Suggest_Reply\Reply_Suggestion
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Suggest_Reply_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Suggest_Reply_Experiment();
		$this->ability    = new Reply_Suggestion(
			'ai/reply-suggestion',
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

		$this->assertSame( 'ai-experiments', $result, 'Category should be ai-experiments' );
	}

	/**
	 * Test that input_schema() returns the expected structure.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'input_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'comment_id', $schema['properties'] );
		$this->assertSame( 'integer', $schema['properties']['comment_id']['type'] );
		$this->assertArrayHasKey( 'tone', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['tone']['type'] );
		$this->assertSame( array( 'professional', 'friendly', 'casual' ), $schema['properties']['tone']['enum'] );
		$this->assertSame( 'friendly', $schema['properties']['tone']['default'] );
		$this->assertArrayHasKey( 'guidelines', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['guidelines']['type'] );
		$this->assertContains( 'comment_id', $schema['required'] );
	}

	/**
	 * Test that output_schema() returns the expected structure.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'output_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'comment_id', $schema['properties'] );
		$this->assertSame( 'integer', $schema['properties']['comment_id']['type'] );
		$this->assertArrayHasKey( 'reply', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['reply']['type'] );
	}

	/**
	 * Test that execute_callback() returns error when comment_id is missing.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_without_comment_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_comment_id', $result->get_error_code() );
	}

	/**
	 * Test that execute_callback() returns error for invalid comment ID.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_with_invalid_comment_id() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, array( 'comment_id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'comment_not_found', $result->get_error_code() );
	}

	/**
	 * Test that permission_callback() allows users who can moderate comments.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_allows_moderate_comments_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, array( 'comment_id' => 1 ) );

		$this->assertTrue( $result );
	}

	/**
	 * Test that permission_callback() denies users without moderate_comments capability.
	 *
	 * @since x.x.x
	 */
	public function test_permission_callback_denies_without_moderate_comments_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'permission_callback' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, array( 'comment_id' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Test that meta() returns expected shape.
	 *
	 * @since x.x.x
	 */
	public function test_meta_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'meta' );
		$method->setAccessible( true );

		$meta = $method->invoke( $this->ability );

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'show_in_rest', $meta );
		$this->assertTrue( $meta['show_in_rest'] );
	}

	/**
	 * Test that get_system_instruction() returns a non-empty string with expected content.
	 *
	 * @since x.x.x
	 */
	public function test_get_system_instruction_returns_expected_content() {
		$system_instruction = $this->ability->get_system_instruction();

		$this->assertIsString( $system_instruction );
		$this->assertNotEmpty( $system_instruction );
		$this->assertStringContainsString( 'WordPress site moderator', $system_instruction );
		$this->assertStringContainsString( 'tone', $system_instruction );
		$this->assertStringContainsString( 'reply', $system_instruction );
	}

	/**
	 * Test that build_context() assembles the expected prompt parts.
	 *
	 * @since x.x.x
	 */
	public function test_build_context_includes_all_parts() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Test Post Title',
				'post_content' => 'This is the test post content for the reply suggestion.',
			)
		);

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_author'  => 'Jane Doe',
				'comment_content' => 'This is a great article!',
			)
		);

		$comment = get_comment( $comment_id );

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'build_context' );
		$method->setAccessible( true );

		$context = $method->invoke(
			$this->ability,
			$comment,
			'Test Post Title',
			'This is the test post content for the reply suggestion.',
			'professional',
			'Be concise.'
		);

		$this->assertIsString( $context );
		$this->assertStringContainsString( 'Post Title: Test Post Title', $context );
		$this->assertStringContainsString( 'Post Context:', $context );
		$this->assertStringContainsString( 'Comment Author: Jane Doe', $context );
		$this->assertStringContainsString( 'Comment: """This is a great article!"""', $context );
		$this->assertStringContainsString( 'Requested Tone: professional', $context );
		$this->assertStringContainsString( 'Editorial Guidelines: Be concise.', $context );
	}

	/**
	 * Test that build_context() omits post sections when post data is empty.
	 *
	 * @since x.x.x
	 */
	public function test_build_context_omits_empty_post_sections() {
		$comment_id = self::factory()->comment->create(
			array(
				'comment_author'  => 'John Smith',
				'comment_content' => 'Nice work!',
			)
		);

		$comment = get_comment( $comment_id );

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'build_context' );
		$method->setAccessible( true );

		$context = $method->invoke(
			$this->ability,
			$comment,
			'', 
			'', 
			'casual'
		);

		$this->assertStringNotContainsString( 'Post Title:', $context );
		$this->assertStringNotContainsString( 'Post Context:', $context );
		$this->assertStringContainsString( 'Comment Author: John Smith', $context );
		$this->assertStringContainsString( 'Requested Tone: casual', $context );
	}

	/**
	 * Test that build_context() omits the guidelines line when no guidelines are provided.
	 *
	 * @since x.x.x
	 */
	public function test_build_context_omits_empty_guidelines() {
		$comment_id = self::factory()->comment->create(
			array(
				'comment_author'  => 'Alice',
				'comment_content' => 'Great post!',
			)
		);

		$comment = get_comment( $comment_id );

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'build_context' );
		$method->setAccessible( true );

		$context = $method->invoke(
			$this->ability,
			$comment,
			'My Post',
			'Some excerpt.',
			'friendly',
			'' 
		);

		$this->assertStringNotContainsString( 'Editorial Guidelines:', $context );
	}
}

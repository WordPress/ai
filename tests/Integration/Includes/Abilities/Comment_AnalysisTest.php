<?php
/**
 * Integration tests for the Comment_Analysis Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Abilities\Comment_Moderation\Comment_Analysis;
use WordPress\AI\Experiments\Comment_Moderation\Comment_Moderation;

/**
 * Test experiment for Comment_Analysis Ability tests.
 *
 * @since x.x.x
 */
class Test_Comment_Moderation_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'comment-moderation';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Comment Moderation',
			'description' => 'AI-powered sentiment and toxicity analysis for comments.',
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
 * Comment_Analysis Ability test case.
 *
 * @since x.x.x
 */
class Comment_AnalysisTest extends WP_UnitTestCase {
	/**
	 * Comment_Analysis ability instance.
	 *
	 * @var \WordPress\AI\Abilities\Comment_Moderation\Comment_Analysis
	 */
	private $ability;

	/**
	 * Test experiment instance.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Abilities\Test_Comment_Moderation_Experiment
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->experiment = new Test_Comment_Moderation_Experiment();
		$this->ability    = new Comment_Analysis(
			'ai/comment-analysis',
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
		$this->assertArrayHasKey( 'toxicity_score', $schema['properties'] );
		$this->assertArrayHasKey( 'sentiment', $schema['properties'] );
		$this->assertSame( 'number', $schema['properties']['toxicity_score']['type'] );
		$this->assertSame( 0, $schema['properties']['toxicity_score']['minimum'] );
		$this->assertSame( 1, $schema['properties']['toxicity_score']['maximum'] );
		$this->assertSame(
			array( 'positive', 'negative', 'neutral' ),
			$schema['properties']['sentiment']['enum']
		);
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
	 * Test that execute_callback() returns error when comment is already processing.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_with_already_processing_status() {
		$comment_id = self::factory()->comment->create();
		update_comment_meta( $comment_id, Comment_Moderation::META_ANALYSIS_STATUS, Comment_Moderation::STATUS_PROCESSING );

		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'execute_callback' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->ability, array( 'comment_id' => $comment_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'already_processing', $result->get_error_code() );
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
	 * Test that response_schema() returns strict expected structure.
	 *
	 * @since x.x.x
	 */
	public function test_response_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'response_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'toxicity_score', $schema['properties'] );
		$this->assertArrayHasKey( 'sentiment', $schema['properties'] );
		$this->assertSame( array( 'toxicity_score', 'sentiment' ), $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}

	/**
	 * Test that get_system_instruction() returns configured instruction.
	 *
	 * @since x.x.x
	 */
	public function test_get_system_instruction_returns_expected_content() {
		$system_instruction = $this->ability->get_system_instruction();

		$this->assertIsString( $system_instruction );
		$this->assertNotEmpty( $system_instruction );
		$this->assertStringContainsString( 'comment moderation assistant', $system_instruction );
		$this->assertStringContainsString( 'toxicity_score', $system_instruction );
		$this->assertStringContainsString( 'sentiment', $system_instruction );
	}
}

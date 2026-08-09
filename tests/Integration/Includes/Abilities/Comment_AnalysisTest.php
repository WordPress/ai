<?php
/**
 * Integration tests for the Comment_Analysis Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Comment_Moderation\Comment_Analysis;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Comment_Moderation\Comment_Moderation;

/**
 * Test experiment for Comment_Analysis Ability tests.
 *
 * @since 0.9.0
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
	 * @since 0.9.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Comment_Analysis Ability test case.
 *
 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'wpai_comment_analysis_result' );
		parent::tearDown();
	}

	/**
	 * Test that category() returns the correct category.
	 *
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
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
			array( 'positive', 'neutral', 'negative' ),
			$schema['properties']['sentiment']['enum']
		);

		// value_score field.
		$this->assertArrayHasKey( 'value_score', $schema['properties'] );
		$this->assertSame( 'number', $schema['properties']['value_score']['type'] );
		$this->assertSame( 0, $schema['properties']['value_score']['minimum'] );
		$this->assertSame( 1, $schema['properties']['value_score']['maximum'] );
	}

	/**
	 * Test that execute_callback() returns error when comment_id is missing.
	 *
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
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
	 * @since 0.9.0
	 */
	public function test_response_schema_returns_expected_structure() {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( 'response_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( $this->ability );

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'toxicity_score', $schema['properties'] );
		$this->assertArrayHasKey( 'sentiment', $schema['properties'] );
		$this->assertArrayHasKey( 'value_score', $schema['properties'] );
		$this->assertSame( array( 'toxicity_score', 'sentiment', 'value_score' ), $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}

	/**
	 * Test that get_system_instruction() returns configured instruction.
	 *
	 * @since 0.9.0
	 */
	public function test_get_system_instruction_returns_expected_content() {
		$system_instruction = $this->ability->get_system_instruction();

		$this->assertIsString( $system_instruction );
		$this->assertNotEmpty( $system_instruction );
		$this->assertStringContainsString( 'comment moderation assistant', $system_instruction );
		$this->assertStringContainsString( 'toxicity_score', $system_instruction );
		$this->assertStringContainsString( 'sentiment', $system_instruction );
		$this->assertStringContainsString( 'value_score', $system_instruction );
	}


	/**
	 * Test that execute_callback() stores the value_score in comment meta.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_stores_value_score_meta() {
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		add_filter(
			'wpai_comment_analysis_result',
			static function () {
				return array(
					'toxicity_score' => 0.1,
					'sentiment'      => 'positive',
					'value_score'    => 0.85,
				);
			},
			10,
			4
		);

		$result = $this->invoke_ability_method( 'execute_callback', array( array( 'comment_id' => $comment_id ) ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'value_score', $result );
		$this->assertSame( 0.85, $result['value_score'] );
		$this->assertSame( 0.85, (float) get_comment_meta( $comment_id, Comment_Moderation::META_VALUE_SCORE, true ) );
	}

	/**
	 * Test that get_post_context() returns null for a nonexistent post.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_context_returns_null_for_nonexistent_post() {
		$result = $this->invoke_ability_method( 'get_post_context', array( 999999 ) );

		$this->assertNull( $result );
	}

	/**
	 * Test that get_post_context() returns the post excerpt when available.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_context_uses_excerpt_when_available() {
		$post_id = self::factory()->post->create(
			array(
				'post_excerpt' => 'A human-written excerpt.',
				'post_content' => 'Full post content that should not be used.',
			)
		);
		update_post_meta( $post_id, '_ai_post_summary', 'An AI summary that should not be used either.' );

		$result = $this->invoke_ability_method( 'get_post_context', array( $post_id ) );

		$this->assertSame( 'A human-written excerpt.', $result );
	}

	/**
	 * Test that get_post_context() falls back to the AI-generated summary when no excerpt is available.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_context_falls_back_to_ai_summary_when_no_excerpt() {
		$post_id = self::factory()->post->create(
			array(
				'post_excerpt' => '',
				'post_content' => 'Full post content that should not be used.',
			)
		);
		update_post_meta( $post_id, '_ai_post_summary', 'An AI-generated summary.' );

		$result = $this->invoke_ability_method( 'get_post_context', array( $post_id ) );

		$this->assertSame( 'An AI-generated summary.', $result );
	}

	/**
	 * Test that get_post_context() falls back to trimmed post content when no excerpt or AI summary is available.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_context_falls_back_to_trimmed_content() {
		$long_content = '<p>' . str_repeat( 'Lorem ipsum dolor sit amet. ', 40 ) . '</p>';
		$post_id      = self::factory()->post->create(
			array(
				'post_excerpt' => '',
				'post_content' => $long_content,
			)
		);

		$result = $this->invoke_ability_method( 'get_post_context', array( $post_id ) );

		$this->assertIsString( $result );
		$this->assertLessThanOrEqual( 650, mb_strlen( $result ) );
		$this->assertStringNotContainsString( '<p>', $result, 'Tags should be stripped.' );
	}

	/**
	 * Test that get_post_context() returns null when no post context is available.
	 *
	 * @since x.x.x
	 */
	public function test_get_post_context_returns_null_when_no_content_available() {
		$post_id = self::factory()->post->create(
			array(
				'post_excerpt' => '',
				'post_content' => '',
			)
		);

		$result = $this->invoke_ability_method( 'get_post_context', array( $post_id ) );

		$this->assertNull( $result );
	}

	/**
	 * Test that sanitize_analysis_result() clamps value_score values above 1.
	 *
	 * @since x.x.x
	 */
	public function test_sanitize_analysis_result_clamps_value_score_above_one() {
		$result = $this->invoke_ability_method(
			'sanitize_analysis_result',
			array(
				array(
					'toxicity_score' => 0.2,
					'sentiment'      => 'neutral',
					'value_score'    => 1.5,
				),
			)
		);

		$this->assertSame( 1.0, $result['value_score'] );
	}

	/**
	 * Test that sanitize_analysis_result() clamps value_score values below 0.
	 *
	 * @since x.x.x
	 */
	public function test_sanitize_analysis_result_clamps_value_score_below_zero() {
		$result = $this->invoke_ability_method(
			'sanitize_analysis_result',
			array(
				array(
					'toxicity_score' => 0.2,
					'sentiment'      => 'neutral',
					'value_score'    => -0.4,
				),
			)
		);

		$this->assertSame( 0.0, $result['value_score'] );
	}

	/**
	 * Test that sanitize_analysis_result() defaults a missing value_score to 0.
	 *
	 * @since x.x.x
	 */
	public function test_sanitize_analysis_result_defaults_missing_value_score_to_zero() {
		$result = $this->invoke_ability_method(
			'sanitize_analysis_result',
			array(
				array(
					'toxicity_score' => 0.2,
					'sentiment'      => 'neutral',
				),
			)
		);

		$this->assertArrayHasKey( 'value_score', $result );
		$this->assertSame( 0.0, $result['value_score'] );
	}

	/**
	 * Test that sanitize_analysis_result() preserves a valid value_score.
	 *
	 * @since x.x.x
	 */
	public function test_sanitize_analysis_result_preserves_valid_value_score() {
		$result = $this->invoke_ability_method(
			'sanitize_analysis_result',
			array(
				array(
					'toxicity_score' => 0.2,
					'sentiment'      => 'neutral',
					'value_score'    => 0.42,
				),
			)
		);

		$this->assertSame( 0.42, $result['value_score'] );
	}

	/**
	 * Test that analyze_comment() passes the post ID through the wpai_comment_analysis_result filter.
	 *
	 * @since x.x.x
	 */
	public function test_analyze_comment_passes_post_id_through_filter() {
		$post_id = self::factory()->post->create();

		$captured_args = array();

		add_filter(
			'wpai_comment_analysis_result',
			static function ( $pre_result, $content, $author, $post_id_arg ) use ( &$captured_args ) {
				$captured_args = array( $content, $author, $post_id_arg );

				return array(
					'toxicity_score' => 0.0,
					'sentiment'      => 'neutral',
					'value_score'    => 0.5,
				);
			},
			10,
			4
		);

		$result = $this->invoke_ability_method(
			'analyze_comment',
			array( 'Great write-up, thanks!', 'Jane Doe', $post_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Great write-up, thanks!', $captured_args[0] );
		$this->assertSame( 'Jane Doe', $captured_args[1] );
		$this->assertSame( $post_id, $captured_args[2] );
	}

	/**
	 * Test that analyze_comment() returns a sanitized value_score.
	 *
	 * @since x.x.x
	 */
	public function test_analyze_comment_returns_sanitized_value_score() {
		$post_id = self::factory()->post->create();

		add_filter(
			'wpai_comment_analysis_result',
			static function () {
				return array(
					'toxicity_score' => 0.1,
					'sentiment'      => 'positive',
					'value_score'    => 2, // Deliberately out of range.
				);
			},
			10,
			4
		);

		$result = $this->invoke_ability_method(
			'analyze_comment',
			array( 'Some comment content.', 'Jane Doe', $post_id )
		);

		$this->assertSame( 1.0, $result['value_score'] );
	}

	/**
	 * Test that analyze_comment_by_id() stores the value_score in comment meta.
	 *
	 * @since x.x.x
	 */
	public function test_analyze_comment_by_id_stores_value_score_meta() {
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		add_filter(
			'wpai_comment_analysis_result',
			static function () {
				return array(
					'toxicity_score' => 0.05,
					'sentiment'      => 'positive',
					'value_score'    => 0.9,
				);
			},
			10,
			4
		);

		$result = $this->ability->analyze_comment_by_id( $comment_id );

		$this->assertIsArray( $result );
		$this->assertSame( 0.9, $result['value_score'] );
		$this->assertSame(
			Comment_Moderation::STATUS_COMPLETE,
			get_comment_meta( $comment_id, Comment_Moderation::META_ANALYSIS_STATUS, true )
		);
		$this->assertSame(
			0.9,
			(float) get_comment_meta( $comment_id, Comment_Moderation::META_VALUE_SCORE, true )
		);
	}

	/**
	 * Invoke a non-public ability method using reflection.
	 *
	 * @since x.x.x
	 *
	 * @param string $method_name Name of the method to invoke.
	 * @param array  $args        Arguments to pass to the method.
	 * @return mixed The value returned by the invoked method.
	 */
	private function invoke_ability_method( string $method_name, array $args = array() ) {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( $this->ability, $args );
	}
}

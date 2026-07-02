<?php
/**
 * Integration tests for the Type_Ahead Ability class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Type_Ahead\Type_Ahead;
use WordPress\AI\Abstracts\Abstract_Feature;

/**
 * Test experiment for Type_Ahead Ability tests.
 *
 * @since 1.1.0
 */
class Test_Type_Ahead_Experiment extends Abstract_Feature {
	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'type-ahead';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 *
	 * @return array{label: string, description: string}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Type-ahead Text',
			'description' => 'Ghost text suggestions while writing paragraphs in the block editor.',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.1.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Type_Ahead Ability test case.
 *
 * @since 1.1.0
 */
class Type_AheadTest extends WP_UnitTestCase {
	/**
	 * Ability instance.
	 *
	 * @since 1.1.0
	 *
	 * @var Type_Ahead
	 */
	private $ability;

	/**
	 * Sets up the test case.
	 *
	 * @since 1.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		$experiment   = new Test_Type_Ahead_Experiment();
		$this->ability = new Type_Ahead(
			'ai/type-ahead',
			array(
				'label'       => $experiment->get_label(),
				'description' => $experiment->get_description(),
			)
		);
	}

	/**
	 * Tears down the test case.
	 *
	 * @since 1.1.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Returns an accessible reflection method.
	 *
	 * @since 1.1.0
	 *
	 * @param string $name Method name.
	 * @return \ReflectionMethod
	 */
	private function get_method( string $name ): \ReflectionMethod {
		$reflection = new \ReflectionClass( $this->ability );
		$method     = $reflection->getMethod( $name );
		$method->setAccessible( true );
		return $method;
	}

	/**
	 * Test that guideline_categories() returns site and copy.
	 *
	 * @since 1.1.0
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
	 * Tests input schema structure.
	 *
	 * @since 1.1.0
	 */
	public function test_input_schema_returns_expected_structure() {
		$schema = $this->get_method( 'input_schema' )->invoke( $this->ability );

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'block_content', $schema['properties'] );
		$this->assertArrayHasKey( 'preceding_text', $schema['properties'] );
		$this->assertArrayHasKey( 'following_text', $schema['properties'] );
		$this->assertArrayHasKey( 'surrounding_context', $schema['properties'] );
		$this->assertArrayHasKey( 'cursor_position', $schema['properties'] );
		$this->assertArrayHasKey( 'mode', $schema['properties'] );
		$this->assertArrayHasKey( 'max_words', $schema['properties'] );
		$this->assertArrayHasKey( 'manual_trigger', $schema['properties'] );
		$this->assertContains( 'block_content', $schema['required'] );
	}

	/**
	 * Tests output schema structure.
	 *
	 * @since 1.1.0
	 */
	public function test_output_schema_returns_expected_structure() {
		$schema = $this->get_method( 'output_schema' )->invoke( $this->ability );

		$this->assertIsArray( $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'suggestion', $schema['properties'] );
		$this->assertArrayHasKey( 'confidence', $schema['properties'] );
		$this->assertArrayHasKey( 'cursor_position', $schema['properties'] );
	}

	/**
	 * Tests meta schema.
	 *
	 * @since 1.1.0
	 */
	public function test_meta_returns_expected_structure() {
		$meta = $this->get_method( 'meta' )->invoke( $this->ability );

		$this->assertIsArray( $meta );
		$this->assertArrayHasKey( 'show_in_rest', $meta );
		$this->assertTrue( $meta['show_in_rest'] );
	}

	/**
	 * Tests system instruction loading.
	 *
	 * @since 1.1.0
	 */
	public function test_get_system_instruction_returns_string() {
		$instruction = $this->ability->get_system_instruction();
		$this->assertIsString( $instruction );
		$this->assertNotEmpty( $instruction );
	}

	/**
	 * Tests private suggestion schema.
	 *
	 * @since 1.1.0
	 */
	public function test_suggestion_schema_returns_expected_structure() {
		$schema = $this->get_method( 'suggestion_schema' )->invoke( $this->ability );

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'suggestion', $schema['properties'] );
		$this->assertArrayHasKey( 'confidence', $schema['properties'] );
		$this->assertContains( 'suggestion', $schema['required'] );
		$this->assertContains( 'confidence', $schema['required'] );
	}

	/**
	 * Tests prompt context composition.
	 *
	 * @since 1.1.0
	 */
	public function test_prepare_prompt_context_returns_expected_payload() {
		$context = $this->get_method( 'prepare_prompt_context' )->invoke(
			$this->ability,
			'Block text',
			'Before',
			'After',
			'Neighbors',
			6,
			'smart',
			20,
			true
		);

		$this->assertSame( 'smart', $context['mode'] );
		$this->assertSame( 20, $context['max_words'] );
		$this->assertSame( 6, $context['cursor_position'] );
		$this->assertSame( 'Block text', $context['block_content'] );
		$this->assertTrue( $context['manual_trigger'] );
	}

	/**
	 * Tests text truncation behavior.
	 *
	 * @since 1.1.0
	 */
	public function test_truncate_text_respects_context_limit() {
		$long_text = str_repeat( 'a', 5500 );
		$result    = $this->get_method( 'truncate_text' )->invoke( $this->ability, $long_text );

		$this->assertSame( 5000, mb_strlen( $result ) );
		$this->assertSame( mb_substr( $long_text, -5000 ), $result );
	}

	/**
	 * Tests cache key generation changes by mode.
	 *
	 * @since 1.1.0
	 */
	public function test_build_cache_key_changes_when_mode_changes() {
		$method = $this->get_method( 'build_cache_key' );

		$key_a = $method->invoke( $this->ability, 'Text', 'Before', 'word', 20 );
		$key_b = $method->invoke( $this->ability, 'Text', 'Before', 'smart', 20 );

		$this->assertIsString( $key_a );
		$this->assertIsString( $key_b );
		$this->assertNotSame( $key_a, $key_b );
	}

	/**
	 * Tests permission callback for edit_posts users.
	 *
	 * @since 1.1.0
	 */
	public function test_permission_callback_with_edit_posts_capability() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $this->get_method( 'permission_callback' )->invoke( $this->ability, array() );

		$this->assertTrue( $result );
	}

	/**
	 * Tests permission callback for users without edit_posts.
	 *
	 * @since 1.1.0
	 */
	public function test_permission_callback_without_edit_posts_capability() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$result = $this->get_method( 'permission_callback' )->invoke( $this->ability, array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_capabilities', $result->get_error_code() );
	}

	/**
	 * Tests permission callback for valid post_id.
	 *
	 * @since 1.1.0
	 */
	public function test_permission_callback_returns_true_for_editor_with_valid_post() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$result  = $this->get_method( 'permission_callback' )->invoke( $this->ability, array( 'post_id' => $post_id ) );

		$this->assertTrue( $result );
	}

	/**
	 * Tests permission callback for missing post_id.
	 *
	 * @since 1.1.0
	 */
	public function test_permission_callback_returns_error_for_missing_post() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = $this->get_method( 'permission_callback' )->invoke( $this->ability, array( 'post_id' => 999999 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	/**
	 * Tests permission callback for non-REST post types.
	 *
	 * @since 1.1.0
	 */
	public function test_permission_callback_returns_false_for_non_rest_post_type() {
		register_post_type(
			'ai_tahead_private',
			array(
				'public'       => true,
				'show_in_rest' => false,
			)
		);

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'ai_tahead_private',
				'post_status' => 'publish',
			)
		);

		$result = $this->get_method( 'permission_callback' )->invoke( $this->ability, array( 'post_id' => $post_id ) );

		unregister_post_type( 'ai_tahead_private' );

		$this->assertFalse( $result );
	}

	/**
	 * Tests execute_callback() returns cached value when present.
	 *
	 * @since 1.1.0
	 */
	public function test_execute_callback_returns_cached_result() {
		$key_method     = $this->get_method( 'build_cache_key' );
		$execute_method = $this->get_method( 'execute_callback' );

		$cache_key = $key_method->invoke( $this->ability, 'Hello world', '', 'smart', 20 );
		$cached    = array(
			'suggestion'      => ' next',
			'confidence'      => 0.9,
			'cursor_position' => 11,
		);

		wp_cache_set( $cache_key, $cached, 'ai-type-ahead', 45 );

		$result = $execute_method->invoke(
			$this->ability,
			array(
				'block_content' => 'Hello world',
			)
		);

		wp_cache_delete( $cache_key, 'ai-type-ahead' );

		$this->assertSame( $cached, $result );
	}

	/**
	 * Tests empty block content can still execute through cached path.
	 *
	 * @since 1.1.0
	 */
	public function test_execute_callback_allows_empty_block_content() {
		$key_method     = $this->get_method( 'build_cache_key' );
		$execute_method = $this->get_method( 'execute_callback' );

		$cache_key = $key_method->invoke( $this->ability, '', '', 'smart', 20 );
		$cached    = array(
			'suggestion'      => 'Start here',
			'confidence'      => 0.7,
			'cursor_position' => 0,
		);

		wp_cache_set( $cache_key, $cached, 'ai-type-ahead', 45 );

		$result = $execute_method->invoke(
			$this->ability,
			array(
				'block_content' => '',
			)
		);

		wp_cache_delete( $cache_key, 'ai-type-ahead' );

		$this->assertIsArray( $result );
		$this->assertSame( $cached, $result );
	}
}

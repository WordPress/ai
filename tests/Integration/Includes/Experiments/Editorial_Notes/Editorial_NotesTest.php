<?php
/**
 * Integration tests for the Editorial_Notes experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Editorial_Notes;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Editorial_Notes\Editorial_Notes;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Editorial_Notes test case.
 *
 * @since 0.4.0
 */
class Editorial_NotesTest extends WP_UnitTestCase {

	/**
	 * The experiment instance under test.
	 *
	 * @since 0.4.0
	 *
	 * @var \WordPress\AI\Experiments\Editorial_Notes\Editorial_Notes
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since 0.4.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_editorial-notes_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'editorial-notes' );
		$this->assertInstanceOf( Editorial_Notes::class, $experiment, 'Editorial_Notes experiment should be registered in the registry.' );

		$this->experiment = $experiment;
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.4.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_editorial-notes_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Experiment registration
	// -------------------------------------------------------------------------

	/**
	 * Tests that the experiment is registered correctly.
	 *
	 * @since 0.4.0
	 */
	public function test_experiment_registration() {
		$this->assertEquals( 'editorial-notes', $this->experiment->get_id() );
		$this->assertEquals( 'Editorial Notes', $this->experiment->get_label() );
		$this->assertTrue( $this->experiment->is_enabled() );
	}

	// -------------------------------------------------------------------------
	// Hook and meta registration
	// -------------------------------------------------------------------------

	/**
	 * Tests that the required hooks are registered after the experiment initialises.
	 *
	 * @since 0.4.0
	 */
	public function test_hooks_are_registered() {
		$this->assertNotFalse(
			has_filter( 'rest_pre_insert_comment', array( $this->experiment, 'maybe_set_ai_author' ) ),
			'rest_pre_insert_comment filter should be registered'
		);
	}

	/**
	 * Tests that register_abilities() registers the ai/editorial-notes ability.
	 *
	 * @since 1.0.0
	 */
	public function test_register_abilities_registers_editorial_notes_ability() {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/editorial-notes' );
		$this->assertNotNull( $ability, 'ai/editorial-notes ability should be registered' );
	}

	/**
	 * Tests that the ai_note comment meta is registered with show_in_rest.
	 *
	 * @since 0.4.0
	 */
	public function test_ai_note_comment_meta_is_registered() {
		$registered = get_registered_meta_keys( 'comment' );
		$this->assertArrayHasKey( 'ai_note', $registered, 'ai_note meta should be registered for comments' );
		$this->assertTrue( $registered['ai_note']['show_in_rest'], 'ai_note meta should have show_in_rest enabled' );
	}

	// -------------------------------------------------------------------------
	// maybe_set_ai_author()
	// -------------------------------------------------------------------------

	/**
	 * Tests that maybe_set_ai_author() overrides author fields when meta.ai_note is true.
	 *
	 * @since 0.4.0
	 */
	public function test_maybe_set_ai_author_overrides_author_when_ai_note_true() {
		$prepared = array(
			'comment_author'       => 'Test User',
			'comment_author_email' => 'test@example.com',
			'comment_author_url'   => 'https://example.com',
			'user_id'              => 99,
		);

		$request = new \WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'meta', array( 'ai_note' => true ) );

		$result = $this->experiment->maybe_set_ai_author( $prepared, $request );

		$this->assertIsArray( $result );
		$this->assertEquals( 'WordPress AI', $result['comment_author'] );
		$this->assertSame( '', $result['comment_author_email'] );
		$this->assertSame( '', $result['comment_author_url'] );
		$this->assertSame( 0, $result['user_id'] );
	}

	/**
	 * Tests that maybe_set_ai_author() leaves data unchanged when meta.ai_note is absent.
	 *
	 * @since 0.4.0
	 */
	public function test_maybe_set_ai_author_passes_through_without_ai_note() {
		$prepared = array(
			'comment_author'       => 'Test User',
			'comment_author_email' => 'test@example.com',
			'user_id'              => 99,
		);

		$request = new \WP_REST_Request( 'POST', '/wp/v2/comments' );

		$result = $this->experiment->maybe_set_ai_author( $prepared, $request );

		$this->assertEquals( 'Test User', $result['comment_author'] );
		$this->assertEquals( 'test@example.com', $result['comment_author_email'] );
		$this->assertEquals( 99, $result['user_id'] );
	}

	/**
	 * Tests that maybe_set_ai_author() passes through when meta.ai_note is false.
	 *
	 * @since 0.4.0
	 */
	public function test_maybe_set_ai_author_passes_through_when_ai_note_false() {
		$prepared = array(
			'comment_author' => 'Test User',
			'user_id'        => 99,
		);

		$request = new \WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'meta', array( 'ai_note' => false ) );

		$result = $this->experiment->maybe_set_ai_author( $prepared, $request );

		$this->assertEquals( 'Test User', $result['comment_author'] );
		$this->assertEquals( 99, $result['user_id'] );
	}

	/**
	 * Tests that maybe_set_ai_author() returns a WP_Error unchanged.
	 *
	 * @since 0.4.0
	 */
	public function test_maybe_set_ai_author_returns_wp_error_unchanged() {
		$error   = new \WP_Error( 'test_error', 'Test error message' );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'meta', array( 'ai_note' => true ) );

		$result = $this->experiment->maybe_set_ai_author( $error, $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'test_error', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// enqueue_assets()
	// -------------------------------------------------------------------------

	/**
	 * Tests that enqueue_assets() runs without error and attempts to enqueue the script.
	 *
	 * @since 1.0.0
	 */
	public function test_enqueue_assets_runs_without_error() {
		$this->experiment->enqueue_assets();

		$this->assertTrue( true, 'enqueue_assets() should run without throwing an exception' );
	}

	// -------------------------------------------------------------------------
	// register_meta() auth_callback
	// -------------------------------------------------------------------------

	/**
	 * Tests that the ai_note meta auth_callback returns true for a user with edit_posts.
	 *
	 * @since 1.0.0
	 */
	public function test_ai_note_meta_auth_callback_returns_true_for_editor() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$registered = get_registered_meta_keys( 'comment' );
		$callback   = $registered['ai_note']['auth_callback'] ?? null;

		$this->assertIsCallable( $callback, 'auth_callback should be callable' );
		$this->assertTrue( $callback(), 'auth_callback should return true for a user with edit_posts' );
	}
}

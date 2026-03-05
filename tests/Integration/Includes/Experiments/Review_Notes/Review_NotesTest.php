<?php
/**
 * Integration tests for the Review_Notes class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Review_Notes;

use WordPress\AI\Experiment_Registry;
use WordPress\AI\Experiment_Loader;
use WordPress\AI\Experiments\Review_Notes\Review_Notes;
use WP_UnitTestCase;

/**
 * Review_Notes test case.
 *
 * @since x.x.x
 */
class Review_NotesTest extends WP_UnitTestCase {

	/**
	 * The experiment instance under test.
	 *
	 * @since x.x.x
	 *
	 * @var Review_Notes
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_review-notes_enabled', true );

		$registry = new Experiment_Registry();
		$loader   = new Experiment_Loader( $registry );
		$loader->register_default_experiments();
		$loader->initialize_experiments();

		$experiment = $registry->get_experiment( 'review-notes' );
		$this->assertInstanceOf( Review_Notes::class, $experiment, 'Review_Notes experiment should be registered in the registry.' );

		$this->experiment = $experiment;
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_review-notes_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Experiment registration
	// -------------------------------------------------------------------------

	/**
	 * Tests that the experiment is registered correctly.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_registration() {
		$this->assertEquals( 'review-notes', $this->experiment->get_id() );
		$this->assertEquals( 'Review Notes', $this->experiment->get_label() );
		$this->assertTrue( $this->experiment->is_enabled() );
	}

	// -------------------------------------------------------------------------
	// Hook and meta registration
	// -------------------------------------------------------------------------

	/**
	 * Tests that the required hooks are registered after the experiment initialises.
	 *
	 * @since x.x.x
	 */
	public function test_hooks_are_registered() {
		$this->assertNotFalse(
			has_filter( 'rest_pre_insert_comment', array( $this->experiment, 'maybe_set_ai_author' ) ),
			'rest_pre_insert_comment filter should be registered'
		);
	}

	/**
	 * Tests that the ai_note comment meta is registered with show_in_rest.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	public function test_maybe_set_ai_author_returns_wp_error_unchanged() {
		$error   = new \WP_Error( 'test_error', 'Test error message' );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'meta', array( 'ai_note' => true ) );

		$result = $this->experiment->maybe_set_ai_author( $error, $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'test_error', $result->get_error_code() );
	}
}

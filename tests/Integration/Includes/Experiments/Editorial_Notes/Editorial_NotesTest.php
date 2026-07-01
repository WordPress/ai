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

	/**
	 * Tests that enqueue_assets() localizes the default minimum content length.
	 *
	 * @since 1.1.0
	 */
	public function test_enqueue_assets_localizes_default_min_content_length() {
		$this->experiment->enqueue_assets();

		$this->assertTrue( wp_script_is( 'ai_editorial_notes', 'enqueued' ) );
		$this->assertStringContainsString(
			'"minContentLength":"75"',
			(string) wp_scripts()->get_data( 'ai_editorial_notes', 'data' )
		);
	}

	/**
	 * Tests that enqueue_assets() localizes the filtered minimum content length.
	 *
	 * @since 1.1.0
	 */
	public function test_enqueue_assets_localizes_filtered_min_content_length() {
		$filter = static function () {
			return 250;
		};

		add_filter( 'wpai_min_content_length', $filter );

		$this->experiment->enqueue_assets();

		remove_filter( 'wpai_min_content_length', $filter );

		$this->assertStringContainsString(
			'"minContentLength":"250"',
			(string) wp_scripts()->get_data( 'ai_editorial_notes', 'data' )
		);
	}

	// -------------------------------------------------------------------------
	// maybe_set_ai_author()
	// -------------------------------------------------------------------------

	/**
	 * Tests that maybe_set_ai_author() overrides author fields when meta.ai_note is true,
	 * the comment is a Note, and the current user can edit posts.
	 *
	 * @since 0.4.0
	 */
	public function test_maybe_set_ai_author_overrides_author_when_ai_note_true() {
		$post_id = self::factory()->post->create();
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$prepared = array(
			'comment_author'       => 'Test User',
			'comment_author_email' => 'test@example.com',
			'comment_author_url'   => 'https://example.com',
			'comment_post_ID'      => $post_id,
			'comment_type'         => 'note',
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
	 * Tests that maybe_set_ai_author() returns a WP_Error when meta.ai_note is true
	 * but the current user cannot edit posts.
	 *
	 * Guards against identity spoofing: the author override and the ai_note meta
	 * auth_callback must enforce the same edit_posts capability, otherwise a
	 * low-privileged user could create a comment attributed to the "WordPress AI"
	 * identity (the comment is committed before the later meta save is rejected).
	 *
	 * @since 1.1.0
	 */
	public function test_maybe_set_ai_author_returns_error_for_unauthorized_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$prepared = array(
			'comment_author' => 'Subscriber',
			'user_id'        => $user_id,
		);

		$request = new \WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'meta', array( 'ai_note' => true ) );

		$result = $this->experiment->maybe_set_ai_author( $prepared, $request );

		$this->assertInstanceOf( \WP_Error::class, $result, 'A subscriber should be blocked from setting the AI author.' );
		$this->assertEquals( 'ai_note_forbidden', $result->get_error_code() );
	}

	/**
	 * Tests that maybe_set_ai_author() does not override the author for a non-Note
	 * comment, even when meta.ai_note is true and the user can edit posts.
	 *
	 * The AI identity is reserved for Notes; a regular comment must remain attributed
	 * to its actual author.
	 *
	 * @since 1.1.0
	 */
	public function test_maybe_set_ai_author_passes_through_non_note_comment() {
		$post_id = self::factory()->post->create();
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$prepared = array(
			'comment_author'  => 'Test User',
			'comment_post_ID' => $post_id,
			'comment_type'    => 'comment',
			'user_id'         => 99,
		);

		$request = new \WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'meta', array( 'ai_note' => true ) );

		$result = $this->experiment->maybe_set_ai_author( $prepared, $request );

		$this->assertIsArray( $result );
		$this->assertEquals( 'Test User', $result['comment_author'], 'A non-Note comment should keep its author.' );
		$this->assertEquals( 99, $result['user_id'], 'A non-Note comment should keep its user_id.' );
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
	// End-to-end REST behaviour
	// -------------------------------------------------------------------------

	/**
	 * Tests that a subscriber posting a comment with meta.ai_note = true is rejected
	 * and that no comment row is persisted.
	 *
	 * This is the regression guard for the spoofing report: because WordPress core
	 * commits the comment before the ai_note meta auth_callback runs, a fix that only
	 * gated the meta would still leave an orphaned, spoofed comment in the database.
	 * Aborting in the rest_pre_insert_comment filter must prevent the row entirely.
	 *
	 * @since 1.1.0
	 */
	public function test_subscriber_ai_note_request_is_rejected_and_persists_no_comment() {
		do_action( 'rest_api_init', rest_get_server() );

		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = self::factory()->post->create( array( 'post_author' => $author_id ) );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'content', 'This paragraph has accessibility issues and should be rewritten.' );
		$request->set_param( 'meta', array( 'ai_note' => true ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertTrue( $response->is_error(), 'The AI note request from a subscriber should fail.' );
		$this->assertEquals( 'ai_note_forbidden', $response->as_error()->get_error_code() );

		$comments = get_comments( array( 'post_id' => $post_id ) );
		$this->assertCount( 0, $comments, 'No comment row should be persisted for a rejected AI note request.' );
	}

	/**
	 * Tests that an editor posting a comment with meta.ai_note = true succeeds and the
	 * persisted comment is attributed to the AI identity rather than the editor.
	 *
	 * @since 1.1.0
	 */
	public function test_editor_ai_note_request_creates_ai_attributed_comment() {
		do_action( 'rest_api_init', rest_get_server() );

		$post_id   = self::factory()->post->create();
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'post', $post_id );
		$request->set_param( 'content', 'AI editorial suggestion.' );
		$request->set_param( 'type', 'note' );
		$request->set_param( 'meta', array( 'ai_note' => true ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertFalse( $response->is_error(), 'An editor should be allowed to create an AI note.' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data, 'The created Note should be returned with an ID.' );

		$comment = get_comment( $data['id'] );
		$this->assertInstanceOf( \WP_Comment::class, $comment, 'The AI note comment should be persisted.' );
		$this->assertEquals( 'note', $comment->comment_type, 'The persisted comment should be a Note.' );
		$this->assertEquals( 'WordPress AI', $comment->comment_author, 'The comment should be attributed to the AI identity.' );
		$this->assertEquals( 0, (int) $comment->user_id, 'The comment should not be linked to the editor account.' );
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
	 * Tests that the ai_note meta auth_callback checks whether the user can edit the
	 * comment's post.
	 *
	 * @since 1.1.0
	 */
	public function test_ai_note_meta_auth_callback_returns_true_when_user_can_edit_comment_post() {
		$user_id    = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id    = self::factory()->post->create( array( 'post_author' => $user_id ) );
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		wp_set_current_user( $user_id );

		$registered = get_registered_meta_keys( 'comment' );
		$callback   = $registered['ai_note']['auth_callback'] ?? null;

		$this->assertIsCallable( $callback, 'auth_callback should be callable' );
		$this->assertTrue( $callback( true, 'ai_note', $comment_id ), 'auth_callback should return true when the user can edit the comment post.' );
	}

	/**
	 * Tests that the ai_note meta auth_callback returns false when the user cannot
	 * edit the comment's post.
	 *
	 * @since 1.1.0
	 */
	public function test_ai_note_meta_auth_callback_returns_false_when_user_cannot_edit_comment_post() {
		$author_id  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id    = self::factory()->post->create( array( 'post_author' => $author_id ) );
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		$other_author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $other_author_id );

		$registered = get_registered_meta_keys( 'comment' );
		$callback   = $registered['ai_note']['auth_callback'] ?? null;

		$this->assertIsCallable( $callback, 'auth_callback should be callable' );
		$this->assertFalse( $callback( true, 'ai_note', $comment_id ), 'auth_callback should return false when the user cannot edit the comment post.' );
	}
}

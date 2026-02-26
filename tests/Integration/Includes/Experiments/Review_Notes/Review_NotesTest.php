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
			has_action( 'deleted_comment', array( $this->experiment, 'clear_block_note_meta' ) ),
			'deleted_comment hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'trashed_comment', array( $this->experiment, 'clear_block_note_meta' ) ),
			'trashed_comment hook should be registered'
		);
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
		$this->assertEquals( 'AI Reviewer', $result['comment_author'] );
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

	// -------------------------------------------------------------------------
	// clear_block_note_meta()
	// -------------------------------------------------------------------------

	/**
	 * Tests that clear_block_note_meta() removes noteId from block metadata when a root note is deleted.
	 *
	 * @since x.x.x
	 */
	public function test_clear_block_note_meta_removes_note_id_for_deleted_note() {
		$post_id = $this->factory->post->create();

		$comment_id = $this->factory->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_type'    => 'note',
				'comment_parent'  => 0,
			)
		);

		// Set post content with a block referencing that comment's ID.
		$post_content = sprintf(
			'<!-- wp:paragraph {"metadata":{"noteId":%d}} -->' . "\n" .
			'<p>Hello world.</p>' . "\n" .
			'<!-- /wp:paragraph -->',
			$comment_id
		);
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $post_content ) );

		$comment = get_comment( $comment_id );
		$this->assertInstanceOf( \WP_Comment::class, $comment );

		$this->experiment->clear_block_note_meta( $comment_id, $comment );

		$updated_post = get_post( $post_id );
		$this->assertStringNotContainsString( '"noteId"', $updated_post->post_content );
	}

	/**
	 * Tests that clear_block_note_meta() does nothing for non-note comment types.
	 *
	 * @since x.x.x
	 */
	public function test_clear_block_note_meta_ignores_non_note_comment_types() {
		$post_id = $this->factory->post->create();

		$comment_id = $this->factory->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_type'    => 'comment',
				'comment_parent'  => 0,
			)
		);

		$original_content = sprintf(
			'<!-- wp:paragraph {"metadata":{"noteId":%d}} -->' . "\n" .
			'<p>Hello world.</p>' . "\n" .
			'<!-- /wp:paragraph -->',
			$comment_id
		);
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $original_content ) );

		$comment = get_comment( $comment_id );
		$this->experiment->clear_block_note_meta( $comment_id, $comment );

		$post = get_post( $post_id );
		$this->assertStringContainsString( '"noteId"', $post->post_content, 'Non-note comments should not trigger noteId removal' );
	}

	/**
	 * Tests that clear_block_note_meta() does nothing when a reply comment is deleted.
	 *
	 * @since x.x.x
	 */
	public function test_clear_block_note_meta_ignores_reply_comments() {
		$post_id = $this->factory->post->create();

		$root_id = $this->factory->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_type'    => 'note',
				'comment_parent'  => 0,
			)
		);

		$reply_id = $this->factory->comment->create(
			array(
				'comment_post_ID' => $post_id,
				'comment_type'    => 'note',
				'comment_parent'  => $root_id,
			)
		);

		$original_content = sprintf(
			'<!-- wp:paragraph {"metadata":{"noteId":%d}} -->' . "\n" .
			'<p>Hello world.</p>' . "\n" .
			'<!-- /wp:paragraph -->',
			$root_id
		);
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $original_content ) );

		$reply = get_comment( $reply_id );
		$this->experiment->clear_block_note_meta( $reply_id, $reply );

		$post = get_post( $post_id );
		$this->assertStringContainsString( '"noteId"', $post->post_content, 'Reply deletion should not clear the root noteId' );
	}

	/**
	 * Tests that clear_block_note_meta() does not error when the post does not exist.
	 *
	 * @since x.x.x
	 */
	public function test_clear_block_note_meta_handles_missing_post_gracefully() {
		// Create a comment against a non-existent post ID.
		$comment = new \WP_Comment( (object) array(
			'comment_ID'       => 1,
			'comment_post_ID'  => 999999,
			'comment_type'     => 'note',
			'comment_parent'   => '0',
			'comment_author'   => 'AI Reviewer',
			'comment_content'  => 'Test',
		) );

		// Should not throw — the missing-post guard exits silently.
		$this->experiment->clear_block_note_meta( 1, $comment );
		$this->assertTrue( true, 'No exception thrown for missing post' );
	}

	// -------------------------------------------------------------------------
	// clear_note_id_from_blocks() (private, tested via reflection)
	// -------------------------------------------------------------------------

	/**
	 * Returns the clear_note_id_from_blocks() method via reflection.
	 *
	 * @since x.x.x
	 *
	 * @return \ReflectionMethod
	 */
	private function get_clear_blocks_method(): \ReflectionMethod {
		$reflection = new \ReflectionClass( $this->experiment );
		$method     = $reflection->getMethod( 'clear_note_id_from_blocks' );
		$method->setAccessible( true );
		return $method;
	}

	/**
	 * Tests that clear_note_id_from_blocks() removes the noteId from a matching top-level block.
	 *
	 * @since x.x.x
	 */
	public function test_clear_note_id_from_blocks_removes_matching_note_id() {
		$method = $this->get_clear_blocks_method();

		$blocks = array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array( 'metadata' => array( 'noteId' => 42, 'other' => 'value' ) ),
				'innerBlocks'  => array(),
				'innerHTML'    => '<p>Text</p>',
				'innerContent' => array( '<p>Text</p>' ),
			),
		);

		$result = $method->invoke( $this->experiment, $blocks, 42 );

		$this->assertTrue( $result['changed'] );
		$this->assertArrayNotHasKey( 'noteId', $result['blocks'][0]['attrs']['metadata'] );
		// Other metadata keys should be preserved.
		$this->assertEquals( 'value', $result['blocks'][0]['attrs']['metadata']['other'] );
	}

	/**
	 * Tests that clear_note_id_from_blocks() removes the metadata key entirely when it becomes empty.
	 *
	 * @since x.x.x
	 */
	public function test_clear_note_id_from_blocks_removes_empty_metadata_key() {
		$method = $this->get_clear_blocks_method();

		$blocks = array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array( 'metadata' => array( 'noteId' => 7 ) ),
				'innerBlocks'  => array(),
				'innerHTML'    => '<p>Text</p>',
				'innerContent' => array( '<p>Text</p>' ),
			),
		);

		$result = $method->invoke( $this->experiment, $blocks, 7 );

		$this->assertTrue( $result['changed'] );
		$this->assertArrayNotHasKey( 'metadata', $result['blocks'][0]['attrs'] );
	}

	/**
	 * Tests that clear_note_id_from_blocks() recursively removes noteId from inner blocks.
	 *
	 * @since x.x.x
	 */
	public function test_clear_note_id_from_blocks_removes_note_id_from_inner_block() {
		$method = $this->get_clear_blocks_method();

		$blocks = array(
			array(
				'blockName'    => 'core/group',
				'attrs'        => array(),
				'innerBlocks'  => array(
					array(
						'blockName'    => 'core/paragraph',
						'attrs'        => array( 'metadata' => array( 'noteId' => 99 ) ),
						'innerBlocks'  => array(),
						'innerHTML'    => '<p>Inner text</p>',
						'innerContent' => array( '<p>Inner text</p>' ),
					),
				),
				'innerHTML'    => '<div></div>',
				'innerContent' => array( '<div>', null, '</div>' ),
			),
		);

		$result = $method->invoke( $this->experiment, $blocks, 99 );

		$this->assertTrue( $result['changed'] );
		$this->assertArrayNotHasKey( 'metadata', $result['blocks'][0]['innerBlocks'][0]['attrs'] );
	}

	/**
	 * Tests that clear_note_id_from_blocks() returns changed = false when no block matches.
	 *
	 * @since x.x.x
	 */
	public function test_clear_note_id_from_blocks_returns_unchanged_when_no_match() {
		$method = $this->get_clear_blocks_method();

		$blocks = array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array( 'metadata' => array( 'noteId' => 5 ) ),
				'innerBlocks'  => array(),
				'innerHTML'    => '<p>Text</p>',
				'innerContent' => array( '<p>Text</p>' ),
			),
		);

		// Search for a different noteId — no match expected.
		$result = $method->invoke( $this->experiment, $blocks, 999 );

		$this->assertFalse( $result['changed'] );
		$this->assertEquals( 5, $result['blocks'][0]['attrs']['metadata']['noteId'] );
	}
}

<?php
/**
 * Integration tests for the core/comments-query Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Comments
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Comments;

use WP_Ability;
use WP_UnitTestCase;
use WP_UnitTest_Factory;
use WordPress\AI\Abilities\Comments\Comments;
use WordPress\AI\Abilities\Show_In_Abilities;

/**
 * Comments ability test case.
 *
 * Covers the single-comment-by-ID lookup mode. Collection/query mode is added in a
 * follow-up ability-schema increment — see https://github.com/WordPress/ai/issues/1064.
 *
 * @since x.x.x
 */
class CommentsTest extends WP_UnitTestCase {

	/**
	 * Shared fixture IDs.
	 *
	 * @since x.x.x
	 * @var array<string, int>
	 */
	private static $fixture_ids = array();

	/**
	 * Set up shared test fixtures.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The WordPress unit test factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		self::$fixture_ids['editor']     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$fixture_ids['subscriber'] = $factory->user->create( array( 'role' => 'subscriber' ) );
		self::$fixture_ids['commenter']  = $factory->user->create( array( 'role' => 'subscriber' ) );

		self::$fixture_ids['published_post'] = $factory->post->create( array( 'post_status' => 'publish' ) );
		self::$fixture_ids['private_post']   = $factory->post->create( array( 'post_status' => 'private' ) );

		self::$fixture_ids['approved_comment'] = $factory->comment->create(
			array(
				'comment_post_ID'  => self::$fixture_ids['published_post'],
				'comment_approved' => '1',
				'comment_content'  => 'An approved, publicly readable comment.',
				'comment_author'   => 'Jane Commenter',
			)
		);

		self::$fixture_ids['held_comment'] = $factory->comment->create(
			array(
				'comment_post_ID'  => self::$fixture_ids['published_post'],
				'comment_approved' => '0',
				'comment_content'  => 'Awaiting moderation.',
			)
		);

		self::$fixture_ids['own_held_comment'] = $factory->comment->create(
			array(
				'comment_post_ID'  => self::$fixture_ids['published_post'],
				'comment_approved' => '0',
				'user_id'          => self::$fixture_ids['commenter'],
				'comment_content'  => 'My own comment, awaiting moderation.',
			)
		);

		self::$fixture_ids['private_post_comment'] = $factory->comment->create(
			array(
				'comment_post_ID'  => self::$fixture_ids['private_post'],
				'comment_approved' => '1',
				'comment_content'  => 'An approved comment on a post most users cannot read.',
			)
		);

		self::$fixture_ids['note'] = $factory->comment->create(
			array(
				'comment_post_ID'  => self::$fixture_ids['published_post'],
				'comment_approved' => '1',
				'comment_type'     => 'note',
				'comment_content'  => 'An internal editorial note.',
			)
		);
	}

	/**
	 * Tear down shared test fixtures.
	 *
	 * @since x.x.x
	 */
	public static function wpTearDownAfterClass(): void {
		foreach ( array( 'approved_comment', 'held_comment', 'own_held_comment', 'private_post_comment', 'note' ) as $key ) {
			wp_delete_comment( self::$fixture_ids[ $key ], true );
		}

		foreach ( array( 'published_post', 'private_post' ) as $key ) {
			wp_delete_post( self::$fixture_ids[ $key ], true );
		}

		foreach ( array( 'editor', 'subscriber', 'commenter' ) as $key ) {
			wp_delete_user( self::$fixture_ids[ $key ] );
		}

		self::$fixture_ids = array();
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		// Mark the curated core post types (post, page) as exposed to abilities.
		( new Show_In_Abilities() )->register();

		$this->ensure_ability_category( 'content' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		if ( wp_has_ability( 'core/comments-query' ) ) {
			wp_unregister_ability( 'core/comments-query' );
		}

		$object = get_post_type_object( 'post' );
		if ( $object ) {
			unset( $object->show_in_abilities );
		}

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Registers an ability category inside a faked init action, unless it already exists.
	 *
	 * @since x.x.x
	 *
	 * @param string $slug The category slug.
	 */
	private function ensure_ability_category( string $slug ): void {
		if ( wp_has_ability_category( $slug ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability_category(
				$slug,
				array(
					'label'       => ucfirst( $slug ),
					'description' => ucfirst( $slug ) . '.',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers the plugin's core/comments-query ability inside a faked init action.
	 *
	 * @since x.x.x
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Comments() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * The ability is registered in the `content` category and flagged read-only.
	 *
	 * @since x.x.x
	 */
	public function test_core_comments_ability_is_registered(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/comments-query' );

		$this->assertInstanceOf( WP_Ability::class, $ability, 'The comments ability should be registered.' );
		$this->assertSame( 'content', $ability->get_category(), 'The comments ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The comments ability should be exposed over REST.' );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertTrue( $annotations['readonly'], 'The comments ability should be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'The comments ability should not be marked destructive.' );
		$this->assertTrue( $annotations['idempotent'], 'The comments ability should be marked idempotent.' );
	}

	/**
	 * An approved comment on a readable post is visible to a logged-out visitor.
	 *
	 * @since x.x.x
	 */
	public function test_approved_comment_readable_when_logged_out(): void {
		$this->register_ability();

		$result = wp_get_ability( 'core/comments-query' )->execute(
			array( 'id' => self::$fixture_ids['approved_comment'] )
		);

		$this->assertIsArray( $result, 'An approved comment on a readable post should be readable while logged out.' );
		$this->assertSame( self::$fixture_ids['approved_comment'], $result['id'] );
		$this->assertSame( 'approved', $result['status'] );
	}

	/**
	 * An unapproved comment is denied to a subscriber and allowed to an editor.
	 *
	 * @since x.x.x
	 */
	public function test_held_comment_denied_to_subscriber_allowed_to_editor(): void {
		$this->register_ability();
		$ability = wp_get_ability( 'core/comments-query' );

		wp_set_current_user( self::$fixture_ids['subscriber'] );
		$denied = $ability->execute( array( 'id' => self::$fixture_ids['held_comment'] ) );
		$this->assertWPError( $denied, 'A subscriber should not be able to read an unapproved comment that is not their own.' );

		wp_set_current_user( self::$fixture_ids['editor'] );
		$allowed = $ability->execute( array( 'id' => self::$fixture_ids['held_comment'] ) );
		$this->assertIsArray( $allowed, 'An editor (who can moderate comments) should be able to read an unapproved comment.' );
		$this->assertSame( 'hold', $allowed['status'] );
	}

	/**
	 * A logged-out visitor cannot read an unapproved comment.
	 *
	 * @since x.x.x
	 */
	public function test_held_comment_denied_when_logged_out(): void {
		$this->register_ability();

		$result = wp_get_ability( 'core/comments-query' )->execute( array( 'id' => self::$fixture_ids['held_comment'] ) );

		$this->assertWPError( $result, 'A logged-out visitor should not be able to read an unapproved comment.' );
	}

	/**
	 * A commenter can read their own unapproved comment.
	 *
	 * @since x.x.x
	 */
	public function test_own_held_comment_readable_by_its_author(): void {
		$this->register_ability();

		wp_set_current_user( self::$fixture_ids['commenter'] );
		$result = wp_get_ability( 'core/comments-query' )->execute(
			array( 'id' => self::$fixture_ids['own_held_comment'] )
		);

		$this->assertIsArray( $result, 'A commenter should be able to read their own comment while it awaits moderation.' );
	}

	/**
	 * Moderator-only fields are stripped for a non-moderator and present for a moderator.
	 *
	 * @since x.x.x
	 */
	public function test_moderator_only_fields_are_gated(): void {
		$this->register_ability();
		$ability = wp_get_ability( 'core/comments-query' );

		$input = array(
			'id'     => self::$fixture_ids['approved_comment'],
			'fields' => array( 'id', 'content_raw', 'author_email', 'author_ip' ),
		);

		$as_visitor = $ability->execute( $input );
		$this->assertIsArray( $as_visitor, 'The approved comment should still be readable with a moderator-only field requested.' );
		$this->assertArrayNotHasKey( 'content_raw', $as_visitor, 'A non-moderator should not receive raw comment content.' );
		$this->assertArrayNotHasKey( 'author_email', $as_visitor, 'A non-moderator should not receive the author email.' );
		$this->assertArrayNotHasKey( 'author_ip', $as_visitor, 'A non-moderator should not receive the author IP.' );

		wp_set_current_user( self::$fixture_ids['editor'] );
		$as_moderator = $ability->execute( $input );
		$this->assertIsArray( $as_moderator, 'An editor should be able to read the approved comment.' );
		$this->assertArrayHasKey( 'content_raw', $as_moderator, 'A moderator should receive raw comment content.' );
		$this->assertArrayHasKey( 'author_email', $as_moderator, 'A moderator should receive the author email.' );
		$this->assertArrayHasKey( 'author_ip', $as_moderator, 'A moderator should receive the author IP.' );
	}

	/**
	 * A comment on a post the current user cannot read is hidden, even when approved.
	 *
	 * @since x.x.x
	 */
	public function test_comment_on_unreadable_post_is_hidden(): void {
		$this->register_ability();

		$result = wp_get_ability( 'core/comments-query' )->execute(
			array( 'id' => self::$fixture_ids['private_post_comment'] )
		);

		$this->assertWPError( $result, 'A comment on a private post should not be readable by a logged-out visitor, even though it is approved.' );

		wp_set_current_user( self::$fixture_ids['subscriber'] );
		$result = wp_get_ability( 'core/comments-query' )->execute(
			array( 'id' => self::$fixture_ids['private_post_comment'] )
		);

		$this->assertWPError( $result, 'A subscriber who cannot read private posts should not be able to read a comment on one.' );

		wp_set_current_user( self::$fixture_ids['editor'] );
		$result = wp_get_ability( 'core/comments-query' )->execute(
			array( 'id' => self::$fixture_ids['private_post_comment'] )
		);

		$this->assertIsArray( $result, 'An editor who can read private posts should be able to read a comment on one.' );
	}

	/**
	 * A comment on a post type not exposed to abilities is hidden even from a moderator.
	 *
	 * @since x.x.x
	 */
	public function test_comment_on_unexposed_post_type_is_hidden(): void {
		$object = get_post_type_object( 'post' );
		unset( $object->show_in_abilities );

		$this->register_ability();

		wp_set_current_user( self::$fixture_ids['editor'] );
		$result = wp_get_ability( 'core/comments-query' )->execute(
			array( 'id' => self::$fixture_ids['approved_comment'] )
		);

		$this->assertWPError( $result, 'A comment on a post type not exposed via show_in_abilities should not be readable, even by a moderator.' );
	}

	/**
	 * An internal editorial note requires edit access, even when marked approved.
	 *
	 * @since x.x.x
	 */
	public function test_note_requires_edit_access(): void {
		$this->register_ability();
		$ability = wp_get_ability( 'core/comments-query' );

		$denied = $ability->execute( array( 'id' => self::$fixture_ids['note'] ) );
		$this->assertWPError( $denied, 'A logged-out visitor should not be able to read an internal editorial note.' );

		wp_set_current_user( self::$fixture_ids['subscriber'] );
		$denied = $ability->execute( array( 'id' => self::$fixture_ids['note'] ) );
		$this->assertWPError( $denied, 'A subscriber should not be able to read an internal editorial note.' );

		wp_set_current_user( self::$fixture_ids['editor'] );
		$allowed = $ability->execute( array( 'id' => self::$fixture_ids['note'] ) );
		$this->assertIsArray( $allowed, 'An editor should be able to read an internal editorial note.' );
	}

	/**
	 * An unknown comment ID returns a not-found error.
	 *
	 * @since x.x.x
	 */
	public function test_unknown_comment_id_returns_not_found(): void {
		$this->register_ability();

		wp_set_current_user( self::$fixture_ids['editor'] );
		$result = wp_get_ability( 'core/comments-query' )->execute( array( 'id' => 999999999 ) );

		$this->assertWPError( $result, 'An unknown comment ID should return an error.' );
	}

	/**
	 * Omitted fields return a lean default shape.
	 *
	 * @since x.x.x
	 */
	public function test_omitted_fields_return_lean_defaults(): void {
		$this->register_ability();

		$result = wp_get_ability( 'core/comments-query' )->execute(
			array( 'id' => self::$fixture_ids['approved_comment'] )
		);

		$this->assertIsArray( $result, 'An approved comment lookup should return an array.' );
		$this->assertSame(
			array( 'id', 'post', 'parent', 'author', 'date', 'content_rendered', 'status', 'type', 'link' ),
			array_keys( $result ),
			'Omitted fields should return the lean default field set.'
		);
		$this->assertArrayNotHasKey( 'author_email', $result, 'Default fields should not include moderator-only fields.' );
	}
}

<?php
/**
 * Integration tests for the content write abilities provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Content\Content;
use WordPress\AI\Abilities\Show_In_Abilities;

/**
 * Content write ability test case.
 *
 * The abilities have no native implementation: every write goes through the posts
 * endpoint. These tests therefore assert both halves of that arrangement — the mapping
 * this plugin owns, and the controller behaviour it inherits and must not swallow.
 *
 * @since x.x.x
 */
class Content_WriteTest extends WP_UnitTestCase {

	/**
	 * Shared user IDs keyed by role.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, int>
	 */
	private static $user_ids = array();

	/**
	 * Creates shared users for the write ability tests.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_UnitTest_Factory $factory The unit test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$user_ids = array(
			'administrator'    => $factory->user->create( array( 'role' => 'administrator' ) ),
			'editor'           => $factory->user->create( array( 'role' => 'editor' ) ),
			'subscriber'       => $factory->user->create( array( 'role' => 'subscriber' ) ),
			'contributor'      => $factory->user->create( array( 'role' => 'contributor' ) ),
			'author'           => $factory->user->create( array( 'role' => 'author' ) ),
			'author_secondary' => $factory->user->create( array( 'role' => 'author' ) ),
		);
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

		foreach ( array( 'content', 'site', 'user' ) as $category ) {
			$this->ensure_ability_category( $category );
		}
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
	 * The three write abilities are registered alongside the read ability.
	 *
	 * @since x.x.x
	 */
	public function test_registers_the_write_abilities(): void {
		$this->register_abilities();

		foreach ( array( 'core/content-create', 'core/content-update', 'core/content-delete' ) as $name ) {
			$ability = wp_get_ability( $name );

			$this->assertNotNull( $ability, sprintf( '%s should be registered.', $name ) );
			$this->assertSame( 'content', $ability->get_category(), sprintf( '%s should be in the content category.', $name ) );
		}
	}

	/**
	 * The write abilities are annotated as writes, not reads.
	 *
	 * MCP clients decide whether to ask the user before running a tool from these hints,
	 * so a write that claims to be read-only would be run unattended.
	 *
	 * @since x.x.x
	 */
	public function test_annotates_the_write_abilities_as_writes(): void {
		$this->register_abilities();

		$expected = array(
			'core/content-create' => array(
				'destructive' => false,
				'idempotent'  => false,
			),
			'core/content-update' => array(
				'destructive' => true,
				'idempotent'  => true,
			),
			'core/content-delete' => array(
				'destructive' => true,
				'idempotent'  => true,
			),
		);

		foreach ( $expected as $name => $hints ) {
			$meta        = wp_get_ability( $name )->get_meta();
			$annotations = $meta['annotations'];

			$this->assertFalse( $annotations['readonly'], sprintf( '%s should not be read-only.', $name ) );
			$this->assertFalse( $annotations['open_world'], sprintf( '%s should not be open-world.', $name ) );
			$this->assertSame( $hints['destructive'], $annotations['destructive'], sprintf( '%s destructive hint should match.', $name ) );
			$this->assertSame( $hints['idempotent'], $annotations['idempotent'], sprintf( '%s idempotent hint should match.', $name ) );
		}
	}

	/**
	 * Creating a post writes it and reports it.
	 *
	 * @since x.x.x
	 */
	public function test_create_writes_the_post(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Written by an ability',
				'content'   => 'Body written by an ability.',
				'status'    => 'publish',
				'fields'    => array( 'id', 'post_type', 'status', 'title_raw', 'content_raw' ),
			)
		);

		$this->assertNotWPError( $result, 'Creating a post should succeed for an administrator.' );
		$this->assertSame( 'post', $result['post_type'], 'The created post should be of the requested post type.' );
		$this->assertSame( 'publish', $result['status'], 'The created post should carry the requested status.' );
		$this->assertSame( 'Written by an ability', $result['title_raw'], 'The created post should carry the requested title.' );

		$post = get_post( $result['id'] );

		$this->assertNotNull( $post, 'The created post should exist in the database.' );
		$this->assertSame( 'Written by an ability', $post->post_title, 'The stored title should match the input.' );
		$this->assertSame( 'publish', $post->post_status, 'The stored status should match the input.' );
	}

	/**
	 * Creating a post reports only the requested fields.
	 *
	 * @since x.x.x
	 */
	public function test_create_reports_only_the_requested_fields(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Field projection',
				'fields'    => array( 'id', 'slug' ),
			)
		);

		$this->assertNotWPError( $result, 'Creating a post should succeed.' );
		$this->assertSame( array( 'id', 'slug' ), array_keys( $result ), 'Only the requested fields should be reported.' );
	}

	/**
	 * A created post defaults to the draft status the endpoint defaults to.
	 *
	 * @since x.x.x
	 */
	public function test_create_defaults_to_a_draft(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Unspecified status',
				'fields'    => array( 'id', 'status' ),
			)
		);

		$this->assertNotWPError( $result, 'Creating a post should succeed.' );
		$this->assertSame( 'draft', $result['status'], 'A post created without a status should be a draft.' );
	}

	/**
	 * Creating a post in a hierarchical post type keeps the parent.
	 *
	 * @since x.x.x
	 */
	public function test_create_keeps_the_parent_for_a_hierarchical_post_type(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$parent = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'page',
				'title'     => 'Child page',
				'parent'    => $parent,
				'fields'    => array( 'id', 'post_type', 'parent' ),
			)
		);

		$this->assertNotWPError( $result, 'Creating a child page should succeed.' );
		$this->assertSame( 'page', $result['post_type'], 'The created post should be a page.' );
		$this->assertSame( $parent, $result['parent'], 'The created page should keep the requested parent.' );
	}

	/**
	 * A conflicting slug is made unique, as the endpoint makes it.
	 *
	 * @since x.x.x
	 */
	public function test_create_makes_a_conflicting_slug_unique(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		self::factory()->post->create(
			array(
				'post_name'   => 'taken-slug',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Slug conflict',
				'slug'      => 'taken-slug',
				'status'    => 'publish',
				'fields'    => array( 'id', 'slug' ),
			)
		);

		$this->assertNotWPError( $result, 'Creating a post with a taken slug should succeed.' );
		$this->assertNotSame( 'taken-slug', $result['slug'], 'A conflicting slug should be made unique.' );
		$this->assertStringStartsWith( 'taken-slug', $result['slug'], 'The unique slug should still derive from the requested one.' );
	}

	/**
	 * Creating in a post type that is not exposed to abilities is refused.
	 *
	 * @since x.x.x
	 */
	public function test_create_refuses_a_post_type_that_is_not_exposed(): void {
		register_post_type(
			'wpai_hidden_cpt',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor' ),
			)
		);

		try {
			wp_set_current_user( self::$user_ids['administrator'] );
			$this->register_abilities();

			$result = wp_get_ability( 'core/content-create' )->execute(
				array(
					'post_type' => 'wpai_hidden_cpt',
					'title'     => 'Should not be written',
				)
			);

			$this->assertWPError( $result, 'A post type that is not exposed to abilities should be refused.' );
		} finally {
			unregister_post_type( 'wpai_hidden_cpt' );
		}
	}

	/**
	 * A post type exposed to abilities but not to REST is still writable.
	 *
	 * The endpoint is the only implementation, so such a post type has no route to call
	 * until one is arranged. The route must not outlive the request that needed it.
	 *
	 * @since x.x.x
	 */
	public function test_create_writes_a_post_type_that_rest_does_not_expose(): void {
		register_post_type(
			'wpai_write_cpt',
			array(
				'public'            => true,
				'show_in_abilities' => true,
				'supports'          => array( 'title', 'editor' ),
			)
		);

		try {
			wp_set_current_user( self::$user_ids['administrator'] );
			$this->register_abilities();

			// The ability runs where no REST server has been built yet, as it does under
			// WP-CLI or in any request that is not a REST request.
			unset( $GLOBALS['wp_rest_server'] );

			$result = wp_get_ability( 'core/content-create' )->execute(
				array(
					'post_type' => 'wpai_write_cpt',
					'title'     => 'Written without a REST route',
					'fields'    => array( 'id', 'post_type' ),
				)
			);

			$this->assertNotWPError( $result, 'A post type that REST does not expose should still be writable.' );
			$this->assertSame( 'wpai_write_cpt', $result['post_type'], 'The post should be created in the requested post type.' );

			$this->assertArrayNotHasKey(
				'/wp/v2/wpai_write_cpt',
				rest_get_server()->get_routes(),
				'A post type that REST does not expose should have no route left after the ability ran.'
			);
		} finally {
			unregister_post_type( 'wpai_write_cpt' );
			unset( $GLOBALS['wp_rest_server'] );
		}
	}

	/**
	 * A user who cannot create posts is refused.
	 *
	 * @since x.x.x
	 */
	public function test_create_is_refused_for_a_user_who_cannot_create(): void {
		wp_set_current_user( self::$user_ids['subscriber'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Should not be written',
			)
		);

		$this->assertWPError( $result, 'A subscriber should not be able to create a post.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Creating without the capability should fail closed as a permission error.' );
	}

	/**
	 * A logged-out caller is refused.
	 *
	 * @since x.x.x
	 */
	public function test_create_is_refused_without_a_user(): void {
		wp_set_current_user( 0 );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Should not be written',
			)
		);

		$this->assertWPError( $result, 'An anonymous caller should not be able to create a post.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Creating anonymously should fail closed as a permission error.' );
	}

	/**
	 * The endpoint's refusal to assign another author is passed on.
	 *
	 * The ability's own gate lets an author through, so this is the controller's check
	 * doing the work the ability relies on it for.
	 *
	 * @since x.x.x
	 */
	public function test_create_passes_on_the_refusal_to_assign_another_author(): void {
		wp_set_current_user( self::$user_ids['author'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Assigned to someone else',
				'author'    => self::$user_ids['author_secondary'],
			)
		);

		$this->assertWPError( $result, 'An author should not be able to create a post for another user.' );
		$this->assertSame( 'rest_cannot_edit_others', $result->get_error_code(), 'The endpoint error should be passed on unchanged.' );
	}

	/**
	 * An input the endpoint rejects is reported as the endpoint's error.
	 *
	 * @since x.x.x
	 */
	public function test_create_passes_on_an_endpoint_validation_error(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-create' )->execute(
			array(
				'post_type' => 'post',
				'title'     => 'Bad status',
				'status'    => 'future',
				'date'      => 'not-a-date',
			)
		);

		$this->assertWPError( $result, 'An unparseable date should be refused.' );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code(), 'The endpoint validation error should be passed on unchanged.' );
	}

	/**
	 * Updating a post changes the fields the input names.
	 *
	 * @since x.x.x
	 */
	public function test_update_changes_the_named_fields(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Before',
				'post_content' => 'Body before.',
				'post_status'  => 'draft',
			)
		);

		$result = wp_get_ability( 'core/content-update' )->execute(
			array(
				'id'     => $post_id,
				'title'  => 'After',
				'status' => 'publish',
				'fields' => array( 'id', 'status', 'title_raw' ),
			)
		);

		$this->assertNotWPError( $result, 'Updating a post should succeed for an administrator.' );
		$this->assertSame( $post_id, $result['id'], 'The updated post should be the requested one.' );
		$this->assertSame( 'After', $result['title_raw'], 'The reported title should be the updated one.' );
		$this->assertSame( 'publish', $result['status'], 'The reported status should be the updated one.' );

		$this->assertSame( 'After', get_post( $post_id )->post_title, 'The stored title should be updated.' );
		$this->assertSame( 'publish', get_post( $post_id )->post_status, 'The stored status should be updated.' );
	}

	/**
	 * An update leaves the fields it does not name alone.
	 *
	 * Only the named fields are sent, so the endpoint has nothing to reset the rest to.
	 *
	 * @since x.x.x
	 */
	public function test_update_leaves_unnamed_fields_alone(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Keep the body',
				'post_content' => 'Body that must survive.',
				'post_excerpt' => 'Excerpt that must survive.',
				'post_status'  => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content-update' )->execute(
			array(
				'id'     => $post_id,
				'title'  => 'Only the title changes',
				'fields' => array( 'id', 'title_raw', 'content_raw', 'excerpt_raw' ),
			)
		);

		$this->assertNotWPError( $result, 'A partial update should succeed.' );
		$this->assertSame( 'Only the title changes', $result['title_raw'], 'The named field should change.' );
		$this->assertSame( 'Body that must survive.', $result['content_raw'], 'An unnamed field should be left alone.' );
		$this->assertSame( 'Excerpt that must survive.', $result['excerpt_raw'], 'An unnamed field should be left alone.' );
	}

	/**
	 * Updating a post that does not exist is refused.
	 *
	 * @since x.x.x
	 */
	public function test_update_refuses_a_missing_post(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-update' )->execute(
			array(
				'id'    => 999999,
				'title' => 'Nothing to update',
			)
		);

		$this->assertWPError( $result, 'Updating a missing post should be refused.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'A missing post should fail closed as a permission error.' );
	}

	/**
	 * Updating another user's post without the capability is refused.
	 *
	 * @since x.x.x
	 */
	public function test_update_is_refused_for_another_users_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['author_secondary'],
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( self::$user_ids['author'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-update' )->execute(
			array(
				'id'    => $post_id,
				'title' => 'Should not be written',
			)
		);

		$this->assertWPError( $result, 'An author should not be able to update another author\'s post.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Editing a post the caller cannot edit should fail closed.' );
	}

	/**
	 * Updating a post of a post type that is not exposed is refused.
	 *
	 * @since x.x.x
	 */
	public function test_update_refuses_a_post_type_that_is_not_exposed(): void {
		register_post_type(
			'wpai_hidden_cpt',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor' ),
			)
		);

		try {
			$post_id = self::factory()->post->create(
				array(
					'post_type'   => 'wpai_hidden_cpt',
					'post_status' => 'publish',
				)
			);

			wp_set_current_user( self::$user_ids['administrator'] );
			$this->register_abilities();

			$result = wp_get_ability( 'core/content-update' )->execute(
				array(
					'id'    => $post_id,
					'title' => 'Should not be written',
				)
			);

			$this->assertWPError( $result, 'A post of an unexposed post type should not be updatable.' );
		} finally {
			unregister_post_type( 'wpai_hidden_cpt' );
		}
	}

	/**
	 * Deleting a post moves it to the trash by default.
	 *
	 * @since x.x.x
	 */
	public function test_delete_trashes_by_default(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'core/content-delete' )->execute(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'status' ),
			)
		);

		$this->assertNotWPError( $result, 'Trashing a post should succeed for an administrator.' );
		$this->assertFalse( $result['deleted'], 'A trashed post should not be reported as permanently deleted.' );
		$this->assertSame( $post_id, $result['post']['id'], 'The trashed post should be the requested one.' );
		$this->assertSame( 'trash', $result['post']['status'], 'The reported status should be the one the delete left behind.' );

		$this->assertSame( 'trash', get_post( $post_id )->post_status, 'The stored post should be in the trash.' );
	}

	/**
	 * Forcing a delete removes the post permanently.
	 *
	 * @since x.x.x
	 */
	public function test_delete_force_removes_the_post(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Gone for good',
				'post_status' => 'publish',
			)
		);

		$result = wp_get_ability( 'core/content-delete' )->execute(
			array(
				'id'     => $post_id,
				'force'  => true,
				'fields' => array( 'id', 'status', 'title_raw' ),
			)
		);

		$this->assertNotWPError( $result, 'Force deleting a post should succeed for an administrator.' );
		$this->assertTrue( $result['deleted'], 'A forced delete should be reported as permanently deleted.' );
		$this->assertSame( $post_id, $result['post']['id'], 'The deleted post should be the requested one.' );
		$this->assertSame( 'Gone for good', $result['post']['title_raw'], 'The post should be reported as it was before the delete.' );

		$this->assertNull( get_post( $post_id ), 'The post should no longer exist.' );
	}

	/**
	 * Trashing a post that is already in the trash is reported.
	 *
	 * @since x.x.x
	 */
	public function test_delete_reports_an_already_trashed_post(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_trash_post( $post_id );

		$result = wp_get_ability( 'core/content-delete' )->execute( array( 'id' => $post_id ) );

		$this->assertWPError( $result, 'Trashing an already trashed post should be refused.' );
		$this->assertSame( 'rest_already_trashed', $result->get_error_code(), 'The endpoint error should be passed on unchanged.' );
	}

	/**
	 * A post type that does not support trashing is reported, not silently deleted.
	 *
	 * @since x.x.x
	 */
	public function test_delete_reports_when_trashing_is_not_supported(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		add_filter( 'rest_post_trashable', '__return_false' );

		try {
			$result = wp_get_ability( 'core/content-delete' )->execute( array( 'id' => $post_id ) );
		} finally {
			remove_filter( 'rest_post_trashable', '__return_false' );
		}

		$this->assertWPError( $result, 'Trashing should be refused when the post type does not support it.' );
		$this->assertSame( 'rest_trash_not_supported', $result->get_error_code(), 'The endpoint error should be passed on unchanged.' );
		$this->assertSame( 'publish', get_post( $post_id )->post_status, 'A refused trash should leave the post alone.' );
	}

	/**
	 * Deleting another user's post without the capability is refused.
	 *
	 * @since x.x.x
	 */
	public function test_delete_is_refused_for_another_users_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['author_secondary'],
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( self::$user_ids['author'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-delete' )->execute( array( 'id' => $post_id ) );

		$this->assertWPError( $result, 'An author should not be able to delete another author\'s post.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'Deleting a post the caller cannot delete should fail closed.' );
		$this->assertSame( 'publish', get_post( $post_id )->post_status, 'A refused delete should leave the post alone.' );
	}

	/**
	 * Deleting a post that does not exist is refused.
	 *
	 * @since x.x.x
	 */
	public function test_delete_refuses_a_missing_post(): void {
		wp_set_current_user( self::$user_ids['administrator'] );
		$this->register_abilities();

		$result = wp_get_ability( 'core/content-delete' )->execute( array( 'id' => 999999 ) );

		$this->assertWPError( $result, 'Deleting a missing post should be refused.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'A missing post should fail closed as a permission error.' );
	}

	/**
	 * Registers the plugin's content abilities inside a faked init action.
	 *
	 * @since x.x.x
	 */
	private function register_abilities(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Content() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers an ability category if it is not already registered.
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
}

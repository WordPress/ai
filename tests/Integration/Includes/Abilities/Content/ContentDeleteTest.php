<?php
/**
 * Integration tests for the core/content-delete Ability provided by the plugin.
 *
 * The cases mirror the delete tests of the WordPress core REST posts controller
 * test suite (`Tests_REST_Posts_Controller`), adapted to the ability's input and
 * output shapes.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

use WordPress\AI\Abilities\Content\Content;

/**
 * Content delete ability test case.
 *
 * @since x.x.x
 */
class ContentDeleteTest extends Content_Ability_TestCase {

	/**
	 * Deletes a post through the ability and returns the result.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function delete( array $input ) {
		return $this->execute_ability( 'core/content-delete', $input );
	}

	/**
	 * The ability is registered in the `content` category and flagged as an idempotent destructive write.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_content_delete_ability(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/content-delete' );

		$this->assertNotNull( $ability, 'The core/content-delete ability should be registered.' );
		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertTrue( $annotations['destructive'], 'Deleting a post is destructive.' );
		$this->assertTrue( $annotations['idempotent'], 'Repeating a deletion has no further effect.' );
		$this->assertFalse( $annotations['open_world'], 'The ability should be marked closed-world; it only writes to the local database.' );
	}

	/**
	 * The ability is not registered when no post types are exposed to it.
	 *
	 * @since x.x.x
	 */
	public function test_does_not_register_without_exposed_post_types(): void {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			get_post_type_object( $post_type )->show_in_abilities = false;
		}

		$this->register_ability();

		$this->assertFalse( wp_has_ability( 'core/content-delete' ), 'The delete ability should not register without any exposed post types.' );
	}

	/**
	 * The input schema requires an ID, accepts a force flag, a post type guard, and a field selection, and rejects unknown properties.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_requires_id_and_accepts_force(): void {
		$this->register_ability();

		$schema = wp_get_ability( 'core/content-delete' )->get_input_schema();

		$this->assertSame( 'object', $schema['type'], 'The input schema should describe an object.' );
		$this->assertSame( array( 'id' ), $schema['required'], 'Only the ID should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );
		$this->assertSame( array( 'id', 'post_type', 'force', 'fields' ), array_keys( $schema['properties'] ), 'The input should mirror the REST delete arguments plus the field selection.' );
		$this->assertSame( 'boolean', $schema['properties']['force']['type'], 'Force should be a boolean.' );
		$this->assertSame( array( 'post', 'page' ), $schema['properties']['post_type']['enum'], 'The post type guard should only accept exposed post types.' );
	}

	/**
	 * Unknown properties fail validation.
	 *
	 * @since x.x.x
	 */
	public function test_rejects_unknown_properties(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$post_id = self::factory()->post->create();

		$result = $this->delete(
			array(
				'id'    => $post_id,
				'title' => 'Not a delete field',
			)
		);

		$this->assertAbilityError( $result, 'ability_invalid_input', 'Unknown properties should fail validation.' );
		$this->assertSame( 'publish', get_post( $post_id )->post_status, 'Nothing should be deleted when validation fails.' );
	}

	/**
	 * The output schema describes either the trashed post or a deleted flag with the previous post.
	 *
	 * @since x.x.x
	 */
	public function test_output_schema_describes_trashed_and_deleted_responses(): void {
		$this->register_ability();

		$schema       = wp_get_ability( 'core/content-delete' )->get_output_schema();
		$query_schema = wp_get_ability( 'core/content-query' )->get_output_schema();

		$this->assertCount( 2, $schema['oneOf'], 'The output should have two shapes.' );
		$this->assertSame( $query_schema['oneOf'][0], $schema['oneOf'][0], 'The trashed post should have the same shape as a queried post.' );
		$this->assertSame( array( 'deleted', 'previous' ), $schema['oneOf'][1]['required'], 'A forced deletion should report the deleted flag and the previous post.' );
		$this->assertSame( $query_schema['oneOf'][0], $schema['oneOf'][1]['properties']['previous'], 'The previous post should have the same shape as a queried post.' );
	}

	/**
	 * A post is moved to the trash by default and returned with its new status.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$post_id = self::factory()->post->create( array( 'post_title' => 'Deleted post' ) );

		$result = $this->delete(
			array(
				'id'     => $post_id,
				'force'  => false,
				'fields' => array( 'id', 'status', 'title_raw' ),
			)
		);

		$this->assertIsArray( $result, 'Trashing a post should return the trashed post.' );
		$this->assertSame( $post_id, $result['id'], 'The trashed post should be returned.' );
		$this->assertSame( 'Deleted post', $result['title_raw'], 'The trashed post should keep its title.' );
		$this->assertSame( 'trash', $result['status'], 'The returned status should be trash.' );
		$this->assertSame( 'trash', get_post( $post_id )->post_status, 'The stored status should be trash.' );
	}

	/**
	 * Without a force flag the post is trashed rather than deleted.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_trashes_by_default(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$post_id = self::factory()->post->create();

		$result = $this->delete( array( 'id' => $post_id ) );

		$this->assertIsArray( $result, 'Trashing a post should return the trashed post.' );
		$this->assertSame( array( 'id', 'post_type', 'status', 'date', 'slug', 'title_rendered' ), array_keys( $result ), 'The default field set should match the query ability.' );
		$this->assertSame( 'trash', $result['status'], 'The post should be trashed.' );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ), 'The post should still exist.' );
	}

	/**
	 * A forced deletion removes the post and returns it under `previous`.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_skip_trash(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$post_id = self::factory()->post->create( array( 'post_title' => 'Deleted post' ) );

		$result = $this->delete(
			array(
				'id'     => $post_id,
				'force'  => true,
				'fields' => array( 'id', 'status', 'title_raw' ),
			)
		);

		$this->assertIsArray( $result, 'Deleting a post should return a result.' );
		$this->assertSame( array( 'deleted', 'previous' ), array_keys( $result ), 'A forced deletion should report the deleted flag and the previous post.' );
		$this->assertTrue( $result['deleted'], 'The post should be reported as deleted.' );
		$this->assertSame( $post_id, $result['previous']['id'], 'The previous post should be the deleted one.' );
		$this->assertSame( 'Deleted post', $result['previous']['title_raw'], 'The previous post should carry its values before deletion.' );
		$this->assertSame( 'publish', $result['previous']['status'], 'The previous post should carry its status before deletion.' );
		$this->assertNull( get_post( $post_id ), 'The post should no longer exist.' );
	}

	/**
	 * A forced deletion with an empty field projection still returns an object for the previous post.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_skip_trash_with_empty_projection(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$post_id = self::factory()->post->create();

		// Posts are not hierarchical, so the projection is empty.
		$result = $this->delete(
			array(
				'id'     => $post_id,
				'force'  => true,
				'fields' => array( 'parent' ),
			)
		);

		$this->assertIsArray( $result, 'Deleting a post should return a result.' );
		$this->assertTrue( $result['deleted'], 'The post should be reported as deleted.' );
		$this->assertEquals( (object) array(), $result['previous'], 'An empty projection should be an empty object, not a list.' );
	}

	/**
	 * Trashing an already trashed post is an error, while forcing still deletes it.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_already_trashed(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$post_id = self::factory()->post->create( array( 'post_title' => 'Deleted post' ) );

		$first = $this->delete( array( 'id' => $post_id ) );
		$this->assertIsArray( $first, 'The first deletion should trash the post.' );

		$second = $this->delete( array( 'id' => $post_id ) );
		$this->assertAbilityError( $second, 'content_already_trashed', 'Trashing a trashed post should be an error.' );
		$this->assertSame( 410, $second->get_error_data()['status'], 'An already trashed post should be reported as gone.' );

		$forced = $this->delete(
			array(
				'id'    => $post_id,
				'force' => true,
			)
		);
		$this->assertIsArray( $forced, 'A forced deletion of a trashed post should succeed.' );
		$this->assertTrue( $forced['deleted'], 'The trashed post should be deleted.' );
		$this->assertNull( get_post( $post_id ), 'The post should no longer exist.' );
	}

	/**
	 * A missing post is denied before execution, and a direct call reports it as not found.
	 *
	 * @since x.x.x
	 */
	public function test_delete_post_invalid_id(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->delete( array( 'id' => 999999 ) );
		$this->assertAbilityDenied( $result, 'A missing post should be denied before execution.' );

		$direct = ( new Content() )->execute_content_delete( array( 'id' => 999999 ) );
		$this->assertAbilityError( $direct, 'content_not_found', 'A direct call should still fail closed on a missing post.' );
	}

	/**
	 * A post type guard that does not match the post denies the deletion, like a mismatched REST route.
	 *
	 * @since x.x.x
	 */
	public function test_delete_post_invalid_post_type(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$mismatched = $this->delete(
			array(
				'id'        => $page_id,
				'post_type' => 'post',
			)
		);
		$this->assertAbilityDenied( $mismatched, 'A mismatched post type guard should deny the deletion.' );
		$this->assertSame( 'publish', get_post( $page_id )->post_status, 'The page should be untouched.' );

		$matching = $this->delete(
			array(
				'id'        => $page_id,
				'post_type' => 'page',
				'fields'    => array( 'id', 'status' ),
			)
		);
		$this->assertIsArray( $matching, 'A matching post type guard should allow the deletion.' );
		$this->assertSame( 'trash', $matching['status'], 'The page should be trashed.' );
	}

	/**
	 * A post from a post type not exposed to abilities cannot be deleted.
	 *
	 * @since x.x.x
	 */
	public function test_delete_post_for_unexposed_post_type_is_denied(): void {
		register_post_type(
			'wpai_hidden_cpt',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor' ),
			)
		);

		try {
			$this->login_as( 'administrator' );
			$this->register_ability();

			$post_id = self::factory()->post->create( array( 'post_type' => 'wpai_hidden_cpt' ) );

			$result = $this->delete( array( 'id' => $post_id ) );

			$this->assertAbilityDenied( $result, 'Posts from unexposed post types should be denied.' );
			$this->assertSame( 'publish', get_post( $post_id )->post_status, 'The post should be untouched.' );
		} finally {
			unregister_post_type( 'wpai_hidden_cpt' );
		}
	}

	/**
	 * Users who cannot delete the post are denied.
	 *
	 * @since x.x.x
	 */
	public function test_delete_post_without_permission(): void {
		$this->register_ability();

		$post_id = self::factory()->post->create( array( 'post_author' => self::$user_ids['editor'] ) );

		wp_set_current_user( 0 );
		$this->assertAbilityDenied( $this->delete( array( 'id' => $post_id ) ), 'A logged-out user should not delete posts.' );

		$this->login_as( 'subscriber' );
		$this->assertAbilityDenied( $this->delete( array( 'id' => $post_id ) ), 'A subscriber should not delete posts.' );

		$this->login_as( 'author' );
		$this->assertAbilityDenied( $this->delete( array( 'id' => $post_id ) ), "An author should not delete another user's post." );

		$this->assertSame( 'publish', get_post( $post_id )->post_status, 'Denied deletions should not write.' );
	}

	/**
	 * An author can delete their own draft.
	 *
	 * @since x.x.x
	 */
	public function test_author_can_delete_own_draft(): void {
		$author_id = $this->login_as( 'author' );
		$this->register_ability();

		$post_id = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'draft',
			)
		);

		$result = $this->delete(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'status' ),
			)
		);

		$this->assertIsArray( $result, 'An author should be able to delete their own draft.' );
		$this->assertSame( 'trash', $result['status'], 'The draft should be trashed.' );
	}

	/**
	 * Query-string style inputs are honored, as the DELETE transport delivers them.
	 *
	 * The Abilities REST run controller routes destructive idempotent abilities to the
	 * DELETE method, whose input arrives as strings.
	 *
	 * @since x.x.x
	 */
	public function test_string_inputs_are_honored(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$content  = new Content();
		$post_id  = self::factory()->post->create();
		$as_query = array(
			'id'     => (string) $post_id,
			'force'  => 'false',
			'fields' => 'id,status',
		);

		$this->assertTrue( $content->check_delete_permission( $as_query ), 'A string ID should resolve the post.' );

		$trashed = $content->execute_content_delete( $as_query );
		$this->assertIsArray( $trashed, 'A "false" force string should trash the post.' );
		$this->assertSame( array( 'id', 'status' ), array_keys( $trashed ), 'A CSV field list should be honored.' );
		$this->assertSame( 'trash', $trashed['status'], 'The post should be trashed.' );

		$deleted = $content->execute_content_delete(
			array(
				'id'    => (string) $post_id,
				'force' => 'true',
			)
		);
		$this->assertIsArray( $deleted, 'A "true" force string should delete the post.' );
		$this->assertTrue( $deleted['deleted'], 'The post should be deleted.' );
		$this->assertNull( get_post( $post_id ), 'The post should no longer exist.' );
	}

	/**
	 * A trashing that core refuses is reported as a failure.
	 *
	 * @since x.x.x
	 */
	public function test_delete_reports_a_refused_trash(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$post_id = self::factory()->post->create();

		add_filter( 'pre_trash_post', '__return_false' );
		try {
			$result = $this->delete( array( 'id' => $post_id ) );
		} finally {
			remove_filter( 'pre_trash_post', '__return_false' );
		}

		$this->assertAbilityError( $result, 'content_cannot_delete', 'A refused trash should be reported.' );
		$this->assertSame( 500, $result->get_error_data()['status'], 'A refused trash should be a server error.' );
		$this->assertSame( 'publish', get_post( $post_id )->post_status, 'The post should be untouched.' );
	}

	/**
	 * A deletion that core refuses is reported as a failure.
	 *
	 * @since x.x.x
	 */
	public function test_delete_reports_a_refused_deletion(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$post_id = self::factory()->post->create();

		add_filter( 'pre_delete_post', '__return_false' );
		try {
			$result = $this->delete(
				array(
					'id'    => $post_id,
					'force' => true,
				)
			);
		} finally {
			remove_filter( 'pre_delete_post', '__return_false' );
		}

		$this->assertAbilityError( $result, 'content_cannot_delete', 'A refused deletion should be reported.' );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ), 'The post should still exist.' );
	}

	/**
	 * A trashed post can still be read by ID through the query ability.
	 *
	 * @since x.x.x
	 */
	public function test_trashed_post_is_still_readable_by_id(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$post_id = self::factory()->post->create();

		$this->delete( array( 'id' => $post_id ) );

		$read = $this->execute_ability(
			'core/content-query',
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'status' ),
			)
		);

		$this->assertIsArray( $read, 'Reading a trashed post by ID should succeed for a user who can edit it.' );
		$this->assertSame( 'trash', $read['status'], 'The trashed post should report the trash status.' );
	}
}

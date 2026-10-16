<?php
/**
 * Integration tests for the core/content-delete Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Content
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Content;

/**
 * Content delete ability test case.
 *
 * @since x.x.x
 */
class ContentDeleteTest extends Content_Ability_TestCase {

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->register_ability();
	}

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
	 * The ability is registered as a closed-world, idempotent destructive write that takes an
	 * ID, an optional post type guard, a force flag, and a field selection, and returns the
	 * trashed or deleted post shaped like a queried one.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_content_delete_ability(): void {
		$ability      = wp_get_ability( 'core/content-delete' );
		$annotations  = $ability->get_meta_item( 'annotations', array() );
		$schema       = $ability->get_input_schema();
		$output       = $ability->get_output_schema();
		$query_schema = wp_get_ability( 'core/content-query' )->get_output_schema();

		$this->assertSame( 'content', $ability->get_category(), 'The registered ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );
		$this->assertTrue( $ability->get_meta_item( 'public', false ), 'The ability should be marked public.' );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertTrue( $annotations['destructive'], 'Deleting a post is destructive.' );
		$this->assertTrue( $annotations['idempotent'], 'Repeating a deletion has no further effect.' );
		$this->assertFalse( $annotations['open_world'], 'The ability only writes to the local database.' );
		$this->assertSame( array( 'id' ), $schema['required'], 'Only the ID should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );
		$this->assertSame( array( 'id', 'type', 'force', 'fields' ), array_keys( $schema['properties'] ), 'The input should take the ID, a post type guard, the force flag, and the field selection.' );
		$this->assertSame( wp_list_pluck( $query_schema['oneOf'][0]['properties'], 'type' ), wp_list_pluck( $output['properties'], 'type' ), 'The trashed or deleted post should have the same fields as a queried post.' );
	}

	/**
	 * A post is moved to the trash by default and returned with its new status.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item(): void {
		$this->login_as( 'editor' );

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
	 * A forced deletion removes the post and returns it as it was just before.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_skip_trash(): void {
		$this->login_as( 'editor' );

		$post_id = self::factory()->post->create( array( 'post_title' => 'Deleted post' ) );

		$result = $this->delete(
			array(
				'id'     => $post_id,
				'force'  => true,
				'fields' => array( 'id', 'status', 'title_raw' ),
			)
		);

		$this->assertIsArray( $result, 'Deleting a post should return the deleted post.' );
		$this->assertSame( array( 'id', 'status', 'title_raw' ), array_keys( $result ), 'A forced deletion should return the post with the requested fields.' );
		$this->assertSame( $post_id, $result['id'], 'The deleted post should be returned.' );
		$this->assertSame( 'Deleted post', $result['title_raw'], 'The deleted post should carry its values before deletion.' );
		$this->assertSame( 'publish', $result['status'], 'The deleted post should carry its status before deletion.' );
		$this->assertNull( get_post( $post_id ), 'The post should no longer exist.' );
	}

	/**
	 * A forced deletion returns the deleted post's ID when none of the requested fields apply to it.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_skip_trash_returns_its_id_when_requested_fields_do_not_apply(): void {
		$this->login_as( 'editor' );

		$post_id = self::factory()->post->create();

		// `parent` never applies to the non-hierarchical `post` type.
		$result = $this->delete(
			array(
				'id'     => $post_id,
				'force'  => true,
				'fields' => array( 'parent' ),
			)
		);

		$this->assertSame( array( 'id' => $post_id ), $result, 'A deleted post whose requested fields do not apply should return only its ID.' );
		$this->assertNull( get_post( $post_id ), 'The post should no longer exist.' );
	}

	/**
	 * Trashing an already trashed post is an error, while forcing still deletes it.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_already_trashed(): void {
		$this->login_as( 'editor' );

		$post_id = self::factory()->post->create( array( 'post_title' => 'Deleted post' ) );

		$first = $this->delete( array( 'id' => $post_id ) );
		$this->assertIsArray( $first, 'The first deletion should trash the post.' );

		$second = $this->delete( array( 'id' => $post_id ) );
		$this->assertAbilityError( $second, 'content_already_trashed', 'Trashing a trashed post should be an error.', 410 );

		$forced = $this->delete(
			array(
				'id'    => $post_id,
				'force' => true,
			)
		);
		$this->assertIsArray( $forced, 'A forced deletion of a trashed post should succeed.' );
		$this->assertSame( $post_id, $forced['id'], 'The trashed post should be deleted and returned.' );
		$this->assertSame( 'trash', $forced['status'], 'The deleted post should carry its status before deletion.' );
		$this->assertNull( get_post( $post_id ), 'The post should no longer exist.' );
	}

	/**
	 * An attachment cannot be trashed while the media trash is off.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_no_trash(): void {
		$this->login_as( 'editor' );
		get_post_type_object( 'attachment' )->show_in_abilities = true;

		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'test-image.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_excerpt'   => 'A sample caption',
			)
		);

		// Attempt trashing.
		$result = $this->delete( array( 'id' => $attachment_id ) );
		$this->assertAbilityError( $result, 'content_trash_not_supported', 'An attachment should not be trashed while the media trash is off.', 501 );

		$result = $this->delete(
			array(
				'id'    => $attachment_id,
				'force' => 'false',
			)
		);
		$this->assertAbilityError( $result, 'content_trash_not_supported', 'A false force should not trash the attachment either.', 501 );

		// Ensure the post still exists.
		$this->assertNotEmpty( get_post( $attachment_id ), 'The attachment should still exist.' );
	}

	/**
	 * A missing post is denied before execution, and a direct call reports it as not found.
	 *
	 * @since x.x.x
	 */
	public function test_delete_post_invalid_id(): void {
		$this->login_as( 'editor' );

		$result = $this->delete( array( 'id' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ) );
		$this->assertAbilityDenied( $result, 'A missing post should be denied before execution.' );

		$execute = $this->get_ability_callbacks( 'core/content-delete' )['execute_callback'];
		$direct  = $execute( array( 'id' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ) );
		$this->assertAbilityError( $direct, 'content_not_found', 'A direct call should still fail closed on a missing post.' );
	}

	/**
	 * A post type guard that does not match the post denies the deletion.
	 *
	 * @since x.x.x
	 */
	public function test_delete_post_invalid_post_type(): void {
		$this->login_as( 'editor' );

		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$mismatched = $this->delete(
			array(
				'id'   => $page_id,
				'type' => 'post',
			)
		);
		$this->assertAbilityDenied( $mismatched, 'A mismatched post type guard should deny the deletion.' );

		$matching = $this->delete(
			array(
				'id'     => $page_id,
				'type'   => 'page',
				'fields' => array( 'id', 'status' ),
			)
		);
		$this->assertIsArray( $matching, 'A matching post type guard should allow the deletion.' );
		$this->assertSame( 'trash', $matching['status'], 'The page should be trashed.' );
	}

	/**
	 * Users who cannot delete the post are denied.
	 *
	 * @since x.x.x
	 */
	public function test_delete_post_without_permission(): void {
		$post_id = self::factory()->post->create( array( 'post_author' => self::$user_ids['editor'] ) );

		wp_set_current_user( 0 );
		$this->assertAbilityDenied( $this->delete( array( 'id' => $post_id ) ), 'A logged-out user should not delete posts.' );

		$this->login_as( 'subscriber' );
		$this->assertAbilityDenied( $this->delete( array( 'id' => $post_id ) ), 'A subscriber should not delete posts.' );

		$this->login_as( 'author' );
		$this->assertAbilityDenied( $this->delete( array( 'id' => $post_id ) ), "An author should not delete another user's post." );
	}

	/**
	 * An author can delete their own draft.
	 *
	 * @since x.x.x
	 */
	public function test_author_can_delete_own_draft(): void {
		$author_id = $this->login_as( 'author' );

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
	 * The Abilities API serves destructive idempotent abilities over the DELETE method,
	 * whose input arrives as strings.
	 *
	 * @since x.x.x
	 */
	public function test_string_inputs_are_honored(): void {
		$this->login_as( 'editor' );

		$callbacks = $this->get_ability_callbacks( 'core/content-delete' );
		$post_id   = self::factory()->post->create();
		$as_query  = array(
			'id'     => (string) $post_id,
			'force'  => 'false',
			'fields' => 'id,status',
		);

		$this->assertTrue( $callbacks['permission_callback']( $as_query ), 'A string ID should resolve the post.' );

		$trashed = $callbacks['execute_callback']( $as_query );
		$this->assertIsArray( $trashed, 'A "false" force string should trash the post.' );
		$this->assertSame( array( 'id', 'status' ), array_keys( $trashed ), 'A CSV field list should be honored.' );
		$this->assertSame( 'trash', $trashed['status'], 'The post should be trashed.' );

		$deleted = $callbacks['execute_callback'](
			array(
				'id'    => (string) $post_id,
				'force' => 'true',
			)
		);
		$this->assertIsArray( $deleted, 'A "true" force string should delete the post.' );
		$this->assertSame( $post_id, $deleted['id'], 'The deleted post should be returned.' );
		$this->assertNull( get_post( $post_id ), 'The post should no longer exist.' );
	}

	/**
	 * Returns the filters that make core refuse to remove a post, with the force flag that reaches them.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: bool}> The filter that refuses, and whether to delete permanently.
	 */
	public function data_refused_deletions(): array {
		return array(
			'moving to the trash' => array( 'pre_trash_post', false ),
			'deleting for good'   => array( 'pre_delete_post', true ),
		);
	}

	/**
	 * A trashing or a deletion that core refuses is reported as a failure.
	 *
	 * @dataProvider data_refused_deletions
	 *
	 * @since x.x.x
	 *
	 * @param string $filter The filter that refuses the operation.
	 * @param bool   $force  Whether to delete the post permanently.
	 */
	public function test_delete_reports_a_refused_deletion( string $filter, bool $force ): void {
		$this->login_as( 'editor' );

		$post_id = self::factory()->post->create();

		add_filter( $filter, '__return_false' );
		$result = $this->delete(
			array(
				'id'    => $post_id,
				'force' => $force,
			)
		);

		$this->assertAbilityError( $result, 'content_cannot_delete', 'A refused deletion should be reported.', 500 );
		$this->assertSame( 'publish', get_post_status( $post_id ), 'The post should be untouched.' );
	}

	/**
	 * The execute callback checks the delete capability itself before removing anything.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_rechecks_the_delete_capability(): void {
		$this->login_as( 'subscriber' );

		$post_id = self::factory()->post->create( array( 'post_author' => self::$user_ids['editor'] ) );

		$execute = $this->get_ability_callbacks( 'core/content-delete' )['execute_callback'];

		$trash = $execute( array( 'id' => $post_id ) );
		$this->assertAbilityError( $trash, 'content_cannot_delete', 'A direct call should not trash a post the user cannot delete.', 403 );

		$delete = $execute(
			array(
				'id'    => $post_id,
				'force' => true,
			)
		);
		$this->assertAbilityError( $delete, 'content_cannot_delete', 'A direct call should not delete a post the user cannot delete.' );
		$this->assertSame( 'publish', get_post( $post_id )->post_status, 'The post should be untouched.' );
	}

	/**
	 * A user who can delete a post but not edit it is refused raw fields before anything is
	 * deleted, as the query ability refuses them, and gets the read fields otherwise.
	 *
	 * @since x.x.x
	 */
	public function test_delete_with_raw_fields_requires_edit_access(): void {
		$this->login_as( 'editor' );

		wp_get_current_user()->add_cap( 'edit_published_posts', false );

		$post_id = self::factory()->post->create( array( 'post_title' => 'Deleted post' ) );
		$this->assertFalse( current_user_can( 'edit_post', $post_id ), 'Precondition: the user cannot edit the post.' );
		$this->assertTrue( current_user_can( 'delete_post', $post_id ), 'Precondition: the user can delete the post.' );

		$refused = $this->delete(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'title_raw' ),
			)
		);

		$this->assertAbilityDenied( $refused, 'Raw fields should require edit access.' );

		$result = $this->delete(
			array(
				'id'     => $post_id,
				'fields' => array( 'id', 'title_rendered' ),
			)
		);

		$this->assertSame(
			array(
				'id'             => $post_id,
				'title_rendered' => 'Deleted post',
			),
			$result,
			'The read fields should be returned.'
		);
	}
}

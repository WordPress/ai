<?php
/**
 * Integration tests for the core/media-delete Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Media
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Media;

use MockAction;
use WP_Post;
use WP_REST_Request;
use WordPress\AI\Abilities\Media\Media;

/**
 * Media delete ability test case.
 *
 * Tests named like core's REST attachments controller tests port them to the ability. The
 * tests that trash an attachment need the `MEDIA_TRASH` constant, which the site defines.
 *
 * @since x.x.x
 */
class MediaDeleteTest extends Media_Ability_TestCase {

	/**
	 * Deletes an attachment through the ability and returns the result.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return mixed The ability result.
	 */
	private function delete( $input ) {
		return $this->execute_ability( 'core/media-delete', $input );
	}

	/**
	 * Skips the test unless the media trash is enabled.
	 *
	 * @since x.x.x
	 */
	private function require_media_trash(): void {
		if ( constant( 'MEDIA_TRASH' ) ) {
			return;
		}

		$this->markTestSkipped( 'Trashing attachments requires the MEDIA_TRASH constant.' );
	}

	/**
	 * Skips the test when the media trash is enabled.
	 *
	 * @since x.x.x
	 */
	private function require_no_media_trash(): void {
		if ( ! constant( 'MEDIA_TRASH' ) ) {
			return;
		}

		$this->markTestSkipped( 'This test requires the media trash to be off.' );
	}

	/**
	 * The ability is registered in the `content` category as a closed-world, idempotent
	 * destructive write that takes an ID, a force flag, and a field selection, and returns
	 * the trashed attachment or a deleted flag with the previous attachment.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_media_delete_ability(): void {
		$ability     = wp_get_ability( 'core/media-delete' );
		$annotations = $ability->get_meta_item( 'annotations', array() );
		$schema      = $ability->get_input_schema();
		$output      = $ability->get_output_schema();
		$query_types = wp_list_pluck( wp_get_ability( 'core/media-query' )->get_output_schema()['oneOf'][0]['properties'], 'type' );

		$this->assertSame( 'Media Delete', $ability->get_label(), 'The ability should use the expected label.' );
		$this->assertSame( 'content', $ability->get_category(), 'The ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertTrue( $annotations['destructive'], 'Deleting an attachment is destructive.' );
		$this->assertTrue( $annotations['idempotent'], 'Repeating a deletion has no further effect.' );
		$this->assertFalse( $annotations['open_world'], 'The ability only writes to the local database.' );
		$this->assertSame( array( 'id' ), $schema['required'], 'Only the ID should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );
		$this->assertSame( array( 'id', 'force', 'fields' ), array_keys( $schema['properties'] ), 'The input should take the ID, the force flag, and the field selection.' );

		[ $trashed, $deleted ] = $output['oneOf'];

		$this->assertFalse( $trashed['additionalProperties'], 'The trashed attachment should reject unknown properties.' );
		$this->assertSame( $query_types, wp_list_pluck( $trashed['properties'], 'type' ), 'The trashed attachment should have the same fields as a queried one.' );
		$this->assertSame( array( 'deleted', 'previous' ), $deleted['required'], 'A deletion should report the deleted flag and the previous attachment.' );
		$this->assertFalse( $deleted['additionalProperties'], 'A deletion should reject unknown properties.' );
		$this->assertSame( $query_types, wp_list_pluck( $deleted['properties']['previous']['properties'], 'type' ), 'The previous attachment should have the same fields as a queried one.' );
	}

	/**
	 * Deletes an attachment permanently.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item(): void {
		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment();

		$result = $this->delete(
			array(
				'id'    => $attachment_id,
				'force' => true,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
	}

	/**
	 * An attachment cannot be trashed while the media trash is off.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_no_trash(): void {
		$this->require_no_media_trash();

		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment();

		// Attempt trashing.
		$result = $this->delete( array( 'id' => $attachment_id ) );
		$this->assertErrorResponse( 'media_trash_not_supported', $result, 501 );

		$result = $this->delete(
			array(
				'id'    => $attachment_id,
				'force' => false,
			)
		);
		$this->assertErrorResponse( 'media_trash_not_supported', $result, 501 );

		// Ensure the post still exists.
		$post = get_post( $attachment_id );
		$this->assertNotEmpty( $post );
	}

	/**
	 * An author cannot delete another user's attachment.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_invalid_delete_permissions(): void {
		$this->login_as( 'author' );
		$attachment_id = $this->create_attachment( array( 'post_author' => self::$user_ids['editor'] ) );

		$this->assertErrorResponse( 'ability_invalid_permissions', $this->delete( array( 'id' => $attachment_id ) ) );
	}

	/**
	 * A forced deletion removes the attachment and its files, and returns it under
	 * `previous` with the requested fields.
	 *
	 * @since x.x.x
	 *
	 * @requires function imagejpeg
	 */
	public function test_delete_item_skip_trash(): void {
		$this->login_as( 'editor' );

		$uploaded = $this->execute_ability(
			'core/media-upload',
			array(
				'data'     => $this->encode_file( self::$test_file ),
				'filename' => 'canola.jpg',
				'title'    => 'Deleted attachment',
			)
		);
		$this->assertIsArray( $uploaded, 'Precondition: the upload should succeed.' );

		$attachment_id = $uploaded['id'];
		$file          = get_attached_file( $attachment_id );
		$sizes         = wp_get_attachment_metadata( $attachment_id )['sizes'];
		$this->assertNotEmpty( $sizes, 'Precondition: the upload should have sub-sizes.' );

		$result = $this->delete(
			array(
				'id'     => $attachment_id,
				'force'  => true,
				'fields' => array( 'id', 'title_raw', 'status' ),
			)
		);

		$this->assertSame(
			array(
				'deleted'  => true,
				'previous' => array(
					'id'        => $attachment_id,
					'status'    => 'inherit',
					'title_raw' => 'Deleted attachment',
				),
			),
			$result,
			'A forced deletion should return the deleted flag and the previous attachment.'
		);
		$this->assertNull( get_post( $attachment_id ), 'The attachment should no longer exist.' );
		$this->assertFileDoesNotExist( $file, 'The file should be deleted.' );
		foreach ( $sizes as $size ) {
			$this->assertFileDoesNotExist( path_join( dirname( $file ), $size['file'] ), 'The sub-size files should be deleted.' );
		}
	}

	/**
	 * With the media trash enabled, an attachment is trashed by default and returned with
	 * its new status.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_trash(): void {
		$this->require_media_trash();

		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment( array( 'post_title' => 'Trashed attachment' ) );

		$result = $this->delete(
			array(
				'id'     => $attachment_id,
				'fields' => array( 'id', 'status', 'title_raw' ),
			)
		);

		$this->assertIsArray( $result, 'Trashing an attachment should return it.' );
		$this->assertSame( 'trash', $result['status'], 'The returned status should be trash.' );
		$this->assertSame( 'Trashed attachment', $result['title_raw'], 'The trashed attachment should keep its title.' );
		$this->assertSame( 'trash', get_post( $attachment_id )->post_status, 'The stored status should be trash.' );
	}

	/**
	 * Trashing an attachment already in the trash is an error, while forcing still deletes it.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_already_trashed(): void {
		$this->require_media_trash();

		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment();

		$this->assertIsArray( $this->delete( array( 'id' => $attachment_id ) ), 'The first deletion should trash the attachment.' );

		$second = $this->delete( array( 'id' => $attachment_id ) );
		$this->assertErrorResponse( 'media_already_trashed', $second, 410, 'Trashing a trashed attachment should be an error.' );

		$forced = $this->delete(
			array(
				'id'    => $attachment_id,
				'force' => true,
			)
		);
		$this->assertTrue( $forced['deleted'], 'A forced deletion of a trashed attachment should succeed.' );
		$this->assertNull( get_post( $attachment_id ), 'The attachment should no longer exist.' );
	}

	/**
	 * A trashing that core refuses is reported as a failure.
	 *
	 * @since x.x.x
	 */
	public function test_delete_reports_a_refused_trash(): void {
		$this->require_media_trash();

		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment();

		add_filter( 'pre_trash_post', '__return_false' );

		$this->assertErrorResponse( 'media_cannot_delete', $this->delete( array( 'id' => $attachment_id ) ), 500, 'A refused trash should be a server error.' );
		$this->assertSame( 'inherit', get_post( $attachment_id )->post_status, 'The attachment should be untouched.' );
	}

	/**
	 * A deletion that core refuses is reported as a failure.
	 *
	 * @since x.x.x
	 */
	public function test_delete_reports_a_refused_deletion(): void {
		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment();

		add_filter( 'pre_delete_attachment', '__return_false' );

		$result = $this->delete(
			array(
				'id'    => $attachment_id,
				'force' => true,
			)
		);

		$this->assertErrorResponse( 'media_cannot_delete', $result, 500, 'A refused deletion should be a server error.' );
		$this->assertInstanceOf( WP_Post::class, get_post( $attachment_id ), 'The attachment should still exist.' );
	}

	/**
	 * A missing attachment, or a post that is not an attachment, is denied before execution,
	 * and a direct call reports the ID as invalid.
	 *
	 * @since x.x.x
	 */
	public function test_delete_post_invalid_id(): void {
		$this->login_as( 'editor' );
		$post_id = self::factory()->post->create();

		$this->assertErrorResponse( 'ability_invalid_permissions', $this->delete( array( 'id' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ) ), null, 'A missing attachment should be denied.' );
		$this->assertErrorResponse(
			'ability_invalid_permissions',
			$this->delete(
				array(
					'id'    => $post_id,
					'force' => true,
				)
			),
			null,
			'A post should be denied.'
		);
		$this->assertInstanceOf( WP_Post::class, get_post( $post_id ), 'The post should still exist.' );

		$direct = ( new Media() )->execute_media_delete( array( 'id' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ) );
		$this->assertErrorResponse( 'media_post_invalid_id', $direct, 404, 'A direct call should report the invalid ID.' );
	}

	/**
	 * Logged-out users and users who cannot delete the attachment are denied, and nothing is
	 * deleted.
	 *
	 * @since x.x.x
	 */
	public function test_delete_post_without_permission(): void {
		$attachment_id = $this->create_attachment( array( 'post_author' => self::$user_ids['editor'] ) );
		$input         = array(
			'id'    => $attachment_id,
			'force' => true,
		);

		$this->assertErrorResponse( 'ability_invalid_permissions', $this->delete( $input ), null, 'A logged-out user should be denied.' );

		foreach ( array( 'subscriber', 'contributor', 'author', 'uploader' ) as $role ) {
			$this->login_as( $role );
			$this->assertErrorResponse( 'ability_invalid_permissions', $this->delete( $input ), null, "A user with the {$role} role should be denied." );
		}

		$this->assertInstanceOf( WP_Post::class, get_post( $attachment_id ), 'Denied deletions should not delete.' );
	}

	/**
	 * An author can delete their own attachment.
	 *
	 * @since x.x.x
	 */
	public function test_author_can_delete_own_attachment(): void {
		$author_id     = $this->login_as( 'author' );
		$attachment_id = $this->create_attachment( array( 'post_author' => $author_id ) );

		$result = $this->delete(
			array(
				'id'    => $attachment_id,
				'force' => true,
			)
		);

		$this->assertTrue( $result['deleted'], 'An author should delete their own attachment.' );
		$this->assertNull( get_post( $attachment_id ), 'The attachment should no longer exist.' );
	}

	/**
	 * Query-string style inputs are honored, as the DELETE transport delivers them.
	 *
	 * The Abilities API serves destructive idempotent abilities over the DELETE method, whose
	 * input arrives as strings.
	 *
	 * @since x.x.x
	 */
	public function test_string_inputs_are_honored(): void {
		$this->require_no_media_trash();

		$this->login_as( 'editor' );

		$media         = new Media();
		$attachment_id = $this->create_attachment( array( 'post_title' => 'Deleted attachment' ) );

		$this->assertTrue( $media->delete_item_permissions_check( array( 'id' => (string) $attachment_id ) ), 'A string ID should resolve the attachment.' );

		$trashed = $media->execute_media_delete(
			array(
				'id'    => (string) $attachment_id,
				'force' => 'false',
			)
		);
		$this->assertErrorResponse( 'media_trash_not_supported', $trashed, 501, 'A "false" force string should not delete the attachment.' );

		$deleted = $media->execute_media_delete(
			array(
				'id'     => (string) $attachment_id,
				'force'  => 'true',
				'fields' => 'id,title_raw',
			)
		);
		$this->assertIsArray( $deleted, 'A "true" force string should delete the attachment.' );
		$this->assertTrue( $deleted['deleted'], 'The attachment should be deleted.' );
		$this->assertSame( array( 'id', 'title_raw' ), array_keys( $deleted['previous'] ), 'A CSV field list should be honored.' );
		$this->assertNull( get_post( $attachment_id ), 'The attachment should no longer exist.' );
	}

	/**
	 * The execute callback checks the delete capability itself before removing anything.
	 *
	 * @since x.x.x
	 */
	public function test_execute_callback_rechecks_the_delete_capability(): void {
		$this->login_as( 'subscriber' );
		$attachment_id = $this->create_attachment( array( 'post_author' => self::$user_ids['editor'] ) );

		$media = new Media();

		$this->assertErrorResponse( 'media_user_cannot_delete_post', $media->execute_media_delete( array( 'id' => $attachment_id ) ), 403, 'A direct call should not trash an attachment the user cannot delete.' );
		$this->assertErrorResponse(
			'media_user_cannot_delete_post',
			$media->execute_media_delete(
				array(
					'id'    => $attachment_id,
					'force' => true,
				)
			),
			403,
			'A direct call should not delete an attachment the user cannot delete.'
		);
		$this->assertInstanceOf( WP_Post::class, get_post( $attachment_id ), 'The attachment should still exist.' );
	}

	/**
	 * A user who can delete an attachment but not edit it still gets its raw fields, as a
	 * deletion is answered in the edit context.
	 *
	 * @since x.x.x
	 */
	public function test_delete_returns_raw_fields_to_a_user_who_cannot_edit(): void {
		$author_id     = $this->login_as( 'author' );
		$attachment_id = $this->create_attachment(
			array(
				'post_author' => $author_id,
				'post_title'  => 'Deleted attachment',
			)
		);

		$user = wp_get_current_user();
		$user->add_cap( 'edit_posts', false );
		// Flush capabilities, https://core.trac.wordpress.org/ticket/28374
		$user->get_role_caps();
		$user->update_user_level_from_caps();
		$this->assertFalse( current_user_can( 'edit_post', $attachment_id ), 'Precondition: the user cannot edit the attachment.' );

		$result = $this->delete(
			array(
				'id'     => $attachment_id,
				'force'  => true,
				'fields' => array( 'title_raw' ),
			)
		);

		$this->assertIsArray( $result, 'The attachment should be deleted.' );
		$this->assertSame( 'Deleted attachment', $result['previous']['title_raw'], 'The raw title should be returned.' );
	}

	/**
	 * A deletion fires the delete hooks once.
	 *
	 * @since x.x.x
	 */
	public function test_delete_fires_the_delete_hooks_once(): void {
		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment();

		$delete_attachment = new MockAction();
		$deleted_post      = new MockAction();
		add_action( 'delete_attachment', array( $delete_attachment, 'action' ) );
		add_action( 'deleted_post', array( $deleted_post, 'action' ) );

		$this->delete(
			array(
				'id'    => $attachment_id,
				'force' => true,
			)
		);

		$this->assertSame( 1, $delete_attachment->get_call_count(), 'delete_attachment should fire once.' );
		$this->assertSame( array( $attachment_id ), $delete_attachment->get_args()[0], 'delete_attachment should report the attachment.' );
		$this->assertSame( 1, $deleted_post->get_call_count(), 'deleted_post should fire once.' );
	}

	/**
	 * A deletion returns what the media endpoint returns for the same input.
	 *
	 * @since x.x.x
	 */
	public function test_delete_matches_the_media_endpoint(): void {
		$this->login_as( 'editor' );

		$args       = array(
			'post_title' => 'Deleted attachment',
			'post_date'  => '2016-12-12 14:00:00',
		);
		$rest_id    = $this->create_attachment( $args );
		$ability_id = $this->create_attachment( $args );
		update_post_meta( $rest_id, '_wp_attachment_image_alt', 'An alt text' );
		update_post_meta( $ability_id, '_wp_attachment_image_alt', 'An alt text' );
		$fields = array( 'date', 'date_gmt', 'status', 'title_raw', 'title_rendered', 'author', 'description_raw', 'caption_raw', 'caption_rendered', 'alt_text', 'media_type', 'mime_type', 'post', 'source_url' );

		$request = new WP_REST_Request( 'DELETE', '/wp/v2/media/' . $rest_id );

		if ( ! constant( 'MEDIA_TRASH' ) ) {
			$this->assertErrorResponse( 'rest_trash_not_supported', rest_do_request( $request ), 501, 'The endpoint should not trash the attachment.' );
			$this->assertErrorResponse( 'media_trash_not_supported', $this->delete( array( 'id' => $ability_id ) ), 501, 'The ability should not trash the attachment.' );
		}

		$request->set_param( 'force', true );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), 'The endpoint should delete the attachment.' );

		$result = $this->delete(
			array(
				'id'     => $ability_id,
				'force'  => true,
				'fields' => $fields,
			)
		);
		$this->assertIsArray( $result, 'The ability should delete the attachment.' );
		$this->assertTrue( $response->get_data()['deleted'], 'The endpoint should report the deletion.' );
		$this->assertTrue( $result['deleted'], 'The ability should report the deletion.' );

		$previous = $result['previous'];
		unset( $previous['id'] );
		$expected = $this->flatten_rest_fields( $response->get_data()['previous'], $fields );
		ksort( $expected );
		ksort( $previous );
		$this->assertSame( $expected, $previous, 'The ability should return what the endpoint returns.' );
		$this->assertNull( get_post( $rest_id ), 'The endpoint should delete the attachment.' );
		$this->assertNull( get_post( $ability_id ), 'The ability should delete the attachment.' );
	}
}

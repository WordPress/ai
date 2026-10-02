<?php
/**
 * Integration tests for the core/media-update Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Media
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Media;

use MockAction;
use WP_REST_Request;
use WordPress\AI\Abilities\Media\Media;

/**
 * Media update ability test case.
 *
 * Tests named like core's REST attachments controller tests port them to the ability.
 *
 * @since x.x.x
 */
class MediaUpdateTest extends Media_Ability_TestCase {

	/**
	 * Updates an attachment through the ability and returns the result.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The ability input.
	 * @return mixed The ability result.
	 */
	private function update( $input ) {
		return $this->execute_ability( 'core/media-update', $input );
	}

	/**
	 * The ability is registered in the `content` category as a closed-world destructive write
	 * that is not idempotent, takes an ID and the attachment fields, and returns the
	 * attachment.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_media_update_ability(): void {
		$ability     = wp_get_ability( 'core/media-update' );
		$annotations = $ability->get_meta_item( 'annotations', array() );
		$schema      = $ability->get_input_schema();
		$output      = $ability->get_output_schema();
		$query_item  = wp_get_ability( 'core/media-query' )->get_output_schema()['oneOf'][0];

		$this->assertSame( 'Media Update', $ability->get_label(), 'The ability should use the expected label.' );
		$this->assertSame( 'content', $ability->get_category(), 'The ability should use the content category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertTrue( $annotations['destructive'], 'An update overwrites values that are not kept.' );
		$this->assertFalse( $annotations['idempotent'], 'The ability should be served over POST.' );
		$this->assertFalse( $annotations['open_world'], 'The ability only writes to the local database.' );
		$this->assertSame( array( 'id' ), $schema['required'], 'Only the ID should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );
		$this->assertSame(
			array( 'id', 'title', 'caption', 'description', 'alt_text', 'post', 'author', 'status', 'slug', 'date', 'date_gmt', 'fields' ),
			array_keys( $schema['properties'] ),
			'The input should take the ID, the attachment fields, and the field selection.'
		);
		$this->assertArrayNotHasKey( 'enum', $schema['properties']['status'], 'The current status of an attachment should be accepted.' );
		$this->assertFalse( $output['additionalProperties'], 'The output should reject unknown properties.' );
		$this->assertSame( wp_list_pluck( $query_item['properties'], 'type' ), wp_list_pluck( $output['properties'], 'type' ), 'An updated attachment should have the same fields as a queried one.' );
	}

	/**
	 * Updates the title, caption, description, and alt text.
	 *
	 * @since x.x.x
	 */
	public function test_update_item(): void {
		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment();

		$data = $this->update(
			array(
				'id'          => $attachment_id,
				'title'       => 'My title is very cool',
				'caption'     => 'This is a better caption.',
				'description' => 'Without a description, my attachment is descriptionless.',
				'alt_text'    => 'Alt text is stored outside post schema.',
				'fields'      => array( 'title_raw', 'caption_raw', 'description_raw', 'alt_text' ),
			)
		);

		$attachment = get_post( $data['id'] );
		$this->assertSame( 'My title is very cool', $data['title_raw'] );
		$this->assertSame( 'My title is very cool', $attachment->post_title );
		$this->assertSame( 'This is a better caption.', $data['caption_raw'] );
		$this->assertSame( 'This is a better caption.', $attachment->post_excerpt );
		$this->assertSame( 'Without a description, my attachment is descriptionless.', $data['description_raw'] );
		$this->assertSame( 'Without a description, my attachment is descriptionless.', $attachment->post_content );
		$this->assertSame( 'Alt text is stored outside post schema.', $data['alt_text'] );
		$this->assertSame( 'Alt text is stored outside post schema.', get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Moves an attachment to another parent post.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_parent(): void {
		$this->login_as( 'editor' );
		$original_parent = self::factory()->post->create( array() );
		$attachment_id   = $this->create_attachment( array(), $original_parent );

		$attachment = get_post( $attachment_id );
		$this->assertSame( $original_parent, $attachment->post_parent );

		$new_parent = self::factory()->post->create( array() );
		$this->update(
			array(
				'id'   => $attachment_id,
				'post' => $new_parent,
			)
		);

		$attachment = get_post( $attachment_id );
		$this->assertSame( $new_parent, $attachment->post_parent );
	}

	/**
	 * An author cannot update another user's attachment.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_invalid_permissions(): void {
		$this->login_as( 'author' );
		$attachment_id = $this->create_attachment();

		$result = $this->update(
			array(
				'id'      => $attachment_id,
				'caption' => 'This is a better caption.',
			)
		);

		$this->assertErrorResponse( 'ability_invalid_permissions', $result );
	}

	/**
	 * An attachment cannot be its own parent.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_invalid_post_type(): void {
		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment();

		$result = $this->update(
			array(
				'id'   => $attachment_id,
				'post' => $attachment_id,
			)
		);

		$this->assertErrorResponse( 'media_invalid_param', $result, 400 );
	}

	/**
	 * Keeping the `inherit` status of an attachment is valid.
	 *
	 * @ticket 40399
	 *
	 * @since x.x.x
	 */
	public function test_update_item_with_existing_inherit_status(): void {
		$this->login_as( 'editor' );
		$parent_id     = self::factory()->post->create( array() );
		$attachment_id = $this->create_attachment( array(), $parent_id );

		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'status' => 'inherit',
				'fields' => array( 'status' ),
			)
		);

		$this->assertNotWPError( $data );
		$this->assertSame( 'inherit', $data['status'] );
	}

	/**
	 * Changing an attachment to the internal `inherit` status is invalid.
	 *
	 * @ticket 40399
	 *
	 * @since x.x.x
	 */
	public function test_update_item_with_new_inherit_status(): void {
		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment( array( 'post_status' => 'private' ) );

		$result = $this->update(
			array(
				'id'     => $attachment_id,
				'status' => 'inherit',
			)
		);

		$this->assertErrorResponse( 'media_invalid_param', $result, 400 );
	}

	/**
	 * Fields left out of the input keep their stored values.
	 *
	 * @since x.x.x
	 */
	public function test_update_keeps_omitted_fields(): void {
		$this->login_as( 'editor' );
		$parent_id     = self::factory()->post->create();
		$attachment_id = $this->create_attachment(
			array(
				'post_title'   => 'Original title',
				'post_content' => 'Original description',
				'post_name'    => 'original-slug',
			),
			$parent_id
		);
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Original alt text' );

		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'title'  => 'New title',
				'fields' => array( 'title_raw', 'caption_raw', 'description_raw', 'alt_text', 'slug', 'post', 'author' ),
			)
		);

		$this->assertSame(
			array(
				'id'              => $attachment_id,
				'slug'            => 'original-slug',
				'title_raw'       => 'New title',
				'author'          => self::$user_ids['editor'],
				'description_raw' => 'Original description',
				'caption_raw'     => 'A sample caption',
				'alt_text'        => 'Original alt text',
				'post'            => $parent_id,
			),
			$data,
			'Only the title should change.'
		);
	}

	/**
	 * Text fields given as objects use their `raw` value: an empty raw caption or description
	 * clears it, while an empty raw title is ignored.
	 *
	 * @since x.x.x
	 */
	public function test_update_accepts_text_fields_as_raw_objects(): void {
		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment(
			array(
				'post_title'   => 'Original title',
				'post_content' => 'Original description',
			)
		);

		$data = $this->update(
			array(
				'id'          => $attachment_id,
				'title'       => array( 'raw' => '' ),
				'caption'     => array( 'raw' => '' ),
				'description' => array( 'raw' => '' ),
				'fields'      => array( 'title_raw', 'caption_raw', 'description_raw' ),
			)
		);

		$this->assertIsArray( $data, 'The update should succeed.' );
		$this->assertSame( 'Original title', $data['title_raw'], 'An empty raw title should be ignored.' );
		$this->assertSame( '', $data['caption_raw'], 'An empty raw caption should clear the caption.' );
		$this->assertSame( '', $data['description_raw'], 'An empty raw description should clear the description.' );
	}

	/**
	 * A missing attachment, or a post that is not an attachment, is denied like one the user
	 * cannot edit, and a direct call reports the ID as invalid.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_invalid_id(): void {
		$this->login_as( 'editor' );
		$post_id = self::factory()->post->create();

		$this->assertErrorResponse( 'ability_invalid_permissions', $this->update( array( 'id' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ) ), null, 'A missing attachment should be denied.' );
		$this->assertErrorResponse( 'ability_invalid_permissions', $this->update( array( 'id' => $post_id ) ), null, 'A post should be denied.' );

		$direct = ( new Media() )->execute_media_update( array( 'id' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER ) );
		$this->assertErrorResponse( 'media_post_invalid_id', $direct, 404, 'A direct call should report the invalid ID.' );
	}

	/**
	 * Logged-out users and users who cannot edit the attachment are denied, and nothing is
	 * written.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_without_permission(): void {
		$attachment_id = $this->create_attachment( array( 'post_title' => 'Original title' ) );
		$input         = array(
			'id'    => $attachment_id,
			'title' => 'New title',
		);

		$this->assertErrorResponse( 'ability_invalid_permissions', $this->update( $input ), null, 'A logged-out user should be denied.' );

		foreach ( array( 'subscriber', 'contributor', 'author', 'uploader' ) as $role ) {
			$this->login_as( $role );
			$this->assertErrorResponse( 'ability_invalid_permissions', $this->update( $input ), null, "A user with the {$role} role should be denied." );
		}

		$this->assertSame( 'Original title', get_post( $attachment_id )->post_title, 'Denied updates should not write.' );
	}

	/**
	 * An author can update their own attachment.
	 *
	 * @since x.x.x
	 */
	public function test_author_can_update_own_attachment(): void {
		$author_id     = $this->login_as( 'author' );
		$attachment_id = $this->create_attachment( array( 'post_author' => $author_id ) );

		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'title'  => 'My own title',
				'fields' => array( 'title_raw' ),
			)
		);

		$this->assertIsArray( $data, 'An author should update their own attachment.' );
		$this->assertSame( 'My own title', $data['title_raw'], 'The title should be stored.' );
	}

	/**
	 * Assigning another author requires the capability to edit other users' posts.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_other_author_without_permission(): void {
		$author_id     = $this->login_as( 'author' );
		$attachment_id = $this->create_attachment( array( 'post_author' => $author_id ) );

		$result = $this->update(
			array(
				'id'     => $attachment_id,
				'author' => self::$user_ids['editor'],
			)
		);
		$this->assertErrorResponse( 'media_cannot_edit_others', $result, 403, 'An author should not assign another author.' );
		$this->assertSame( $author_id, (int) get_post( $attachment_id )->post_author, 'The author should be unchanged.' );

		$this->login_as( 'editor' );
		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'author' => self::$user_ids['editor'],
				'fields' => array( 'author' ),
			)
		);
		$this->assertSame( self::$user_ids['editor'], $data['author'], 'An editor should assign another author.' );
	}

	/**
	 * An author that is not a user is an error, while an author of 0 is ignored.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_invalid_author(): void {
		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment();

		$result = $this->update(
			array(
				'id'     => $attachment_id,
				'author' => REST_TESTS_IMPOSSIBLY_HIGH_NUMBER,
			)
		);
		$this->assertErrorResponse( 'media_invalid_author', $result, 400, 'A missing user should not become the author.' );

		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'author' => 0,
				'fields' => array( 'author' ),
			)
		);
		$this->assertSame( self::$user_ids['editor'], $data['author'], 'An author of 0 should be ignored.' );
	}

	/**
	 * Making an attachment private requires the capability to publish posts.
	 *
	 * @since x.x.x
	 */
	public function test_update_private_requires_the_publish_capability(): void {
		$contributor_id = $this->login_as( 'contributor' );
		$attachment_id  = $this->create_attachment( array( 'post_author' => $contributor_id ) );

		$result = $this->update(
			array(
				'id'     => $attachment_id,
				'status' => 'private',
			)
		);
		$this->assertErrorResponse( 'media_cannot_publish', $result, 403, 'A contributor should not make an attachment private.' );
		$this->assertSame( 'inherit', get_post( $attachment_id )->post_status, 'The status should be unchanged.' );

		$this->login_as( 'editor' );
		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'status' => 'private',
				'fields' => array( 'status' ),
			)
		);
		$this->assertSame( 'private', $data['status'], 'An editor should make the attachment private.' );
	}

	/**
	 * A public status is stored as `inherit`, as all attachments that are not private are.
	 *
	 * @since x.x.x
	 */
	public function test_update_stores_a_public_status_as_inherit(): void {
		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment( array( 'post_status' => 'private' ) );

		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'status' => 'publish',
				'fields' => array( 'status' ),
			)
		);

		$this->assertSame( 'inherit', $data['status'], 'The returned status should be inherit.' );
		$this->assertSame( 'inherit', get_post( $attachment_id )->post_status, 'The stored status should be inherit.' );
	}

	/**
	 * A status that is not registered, or that is internal, is invalid.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_invalid_status(): void {
		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment();

		foreach ( array( 'not-a-status', 'auto-draft' ) as $status ) {
			$result = $this->update(
				array(
					'id'     => $attachment_id,
					'status' => $status,
				)
			);
			$this->assertErrorResponse( 'media_invalid_param', $result, 400, "The {$status} status should be invalid." );
			$this->assertSame( 'status is not one of publish, future, draft, pending, and private.', $result->get_error_message(), 'The error should list the valid statuses.' );
		}

		$this->assertSame( 'inherit', get_post( $attachment_id )->post_status, 'The status should be unchanged.' );
	}

	/**
	 * Dates with or without an offset are stored as local and GMT dates, and a null date dates
	 * the attachment now.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_date(): void {
		$this->login_as( 'editor' );
		update_option( 'timezone_string', 'America/New_York' );
		$attachment_id = $this->create_attachment( array( 'post_date' => '2010-01-01 00:00:00' ) );

		$cases = array(
			array( 'date' => '2016-12-12T14:00:00' ),
			array( 'date_gmt' => '2016-12-12T19:00:00' ),
			array( 'date' => '2016-12-12T18:00:00-01:00' ),
			array( 'date_gmt' => '2016-12-12T18:00:00-01:00' ),
		);

		foreach ( $cases as $input ) {
			$this->update( array_merge( array( 'id' => $attachment_id ), $input, array( 'fields' => array( 'id' ) ) ) );

			$post = get_post( $attachment_id );
			$this->assertSame( '2016-12-12 14:00:00', $post->post_date, 'The local date should be stored for ' . wp_json_encode( $input ) . '.' );
			$this->assertSame( '2016-12-12 19:00:00', $post->post_date_gmt, 'The GMT date should be stored for ' . wp_json_encode( $input ) . '.' );

			wp_update_post(
				array(
					'ID'            => $attachment_id,
					'post_date'     => '2010-01-01 00:00:00',
					'post_date_gmt' => '2010-01-01 05:00:00',
				)
			);
		}

		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'date'   => null,
				'fields' => array( 'date_gmt' ),
			)
		);
		$this->assertEqualsWithDelta( time(), strtotime( $data['date_gmt'] . 'Z' ), 60, 'A null date should date the attachment now.' );
	}

	/**
	 * A date that is not a date-time fails schema validation.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_invalid_date(): void {
		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment();

		$result = $this->update(
			array(
				'id'   => $attachment_id,
				'date' => '2010-60-01T02:00:00Z',
			)
		);

		$this->assertErrorResponse( 'ability_invalid_input', $result );
	}

	/**
	 * The slug is sanitized like a title and made unique.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_slug(): void {
		$this->login_as( 'editor' );
		$this->create_attachment( array( 'post_name' => 'taken-slug' ) );
		$attachment_id = $this->create_attachment();

		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'slug'   => 'My New Slug!',
				'fields' => array( 'slug' ),
			)
		);
		$this->assertSame( 'my-new-slug', $data['slug'], 'The slug should be sanitized.' );

		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'slug'   => 'taken-slug',
				'fields' => array( 'slug' ),
			)
		);
		$this->assertSame( 'taken-slug-2', $data['slug'], 'A taken slug should be made unique.' );
	}

	/**
	 * An update fires the update hooks once, with the attachment before the update.
	 *
	 * @since x.x.x
	 */
	public function test_update_fires_the_update_hooks_once(): void {
		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment( array( 'post_title' => 'Original title' ) );

		$edit_attachment = new MockAction();
		$after_insert    = new MockAction();
		add_action( 'edit_attachment', array( $edit_attachment, 'action' ) );
		add_action( 'wp_after_insert_post', array( $after_insert, 'action' ), 10, 4 );

		$this->update(
			array(
				'id'    => $attachment_id,
				'title' => 'New title',
			)
		);

		$this->assertSame( 1, $edit_attachment->get_call_count(), 'edit_attachment should fire once.' );
		$this->assertSame( 1, $after_insert->get_call_count(), 'wp_after_insert_post should fire once.' );

		[ $post_id, $post, $update, $post_before ] = $after_insert->get_args()[0];
		$this->assertSame( $attachment_id, $post_id, 'wp_after_insert_post should report the attachment.' );
		$this->assertSame( 'New title', $post->post_title, 'wp_after_insert_post should pass the updated attachment.' );
		$this->assertTrue( $update, 'wp_after_insert_post should report an update.' );
		$this->assertSame( 'Original title', $post_before->post_title, 'wp_after_insert_post should pass the attachment before the update.' );
	}

	/**
	 * A failed database write is reported as a server error.
	 *
	 * @since x.x.x
	 */
	public function test_update_post_with_db_error(): void {
		global $wpdb;

		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment( array( 'post_title' => 'Original title' ) );

		$break_update = static function ( string $query ) use ( $wpdb ): string {
			return 0 === strpos( $query, "UPDATE `{$wpdb->posts}`" ) ? '],' : $query;
		};

		$wpdb->suppress_errors = true;
		add_filter( 'query', $break_update );

		try {
			$result = $this->update(
				array(
					'id'    => $attachment_id,
					'title' => 'New title',
				)
			);
		} finally {
			remove_filter( 'query', $break_update );
			$wpdb->suppress_errors = false;
		}

		$this->assertErrorResponse( 'db_update_error', $result, 500, 'A failed write should be a server error.' );
		$this->assertSame( 'Original title', get_post( $attachment_id )->post_title, 'The title should be unchanged.' );
	}

	/**
	 * The new parent post is not checked for edit access, so an author can attach their
	 * attachment to another user's post.
	 *
	 * @since x.x.x
	 */
	public function test_update_does_not_check_the_new_parent(): void {
		$author_id     = $this->login_as( 'author' );
		$attachment_id = $this->create_attachment( array( 'post_author' => $author_id ) );
		$editor_post   = self::factory()->post->create(
			array(
				'post_author' => self::$user_ids['editor'],
				'post_status' => 'draft',
			)
		);
		$this->assertFalse( current_user_can( 'edit_post', $editor_post ), 'Precondition: the author cannot edit the post.' );

		$data = $this->update(
			array(
				'id'     => $attachment_id,
				'post'   => $editor_post,
				'fields' => array( 'post' ),
			)
		);

		$this->assertSame( $editor_post, $data['post'], 'The attachment should be attached to the post.' );
	}

	/**
	 * Input the ability does not take, such as meta, comment and ping statuses, or a
	 * template, fails schema validation.
	 *
	 * @dataProvider data_unsupported_input
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The unsupported input.
	 */
	public function test_update_rejects_unsupported_input( array $input ): void {
		$this->login_as( 'editor' );
		$attachment_id = $this->create_attachment();

		$this->assertErrorResponse( 'ability_invalid_input', $this->update( array_merge( array( 'id' => $attachment_id ), $input ) ) );
	}

	/**
	 * Provides input the ability does not take.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: array<string, mixed>}> The inputs.
	 */
	public function data_unsupported_input(): array {
		return array(
			'meta'           => array( array( 'meta' => array( 'key' => 'value' ) ) ),
			'comment status' => array( array( 'comment_status' => 'open' ) ),
			'ping status'    => array( array( 'ping_status' => 'open' ) ),
			'template'       => array( array( 'template' => 'single.php' ) ),
			'featured media' => array( array( 'featured_media' => 1 ) ),
			'url'            => array( array( 'url' => 'https://example.com/photo.jpg' ) ),
			'unknown field'  => array( array( 'fields' => array( 'content_raw' ) ) ),
			'negative post'  => array( array( 'post' => -1 ) ),
		);
	}

	/**
	 * An update stores and returns what the media endpoint stores and returns for the same
	 * input.
	 *
	 * @since x.x.x
	 */
	public function test_update_matches_the_media_endpoint(): void {
		$this->login_as( 'editor' );
		update_option( 'timezone_string', 'America/New_York' );

		$input  = array(
			'title'       => 'A <em>title</em>',
			'caption'     => array( 'raw' => 'A caption' ),
			'description' => 'A description',
			'alt_text'    => '<b>An alt text</b>',
			'post'        => self::factory()->post->create(),
			'author'      => self::$user_ids['author'],
			'date_gmt'    => '2016-12-12T19:00:00',
		);
		$fields = array( 'date', 'date_gmt', 'status', 'title_raw', 'title_rendered', 'author', 'description_raw', 'caption_raw', 'caption_rendered', 'alt_text', 'post' );

		$rest_id    = $this->create_attachment();
		$ability_id = $this->create_attachment();

		$request = new WP_REST_Request( 'POST', '/wp/v2/media/' . $rest_id );
		foreach ( $input as $name => $value ) {
			$request->set_param( $name, $value );
		}
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), 'The endpoint should update the attachment.' );

		$result = $this->update( array_merge( array( 'id' => $ability_id ), $input, array( 'fields' => $fields ) ) );
		$this->assertIsArray( $result, 'The ability should update the attachment.' );
		unset( $result['id'] );

		$expected = $this->flatten_rest_fields( $response->get_data(), $fields );
		ksort( $expected );
		ksort( $result );
		$this->assertSame( $expected, $result, 'The ability should return what the endpoint returns.' );

		$rest_post    = get_post( $rest_id );
		$ability_post = get_post( $ability_id );
		foreach ( array( 'post_title', 'post_excerpt', 'post_content', 'post_status', 'post_parent', 'post_author', 'post_date', 'post_date_gmt' ) as $column ) {
			$this->assertSame( $rest_post->$column, $ability_post->$column, "The {$column} column should match." );
		}
		$this->assertSame( get_post_meta( $rest_id, '_wp_attachment_image_alt', true ), get_post_meta( $ability_id, '_wp_attachment_image_alt', true ), 'The alt text should match.' );
	}
}

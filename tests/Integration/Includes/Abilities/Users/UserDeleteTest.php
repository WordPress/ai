<?php
/**
 * Integration tests for the core/user-delete Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Users
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Users;

/**
 * User delete ability test case.
 *
 * @since x.x.x
 */
class UserDeleteTest extends Users_Ability_TestCase {

	/**
	 * Deletes a user through the ability and returns the result.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function delete( array $input ) {
		return $this->execute_ability( 'core/user-delete', $input );
	}

	/**
	 * Returns the ID of a user that no longer exists.
	 *
	 * @since x.x.x
	 *
	 * @return int The user ID.
	 */
	private function get_missing_user_id(): int {
		$user_id = self::factory()->user->create();
		self::delete_user( $user_id );

		return $user_id;
	}

	/**
	 * The ability is registered as a closed-world, idempotent destructive write that takes an
	 * ID, the force flag, the user to reassign content to, and a field selection, and returns
	 * a deleted flag with the previous user.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_user_delete_ability(): void {
		$this->register_ability();

		$ability     = wp_get_ability( 'core/user-delete' );
		$annotations = $ability->get_meta_item( 'annotations', array() );
		$schema      = $ability->get_input_schema();
		$output      = $ability->get_output_schema();

		$this->assertSame( 'user', $ability->get_category(), 'The registered ability should use the user category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertTrue( $annotations['destructive'], 'Deleting a user is destructive.' );
		$this->assertTrue( $annotations['idempotent'], 'Repeating a deletion has no further effect.' );
		$this->assertFalse( $annotations['open_world'], 'The ability only writes to the local database.' );
		$this->assertSame( array( 'id', 'reassign' ), $schema['required'], 'The ID and the user to reassign content to should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );
		$this->assertSame( array( 'id', 'force', 'reassign', 'fields' ), array_keys( $schema['properties'] ), 'The input should take the ID, the force flag, the user to reassign content to, and the field selection.' );
		$this->assertSame( array( 'deleted', 'previous' ), $output['required'], 'The deleted flag and the previous user should always be returned.' );
		$this->assertSame( wp_list_pluck( wp_get_ability( 'core/users-query' )->get_output_schema()['oneOf'][0]['properties'], 'type' ), wp_list_pluck( $output['properties']['previous']['properties'], 'type' ), 'The previous user should have the same fields as a queried user.' );
	}

	/**
	 * When core already provides core/user-delete, the plugin's version replaces it.
	 *
	 * @since x.x.x
	 */
	public function test_override_replaces_existing_core_user_delete(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				'core/user-delete',
				array(
					'label'               => 'Core Provided',
					'description'         => 'Core provided delete ability.',
					'category'            => 'user',
					'execute_callback'    => '__return_empty_array',
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->register_ability();

		$this->assertSame( 'User Delete', wp_get_ability( 'core/user-delete' )->get_label(), 'The plugin-provided ability should replace the existing one.' );
	}

	/**
	 * A user is deleted and returned under `previous`.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item(): void {
		$user_id = self::factory()->user->create( array( 'display_name' => 'Deleted User' ) );

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$data = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => false,
			)
		);

		// Not implemented in multisite.
		if ( is_multisite() ) {
			$this->assertAbilityError( $data, 'users_cannot_delete', 'Users cannot be deleted on multisite.', 501 );
			return;
		}

		$this->assertIsArray( $data, 'The user should be deleted.' );
		$this->assertTrue( $data['deleted'] );
		$this->assertSame( 'Deleted User', $data['previous']['name'] );
	}

	/**
	 * Without `force`, or with `force` false, a user is not deleted.
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_no_trash(): void {
		$user_id = self::factory()->user->create( array( 'display_name' => 'Deleted User' ) );

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$input = array(
			'id'       => $user_id,
			'reassign' => false,
		);

		$result = $this->delete( $input );

		// Not implemented in multisite.
		if ( is_multisite() ) {
			$this->assertAbilityError( $result, 'users_cannot_delete', 'Users cannot be deleted on multisite.', 501 );
			return;
		}

		$this->assertAbilityError( $result, 'users_trash_not_supported', 'Users cannot be trashed.', 501 );

		$input['force'] = 'false';
		$this->assertAbilityError( $this->delete( $input ), 'users_trash_not_supported', 'Users cannot be trashed.', 501 );

		// Ensure the user still exists.
		$user = get_user_by( 'id', $user_id );
		$this->assertNotEmpty( $user );
	}

	/**
	 * A user can delete themselves.
	 *
	 * @since x.x.x
	 */
	public function test_delete_current_item(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Deleted User',
			)
		);

		wp_set_current_user( $user_id );
		update_site_option( 'site_admins', array( wp_get_current_user()->user_login ) );
		$this->register_ability();

		$data = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => false,
			)
		);

		// Not implemented in multisite.
		if ( is_multisite() ) {
			$this->assertAbilityError( $data, 'users_cannot_delete', 'Users cannot be deleted on multisite.', 501 );
			return;
		}

		$this->assertIsArray( $data, 'The user should be deleted.' );
		$this->assertTrue( $data['deleted'] );
		$this->assertSame( 'Deleted User', $data['previous']['name'] );
	}

	/**
	 * Without `force`, or with `force` false, a user does not delete themselves.
	 *
	 * @since x.x.x
	 */
	public function test_delete_current_item_no_trash(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Deleted User',
			)
		);

		wp_set_current_user( $user_id );
		update_site_option( 'site_admins', array( wp_get_current_user()->user_login ) );
		$this->register_ability();

		$input = array(
			'id'       => $user_id,
			'reassign' => false,
		);

		$result = $this->delete( $input );

		// Not implemented in multisite.
		if ( is_multisite() ) {
			$this->assertAbilityError( $result, 'users_cannot_delete', 'Users cannot be deleted on multisite.', 501 );
			return;
		}

		$this->assertAbilityError( $result, 'users_trash_not_supported', 'Users cannot be trashed.', 501 );

		$input['force'] = 'false';
		$this->assertAbilityError( $this->delete( $input ), 'users_trash_not_supported', 'Users cannot be trashed.', 501 );

		// Ensure the user still exists.
		$user = get_user_by( 'id', $user_id );
		$this->assertNotEmpty( $user );
	}

	/**
	 * An editor can delete neither another user nor themselves.
	 *
	 * @since x.x.x
	 */
	public function test_delete_user_without_permission(): void {
		$user_id = self::factory()->user->create();

		$this->allow_user_to_manage_multisite();
		$this->login_as( 'editor' );
		$this->register_ability();

		foreach ( array( $user_id, self::$user_ids['editor'] ) as $id ) {
			$result = $this->delete(
				array(
					'id'       => $id,
					'force'    => true,
					'reassign' => false,
				)
			);

			$this->assertAbilityDenied( $result, 'An editor should not delete users.' );
			$this->assertInstanceOf( \WP_User::class, get_userdata( $id ), 'The user should still exist.' );
		}
	}

	/**
	 * A user that does not exist is refused like one the caller cannot delete.
	 *
	 * @since x.x.x
	 */
	public function test_delete_user_invalid_id(): void {
		$missing_id = $this->get_missing_user_id();

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->delete(
			array(
				'id'       => $missing_id,
				'force'    => true,
				'reassign' => false,
			)
		);

		$this->assertAbilityDenied( $result, 'A missing user should be refused.' );
	}

	/**
	 * The deleted user's posts are reassigned to the given user.
	 *
	 * @since x.x.x
	 */
	public function test_delete_user_reassign(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		// Test with a new user, to avoid any complications.
		$user_id     = self::factory()->user->create();
		$reassign_id = self::factory()->user->create();
		$test_post   = self::factory()->post->create(
			array(
				'post_author' => $user_id,
			)
		);

		// Confidence check to ensure the factory created the post correctly.
		$post = get_post( $test_post );
		$this->assertSame( (string) $user_id, $post->post_author );

		// Delete our test user, and reassign to the new author.
		$result = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => $reassign_id,
			)
		);

		// Not implemented in multisite.
		if ( is_multisite() ) {
			$this->assertAbilityError( $result, 'users_cannot_delete', 'Users cannot be deleted on multisite.', 501 );
			return;
		}

		$this->assertIsArray( $result, 'The user should be deleted.' );

		// Check that the post has been updated correctly.
		$post = get_post( $test_post );
		$this->assertSame( (string) $reassign_id, $post->post_author );
	}

	/**
	 * Reassigning to a user that does not exist is refused.
	 *
	 * @since x.x.x
	 */
	public function test_delete_user_invalid_reassign_id(): void {
		$user_id    = self::factory()->user->create();
		$missing_id = $this->get_missing_user_id();

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => $missing_id,
			)
		);

		// Not implemented in multisite.
		if ( is_multisite() ) {
			$this->assertAbilityError( $result, 'users_cannot_delete', 'Users cannot be deleted on multisite.', 501 );
			return;
		}

		$this->assertAbilityError( $result, 'users_user_invalid_reassign', 'A missing user should not receive the posts.', 400 );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $user_id ), 'The user should still exist.' );
	}

	/**
	 * A string that is neither an ID nor false is refused.
	 *
	 * @since x.x.x
	 */
	public function test_delete_user_invalid_reassign_passed_as_string(): void {
		$user_id = self::factory()->user->create();

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => 'null',
			)
		);

		$this->assertAbilityError( $result, 'users_invalid_param', 'An invalid reassignment should be refused.', 400 );
		$this->assertSame( 'Invalid user parameter(s).', $result->get_error_data()['params']['reassign'] );
	}

	/**
	 * Reassigning to false deletes the user's posts.
	 *
	 * @since x.x.x
	 */
	public function test_delete_user_reassign_passed_as_boolean_false_trashes_post(): void {
		$user_id = self::factory()->user->create();

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$test_post = self::factory()->post->create(
			array(
				'post_author' => $user_id,
			)
		);

		$result = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => false,
			)
		);

		// Not implemented in multisite.
		if ( is_multisite() ) {
			$this->assertAbilityError( $result, 'users_cannot_delete', 'Users cannot be deleted on multisite.', 501 );
			return;
		}

		$test_post = get_post( $test_post );
		$this->assertSame( 'trash', $test_post->post_status );
	}

	/**
	 * Reassigning to the string false deletes the user's posts.
	 *
	 * @since x.x.x
	 */
	public function test_delete_user_reassign_passed_as_string_false_trashes_post(): void {
		$user_id = self::factory()->user->create();

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$test_post = self::factory()->post->create(
			array(
				'post_author' => $user_id,
			)
		);

		$result = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => 'false',
			)
		);

		// Not implemented in multisite.
		if ( is_multisite() ) {
			$this->assertAbilityError( $result, 'users_cannot_delete', 'Users cannot be deleted on multisite.', 501 );
			return;
		}

		$test_post = get_post( $test_post );
		$this->assertSame( 'trash', $test_post->post_status );
	}

	/**
	 * Reassigning to an empty string deletes the user's posts.
	 *
	 * @since x.x.x
	 */
	public function test_delete_user_reassign_passed_as_empty_string_trashes_post(): void {
		$user_id = self::factory()->user->create();

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$test_post = self::factory()->post->create(
			array(
				'post_author' => $user_id,
			)
		);

		$result = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => '',
			)
		);

		// Not implemented in multisite.
		if ( is_multisite() ) {
			$this->assertAbilityError( $result, 'users_cannot_delete', 'Users cannot be deleted on multisite.', 501 );
			return;
		}

		$test_post = get_post( $test_post );
		$this->assertSame( 'trash', $test_post->post_status );
	}

	/**
	 * Reassigning to 0 leaves the user's posts without an author.
	 *
	 * @since x.x.x
	 */
	public function test_delete_user_reassign_passed_as_0_reassigns_author(): void {
		$user_id = self::factory()->user->create();

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$test_post = self::factory()->post->create(
			array(
				'post_author' => $user_id,
			)
		);

		$result = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => 0,
			)
		);

		// Not implemented in multisite.
		if ( is_multisite() ) {
			$this->assertAbilityError( $result, 'users_cannot_delete', 'Users cannot be deleted on multisite.', 501 );
			return;
		}

		$test_post = get_post( $test_post );
		$this->assertSame( '0', $test_post->post_author );
	}

	/**
	 * On multisite, a site administrator cannot delete a user of another site.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_from_different_site_as_site_administrator(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		switch_to_blog( self::$site );
		$user_id = self::factory()->user->create(
			array(
				'role' => 'author',
			)
		);
		restore_current_blog();

		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => false,
			)
		);

		$this->assertAbilityDenied( $result, 'A user of another site should not be deleted.' );
	}

	/**
	 * On multisite, a super admin cannot delete a user of another site from this one.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_delete_item_from_different_site_as_network_administrator(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		switch_to_blog( self::$site );
		$user_id = self::factory()->user->create(
			array(
				'role' => 'author',
			)
		);
		restore_current_blog();

		$this->login_as( 'superadmin' );
		$this->register_ability();

		$result = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => false,
			)
		);

		$this->assertAbilityDenied( $result, 'A user of another site should not be deleted.' );
	}

	/**
	 * The ID, the force flag, the user to reassign content to, and the fields can be given as
	 * the strings of a query string.
	 *
	 * @group ms-excluded
	 *
	 * @since x.x.x
	 */
	public function test_delete_accepts_query_string_input(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Users cannot be deleted on multisite.' );
		}

		$user_id     = self::factory()->user->create(
			array(
				'display_name' => 'String Deleted',
				'user_email'   => 'string-deleted@example.com',
			)
		);
		$reassign_id = self::factory()->user->create();
		$test_post   = self::factory()->post->create( array( 'post_author' => $user_id ) );

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->delete(
			array(
				'id'       => (string) $user_id,
				'force'    => 'true',
				'reassign' => (string) $reassign_id,
				'fields'   => 'name,email',
			)
		);

		$this->assertSame(
			array(
				'deleted'  => true,
				'previous' => array(
					'id'    => $user_id,
					'name'  => 'String Deleted',
					'email' => 'string-deleted@example.com',
				),
			),
			$result,
			'The string input should be read like its typed form.'
		);
		$this->assertSame( (string) $reassign_id, get_post( $test_post )->post_author, 'The post should be reassigned.' );
	}

	/**
	 * Over REST, the user to reassign content to is read as sent, so a string such as FALSE is
	 * refused rather than read as false.
	 *
	 * @group ms-excluded
	 *
	 * @since x.x.x
	 */
	public function test_delete_over_rest_reads_reassign_as_sent(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Users cannot be deleted on multisite.' );
		}

		$user_id = self::factory()->user->create();

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$request = new \WP_REST_Request( 'DELETE', '/wp-abilities/v1/abilities/core/user-delete/run' );
		$request->set_query_params(
			array(
				'input' => array(
					'id'       => (string) $user_id,
					'force'    => 'true',
					'reassign' => 'FALSE',
				),
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status(), 'The reassignment should be refused.' );
		$this->assertSame( 'users_invalid_param', $response->get_data()['code'] );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $user_id ), 'The user should still exist.' );
	}

	/**
	 * Reassigning to the string 0 leaves the user's posts without an author.
	 *
	 * @group ms-excluded
	 *
	 * @since x.x.x
	 */
	public function test_delete_user_reassign_passed_as_string_0_reassigns_author(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Users cannot be deleted on multisite.' );
		}

		$user_id   = self::factory()->user->create();
		$test_post = self::factory()->post->create( array( 'post_author' => $user_id ) );

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => '0',
			)
		);

		$this->assertIsArray( $result, 'The user should be deleted.' );
		$this->assertSame( '0', get_post( $test_post )->post_author );
	}

	/**
	 * The posts cannot be reassigned to the user being deleted, but can be to the current user.
	 *
	 * @group ms-excluded
	 *
	 * @since x.x.x
	 */
	public function test_delete_user_reassign_to_the_deleted_or_current_user(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Users cannot be deleted on multisite.' );
		}

		$user_id   = self::factory()->user->create();
		$test_post = self::factory()->post->create( array( 'post_author' => $user_id ) );

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => $user_id,
			)
		);

		$this->assertAbilityError( $result, 'users_user_invalid_reassign', 'The deleted user should not receive the posts.', 400 );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $user_id ), 'The user should still exist.' );

		$result = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => get_current_user_id(),
			)
		);

		$this->assertIsArray( $result, 'The user should be deleted.' );
		$this->assertSame( (string) get_current_user_id(), get_post( $test_post )->post_author, 'The current user should receive the posts.' );
	}

	/**
	 * Without `fields`, the previous user is returned with the lean default fields.
	 *
	 * @group ms-excluded
	 *
	 * @since x.x.x
	 */
	public function test_delete_returns_lean_default_fields(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Users cannot be deleted on multisite.' );
		}

		$user_id = self::factory()->user->create();

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => false,
			)
		);

		$this->assertIsArray( $result, 'The user should be deleted.' );
		$this->assertSame( array( 'id', 'name', 'link', 'slug', 'avatar_urls' ), array_keys( $result['previous'] ), 'The lean default fields should be returned.' );
		$this->assertSame( $user_id, $result['previous']['id'], 'The deleted user should be returned.' );
	}

	/**
	 * Provides inputs that miss a required field.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string}> The required field to leave out.
	 */
	public function data_required_fields(): array {
		return array(
			'id'       => array( 'id' ),
			'reassign' => array( 'reassign' ),
		);
	}

	/**
	 * The ID and the user to reassign content to must be given.
	 *
	 * @dataProvider data_required_fields
	 *
	 * @since x.x.x
	 *
	 * @param string $field The required field to leave out.
	 */
	public function test_delete_requires_the_id_and_reassign( string $field ): void {
		$user_id = self::factory()->user->create();

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$input = array(
			'id'       => $user_id,
			'force'    => true,
			'reassign' => false,
		);
		unset( $input[ $field ] );

		$this->assertAbilityInvalidInput( $this->delete( $input ), "A deletion without the {$field} should be rejected." );
		$this->assertInstanceOf( \WP_User::class, get_userdata( $user_id ), 'The user should still exist.' );
	}

	/**
	 * Provides the users who may or may not delete another user.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string|null, 1: bool}> The user fixture name, or null for a logged-out user, and whether the user may delete another user.
	 */
	public function data_delete_permissions(): array {
		return array(
			'administrator' => array( 'administrator', true ),
			'editor'        => array( 'editor', false ),
			'author'        => array( 'author', false ),
			'contributor'   => array( 'contributor', false ),
			'subscriber'    => array( 'subscriber', false ),
			'user creator'  => array( 'user_creator', false ),
			'user promoter' => array( 'user_promoter', false ),
			'super admin'   => array( 'superadmin', true ),
			'logged out'    => array( null, false ),
		);
	}

	/**
	 * Only users who can delete users may delete another user. On multisite only super admins
	 * pass the permission check, and the deletion is then refused.
	 *
	 * @dataProvider data_delete_permissions
	 *
	 * @since x.x.x
	 *
	 * @param string|null $name    The user fixture name, or null for a logged-out user.
	 * @param bool        $allowed Whether the user may delete another user on a single site.
	 */
	public function test_delete_permissions( ?string $name, bool $allowed ): void {
		$user_id = self::factory()->user->create();

		if ( null === $name ) {
			wp_set_current_user( 0 );
		} else {
			$this->login_as( $name );
		}

		$this->register_ability();

		$result = $this->delete(
			array(
				'id'       => $user_id,
				'force'    => true,
				'reassign' => false,
			)
		);

		if ( is_multisite() && 'superadmin' === $name ) {
			$this->assertAbilityError( $result, 'users_cannot_delete', 'Users cannot be deleted on multisite.', 501 );
		} elseif ( $allowed && ! is_multisite() ) {
			$this->assertIsArray( $result, 'The user should be deleted.' );
			$this->assertTrue( $result['deleted'], 'The user should be reported as deleted.' );
			$this->assertFalse( get_userdata( $user_id ), 'The user should no longer exist.' );
			return;
		} else {
			$this->assertAbilityDenied( $result, 'The user should not be deleted.' );
		}

		$this->assertInstanceOf( \WP_User::class, get_userdata( $user_id ), 'The user should still exist.' );
	}

	/**
	 * A user that does not exist and a user the caller cannot delete are refused alike.
	 *
	 * @since x.x.x
	 */
	public function test_delete_does_not_reveal_whether_a_user_exists(): void {
		$missing_id = $this->get_missing_user_id();

		$this->login_as( 'editor' );
		$this->register_ability();

		$input = array(
			'force'    => true,
			'reassign' => false,
		);

		$missing   = $this->delete( array( 'id' => $missing_id ) + $input );
		$forbidden = $this->delete( array( 'id' => self::$user_ids['administrator'] ) + $input );

		$this->assertAbilityDenied( $missing, 'A missing user should be refused.' );
		$this->assertAbilityDenied( $forbidden, 'A user the caller cannot delete should be refused.' );
		$this->assertSame( $missing->get_error_message(), $forbidden->get_error_message(), 'Both refusals should read the same.' );
		$this->assertSame( $missing->get_error_data(), $forbidden->get_error_data(), 'Both refusals should carry the same data.' );
	}
}

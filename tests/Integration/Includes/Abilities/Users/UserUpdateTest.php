<?php
/**
 * Integration tests for the core/user-update Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Users
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Users;

/**
 * User update ability test case.
 *
 * @since x.x.x
 */
class UserUpdateTest extends Users_Ability_TestCase {

	/**
	 * Updates a user through the ability and returns the result.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function update( array $input ) {
		return $this->execute_ability( 'core/user-update', $input );
	}

	/**
	 * Adds German to the available languages.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $languages The available language codes.
	 * @return list<string> The language codes, with German.
	 */
	public function add_german( array $languages ): array {
		return array_merge( $languages, array( 'de_DE' ) );
	}

	/**
	 * The ability is registered as a closed-world destructive write that is not idempotent,
	 * takes an ID plus the create ability's fields except the username, and returns a user
	 * shaped like a queried one.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_user_update_ability(): void {
		$this->register_ability();

		$ability       = wp_get_ability( 'core/user-update' );
		$annotations   = $ability->get_meta_item( 'annotations', array() );
		$schema        = $ability->get_input_schema();
		$create_schema = wp_get_ability( 'core/user-create' )->get_input_schema();

		$this->assertSame( 'user', $ability->get_category(), 'The registered ability should use the user category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );
		$this->assertTrue( $ability->get_meta_item( 'public', false ), 'The ability should be marked public.' );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertTrue( $annotations['destructive'], 'Updating overwrites user fields, so the ability is flagged destructive.' );
		$this->assertFalse( $annotations['idempotent'], 'The ability must stay on the POST method, which keeps the password out of the query string.' );
		$this->assertFalse( $annotations['open_world'], 'The ability only writes to the local database.' );
		$this->assertSame( array( 'id' ), $schema['required'], 'Only the ID should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );
		$this->assertSame( array_merge( array( 'id' ), array_diff( array_keys( $create_schema['properties'] ), array( 'username' ) ) ), array_keys( $schema['properties'] ), 'The update should take an ID and the create ability\'s fields except the username.' );
		$this->assertSame( wp_list_pluck( wp_get_ability( 'core/users-query' )->get_output_schema()['oneOf'][0]['properties'], 'type' ), wp_list_pluck( $ability->get_output_schema()['properties'], 'type' ), 'The updated user should have the same fields as a queried user.' );
	}

	/**
	 * When core already provides core/user-update, the plugin's version replaces it.
	 *
	 * @since x.x.x
	 */
	public function test_override_replaces_existing_core_user_update(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				'core/user-update',
				array(
					'label'               => 'Core Provided',
					'description'         => 'Core provided update ability.',
					'category'            => 'user',
					'execute_callback'    => '__return_empty_array',
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->register_ability();

		$this->assertSame( 'Update User', wp_get_ability( 'core/user-update' )->get_label(), 'The plugin-provided ability should replace the existing one.' );
	}

	/**
	 * A user's name, URL, and locale are updated, and the password is kept.
	 *
	 * @since x.x.x
	 */
	public function test_update_item(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_email' => 'test@example.com',
				'user_pass'  => 'sjflsfls',
				'user_login' => 'test_update',
				'first_name' => 'Old Name',
				'user_url'   => 'http://apple.com',
				'locale'     => 'en_US',
			)
		);

		$this->allow_user_to_manage_multisite();
		add_filter( 'get_available_languages', array( $this, 'add_german' ) );
		$this->register_ability();

		$userdata  = get_userdata( $user_id );
		$pw_before = $userdata->user_pass;

		$data = $this->update(
			array(
				'id'         => $user_id,
				'email'      => $userdata->user_email,
				'first_name' => 'New Name',
				'url'        => 'http://google.com',
				'locale'     => 'de_DE',
				'fields'     => $this->all_fields(),
			)
		);

		$this->check_user_data( $data );

		// Check that the name has been updated correctly.
		$this->assertSame( 'New Name', $data['first_name'] );
		$user = get_userdata( $user_id );
		$this->assertSame( 'New Name', $user->first_name );

		$this->assertSame( 'http://google.com', $data['url'] );
		$this->assertSame( 'http://google.com', $user->user_url );
		$this->assertSame( 'de_DE', $user->locale );

		// Check that we haven't inadvertently changed the user's password.
		$this->assertSame( $pw_before, $user->user_pass );
	}

	/**
	 * Updating a user to its current values succeeds every time.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_no_change(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$input = array(
			'id'   => self::$user_ids['editor'],
			'slug' => get_userdata( self::$user_ids['editor'] )->user_nicename,
		);

		// Run twice to make sure that the update still succeeds
		// even if no DB rows are updated.
		$this->assertIsArray( $this->update( $input ), 'The first update should succeed.' );
		$this->assertIsArray( $this->update( $input ), 'The second update should succeed.' );
	}

	/**
	 * The email address of another user is refused.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_existing_email(): void {
		self::factory()->user->create(
			array(
				'user_login' => 'test_json_user',
				'user_email' => 'testjson@example.com',
			)
		);
		$user2 = self::factory()->user->create(
			array(
				'user_login' => 'test_json_user2',
				'user_email' => 'testjson2@example.com',
			)
		);

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->update(
			array(
				'id'    => $user2,
				'email' => 'testjson@example.com',
			)
		);

		$this->assertAbilityError( $result, 'users_user_invalid_email', 'The email address of another user should be refused.', 400 );
		$this->assertSame( 'testjson2@example.com', get_userdata( $user2 )->user_email, 'The email address should be unchanged.' );
	}

	/**
	 * A user can change the case of their own email address.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_existing_email_case(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$updated_email_with_case_change = ucwords( get_userdata( self::$user_ids['editor'] )->user_email );

		$data = $this->update(
			array(
				'id'     => self::$user_ids['editor'],
				'email'  => $updated_email_with_case_change,
				'fields' => array( 'email' ),
			)
		);

		$this->assertIsArray( $data, 'The update should succeed.' );
		$this->assertSame( $updated_email_with_case_change, $data['email'] );
	}

	/**
	 * The email address of another user is refused whatever its case.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_existing_email_case_not_own(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$updated_email_with_case_change = ucwords( get_userdata( self::$user_ids['subscriber'] )->user_email );

		$result = $this->update(
			array(
				'id'    => self::$user_ids['editor'],
				'email' => $updated_email_with_case_change,
			)
		);

		$this->assertAbilityError( $result, 'users_user_invalid_email', 'The email address of another user should be refused.', 400 );
	}

	/**
	 * A locale that is not available is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_invalid_locale(): void {
		$user1 = self::factory()->user->create(
			array(
				'user_login' => 'test_json_user',
				'user_email' => 'testjson@example.com',
			)
		);

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->update(
			array(
				'id'     => $user1,
				'locale' => 'klingon',
			)
		);

		$this->assertAbilityInvalidInput( $result, 'An unavailable locale should be rejected.' );
	}

	/**
	 * The en_US locale can be set although no language file provides it.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_en_US_locale(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Named after the locale.
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'test_json_user',
				'user_email' => 'testjson@example.com',
			)
		);

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$data = $this->update(
			array(
				'id'     => $user_id,
				'locale' => 'en_US',
				'fields' => $this->all_fields(),
			)
		);

		$this->check_user_data( $data );

		$user = get_userdata( $user_id );
		$this->assertSame( 'en_US', $user->locale );
	}

	/**
	 * An empty locale is stored, and the site locale is returned for it.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_empty_locale(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'test_json_user',
				'user_email' => 'testjson@example.com',
				'locale'     => 'de_DE',
			)
		);

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$data = $this->update(
			array(
				'id'     => $user_id,
				'locale' => '',
				'fields' => $this->all_fields(),
			)
		);

		$this->check_user_data( $data );

		$this->assertSame( get_locale(), $data['locale'] );
		$user = get_userdata( $user_id );
		$this->assertSame( '', $user->locale );
	}

	/**
	 * The username is not an input of the update ability.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_username_attempt(): void {
		self::factory()->user->create(
			array(
				'user_login' => 'test_json_user',
				'user_email' => 'testjson@example.com',
			)
		);
		$user2 = self::factory()->user->create(
			array(
				'user_login' => 'test_json_user2',
				'user_email' => 'testjson2@example.com',
			)
		);

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->update(
			array(
				'id'       => $user2,
				'username' => 'test_json_user',
			)
		);

		$this->assertAbilityInvalidInput( $result, 'A username should be rejected.' );
		$this->assertSame( 'test_json_user2', get_userdata( $user2 )->user_login, 'The username should be unchanged.' );
	}

	/**
	 * The slug of another user is refused.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_existing_nicename(): void {
		self::factory()->user->create(
			array(
				'user_login' => 'test_json_user',
				'user_email' => 'testjson@example.com',
			)
		);
		$user2 = self::factory()->user->create(
			array(
				'user_login' => 'test_json_user2',
				'user_email' => 'testjson2@example.com',
			)
		);

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->update(
			array(
				'id'   => $user2,
				'slug' => 'test_json_user',
			)
		);

		$this->assertAbilityError( $result, 'users_user_invalid_slug', 'The slug of another user should be refused.', 400 );
	}

	/**
	 * A user's names are updated, and the password is kept.
	 *
	 * @since x.x.x
	 */
	public function test_json_update_user(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_email' => 'testjson2@example.com',
				'user_pass'  => 'sjflsfl3sdjls',
				'user_login' => 'test_json_update',
				'first_name' => 'Old Name',
				'last_name'  => 'Original Last',
			)
		);

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$params = array(
			'id'         => $user_id,
			'email'      => 'testjson2@example.com',
			'first_name' => 'JSON Name',
			'last_name'  => 'New Last',
			'fields'     => $this->all_fields(),
		);

		$pw_before = get_userdata( $user_id )->user_pass;

		$new_data = $this->update( $params );
		$this->check_user_data( $new_data );

		// Check that the name has been updated correctly.
		$this->assertSame( 'JSON Name', $new_data['first_name'] );
		$this->assertSame( 'New Last', $new_data['last_name'] );
		$user = get_userdata( $user_id );
		$this->assertSame( 'JSON Name', $user->first_name );
		$this->assertSame( 'New Last', $user->last_name );

		// Check that we haven't inadvertently changed the user's password.
		$this->assertSame( $pw_before, $user->user_pass );
	}

	/**
	 * The roles given replace the user's roles.
	 *
	 * @since x.x.x
	 */
	public function test_update_user_role(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$new_data = $this->update(
			array(
				'id'     => $user_id,
				'roles'  => array( 'editor' ),
				'fields' => array( 'roles' ),
			)
		);

		$this->assertSame( array( 'editor' ), $new_data['roles'] );

		$user = get_userdata( $user_id );
		$this->assertArrayHasKey( 'editor', $user->caps );
		$this->assertArrayNotHasKey( 'administrator', $user->caps );
	}

	/**
	 * Every role in a comma-separated list is assigned.
	 *
	 * @since x.x.x
	 */
	public function test_update_user_multiple_roles(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$new_data = $this->update(
			array(
				'id'     => $user_id,
				'roles'  => 'author,editor',
				'fields' => array( 'roles' ),
			)
		);

		$this->assertSame( array( 'author', 'editor' ), $new_data['roles'] );

		$user = get_userdata( $user_id );
		$this->assertArrayHasKey( 'author', $user->caps );
		$this->assertArrayHasKey( 'editor', $user->caps );
		$this->assertArrayNotHasKey( 'administrator', $user->caps );
	}

	/**
	 * A user who cannot promote users cannot change their own roles.
	 *
	 * @since x.x.x
	 */
	public function test_update_user_role_invalid_privilege_escalation(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		foreach ( array( self::$user_ids['editor'], (string) self::$user_ids['editor'] ) as $id ) {
			$result = $this->update(
				array(
					'id'    => $id,
					'roles' => array( 'administrator' ),
				)
			);

			$this->assertAbilityError( $result, 'users_cannot_edit_roles', 'An editor should not make themselves an administrator.', 403 );

			$user = get_userdata( self::$user_ids['editor'] );
			$this->assertArrayHasKey( 'editor', $user->caps );
			$this->assertArrayNotHasKey( 'administrator', $user->caps );
		}
	}

	/**
	 * An administrator cannot give themselves a role that cannot edit users.
	 *
	 * @group ms-excluded
	 *
	 * @since x.x.x
	 */
	public function test_update_user_role_invalid_privilege_deescalation(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Super admins can change their own roles.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $user_id );
		$this->register_ability();

		foreach ( array( $user_id, (string) $user_id ) as $id ) {
			$result = $this->update(
				array(
					'id'    => $id,
					'roles' => array( 'editor' ),
				)
			);

			$this->assertAbilityError( $result, 'users_user_invalid_role', 'An administrator should not demote themselves.', 403 );

			$user = get_userdata( $user_id );
			$this->assertArrayHasKey( 'administrator', $user->caps );
			$this->assertArrayNotHasKey( 'editor', $user->caps );
		}
	}

	/**
	 * On multisite, a super admin can give themselves a role that cannot edit users.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_update_user_role_privilege_deescalation_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$this->register_ability();

		foreach ( array( 'integer', 'string' ) as $id_type ) {
			$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

			wp_set_current_user( $user_id );
			update_site_option( 'site_admins', array( wp_get_current_user()->user_login ) );

			$new_data = $this->update(
				array(
					'id'     => 'string' === $id_type ? (string) $user_id : $user_id,
					'roles'  => array( 'editor' ),
					'fields' => array( 'roles' ),
				)
			);

			$this->assertSame( array( 'editor' ), $new_data['roles'] );
		}
	}

	/**
	 * A role that does not exist is refused.
	 *
	 * @since x.x.x
	 */
	public function test_update_user_role_invalid_role(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		foreach ( array( 'editor', 'administrator' ) as $name ) {
			$result = $this->update(
				array(
					'id'    => self::$user_ids[ $name ],
					'roles' => array( 'BeSharp' ),
				)
			);

			$this->assertAbilityError( $result, 'users_user_invalid_role', 'A missing role should be refused.', 400 );

			$user = get_userdata( self::$user_ids[ $name ] );
			$this->assertArrayHasKey( $name, $user->caps );
			$this->assertArrayNotHasKey( 'BeSharp', $user->caps );
		}
	}

	/**
	 * A role the current user cannot give is refused.
	 *
	 * @since x.x.x
	 */
	public function test_update_user_role_that_is_not_editable(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		add_filter(
			'editable_roles',
			static function ( array $roles ): array {
				unset( $roles['editor'] );

				return $roles;
			}
		);

		$result = $this->update(
			array(
				'id'    => $user_id,
				'roles' => array( 'editor' ),
			)
		);

		$this->assertAbilityError( $result, 'users_user_invalid_role', 'A role the user cannot give should be refused.', 403 );
		$this->assertSame( array( 'author' ), array_values( get_userdata( $user_id )->roles ), 'The role should be kept.' );
	}

	/**
	 * An editor cannot update an administrator, and cannot change their own username.
	 *
	 * @since x.x.x
	 */
	public function test_update_user_without_permission(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$params = array(
			'password' => 'stupidsexyflanders',
			'email'    => 'chunkylover53@aol.com',
		);

		$result = $this->update( array( 'id' => self::$user_ids['administrator'] ) + $params );
		$this->assertAbilityDenied( $result, 'An editor should not update an administrator.' );
		$this->assertNotSame( 'chunkylover53@aol.com', get_userdata( self::$user_ids['administrator'] )->user_email, 'The administrator should be unchanged.' );

		$result = $this->update( array( 'id' => self::$user_ids['editor'] ) + $params + array( 'username' => 'homersimpson' ) );
		$this->assertAbilityInvalidInput( $result, 'A username should be rejected.' );
		$this->assertSame( 'editor@example.com', get_userdata( self::$user_ids['editor'] )->user_email, 'The editor should be unchanged.' );
	}

	/**
	 * An ID of zero is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_update_user_invalid_id(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$params = array(
			'id'       => '0',
			'password' => 'DavidHasselhoff',
			'email'    => 'smartgirl63_@yahoo.com',
		);

		$this->assertAbilityInvalidInput( $this->update( $params ), 'An ID of zero should be rejected.' );
		$this->assertFalse( email_exists( 'smartgirl63_@yahoo.com' ), 'No user should be updated.' );
	}

	/**
	 * An editor cannot change the roles of another user.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_only_roles_as_editor(): void {
		$user_id = self::factory()->user->create(
			array(
				'role' => 'author',
			)
		);

		$this->login_as( 'editor' );
		$this->register_ability();

		$result = $this->update(
			array(
				'id'    => $user_id,
				'roles' => array( 'editor' ),
			)
		);

		$this->assertAbilityDenied( $result, 'An editor should not change the roles of another user.' );
		$this->assertSame( array( 'author' ), array_values( get_userdata( $user_id )->roles ), 'The role should be unchanged.' );
	}

	/**
	 * A site administrator can change the roles of a user, including on multisite, where they
	 * cannot edit users.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_only_roles_as_site_administrator(): void {
		$user_id = self::factory()->user->create(
			array(
				'role' => 'author',
			)
		);

		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = $this->update(
			array(
				'id'    => $user_id,
				'roles' => array( 'editor' ),
			)
		);

		$this->assertIsArray( $result, 'The roles should be changed.' );
		$this->assertSame( array( 'editor' ), array_values( get_userdata( $user_id )->roles ) );
	}

	/**
	 * Changing the roles together with other fields takes the capability to edit the user,
	 * which site administrators do not have on multisite.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_including_roles_and_other_params(): void {
		$user_id = self::factory()->user->create(
			array(
				'role' => 'author',
			)
		);

		$this->login_as( 'administrator' );
		$this->register_ability();

		$result = $this->update(
			array(
				'id'     => $user_id,
				'roles'  => array( 'editor' ),
				'name'   => 'Short-Lived User',
				'fields' => array( 'roles' ),
			)
		);

		if ( is_multisite() ) {
			/*
			 * Site administrators can promote users, as verified by the previous test,
			 * but they cannot perform other user-editing operations.
			 * This also tests the branch of logic that verifies that no parameters
			 * other than 'id' and 'roles' are specified for a roles update.
			 */
			$this->assertAbilityDenied( $result, 'A site administrator should not edit a user on multisite.' );
			$this->assertSame( array( 'author' ), array_values( get_userdata( $user_id )->roles ), 'The role should be unchanged.' );
		} else {
			$this->assertIsArray( $result, 'The user should be updated.' );
			$this->assertSame( array( 'editor' ), $result['roles'] );
		}
	}

	/**
	 * A password with a backslash, or an empty one, is refused.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_invalid_password(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		foreach ( array( 'no\\backslashes\\allowed', '' ) as $password ) {
			$result = $this->update(
				array(
					'id'       => self::$user_ids['editor'],
					'password' => $password,
				)
			);

			$this->assertAbilityError( $result, 'users_invalid_param', 'The password should be refused.', 400 );
		}
	}

	/**
	 * Creates a user from the input unless it names one, updates the user with the input, and
	 * checks the values each write returns and stores.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, int|string> $input           The user input.
	 * @param array<string, string>     $expected_output The expected values.
	 */
	private function verify_user_roundtrip( array $input, array $expected_output ): void {
		$fields = array( 'id', 'username', 'name', 'first_name', 'last_name', 'url', 'description', 'nickname' );

		if ( isset( $input['id'] ) ) {
			// Existing user; don't try to create one.
			$user_id = (int) $input['id'];
		} else {
			// Create a new user.
			$actual_output = $this->execute_ability(
				'core/user-create',
				array_merge(
					$input,
					array(
						'email'  => 'cbg@androidsdungeon.com',
						'fields' => $fields,
					)
				)
			);
			$this->assertIsArray( $actual_output, 'The user should be created.' );

			// Compare expected output to actual output.
			$this->assertSame( $expected_output['username'], $actual_output['username'] );
			$this->assertSame( $expected_output['name'], $actual_output['name'] );
			$this->assertSame( $expected_output['first_name'], $actual_output['first_name'] );
			$this->assertSame( $expected_output['last_name'], $actual_output['last_name'] );
			$this->assertSame( $expected_output['url'], $actual_output['url'] );
			$this->assertSame( $expected_output['description'], $actual_output['description'] );
			$this->assertSame( $expected_output['nickname'], $actual_output['nickname'] );

			// Compare expected output to WP internal values.
			$user = get_userdata( $actual_output['id'] );
			$this->assertSame( $expected_output['username'], $user->user_login );
			$this->assertSame( $expected_output['name'], $user->display_name );
			$this->assertSame( $expected_output['first_name'], $user->first_name );
			$this->assertSame( $expected_output['last_name'], $user->last_name );
			$this->assertSame( $expected_output['url'], $user->user_url );
			$this->assertSame( $expected_output['description'], $user->description );
			$this->assertSame( $expected_output['nickname'], $user->nickname );
			$this->assertTrue( wp_check_password( addslashes( $expected_output['password'] ), $user->user_pass ) );

			$user_id = $actual_output['id'];
		}

		// Update the user.
		$update = array(
			'id'     => $user_id,
			'fields' => $fields,
		);
		foreach ( $input as $name => $value ) {
			if ( 'username' === $name ) {
				continue;
			}

			$update[ $name ] = $value;
		}
		$actual_output = $this->update( $update );
		$this->assertIsArray( $actual_output, 'The user should be updated.' );

		// Compare expected output to actual output.
		if ( isset( $expected_output['username'] ) ) {
			$this->assertSame( $expected_output['username'], $actual_output['username'] );
		}
		$this->assertSame( $expected_output['name'], $actual_output['name'] );
		$this->assertSame( $expected_output['first_name'], $actual_output['first_name'] );
		$this->assertSame( $expected_output['last_name'], $actual_output['last_name'] );
		$this->assertSame( $expected_output['url'], $actual_output['url'] );
		$this->assertSame( $expected_output['description'], $actual_output['description'] );
		$this->assertSame( $expected_output['nickname'], $actual_output['nickname'] );

		// Compare expected output to WP internal values.
		$user = get_userdata( $actual_output['id'] );
		if ( isset( $expected_output['username'] ) ) {
			$this->assertSame( $expected_output['username'], $user->user_login );
		}
		$this->assertSame( $expected_output['name'], $user->display_name );
		$this->assertSame( $expected_output['first_name'], $user->first_name );
		$this->assertSame( $expected_output['last_name'], $user->last_name );
		$this->assertSame( $expected_output['url'], $user->user_url );
		$this->assertSame( $expected_output['description'], $user->description );
		$this->assertSame( $expected_output['nickname'], $user->nickname );
		$this->assertTrue( wp_check_password( addslashes( $expected_output['password'] ), $user->user_pass ) );
	}

	/**
	 * Returns the description the HTML round-trip tests expect back.
	 *
	 * KSES keeps the text of a disallowed `<script>` element in WordPress 7.1 and earlier,
	 * and drops it since its HTML API rewrite in 7.2, so the expected text follows the
	 * version under test.
	 *
	 * @since x.x.x
	 *
	 * @return string The expected description.
	 */
	private function get_html_roundtrip_description(): string {
		return 'div <strong>strong</strong> ' . wp_kses_data( '<script>oh noes</script>' );
	}

	/**
	 * Backslashes and special characters survive an editor's update of their own profile.
	 *
	 * @since x.x.x
	 */
	public function test_user_roundtrip_as_editor(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$this->assertSame( ! is_multisite(), current_user_can( 'unfiltered_html' ) );
		$this->verify_user_roundtrip(
			array(
				'id'          => self::$user_ids['editor'],
				'name'        => '\o/ ¯\_(ツ)_/¯',
				'first_name'  => '\o/ ¯\_(ツ)_/¯',
				'last_name'   => '\o/ ¯\_(ツ)_/¯',
				'url'         => '\o/ ¯\_(ツ)_/¯',
				'description' => '\o/ ¯\_(ツ)_/¯',
				'nickname'    => '\o/ ¯\_(ツ)_/¯',
				'password'    => 'o/ ¯_(ツ)_/¯ \'"',
			),
			array(
				'name'        => '\o/ ¯\_(ツ)_/¯',
				'first_name'  => '\o/ ¯\_(ツ)_/¯',
				'last_name'   => '\o/ ¯\_(ツ)_/¯',
				'url'         => 'http://o/%20¯_(ツ)_/¯',
				'description' => '\o/ ¯\_(ツ)_/¯',
				'nickname'    => '\o/ ¯\_(ツ)_/¯',
				'password'    => 'o/ ¯_(ツ)_/¯ \'"',
			)
		);
	}

	/**
	 * HTML is stripped from an editor's profile, except the tags allowed in a description.
	 *
	 * @since x.x.x
	 */
	public function test_user_roundtrip_as_editor_html(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		if ( is_multisite() ) {
			$this->assertFalse( current_user_can( 'unfiltered_html' ) );
			$this->verify_user_roundtrip(
				array(
					'id'          => self::$user_ids['editor'],
					'name'        => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'first_name'  => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'last_name'   => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'url'         => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'description' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'nickname'    => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'password'    => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				),
				array(
					'name'        => 'div strong',
					'first_name'  => 'div strong',
					'last_name'   => 'div strong',
					'url'         => 'http://divdiv/div%20strongstrong/strong%20scriptoh%20noes/script',
					'description' => $this->get_html_roundtrip_description(),
					'nickname'    => 'div strong',
					'password'    => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				)
			);
		} else {
			$this->assertTrue( current_user_can( 'unfiltered_html' ) );
			$this->verify_user_roundtrip(
				array(
					'id'          => self::$user_ids['editor'],
					'name'        => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'first_name'  => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'last_name'   => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'url'         => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'description' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'nickname'    => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
					'password'    => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				),
				array(
					'name'        => 'div strong',
					'first_name'  => 'div strong',
					'last_name'   => 'div strong',
					'url'         => 'http://divdiv/div%20strongstrong/strong%20scriptoh%20noes/script',
					'description' => $this->get_html_roundtrip_description(),
					'nickname'    => 'div strong',
					'password'    => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				)
			);
		}
	}

	/**
	 * Backslashes and entities survive a user created and updated by a super admin.
	 *
	 * @since x.x.x
	 */
	public function test_user_roundtrip_as_superadmin(): void {
		$this->login_as( 'superadmin' );
		$this->register_ability();

		$this->assertTrue( current_user_can( 'unfiltered_html' ) );
		$valid_username = is_multisite() ? 'noinvalidcharshere' : 'no-invalid-chars-here';
		$this->verify_user_roundtrip(
			array(
				'username'    => $valid_username,
				'name'        => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
				'first_name'  => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
				'last_name'   => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
				'url'         => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
				'description' => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
				'nickname'    => '\\\&\\\ &amp; &invalid; < &lt; &amp;lt;',
				'password'    => '& &amp; &invalid; < &lt; &amp;lt;',
			),
			array(
				'username'    => $valid_username,
				'name'        => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
				'first_name'  => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
				'last_name'   => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
				'url'         => 'http://&amp;%20&amp;%20&amp;invalid;%20%20&lt;%20&amp;lt;',
				'description' => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
				'nickname'    => '\\\&amp;\\\ &amp; &amp;invalid; &lt; &lt; &amp;lt;',
				'password'    => '& &amp; &invalid; < &lt; &amp;lt;',
			)
		);
	}

	/**
	 * HTML is stripped from a user created and updated by a super admin, except the tags
	 * allowed in a description.
	 *
	 * @since x.x.x
	 */
	public function test_user_roundtrip_as_superadmin_html(): void {
		$this->login_as( 'superadmin' );
		$this->register_ability();

		$this->assertTrue( current_user_can( 'unfiltered_html' ) );
		$valid_username = is_multisite() ? 'noinvalidcharshere' : 'no-invalid-chars-here';
		$this->verify_user_roundtrip(
			array(
				'username'    => $valid_username,
				'name'        => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				'first_name'  => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				'last_name'   => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				'url'         => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				'description' => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				'nickname'    => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
				'password'    => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
			),
			array(
				'username'    => $valid_username,
				'name'        => 'div strong',
				'first_name'  => 'div strong',
				'last_name'   => 'div strong',
				'url'         => 'http://divdiv/div%20strongstrong/strong%20scriptoh%20noes/script',
				'description' => $this->get_html_roundtrip_description(),
				'nickname'    => 'div strong',
				'password'    => '<div>div</div> <strong>strong</strong> <script>oh noes</script>',
			)
		);
	}

	/**
	 * On multisite, a site administrator cannot update a user of another site.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_update_item_from_different_site_as_site_administrator(): void {
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

		$result = $this->update(
			array(
				'id'         => $user_id,
				'first_name' => 'New Name',
			)
		);

		$this->assertAbilityDenied( $result, 'A user of another site should not be updated.' );
	}

	/**
	 * On multisite, a super admin cannot update a user of another site from this one.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_update_item_from_different_site_as_network_administrator(): void {
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

		$result = $this->update(
			array(
				'id'         => $user_id,
				'first_name' => 'New Name',
			)
		);

		$this->assertAbilityDenied( $result, 'A user of another site should not be updated.' );
	}

	/**
	 * The ID, the roles, and the fields can be given as strings.
	 *
	 * @since x.x.x
	 */
	public function test_update_accepts_string_input(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->update(
			array(
				'id'         => (string) $user_id,
				'first_name' => 'Stringly',
				'roles'      => 'editor,author',
				'fields'     => 'first_name,roles',
			)
		);

		$this->assertSame(
			array(
				'id'         => $user_id,
				'first_name' => 'Stringly',
				'roles'      => array( 'editor', 'author' ),
			),
			$result,
			'The string input should be read like its typed form.'
		);
	}

	/**
	 * The password is stored hashed and never returned, while the email address is.
	 *
	 * @since x.x.x
	 */
	public function test_update_never_returns_the_password(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'before@example.com' ) );

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->update(
			array(
				'id'       => $user_id,
				'email'    => 'after@example.com',
				'password' => 'a new secret',
				'fields'   => $this->all_fields(),
			)
		);

		$this->assertIsArray( $result, 'The user should be updated.' );
		$this->assertArrayNotHasKey( 'password', $result, 'The password should not be returned.' );
		$this->assertStringNotContainsString( 'a new secret', (string) wp_json_encode( $result ), 'The password should not appear in the output.' );
		$this->assertSame( 'after@example.com', $result['email'], 'The new email address should be returned.' );
		$this->assertTrue( wp_check_password( 'a new secret', get_userdata( $user_id )->user_pass ), 'The password should be stored hashed.' );
	}

	/**
	 * Changing only the roles takes the capability to promote the user, and anything else in
	 * the input, the fields included, takes the capability to edit the user.
	 *
	 * @since x.x.x
	 */
	public function test_update_only_roles_with_the_promote_capability(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->login_as( 'user_promoter' );
		$this->register_ability();

		$this->assertFalse( current_user_can( 'edit_user', $user_id ), 'Precondition: the user cannot edit the author.' );

		$result = $this->update(
			array(
				'id'    => $user_id,
				'roles' => array( 'editor' ),
			)
		);

		$this->assertIsArray( $result, 'Changing only the roles should take the capability to promote the user.' );
		$this->assertSame( array( 'id', 'name', 'link', 'slug', 'avatar_urls' ), array_keys( $result ), 'The lean default fields should be returned.' );
		$this->assertSame( array( 'editor' ), array_values( get_userdata( $user_id )->roles ), 'The role should be changed.' );

		$result = $this->update(
			array(
				'id'     => $user_id,
				'roles'  => array( 'author' ),
				'fields' => array( 'roles' ),
			)
		);

		$this->assertAbilityDenied( $result, 'Asking for fields should take the capability to edit the user.' );
		$this->assertSame( array( 'editor' ), array_values( get_userdata( $user_id )->roles ), 'The role should be unchanged.' );
	}

	/**
	 * An empty roles list removes every role of the user.
	 *
	 * @since x.x.x
	 */
	public function test_update_with_empty_roles_removes_every_role(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->update(
			array(
				'id'     => $user_id,
				'roles'  => array(),
				'fields' => array( 'roles' ),
			)
		);

		$this->assertIsArray( $result, 'The user should be updated.' );
		$this->assertSame( array(), $result['roles'], 'The user should be left without a role.' );
		$this->assertSame( array(), get_userdata( $user_id )->roles, 'No role should be stored.' );
	}

	/**
	 * An empty roles list, given as an array or as a string without roles, is a roles change,
	 * so changing only the roles takes the capability to promote the user.
	 *
	 * @since x.x.x
	 */
	public function test_update_with_empty_roles_takes_the_promote_capability(): void {
		$this->login_as( 'user_promoter' );
		$this->register_ability();

		foreach ( array( array(), ',', ' ' ) as $roles ) {
			$user_id = self::factory()->user->create( array( 'role' => 'author' ) );

			$result = $this->update(
				array(
					'id'    => $user_id,
					'roles' => $roles,
				)
			);

			$this->assertIsArray( $result, sprintf( 'A user who can promote the user should remove their roles with %s.', wp_json_encode( $roles ) ) );
			$this->assertSame( array(), get_userdata( $user_id )->roles, 'No role should be stored.' );
		}
	}

	/**
	 * A user who can edit another user but not promote them cannot remove their roles.
	 *
	 * @group ms-excluded
	 *
	 * @since x.x.x
	 */
	public function test_update_with_empty_roles_needs_the_promote_capability(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'On multisite only super admins can edit other users.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->login_as( 'user_editor' );
		$this->register_ability();

		$this->assertTrue( current_user_can( 'edit_user', $user_id ), 'Precondition: the user can edit the administrator.' );
		$this->assertFalse( current_user_can( 'promote_user', $user_id ), 'Precondition: the user cannot promote the administrator.' );

		$result = $this->update(
			array(
				'id'    => $user_id,
				'roles' => array(),
			)
		);

		$this->assertAbilityError( $result, 'users_cannot_edit_roles', 'Removing every role should take the capability to promote the user.', 403 );
		$this->assertSame( array( 'administrator' ), array_values( get_userdata( $user_id )->roles ), 'The role should be kept.' );
	}

	/**
	 * An administrator cannot remove their own roles, which would leave them unable to edit users.
	 *
	 * @group ms-excluded
	 *
	 * @since x.x.x
	 */
	public function test_update_refuses_removing_your_own_roles(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Super admins can change their own roles.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $user_id );
		$this->register_ability();

		foreach ( array( array(), ',' ) as $roles ) {
			$result = $this->update(
				array(
					'id'    => $user_id,
					'roles' => $roles,
				)
			);

			$this->assertAbilityError( $result, 'users_user_invalid_role', sprintf( 'An administrator should not remove their own roles with %s.', wp_json_encode( $roles ) ), 403 );
			$this->assertSame( array( 'administrator' ), array_values( get_userdata( $user_id )->roles ), 'The role should be kept.' );
		}
	}

	/**
	 * Sending a user's current roles back, in any order, is no roles change, so it does not take
	 * the capability to promote the user, and a user without a role can be sent back as read.
	 *
	 * @group ms-excluded
	 *
	 * @since x.x.x
	 */
	public function test_update_with_the_current_roles_is_no_roles_change(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'On multisite only super admins can edit other users.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $user_id )->add_role( 'editor' );
		$roleless_id = self::factory()->user->create( array( 'role' => '' ) );

		$this->login_as( 'user_editor' );
		$this->register_ability();

		$result = $this->update(
			array(
				'id'         => $user_id,
				'first_name' => 'Unchanged Roles',
				'roles'      => array( 'editor', 'author', 'editor' ),
			)
		);

		$this->assertIsArray( $result, 'The current roles should not take the capability to promote the user.' );
		$this->assertSame( 'Unchanged Roles', get_userdata( $user_id )->first_name, 'The other fields should be updated.' );
		$this->assertSame( array( 'author', 'editor' ), array_values( get_userdata( $user_id )->roles ), 'The roles should be kept.' );

		$result = $this->update(
			array(
				'id'         => $roleless_id,
				'first_name' => 'No Role',
				'roles'      => array(),
			)
		);

		$this->assertIsArray( $result, 'An empty list should be no change for a user without a role.' );
		$this->assertSame( 'No Role', get_userdata( $roleless_id )->first_name, 'The other fields should be updated.' );
		$this->assertSame( array(), get_userdata( $roleless_id )->roles, 'The user should stay without a role.' );
	}

	/**
	 * Only users who can edit users may update another user. On multisite that is only super
	 * admins.
	 *
	 * @dataProvider data_user_management_permissions
	 *
	 * @since x.x.x
	 *
	 * @param string|null $name    The user fixture name, or null for a logged-out user.
	 * @param bool        $allowed Whether the user may update another user on a single site.
	 */
	public function test_update_permissions( ?string $name, bool $allowed ): void {
		$user_id = self::factory()->user->create(
			array(
				'role'       => 'author',
				'first_name' => 'Before',
			)
		);

		$this->login_as( $name );

		if ( is_multisite() ) {
			$allowed = 'superadmin' === $name;
		}

		$this->register_ability();

		$result = $this->update(
			array(
				'id'         => $user_id,
				'first_name' => 'After',
				'fields'     => array( 'first_name' ),
			)
		);

		if ( $allowed ) {
			$this->assertSame(
				array(
					'id'         => $user_id,
					'first_name' => 'After',
				),
				$result,
				'The user should be updated.'
			);
			return;
		}

		$this->assertAbilityDenied( $result, 'The user should not be updated.' );
		$this->assertSame( 'Before', get_userdata( $user_id )->first_name, 'The user should be unchanged.' );
	}

	/**
	 * Provides the users who update their own profile.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string}> The user fixture name.
	 */
	public function data_users(): array {
		return array(
			'administrator' => array( 'administrator' ),
			'editor'        => array( 'editor' ),
			'author'        => array( 'author' ),
			'contributor'   => array( 'contributor' ),
			'subscriber'    => array( 'subscriber' ),
			'user creator'  => array( 'user_creator' ),
			'user promoter' => array( 'user_promoter' ),
			'super admin'   => array( 'superadmin' ),
		);
	}

	/**
	 * Every user can update their own profile.
	 *
	 * @dataProvider data_users
	 *
	 * @since x.x.x
	 *
	 * @param string $name The user fixture name.
	 */
	public function test_users_can_update_their_own_profile( string $name ): void {
		$user_id = $this->login_as( $name );
		$this->register_ability();

		$result = $this->update(
			array(
				'id'         => $user_id,
				'first_name' => 'Myself',
				'fields'     => array( 'first_name' ),
			)
		);

		$this->assertSame(
			array(
				'id'         => $user_id,
				'first_name' => 'Myself',
			),
			$result,
			'A user should be able to update their own profile.'
		);
	}

	/**
	 * A user that does not exist and a user the caller cannot edit are refused alike.
	 *
	 * @since x.x.x
	 */
	public function test_update_does_not_reveal_whether_a_user_exists(): void {
		$missing_id = self::factory()->user->create();
		self::delete_user( $missing_id );

		$this->login_as( 'editor' );
		$this->register_ability();

		foreach ( array( array( 'first_name' => 'Probe' ), array( 'roles' => array( 'editor' ) ) ) as $change ) {
			$missing   = $this->update( array( 'id' => $missing_id ) + $change );
			$forbidden = $this->update( array( 'id' => self::$user_ids['administrator'] ) + $change );

			$this->assertAbilityDenied( $missing, 'A missing user should be refused.' );
			$this->assertAbilityDenied( $forbidden, 'A user the caller cannot edit should be refused.' );
			$this->assertSame( $missing->get_error_message(), $forbidden->get_error_message(), 'Both refusals should read the same.' );
			$this->assertSame( $missing->get_error_data(), $forbidden->get_error_data(), 'Both refusals should carry the same data.' );
		}
	}
}

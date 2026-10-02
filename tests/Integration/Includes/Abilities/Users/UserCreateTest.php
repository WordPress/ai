<?php
/**
 * Integration tests for the core/user-create Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Users
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Users;

/**
 * User create ability test case.
 *
 * @since x.x.x
 */
class UserCreateTest extends Users_Ability_TestCase {

	/**
	 * Creates a user through the ability and returns the result.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return mixed The ability result.
	 */
	private function create( array $input ) {
		return $this->execute_ability( 'core/user-create', $input );
	}

	/**
	 * Returns the logins the illegal_user_logins filter refuses.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The illegal logins.
	 */
	public function get_illegal_user_logins(): array {
		return array( 'nope' );
	}

	/**
	 * The ability is registered as a closed-world write that is neither destructive nor
	 * idempotent, requires a username, an email address, and a password, rejects unknown
	 * properties, and returns a user shaped like a queried one.
	 *
	 * @since x.x.x
	 */
	public function test_registers_core_user_create_ability(): void {
		$this->register_ability();

		$ability     = wp_get_ability( 'core/user-create' );
		$annotations = $ability->get_meta_item( 'annotations', array() );
		$schema      = $ability->get_input_schema();

		$this->assertSame( 'user', $ability->get_category(), 'The registered ability should use the user category.' );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ), 'The ability should be exposed in REST.' );
		$this->assertFalse( $annotations['readonly'], 'The ability should not be marked read-only.' );
		$this->assertFalse( $annotations['destructive'], 'Creating a user is not destructive.' );
		$this->assertFalse( $annotations['idempotent'], 'Every call creates a new user, so the ability is not idempotent.' );
		$this->assertFalse( $annotations['open_world'], 'The ability only writes to the local database.' );
		$this->assertSame( array( 'username', 'email', 'password' ), $schema['required'], 'The username, email address, and password should be required.' );
		$this->assertFalse( $schema['additionalProperties'], 'Unknown properties should be rejected.' );
		$this->assertSame( array( 'username', 'name', 'first_name', 'last_name', 'email', 'url', 'description', 'locale', 'nickname', 'slug', 'roles', 'password', 'fields' ), array_keys( $schema['properties'] ), 'The input should take the writable user fields and the field selection.' );
		$this->assertSame( wp_list_pluck( wp_get_ability( 'core/users-query' )->get_output_schema()['oneOf'][0]['properties'], 'type' ), wp_list_pluck( $ability->get_output_schema()['properties'], 'type' ), 'The created user should have the same fields as a queried user.' );
	}

	/**
	 * When core already provides core/user-create, the plugin's version replaces it.
	 *
	 * @since x.x.x
	 */
	public function test_override_replaces_existing_core_user_create(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				'core/user-create',
				array(
					'label'               => 'Core Provided',
					'description'         => 'Core provided create ability.',
					'category'            => 'user',
					'execute_callback'    => '__return_empty_array',
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->register_ability();

		$this->assertSame( 'User Create', wp_get_ability( 'core/user-create' )->get_label(), 'The plugin-provided ability should replace the existing one.' );
	}

	/**
	 * A user is created with every writable field.
	 *
	 * @since x.x.x
	 */
	public function test_create_item(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$params = array(
			'username'    => 'testuser',
			'password'    => 'testpassword',
			'email'       => 'test@example.com',
			'name'        => 'Test User',
			'nickname'    => 'testuser',
			'slug'        => 'test-user',
			'roles'       => array( 'editor' ),
			'description' => 'New API User',
			'url'         => 'http://example.com',
			'fields'      => $this->all_fields(),
		);

		$data = $this->create( $params );

		$this->assertIsArray( $data, 'Creating a user should return the created user.' );
		$this->assertSame( 'http://example.com', $data['url'] );
		$this->assertSame( array( 'editor' ), $data['roles'] );
		$this->check_user_data( $data );
	}

	/**
	 * A username with illegal characters is refused before anything is written.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_invalid_username(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$params = array(
			'username'    => '¯\_(ツ)_/¯',
			'password'    => 'testpassword',
			'email'       => 'test@example.com',
			'name'        => 'Test User',
			'nickname'    => 'testuser',
			'slug'        => 'test-user',
			'roles'       => array( 'editor' ),
			'description' => 'New API User',
			'url'         => 'http://example.com',
		);

		// Username rules are different (more strict) for multisite; see `wpmu_validate_user_signup`.
		if ( is_multisite() ) {
			$params['username'] = 'no-dashes-allowed';
		}

		$result = $this->create( $params );
		$this->assertAbilityError( $result, 'users_invalid_param', 'An invalid username should be refused.', 400 );

		if ( is_multisite() ) {
			$this->assertSame( array( 'users_invalid_param', 'user_name' ), $result->get_error_codes(), 'The multisite signup error should be added.' );
			$this->assertSame( 'Usernames can only contain lowercase letters (a-z) and numbers.', $result->get_error_message( 'user_name' ) );
		} else {
			$errors = $result->get_error_data()['params'];
			$this->assertIsString( $errors['username'] );
			$this->assertSame( 'This username is invalid because it uses illegal characters. Please enter a valid username.', $errors['username'] );
		}

		$this->assertFalse( get_user_by( 'email', 'test@example.com' ), 'No user should be created.' );
	}

	/**
	 * A username the illegal_user_logins filter refuses is refused.
	 *
	 * @since x.x.x
	 */
	public function test_create_item_illegal_username(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		add_filter( 'illegal_user_logins', array( $this, 'get_illegal_user_logins' ) );

		$params = array(
			'username'    => 'nope',
			'password'    => 'testpassword',
			'email'       => 'test@example.com',
			'name'        => 'Test User',
			'nickname'    => 'testuser',
			'slug'        => 'test-user',
			'roles'       => array( 'editor' ),
			'description' => 'New API User',
			'url'         => 'http://example.com',
		);

		$result = $this->create( $params );

		remove_filter( 'illegal_user_logins', array( $this, 'get_illegal_user_logins' ) );

		$this->assertAbilityError( $result, 'users_invalid_param', 'An illegal username should be refused.', 400 );

		$errors = $result->get_error_data()['params'];
		$this->assertIsString( $errors['username'] );
		$this->assertSame( 'Sorry, that username is not allowed.', $errors['username'] );
	}

	/**
	 * On multisite, a user created on the main site is not added to another site.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_create_new_network_user_on_site_does_not_add_user_to_sub_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$params = array(
			'username' => 'testuser123',
			'password' => 'testpassword',
			'email'    => 'test@example.com',
			'name'     => 'Test User 123',
			'roles'    => array( 'editor' ),
		);

		$data    = $this->create( $params );
		$user_id = $data['id'];

		$user_is_member = is_user_member_of_blog( $user_id, self::$site );

		wpmu_delete_user( $user_id );

		$this->assertFalse( $user_is_member );
	}

	/**
	 * On multisite, a failure to add the new user to the site is returned.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_create_new_network_user_with_add_user_to_blog_failure(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$params = array(
			'username' => 'testuser123',
			'password' => 'testpassword',
			'email'    => 'test@example.com',
			'name'     => 'Test User 123',
			'roles'    => array( 'editor' ),
		);

		add_filter( 'can_add_user_to_blog', '__return_false' );

		$result = $this->create( $params );
		$this->assertAbilityError( $result, 'user_cannot_be_added', 'The failure to add the user to the site should be returned.' );
	}

	/**
	 * On multisite, a user created on a site is added to that site.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_create_new_network_user_on_sub_site_adds_user_to_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$params = array(
			'username' => 'testuser123',
			'password' => 'testpassword',
			'email'    => 'test@example.com',
			'name'     => 'Test User 123',
			'roles'    => array( 'editor' ),
		);

		switch_to_blog( self::$site );

		$data    = $this->create( $params );
		$user_id = $data['id'];

		restore_current_blog();

		$user_is_member = is_user_member_of_blog( $user_id, self::$site );

		wpmu_delete_user( $user_id );

		$this->assertTrue( $user_is_member );
	}

	/**
	 * On multisite, a user created on an archived site, which has no members, is returned with
	 * their roles.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_create_on_an_archived_site_returns_the_user(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		update_blog_status( get_current_blog_id(), 'archived', '1' );

		$result = $this->create(
			array(
				'username' => 'archivedsiteuser',
				'email'    => 'archived-site-user@example.com',
				'password' => 'password',
				'roles'    => array( 'editor' ),
			)
		);

		$this->assertIsArray( $result, 'The user should be returned.' );
		$this->assertSame( array( 'editor' ), array_values( get_userdata( $result['id'] )->roles ), 'The role should be added.' );
	}

	/**
	 * On multisite, creating a user whose username and email address are taken on the network
	 * returns both signup errors.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_create_existing_network_user_on_sub_site_has_error(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$params = array(
			'username' => 'testuser123',
			'password' => 'testpassword',
			'email'    => 'test@example.com',
			'name'     => 'Test User 123',
			'roles'    => array( 'editor' ),
		);

		$data    = $this->create( $params );
		$user_id = $data['id'];

		switch_to_blog( self::$site );

		$switched_result = $this->create( $params );

		restore_current_blog();

		wpmu_delete_user( $user_id );

		$this->assertAbilityError( $switched_result, 'users_invalid_param', 'A taken username and email address should be refused.', 400 );
		$this->assertSame( array( 'users_invalid_param', 'user_name', 'user_email' ), $switched_result->get_error_codes(), 'Both signup errors should be added.' );
		$this->assertSame( 'Sorry, that username already exists!', $switched_result->get_error_message( 'user_name' ) );
		$expected = '<strong>Error:</strong> This email address is already registered. ' .
					'<a href="http://rest.wordpress.org/wp-login.php">Log in</a> with ' .
					'this address or choose another one.';
		$this->assertSame( $expected, $switched_result->get_error_message( 'user_email' ) );
	}

	/**
	 * A user is created from the required fields alone.
	 *
	 * @since x.x.x
	 */
	public function test_json_create_user(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$params = array(
			'username' => 'testjsonuser',
			'password' => 'testjsonpassword',
			'email'    => 'testjson@example.com',
			'fields'   => $this->all_fields(),
		);

		$this->check_user_data( $this->create( $params ) );
	}

	/**
	 * A user who cannot create users is denied.
	 *
	 * @since x.x.x
	 */
	public function test_create_user_without_permission(): void {
		$this->login_as( 'editor' );
		$this->register_ability();

		$params = array(
			'username' => 'homersimpson',
			'password' => 'stupidsexyflanders',
			'email'    => 'chunkylover53@aol.com',
		);

		$this->assertAbilityDenied( $this->create( $params ), 'An editor should not create users.' );
		$this->assertFalse( username_exists( 'homersimpson' ), 'No user should be created.' );
	}

	/**
	 * An ID is not an input of the create ability.
	 *
	 * @since x.x.x
	 */
	public function test_create_user_invalid_id(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$params = array(
			'id'       => '156',
			'username' => 'lisasimpson',
			'password' => 'DavidHasselhoff',
			'email'    => 'smartgirl63_@yahoo.com',
		);

		$this->assertAbilityInvalidInput( $this->create( $params ), 'An ID should be rejected.' );
		$this->assertFalse( username_exists( 'lisasimpson' ), 'No user should be created.' );
	}

	/**
	 * An invalid email address is rejected.
	 *
	 * @since x.x.x
	 */
	public function test_create_user_invalid_email(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$params = array(
			'username' => 'lisasimpson',
			'password' => 'DavidHasselhoff',
			'email'    => 'something',
		);

		$this->assertAbilityInvalidInput( $this->create( $params ), 'An invalid email address should be rejected.' );
	}

	/**
	 * A role that does not exist is refused.
	 *
	 * @since x.x.x
	 */
	public function test_create_user_invalid_role(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$params = array(
			'username' => 'maggiesimpson',
			'password' => 'i_shot_mrburns',
			'email'    => 'packingheat@example.com',
			'roles'    => array( 'baby' ),
		);

		$this->assertAbilityError( $this->create( $params ), 'users_user_invalid_role', 'A missing role should be refused.', 400 );
		$this->assertFalse( username_exists( 'maggiesimpson' ), 'No user should be created.' );
	}

	/**
	 * Without `fields`, the created user is returned with the lean default fields.
	 *
	 * @since x.x.x
	 */
	public function test_create_returns_lean_default_fields(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->create(
			array(
				'username' => 'leanuser',
				'password' => 'testpassword',
				'email'    => 'lean@example.com',
			)
		);

		$this->assertIsArray( $result, 'The user should be created.' );
		$this->assertSame( array( 'id', 'name', 'link', 'slug', 'avatar_urls' ), array_keys( $result ), 'The lean default fields should be returned.' );
	}

	/**
	 * The fields can be given as a comma-separated string, and the ID is always returned.
	 *
	 * @since x.x.x
	 */
	public function test_create_accepts_fields_as_a_string(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->create(
			array(
				'username' => 'csvfields',
				'password' => 'testpassword',
				'email'    => 'csv@example.com',
				'fields'   => 'email,username',
			)
		);

		$this->assertIsArray( $result, 'The user should be created.' );
		$this->assertSame( array( 'id', 'username', 'email' ), array_keys( $result ), 'The requested fields and the ID should be returned.' );
		$this->assertSame( 'csvfields', $result['username'] );
	}

	/**
	 * The password is stored hashed and never returned.
	 *
	 * @since x.x.x
	 */
	public function test_create_never_returns_the_password(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->create(
			array(
				'username' => 'secretuser',
				'password' => 'a secret password',
				'email'    => 'secret@example.com',
				'fields'   => $this->all_fields(),
			)
		);

		$this->assertIsArray( $result, 'The user should be created.' );
		$this->assertArrayNotHasKey( 'password', $result, 'The password should not be returned.' );
		$this->assertStringNotContainsString( 'a secret password', (string) wp_json_encode( $result ), 'The password should not appear in the output.' );
		$this->assertTrue( wp_check_password( 'a secret password', get_userdata( $result['id'] )->user_pass ), 'The password should be stored hashed.' );
	}

	/**
	 * Every invalid parameter is reported at once, with its reason, and the password never is.
	 *
	 * @since x.x.x
	 */
	public function test_create_reports_every_invalid_parameter(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->create(
			array(
				'username' => '¯\_(ツ)_/¯',
				'password' => 'top\\secret',
				'email'    => 'invalid-both@example.com',
			)
		);

		$this->assertAbilityError( $result, 'users_invalid_param', 'Both parameters should be refused.', 400 );
		$this->assertSame( 'Invalid parameter(s): username, password', $result->get_error_message() );
		$this->assertSame( array( 'username', 'password' ), array_keys( $result->get_error_data()['params'] ) );
		$this->assertSame( 'users_user_invalid_username', $result->get_error_data()['details']['username']['code'] );
		$this->assertSame( 'users_user_invalid_password', $result->get_error_data()['details']['password']['code'] );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( $result->get_error_data() ), 'The password should not appear in the error.' );
	}

	/**
	 * An empty password is refused.
	 *
	 * @since x.x.x
	 */
	public function test_create_refuses_an_empty_password(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->create(
			array(
				'username' => 'emptypassword',
				'password' => '',
				'email'    => 'empty@example.com',
			)
		);

		$this->assertAbilityError( $result, 'users_invalid_param', 'An empty password should be refused.', 400 );
		$this->assertSame( 'Passwords cannot be empty.', $result->get_error_data()['params']['password'] );
	}

	/**
	 * A username that is taken is refused by core.
	 *
	 * @group ms-excluded
	 *
	 * @since x.x.x
	 */
	public function test_create_with_a_taken_username(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Multisite checks the signup first.' );
		}

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->create(
			array(
				'username' => 'superadmin',
				'password' => 'testpassword',
				'email'    => 'taken-login@example.com',
			)
		);

		$this->assertAbilityError( $result, 'existing_user_login', 'A taken username should be refused.' );
		$this->assertFalse( get_user_by( 'email', 'taken-login@example.com' ), 'No user should be created.' );
	}

	/**
	 * An email address that is taken is refused by core.
	 *
	 * @group ms-excluded
	 *
	 * @since x.x.x
	 */
	public function test_create_with_a_taken_email(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Multisite checks the signup first.' );
		}

		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->create(
			array(
				'username' => 'takenemail',
				'password' => 'testpassword',
				'email'    => 'editor@example.com',
			)
		);

		$this->assertAbilityError( $result, 'existing_user_email', 'A taken email address should be refused.' );
		$this->assertFalse( username_exists( 'takenemail' ), 'No user should be created.' );
	}

	/**
	 * A taken slug is made unique instead of refused.
	 *
	 * @since x.x.x
	 */
	public function test_create_with_a_taken_slug_makes_it_unique(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$taken = get_userdata( self::$user_ids['editor'] )->user_nicename;

		$result = $this->create(
			array(
				'username' => 'takenslug',
				'password' => 'testpassword',
				'email'    => 'taken-slug@example.com',
				'slug'     => $taken,
				'fields'   => array( 'slug' ),
			)
		);

		$this->assertIsArray( $result, 'The user should be created.' );
		$this->assertSame( $taken . '-2', $result['slug'], 'The slug should get a suffix.' );
	}

	/**
	 * Roles given as a comma-separated string are all assigned.
	 *
	 * @since x.x.x
	 */
	public function test_create_with_roles_as_a_string(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->create(
			array(
				'username' => 'tworoles',
				'password' => 'testpassword',
				'email'    => 'two-roles@example.com',
				'roles'    => 'author,editor',
				'fields'   => array( 'roles' ),
			)
		);

		$this->assertIsArray( $result, 'The user should be created.' );
		$this->assertSame( array( 'author', 'editor' ), $result['roles'], 'Both roles should be assigned.' );
	}

	/**
	 * An empty roles list creates a user with no role.
	 *
	 * @since x.x.x
	 */
	public function test_create_with_empty_roles_assigns_no_role(): void {
		$this->allow_user_to_manage_multisite();
		$this->register_ability();

		$result = $this->create(
			array(
				'username' => 'noroles',
				'password' => 'testpassword',
				'email'    => 'no-roles@example.com',
				'roles'    => array(),
				'fields'   => array( 'roles' ),
			)
		);

		$this->assertIsArray( $result, 'The user should be created.' );
		$this->assertSame( array(), $result['roles'], 'The user should have no role.' );
	}

	/**
	 * Giving roles while creating a user takes the capability to create users, not to promote
	 * them, and the user is returned in the edit context without the roles of a user the caller
	 * cannot edit or list.
	 *
	 * @group ms-excluded
	 *
	 * @since x.x.x
	 */
	public function test_create_with_roles_does_not_need_the_promote_capability(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'On multisite only super admins can create users by default.' );
		}

		$this->login_as( 'user_creator' );
		$this->register_ability();

		$this->assertFalse( current_user_can( 'promote_users' ), 'Precondition: the user cannot promote users.' );

		$result = $this->create(
			array(
				'username' => 'createdadmin',
				'password' => 'testpassword',
				'email'    => 'created-admin@example.com',
				'roles'    => array( 'administrator' ),
				'fields'   => $this->all_fields(),
			)
		);

		$this->assertIsArray( $result, 'The user should be created.' );
		$this->assertSame( array( 'administrator' ), get_userdata( $result['id'] )->roles, 'The role should be assigned.' );
		$this->assertSame( 'created-admin@example.com', $result['email'], 'The edit context should return the email address.' );
		$this->assertArrayNotHasKey( 'roles', $result, 'The roles should not be returned to a user who cannot edit or list users.' );
	}

	/**
	 * Provides the roles that may or may not create users.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string|null, 1: bool}> The user fixture name, or null for a logged-out user, and whether the user may create users.
	 */
	public function data_create_permissions(): array {
		return array(
			'administrator' => array( 'administrator', true ),
			'editor'        => array( 'editor', false ),
			'author'        => array( 'author', false ),
			'contributor'   => array( 'contributor', false ),
			'subscriber'    => array( 'subscriber', false ),
			'user creator'  => array( 'user_creator', true ),
			'super admin'   => array( 'superadmin', true ),
			'logged out'    => array( null, false ),
		);
	}

	/**
	 * Only users who can create users may use the ability. On multisite that is only super
	 * admins, as long as the network does not let site administrators add users.
	 *
	 * @dataProvider data_create_permissions
	 *
	 * @since x.x.x
	 *
	 * @param string|null $name    The user fixture name, or null for a logged-out user.
	 * @param bool        $allowed Whether the user may create users on a single site.
	 */
	public function test_create_permissions( ?string $name, bool $allowed ): void {
		if ( null === $name ) {
			wp_set_current_user( 0 );
		} else {
			$this->login_as( $name );
		}

		if ( is_multisite() ) {
			$allowed = 'superadmin' === $name;
		}

		$this->register_ability();

		$result = $this->create(
			array(
				'username' => 'permissionuser',
				'password' => 'testpassword',
				'email'    => 'permission@example.com',
			)
		);

		if ( $allowed ) {
			$this->assertIsArray( $result, 'The user should be created.' );
			$this->assertSame( (int) username_exists( 'permissionuser' ), $result['id'], 'The created user should be returned.' );
			return;
		}

		$this->assertAbilityDenied( $result, 'The user should not be created.' );
		$this->assertFalse( username_exists( 'permissionuser' ), 'No user should be created.' );
	}

	/**
	 * The writable fields carry the names and types of the users endpoint fields.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_matches_the_users_endpoint_fields(): void {
		$this->register_ability();

		$properties = wp_get_ability( 'core/user-create' )->get_input_schema()['properties'];
		$endpoint   = ( new \WP_REST_Users_Controller() )->get_item_schema()['properties'];

		unset( $properties['fields'] );

		foreach ( $properties as $field => $definition ) {
			$this->assertArrayHasKey( $field, $endpoint, "The {$field} field should be a field of the users endpoint." );
			$this->assertSame( $endpoint[ $field ]['type'], $definition['type'], "The {$field} field should have the type of the endpoint field." );
			$this->assertSame( $endpoint[ $field ]['enum'] ?? null, $definition['enum'] ?? null, "The {$field} field should accept the values of the endpoint field." );
		}

		foreach ( array_keys( wp_get_ability( 'core/user-create' )->get_output_schema()['properties'] ) as $field ) {
			$this->assertArrayHasKey( $field, $endpoint, "The returned {$field} field should be a field of the users endpoint." );
		}
	}
}

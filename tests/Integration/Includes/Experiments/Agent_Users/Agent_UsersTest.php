<?php
/**
 * Integration tests for the Agent_Users experiment.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Agent_Users
 */

namespace WordPress\AI\Tests\Integration\Experiments\Agent_Users;

use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\Experiments\Agent_Users\Agent_Account;
use WordPress\AI\Experiments\Agent_Users\Agent_Users;
use WordPress\AI\Experiments\Agent_Users\New_User_Screen;
use WordPress\AI\Experiments\Agent_Users\Profile_Screen;
use WordPress\AI\Experiments\Agent_Users\Users_Screen;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;
use WordPress\AI\Main;

/**
 * Agent_Users experiment test case.
 *
 * @since x.x.x
 */
class Agent_UsersTest extends WP_UnitTestCase {
	/**
	 * Experiment instance under test.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\Agent_Users\Agent_Users
	 */
	private Agent_Users $experiment;

	/**
	 * Agent account service.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\Agent_Users\Agent_Account
	 */
	private Agent_Account $account;

	/**
	 * Administrator performing the provisioning in tests.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_agent-users_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'agent-users' );
		$this->assertInstanceOf(
			Agent_Users::class,
			$experiment,
			'Agent Users experiment should be registered in the registry.'
		);

		$this->experiment = $experiment;
		$this->account    = new Agent_Account();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		global $wp_rest_server, $pagenow;
		$wp_rest_server = null;
		$pagenow        = 'index.php';
		unset( $GLOBALS['current_screen'] );

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_agent-users_enabled' );
		remove_all_filters( 'wpai_feature_agent-users_enabled' );
		remove_all_filters( 'wp_redirect' );
		remove_all_actions( 'user_profile_update_errors' );
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available_for_user', '__return_false', 5 );
		remove_role( 'wpai_agent_create_only' );
		remove_role( 'wpai_agent_limited_manager' );
		parent::tearDown();
	}

	/**
	 * Provisions an agent and returns the user.
	 *
	 * @since x.x.x
	 *
	 * @param string $login Agent username.
	 * @param string $role  Role slug.
	 * @return \WP_User Provisioned agent.
	 */
	private function provision_agent( string $login = 'test-agent', string $role = 'editor' ): \WP_User {
		$result = $this->account->provision( $login, $role, $login . '@example.com' );
		$this->assertInstanceOf( \WP_User::class, $result, 'Provisioning should succeed.' );

		return $result;
	}

	/**
	 * Test that the experiment metadata is registered correctly.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_registration() {
		$this->assertSame( 'agent-users', $this->experiment->get_id() );
		$this->assertSame( 'Agent Users', $this->experiment->get_label() );
		$this->assertSame( Experiment_Category::ADMIN, $this->experiment->get_category() );
		$this->assertSame( 'experimental', $this->experiment->get_stability() );
		$this->assertSame( 'none', $this->experiment->get_capability() );
	}

	/**
	 * Test that provisioning creates a flagged account with the expected shape.
	 *
	 * @since x.x.x
	 */
	public function test_provision_creates_flagged_account() {
		$agent = $this->provision_agent( 'content-editor-agent', 'editor' );

		$this->assertTrue( Agent_Account::is_agent( $agent ) );
		$this->assertTrue( Agent_Account::is_agent( $agent->ID ) );
		$this->assertSame( array( 'editor' ), $agent->roles );
		$this->assertSame( 'content-editor-agent', $agent->user_login );
		$this->assertSame( 'content-editor-agent', $agent->display_name );
		$this->assertSame( 'content-editor-agent@example.com', $agent->user_email );
		$this->assertSame( $this->admin_id, (int) get_user_meta( $agent->ID, Agent_Account::META_CREATED_BY, true ) );

		$this->assertCount(
			0,
			\WP_Application_Passwords::get_user_application_passwords( $agent->ID ),
			'Credentials should be created separately through core\'s one-time REST reveal flow.'
		);
	}

	/**
	 * Test that human accounts are not agents.
	 *
	 * @since x.x.x
	 */
	public function test_human_accounts_are_not_agents() {
		$this->assertFalse( Agent_Account::is_agent( $this->admin_id ) );
		$this->assertFalse( Agent_Account::is_agent( 0 ) );
	}

	/**
	 * Test that provisioning validates its input.
	 *
	 * @since x.x.x
	 */
	public function test_provision_validates_input() {
		$empty_login = $this->account->provision( '   ', 'editor', 'a@example.com' );
		$this->assertWPError( $empty_login );
		$this->assertSame( 'wpai_agent_empty_login', $empty_login->get_error_code() );

		$bad_role = $this->account->provision( 'test-agent', 'does-not-exist', 'a@example.com' );
		$this->assertWPError( $bad_role );
		$this->assertSame( 'wpai_agent_invalid_role', $bad_role->get_error_code() );

		$symbols_only = $this->account->provision( '!!!', 'editor', 'a@example.com' );
		$this->assertWPError( $symbols_only );
		$this->assertSame( 'wpai_agent_empty_login', $symbols_only->get_error_code() );

		$taken = $this->account->provision( get_userdata( $this->admin_id )->user_login, 'editor', 'a@example.com' );
		$this->assertWPError( $taken );
		$this->assertSame( 'wpai_agent_login_exists', $taken->get_error_code() );

		$no_email = $this->account->provision( 'no-email-agent', 'editor', '' );
		$this->assertWPError( $no_email );
		$this->assertSame( 'wpai_agent_empty_email', $no_email->get_error_code() );

		$bad_email = $this->account->provision( 'bad-email-agent', 'editor', 'not-an-email' );
		$this->assertWPError( $bad_email );
		$this->assertSame( 'wpai_agent_invalid_email', $bad_email->get_error_code() );

		$taken_email = $this->account->provision( 'taken-email-agent', 'editor', get_userdata( $this->admin_id )->user_email );
		$this->assertWPError( $taken_email );
		$this->assertSame( 'wpai_agent_email_exists', $taken_email->get_error_code() );
	}

	/**
	 * Test that provisioning rejects users who cannot create accounts.
	 *
	 * @since x.x.x
	 */
	public function test_provision_requires_user_creation_capability() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$result = $this->account->provision( 'unauthorized-agent', 'subscriber', 'x@example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'wpai_agent_cannot_create_users', $result->get_error_code() );
	}

	/**
	 * Test that creating users alone does not authorize assigning an agent role.
	 *
	 * @since x.x.x
	 */
	public function test_provision_requires_role_assignment_capability() {
		add_role( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Registering a throwaway role in an integration test.
			'wpai_agent_create_only',
			'Agent Create Only',
			array(
				'read'         => true,
				'create_users' => true,
			)
		);

		$manager_id = self::factory()->user->create( array( 'role' => 'wpai_agent_create_only' ) );
		wp_set_current_user( $manager_id );

		$result = $this->account->provision( 'escalating-agent', 'subscriber', 'x@example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'wpai_agent_cannot_promote_users', $result->get_error_code() );
	}

	/**
	 * Test that an agent role cannot exceed its creator's effective capabilities.
	 *
	 * @since x.x.x
	 */
	public function test_provision_rejects_role_more_powerful_than_creator() {
		add_role( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Registering a throwaway role in an integration test.
			'wpai_agent_limited_manager',
			'Agent Limited Manager',
			array(
				'read'          => true,
				'create_users'  => true,
				'promote_users' => true,
			)
		);

		$manager_id = self::factory()->user->create( array( 'role' => 'wpai_agent_limited_manager' ) );
		wp_set_current_user( $manager_id );

		$assignable_roles = $this->account->get_assignable_roles();
		$this->assertArrayHasKey( 'subscriber', $assignable_roles );
		$this->assertArrayNotHasKey( 'editor', $assignable_roles );
		$this->assertArrayNotHasKey( 'administrator', $assignable_roles );

		$rejected = $this->account->provision( 'escalating-agent', 'administrator', 'x@example.com' );
		$this->assertWPError( $rejected );
		$this->assertSame( 'wpai_agent_role_not_assignable', $rejected->get_error_code() );

		$allowed = $this->account->provision( 'read-only-agent', 'subscriber', 'x@example.com' );
		$this->assertInstanceOf( \WP_User::class, $allowed );
		$this->assertSame( array( 'subscriber' ), $allowed->roles );
	}

	/**
	 * Test that the display name is derived from the names like core does.
	 *
	 * @since x.x.x
	 */
	public function test_provision_display_name() {
		$plain = $this->provision_agent( 'plain-agent' );
		$this->assertSame( 'plain-agent', $plain->display_name );

		$named = $this->account->provision( 'named-agent', 'editor', 'owner@example.com', 'Content', 'Assistant', 'https://example.com/assistant' );
		$this->assertInstanceOf( \WP_User::class, $named );
		$this->assertSame( 'Content Assistant', $named->display_name );
		$this->assertSame( 'https://example.com/assistant', $named->user_url );
		$this->assertSame( 'owner@example.com', $named->user_email );
		$this->assertSame( 'Content', $named->first_name );
		$this->assertSame( 'Assistant', $named->last_name );
	}

	/**
	 * Test that interactive login is blocked for agents but not humans.
	 *
	 * @since x.x.x
	 */
	public function test_interactive_login_blocked_for_agents() {
		$agent = $this->provision_agent();

		$blocked = wp_authenticate_username_password( null, $agent->user_login, 'any-password' );
		$this->assertWPError( $blocked );
		$this->assertSame( 'wpai_agent_login_disabled', $blocked->get_error_code() );

		$human_id = self::factory()->user->create(
			array(
				'role'      => 'editor',
				'user_pass' => 'human-password-1',
			)
		);
		$human    = get_user_by( 'id', $human_id );

		$allowed = wp_authenticate_username_password( null, $human->user_login, 'human-password-1' );
		$this->assertNotWPError( $allowed );
		$this->assertSame( $human_id, $allowed->ID );
	}

	/**
	 * Test that password resets are disabled for agents.
	 *
	 * @since x.x.x
	 */
	public function test_password_reset_disabled_for_agents() {
		$agent = $this->provision_agent();

		$reset = get_password_reset_key( $agent );
		$this->assertWPError( $reset );
		$this->assertSame( 'no_password_reset', $reset->get_error_code() );
	}

	/**
	 * Test that blocked capabilities are removed regardless of role.
	 *
	 * @since x.x.x
	 */
	public function test_blocked_capabilities_removed_from_agents() {
		$agent = $this->provision_agent( 'admin-agent', 'administrator' );

		$this->assertFalse( user_can( $agent, 'unfiltered_html' ) );
		$this->assertFalse( user_can( $agent, 'create_users' ) );
		$this->assertFalse( user_can( $agent, 'edit_users' ) );
		$this->assertFalse( user_can( $agent, 'promote_users' ) );
		$this->assertFalse( user_can( $agent, 'delete_users' ) );
		$this->assertFalse( user_can( $agent, 'edit_user', $this->admin_id ) );

		// The role stays the capability ceiling for everything else.
		$this->assertTrue( user_can( $agent, 'manage_options' ) );
		$this->assertTrue( user_can( $agent, 'edit_others_posts' ) );

		// Humans keep their capabilities untouched.
		$this->assertTrue( user_can( $this->admin_id, 'create_users' ) );
		$this->assertTrue( user_can( $this->admin_id, 'edit_users' ) );
	}

	/**
	 * Test that account safeguards remain active when the experiment is disabled.
	 *
	 * @since x.x.x
	 */
	public function test_safeguards_remain_when_experiment_is_disabled() {
		$agent = $this->provision_agent( 'disabled-experiment-agent', 'administrator' );

		update_option( 'wpai_feature_agent-users_enabled', false );
		$this->remove_agent_account_safeguards();

		// This is the always-on bootstrap path, independent of feature loading.
		Main::get_instance()->register_agent_account_safeguards();

		$this->assertFalse( user_can( $agent, 'unfiltered_html' ) );
		$this->assertFalse( user_can( $agent, 'create_users' ) );
		$this->assertFalse( user_can( $agent, 'edit_users' ) );

		$blocked = wp_authenticate_username_password( null, $agent->user_login, 'any-password' );
		$this->assertWPError( $blocked );
		$this->assertSame( 'wpai_agent_login_disabled', $blocked->get_error_code() );
	}

	/**
	 * Test that Application Passwords stay available for agents.
	 *
	 * @since x.x.x
	 */
	public function test_application_passwords_stay_available_for_agents() {
		$agent = $this->provision_agent();
		$human = get_user_by( 'id', $this->admin_id );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available_for_user', '__return_false', 5 );

		$this->assertTrue( wp_is_application_passwords_available_for_user( $agent ) );
		$this->assertFalse( wp_is_application_passwords_available_for_user( $human ) );

		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available_for_user', '__return_false', 5 );
	}

	/**
	 * Test core's REST flow reveals an Application Password only on creation.
	 *
	 * @since x.x.x
	 */
	public function test_core_rest_flow_creates_application_password() {
		global $wp_rest_server;
		$wp_rest_server = null;

		$agent = $this->provision_agent();

		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		$request = new WP_REST_Request( 'POST', '/wp/v2/users/' . $agent->ID . '/application-passwords' );
		$request->set_param( 'name', 'Test MCP Client' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertNotEmpty( $data['password'] );
		$this->assertCount( 1, \WP_Application_Passwords::get_user_application_passwords( $agent->ID ) );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/users/' . $agent->ID . '/application-passwords/' . $data['uuid'] );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'password', $response->get_data() );

		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	/**
	 * Test the Add User screen renders the agent fields only in agent mode.
	 *
	 * @since x.x.x
	 */
	public function test_new_user_screen_renders_agent_fields() {
		global $pagenow;

		$screen  = new New_User_Screen( $this->account );
		$pagenow = 'user-new.php';

		ob_start();
		$screen->render_fields( 'add-new-user' );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Add Agent</a>', $output, 'Regular mode points to the agent flow.' );
		$this->assertStringNotContainsString( 'name="wpai_agent"', $output );

		$_REQUEST['wpai_agent'] = '1';
		ob_start();
		$screen->render_fields( 'add-new-user' );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'type="hidden" name="wpai_agent" value="1"', $output );
		$this->assertStringContainsString( 'Add User</a>', $output, 'Agent mode points back to the regular flow.' );

		ob_start();
		$screen->render_fields( 'add-existing-user' );
		$this->assertSame( '', ob_get_clean(), 'Existing users cannot become agents.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		$screen->render_fields( 'add-new-user' );
		$this->assertSame( '', ob_get_clean(), 'Users who cannot provision should not see the option.' );
	}

	/**
	 * Test the "Add Agent" submenu opens the shared form in agent mode.
	 *
	 * @since x.x.x
	 */
	public function test_add_agent_submenu() {
		global $submenu, $pagenow;

		// Core's menu.php does not load in tests, so seed the Users submenu.
		$submenu['users.php'] = array(
			5  => array( 'All Users', 'list_users', 'users.php' ),
			10 => array( 'Add User', 'create_users', 'user-new.php' ),
			15 => array( 'Profile', 'read', 'profile.php' ),
		);

		$screen = new New_User_Screen( $this->account );
		$screen->add_submenu();

		$slugs = array_values( array_column( $submenu['users.php'] ?? array(), 2 ) );
		$this->assertSame( 'user-new.php', $slugs[1] ?? null, 'Add User comes first.' );
		$this->assertSame( 'user-new.php?wpai_agent=1', $slugs[2] ?? null, 'Add Agent follows Add User.' );
		$this->assertSame( 'profile.php', $slugs[3] ?? null, 'Profile stays last.' );
		unset( $submenu['users.php'] );
		$this->assertSame( admin_url( 'user-new.php?wpai_agent=1' ), New_User_Screen::url() );

		$pagenow = 'user-new.php';
		$this->assertSame( 'user-new.php', $screen->highlight_submenu( 'user-new.php' ), 'Regular mode keeps the core highlight.' );
		$this->assertSame( 'Add User &lsaquo; Site', $screen->filter_admin_title( 'Add User &lsaquo; Site' ) );

		$_REQUEST['wpai_agent'] = '1';
		$this->assertSame( 'user-new.php?wpai_agent=1', $screen->highlight_submenu( 'user-new.php' ) );
		$this->assertSame( 'Add Agent &lsaquo; Site', $screen->filter_admin_title( 'Add User &lsaquo; Site' ) );
	}

	/**
	 * Test submitting the Add New User form with the agent option creates an agent.
	 *
	 * @since x.x.x
	 */
	public function test_new_user_screen_creates_agent_and_redirects_to_profile() {
		$_POST['wpai_agent']              = '1';
		$_POST['user_login']              = 'form-agent';
		$_POST['email']                   = 'form-agent@example.com';
		$_POST['first_name']              = 'Form Agent';
		$_POST['role']                    = 'author';
		$_POST['_wpnonce_create-user']    = wp_create_nonce( 'create-user' );
		$_REQUEST['_wpnonce_create-user'] = $_POST['_wpnonce_create-user'];

		$redirect = $this->capture_redirect(
			function () {
				( new New_User_Screen( $this->account ) )->handle_create();
			}
		);

		$agent = get_user_by( 'login', 'form-agent' );
		$this->assertInstanceOf( \WP_User::class, $agent );
		$this->assertTrue( Agent_Account::is_agent( $agent ) );
		$this->assertSame( array( 'author' ), $agent->roles );
		$this->assertSame( 'Form Agent', $agent->display_name );
		$this->assertStringContainsString( 'user-edit.php?user_id=' . $agent->ID, $redirect );
		$this->assertStringContainsString( 'wpai_agent_created=1', $redirect );
		$this->assertStringEndsWith( '#application-passwords-section', $redirect );
	}

	/**
	 * Test a failed agent submission is reported through core's form validation.
	 *
	 * @since x.x.x
	 */
	public function test_new_user_screen_reports_errors_on_the_form() {
		$_POST['wpai_agent']              = '1';
		$_POST['user_login']              = '';
		$_POST['email']                   = 'agent@example.com';
		$_POST['role']                    = 'author';
		$_POST['_wpnonce_create-user']    = wp_create_nonce( 'create-user' );
		$_REQUEST['_wpnonce_create-user'] = $_POST['_wpnonce_create-user'];

		$redirected = false;
		add_filter(
			'wp_redirect',
			static function ( string $location ) use ( &$redirected ): string {
				$redirected = true;
				return $location;
			}
		);
		( new New_User_Screen( $this->account ) )->handle_create();
		$this->assertFalse( $redirected, 'Errors should not redirect, core re-renders the form.' );

		// Core's validation would complain about the hidden password; the agent error replaces it.
		$errors = new \WP_Error( 'pass', 'Please enter a password.' );
		$user   = new \stdClass();
		do_action_ref_array( 'user_profile_update_errors', array( &$errors, false, &$user ) );
		$this->assertSame( array( 'wpai_agent_empty_login' ), $errors->get_error_codes() );

		// When core already found something, it speaks alone.
		$errors = new \WP_Error( 'user_login', 'Core error.' );
		do_action_ref_array( 'user_profile_update_errors', array( &$errors, false, &$user ) );
		$this->assertSame( array( 'user_login' ), $errors->get_error_codes() );
		$this->assertFalse( get_user_by( 'email', 'agent@example.com' ) );
	}

	/**
	 * Test the form submission is ignored without the agent option.
	 *
	 * @since x.x.x
	 */
	public function test_new_user_screen_ignores_regular_submissions() {
		$_POST['user_login'] = 'human';

		( new New_User_Screen( $this->account ) )->handle_create();

		$this->assertFalse( get_user_by( 'login', 'human' ) );
	}

	/**
	 * Test the profile screen hides the password block and marks the account.
	 *
	 * @since x.x.x
	 */
	public function test_profile_screen_adapts_to_agents() {
		$agent  = $this->provision_agent();
		$human  = get_user_by( 'id', $this->admin_id );
		$screen = new Profile_Screen();

		$this->assertFalse( $screen->hide_password_fields( true, $agent ) );
		$this->assertTrue( $screen->hide_password_fields( true, $human ) );

		set_current_screen( 'user-edit' );

		$_GET['user_id'] = (string) $human->ID;
		ob_start();
		$screen->render_account_type();
		$screen->print_styles();
		$this->assertSame( '', ob_get_clean(), 'Human profiles keep every field and get no note.' );

		$_GET['user_id'] = (string) $agent->ID;
		ob_start();
		$screen->render_account_type();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( '<p class="wpai-agent-account-type"', $output );
		$this->assertStringContainsString( 'Agent account.', $output );
		$this->assertStringNotContainsString( 'notice', $output, 'The note is plain text, not a notice.' );

		$GLOBALS['pagenow'] = 'user-edit.php';
		$this->assertSame( 'Edit Agent &lsaquo; Site', $screen->filter_admin_title( 'Edit User &lsaquo; Site' ) );
		$_GET['user_id'] = (string) $human->ID;
		$this->assertSame( 'Edit User &lsaquo; Site', $screen->filter_admin_title( 'Edit User &lsaquo; Site' ) );

		$_GET['user_id'] = (string) $agent->ID;
		ob_start();
		$screen->print_styles();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( '.user-admin-color-wrap', $output );
		$this->assertStringNotContainsString( '.user-email-wrap', $output, 'Email stays, it receives notifications.' );
		$this->assertStringNotContainsString( '.user-description-wrap', $output, 'Biographical info stays available to describe the agent.' );
		$this->assertStringNotContainsString( '.user-url-wrap', $output, 'Website stays, themes show it on the frontend.' );
		$this->assertStringNotContainsString( '.user-profile-picture', $output, 'Profile picture stays, avatars show on the frontend.' );
	}

	/**
	 * Test the Role column marks agent accounts.
	 *
	 * @since x.x.x
	 */
	public function test_users_screen_marks_agent_roles() {
		$agent  = $this->provision_agent();
		$human  = get_user_by( 'id', $this->admin_id );
		$screen = new Users_Screen();
		$roles  = array( 'editor' => 'Editor' );

		$this->assertSame( array( 'editor' => 'Editor (agent)' ), $screen->mark_agent_roles( $roles, $agent ) );
		$this->assertSame( $roles, $screen->mark_agent_roles( $roles, $human ) );
	}

	/**
	 * Test the account type filter narrows the Users list table query.
	 *
	 * @since x.x.x
	 */
	public function test_users_screen_account_type_filter() {
		$this->provision_agent();
		$screen = new Users_Screen();

		$this->assertSame( array(), $screen->filter_list_table( array() ), 'No filter by default.' );

		$_GET['wpai_account_type'] = 'agent';
		$this->assertSame( 'EXISTS', $screen->filter_list_table( array() )['meta_compare'] );

		$_GET['wpai_account_type'] = 'human';
		$this->assertSame( 'NOT EXISTS', $screen->filter_list_table( array() )['meta_compare'] );

		$_GET['wpai_account_type'] = 'bogus';
		$this->assertSame( array(), $screen->filter_list_table( array() ), 'Unknown values are ignored.' );

		ob_start();
		$screen->render_filter( 'top' );
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="wpai_account_type"', $output );
		$this->assertStringContainsString( 'Agents only', $output );

		ob_start();
		$screen->render_filter( 'bottom' );
		$this->assertSame( '', ob_get_clean(), 'A second select would overwrite the submitted value.' );
	}

	/**
	 * Test the Users screen swaps the reset link for an Application Passwords link.
	 *
	 * @since x.x.x
	 */
	public function test_users_screen_row_actions_for_agents() {
		$agent   = $this->provision_agent();
		$human   = get_user_by( 'id', $this->admin_id );
		$screen  = new Users_Screen();
		$actions = array(
			'edit'          => '<a>Edit</a>',
			'resetpassword' => '<a>Send password reset</a>',
		);

		$agent_actions = $screen->filter_row_actions( $actions, $agent );
		$this->assertArrayNotHasKey( 'resetpassword', $agent_actions );
		$this->assertArrayHasKey( 'wpai_application_passwords', $agent_actions );
		$this->assertStringContainsString( '#application-passwords-section', $agent_actions['wpai_application_passwords'] );

		$this->assertSame( $actions, $screen->filter_row_actions( $actions, $human ) );
	}

	/**
	 * Runs a callback and returns the URL it tried to redirect to.
	 *
	 * @since x.x.x
	 *
	 * @param callable $callback Callback expected to redirect.
	 * @return string Redirect URL.
	 */
	private function capture_redirect( callable $callback ): string {
		$filter = static function ( string $location ): void {
			throw new \RuntimeException( $location );
		};
		add_filter( 'wp_redirect', $filter );

		try {
			$callback();
			$this->fail( 'Expected a redirect.' );
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter );
		}
	}

	/**
	 * Test that agents stay visible in user queries.
	 *
	 * @since x.x.x
	 */
	public function test_agents_stay_visible_in_user_queries() {
		$agent = $this->provision_agent();

		$ids = get_users( array( 'fields' => 'ID' ) );

		$this->assertContains( (string) $agent->ID, array_map( 'strval', $ids ) );
	}

	/**
	 * Test that the admin UI registers in admin context.
	 *
	 * The experiment framework already guarantees nothing registers while the
	 * experiment is disabled, so only the is_admin() branch needs coverage.
	 *
	 * @since x.x.x
	 */
	public function test_admin_ui_registered_in_admin_context() {
		set_current_screen( 'users' );
		$this->assertTrue( is_admin() );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$this->assertNotFalse( has_action( 'admin_action_createuser' ) );
		$this->assertNotFalse( has_action( 'admin_menu' ) );
		$this->assertNotFalse( has_action( 'user_new_form' ) );
		$this->assertNotFalse( has_filter( 'show_password_fields' ) );
		$this->assertNotFalse( has_filter( 'get_role_list' ) );
		$this->assertNotFalse( has_action( 'manage_users_extra_tablenav' ) );
	}

	/**
	 * Test that the REST user response exposes the agent flag.
	 *
	 * @since x.x.x
	 */
	public function test_rest_user_response_exposes_agent_flag() {
		// Force a fresh REST server so `rest_api_init` fires with this
		// test's hooks attached, even when an earlier test booted one.
		global $wp_rest_server;
		$wp_rest_server = null;

		$agent = $this->provision_agent();

		$request = new WP_REST_Request( 'GET', '/wp/v2/users/' . $agent->ID );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['wpai_is_agent'] );

		$request = new WP_REST_Request( 'GET', '/wp/v2/users/' . $this->admin_id );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['wpai_is_agent'] );
	}

	/**
	 * Removes only Agent Account callbacks from their always-on hooks.
	 *
	 * @since x.x.x
	 */
	private function remove_agent_account_safeguards(): void {
		global $wp_filter;

		$hooks = array(
			'wp_authenticate_user',
			'allow_password_reset',
			'wp_is_application_passwords_available_for_user',
			'user_has_cap',
			'map_meta_cap',
		);

		foreach ( $hooks as $hook_name ) {
			if ( ! isset( $wp_filter[ $hook_name ] ) || ! $wp_filter[ $hook_name ] instanceof \WP_Hook ) {
				continue;
			}

			foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'] ?? null;
					if ( ! is_array( $function ) || ! isset( $function[0] ) || ! $function[0] instanceof Agent_Account ) {
						continue;
					}

					remove_filter( $hook_name, $function, $priority );
				}
			}
		}
	}
}

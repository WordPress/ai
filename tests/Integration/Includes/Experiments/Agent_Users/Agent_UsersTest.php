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
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

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
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $GLOBALS['current_screen'] );

		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_agent-users_enabled' );
		remove_all_filters( 'wpai_feature_agent-users_enabled' );
		parent::tearDown();
	}

	/**
	 * Provisions an agent and returns the result array.
	 *
	 * @since x.x.x
	 *
	 * @param string $name Agent name.
	 * @param string $role Role slug.
	 * @return array{user: \WP_User, password: string} Provisioning result.
	 */
	private function provision_agent( string $name = 'Test Agent', string $role = 'editor' ): array {
		$result = $this->account->provision( $name, $role );
		$this->assertIsArray( $result, 'Provisioning should succeed.' );

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
		$result = $this->provision_agent( 'Content Editor Agent', 'editor' );

		$agent = $result['user'];

		$this->assertTrue( Agent_Account::is_agent( $agent ) );
		$this->assertTrue( Agent_Account::is_agent( $agent->ID ) );
		$this->assertSame( array( 'editor' ), $agent->roles );
		$this->assertSame( 'agent-content-editor-agent', $agent->user_login );
		$this->assertSame( 'Content Editor Agent', $agent->display_name );
		$this->assertSame( 'agent-content-editor-agent@' . Agent_Account::EMAIL_DOMAIN, $agent->user_email );
		$this->assertSame( $this->admin_id, (int) get_user_meta( $agent->ID, Agent_Account::META_CREATED_BY, true ) );

		$this->assertNotSame( '', $result['password'] );
		$this->assertCount(
			1,
			\WP_Application_Passwords::get_user_application_passwords( $agent->ID ),
			'Provisioning should create the first Application Password.'
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
		$empty_name = $this->account->provision( '   ', 'editor' );
		$this->assertWPError( $empty_name );
		$this->assertSame( 'wpai_agent_empty_name', $empty_name->get_error_code() );

		$bad_role = $this->account->provision( 'Test Agent', 'does-not-exist' );
		$this->assertWPError( $bad_role );
		$this->assertSame( 'wpai_agent_invalid_role', $bad_role->get_error_code() );

		$symbols_only = $this->account->provision( '!!!', 'editor' );
		$this->assertWPError( $symbols_only );
		$this->assertSame( 'wpai_agent_invalid_name', $symbols_only->get_error_code() );
	}

	/**
	 * Test that logins stay unique when names collide.
	 *
	 * @since x.x.x
	 */
	public function test_provision_generates_unique_logins() {
		$first  = $this->provision_agent( 'Twin Agent' );
		$second = $this->provision_agent( 'Twin Agent' );

		$this->assertSame( 'agent-twin-agent', $first['user']->user_login );
		$this->assertSame( 'agent-twin-agent-2', $second['user']->user_login );
	}

	/**
	 * Test that interactive login is blocked for agents but not humans.
	 *
	 * @since x.x.x
	 */
	public function test_interactive_login_blocked_for_agents() {
		$agent = $this->provision_agent()['user'];

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
		$agent = $this->provision_agent()['user'];

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
		$agent = $this->provision_agent( 'Admin Agent', 'administrator' )['user'];

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
	 * Test that Application Passwords stay available for agents.
	 *
	 * @since x.x.x
	 */
	public function test_application_passwords_stay_available_for_agents() {
		$agent = $this->provision_agent()['user'];
		$human = get_user_by( 'id', $this->admin_id );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available_for_user', '__return_false', 5 );

		$this->assertTrue( wp_is_application_passwords_available_for_user( $agent ) );
		$this->assertFalse( wp_is_application_passwords_available_for_user( $human ) );

		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available_for_user', '__return_false', 5 );
	}

	/**
	 * Test that agents stay visible in user queries.
	 *
	 * @since x.x.x
	 */
	public function test_agents_stay_visible_in_user_queries() {
		$agent = $this->provision_agent()['user'];

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

		$this->assertNotFalse( has_action( 'admin_post_wpai_create_agent_user' ) );
		$this->assertNotFalse( has_filter( 'manage_users_columns' ) );
		$this->assertNotFalse( has_filter( 'views_users' ) );
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

		$agent = $this->provision_agent()['user'];

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
}

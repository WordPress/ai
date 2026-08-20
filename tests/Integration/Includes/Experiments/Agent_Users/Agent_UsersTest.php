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
		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $GLOBALS['current_screen'] );

		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_agent-users_enabled' );
		remove_all_filters( 'wpai_feature_agent-users_enabled' );
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
	 * @param string $name Agent name.
	 * @param string $role Role slug.
	 * @return \WP_User Provisioned agent.
	 */
	private function provision_agent( string $name = 'Test Agent', string $role = 'editor' ): \WP_User {
		$result = $this->account->provision( $name, $role );
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
		$agent = $this->provision_agent( 'Content Editor Agent', 'editor' );

		$this->assertTrue( Agent_Account::is_agent( $agent ) );
		$this->assertTrue( Agent_Account::is_agent( $agent->ID ) );
		$this->assertSame( array( 'editor' ), $agent->roles );
		$this->assertSame( 'agent-content-editor-agent', $agent->user_login );
		$this->assertSame( 'Content Editor Agent', $agent->display_name );
		$this->assertSame( 'agent-content-editor-agent@' . Agent_Account::EMAIL_DOMAIN, $agent->user_email );
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
	 * Test that provisioning rejects users who cannot create accounts.
	 *
	 * @since x.x.x
	 */
	public function test_provision_requires_user_creation_capability() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$result = $this->account->provision( 'Unauthorized Agent', 'subscriber' );

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

		$result = $this->account->provision( 'Escalating Agent', 'subscriber' );

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

		$rejected = $this->account->provision( 'Escalating Agent', 'administrator' );
		$this->assertWPError( $rejected );
		$this->assertSame( 'wpai_agent_role_not_assignable', $rejected->get_error_code() );

		$allowed = $this->account->provision( 'Read Only Agent', 'subscriber' );
		$this->assertInstanceOf( \WP_User::class, $allowed );
		$this->assertSame( array( 'subscriber' ), $allowed->roles );
	}

	/**
	 * Test that logins stay unique when names collide.
	 *
	 * @since x.x.x
	 */
	public function test_provision_generates_unique_logins() {
		$first  = $this->provision_agent( 'Twin Agent' );
		$second = $this->provision_agent( 'Twin Agent' );

		$this->assertSame( 'agent-twin-agent', $first->user_login );
		$this->assertSame( 'agent-twin-agent-2', $second->user_login );
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
		$agent = $this->provision_agent( 'Admin Agent', 'administrator' );

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
		$agent = $this->provision_agent( 'Disabled Experiment Agent', 'administrator' );

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
	 * Test the creation result uses core's Application Password UI without storing a secret.
	 *
	 * @since x.x.x
	 */
	public function test_creation_result_renders_core_application_password_flow() {
		$agent = $this->provision_agent();

		set_current_screen( 'users_page_wpai-agent-users' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		set_transient(
			'wpai_agent_user_result_' . $this->admin_id,
			array(
				'type'    => 'success',
				'user_id' => $agent->ID,
				'login'   => $agent->user_login,
			),
			5 * MINUTE_IN_SECONDS
		);

		$page = new \WordPress\AI\Experiments\Agent_Users\Admin_Page( $this->account );

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="application-passwords-section"', $output );
		$this->assertStringContainsString( 'id="new_application_password_name"', $output );
		$this->assertStringContainsString( 'id="tmpl-new-application-password"', $output );
		$this->assertStringNotContainsString( '<code>', $output );
		$this->assertCount( 0, \WP_Application_Passwords::get_user_application_passwords( $agent->ID ) );
		$this->assertFalse( get_transient( 'wpai_agent_user_result_' . $this->admin_id ) );

		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	/**
	 * Test the recent agents table reports the total and links to the full view.
	 *
	 * @since x.x.x
	 */
	public function test_recent_agents_table_reports_total_and_links_to_users_screen() {
		for ( $i = 1; $i <= 21; $i++ ) {
			$this->provision_agent( 'Bulk Agent ' . $i, 'subscriber' );
		}

		set_current_screen( 'users_page_wpai-agent-users' );
		$page = new \WordPress\AI\Experiments\Agent_Users\Admin_Page( $this->account );

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Recent Agents (21)', $output );
		$this->assertStringContainsString( 'Showing the 20 most recent agents.', $output );
		$this->assertStringContainsString( 'See all 21 agents on the Users screen', $output );
		$this->assertStringContainsString( esc_url( Users_Screen::view_url() ), $output );
		$this->assertSame( 20, substr_count( $output, 'agent-bulk-agent-' ) );
		$this->assertStringNotContainsString( 'agent-bulk-agent-1</td>', $output, 'The oldest agent should fall outside the recent list.' );
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

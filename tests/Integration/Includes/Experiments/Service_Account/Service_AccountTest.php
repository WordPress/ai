<?php
/**
 * Integration tests for the Service_Account experiment.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Service_Account;

use WP_UnitTestCase;
use WordPress\AI\Experiment_Loader;
use WordPress\AI\Experiment_Registry;
use WordPress\AI\Experiments\Service_Account\Service_Account;
use WordPress\AI\Experiments\Service_Account\Service_Account_Manager;

/**
 * Service_Account test case.
 *
 * @since 0.3.0
 */
class Service_AccountTest extends WP_UnitTestCase {
	/**
	 * The experiment instance.
	 *
	 * @var Service_Account
	 */
	private Service_Account $experiment;

	/**
	 * The manager instance.
	 *
	 * @var Service_Account_Manager
	 */
	private Service_Account_Manager $manager;

	/**
	 * Set up test case.
	 *
	 * @since 0.3.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_service-account_enabled', true );

		$registry = new Experiment_Registry();
		$loader   = new Experiment_Loader( $registry );
		$loader->register_default_experiments();
		$loader->initialize_experiments();

		$this->experiment = $registry->get_experiment( 'service-account' );
		$this->assertInstanceOf( Service_Account::class, $this->experiment, 'Service Account experiment should be registered in the registry.' );

		$this->manager = Service_Account_Manager::get_instance();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.3.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_service-account_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );
		remove_all_filters( 'service_account_default_role_capabilities' );
		remove_all_filters( 'service_account_restricted_capabilities' );
		remove_all_filters( 'service_account_capabilities' );
		remove_all_filters( 'is_service_account' );

		// Clean up the role.
		remove_role( Service_Account::ROLE );

		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since 0.3.0
	 */
	public function test_experiment_registration() {
		$this->assertEquals( 'service-account', $this->experiment->get_id() );
		$this->assertEquals( 'Service Accounts', $this->experiment->get_label() );
		$this->assertTrue( $this->experiment->is_enabled() );
	}

	/**
	 * Test that the service account role is registered.
	 *
	 * @since 0.3.0
	 */
	public function test_service_account_role_registration() {
		$role = get_role( Service_Account::ROLE );

		$this->assertNotNull( $role, 'Service account role should be registered.' );
		$this->assertTrue( $role->has_cap( 'read' ) );
		$this->assertTrue( $role->has_cap( 'edit_posts' ) );
		$this->assertFalse( $role->has_cap( 'delete_posts' ) );
		$this->assertFalse( $role->has_cap( 'publish_posts' ) );
	}

	/**
	 * Test that service accounts have restricted capabilities.
	 *
	 * @since 0.3.0
	 */
	public function test_service_account_capability_restrictions() {
		$service_account_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $service_account_id, Service_Account::META_KEY, true );
		wp_set_current_user( $service_account_id );

		$this->assertFalse( current_user_can( 'manage_options' ) );
		$this->assertFalse( current_user_can( 'install_plugins' ) );
		$this->assertFalse( current_user_can( 'edit_users' ) );
		$this->assertFalse( current_user_can( 'update_core' ) );
		$this->assertFalse( current_user_can( 'list_users' ) );
	}

	/**
	 * Test that is_service_account correctly identifies service accounts.
	 *
	 * @since 0.3.0
	 */
	public function test_is_service_account() {
		$service_account_id = $this->factory->user->create( array( 'role' => Service_Account::ROLE ) );
		update_user_meta( $service_account_id, Service_Account::META_KEY, true );

		$regular_user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$this->assertTrue( $this->experiment->is_service_account( $service_account_id ) );
		$this->assertFalse( $this->experiment->is_service_account( $regular_user_id ) );
	}

	/**
	 * Test that service accounts are excluded from default queries.
	 *
	 * @since 0.3.0
	 */
	public function test_service_accounts_excluded_from_queries() {
		$regular_user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$service_account_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $service_account_id, Service_Account::META_KEY, true );

		$users    = get_users();
		$user_ids = wp_list_pluck( $users, 'ID' );

		$this->assertContains( $regular_user_id, $user_ids );
		$this->assertNotContains( $service_account_id, $user_ids );
	}

	/**
	 * Test that service accounts can be explicitly included in queries.
	 *
	 * @since 0.3.0
	 */
	public function test_service_accounts_can_be_included_in_queries() {
		$service_account_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $service_account_id, Service_Account::META_KEY, true );

		$users    = get_users( array( 'include_service_accounts' => true ) );
		$user_ids = wp_list_pluck( $users, 'ID' );

		$this->assertContains( $service_account_id, $user_ids );
	}

	/**
	 * Test that get_service_accounts includes non-service roles.
	 *
	 * @since 0.3.0
	 */
	public function test_get_service_accounts_includes_non_service_role() {
		$service_account_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		update_user_meta( $service_account_id, Service_Account::META_KEY, true );

		$accounts = $this->manager->get_service_accounts();
		$account_ids = wp_list_pluck( $accounts, 'ID' );

		$this->assertContains( $service_account_id, $account_ids );
	}

	/**
	 * Test that REST routes are registered.
	 *
	 * @since 0.3.0
	 */
	public function test_rest_routes_registration() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/wp/v2/service-accounts', $routes );
		$this->assertArrayHasKey( '/wp/v2/service-accounts/(?P<id>[\\d]+)', $routes );
		$this->assertArrayHasKey( '/wp/v2/service-accounts/(?P<id>[\\d]+)/app-password', $routes );
	}

	/**
	 * Test creating a service account via manager.
	 *
	 * @since 0.3.0
	 */
	public function test_create_service_account() {
		$user = $this->manager->create_service_account(
			array(
				'name'        => 'Test Service Bot',
				'description' => 'Test service account',
			)
		);

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertNotEmpty( $user->user_login );
		$this->assertNotEmpty( $user->user_email );
		$this->assertContains( Service_Account::ROLE, $user->roles );
		$this->assertTrue( $this->manager->is_service_account( $user ) );
		$this->assertTrue( (bool) get_user_meta( $user->ID, Service_Account::META_KEY, true ) );
	}

	/**
	 * Test creating a service account via REST API.
	 *
	 * @since 0.3.0
	 */
	public function test_create_service_account_via_rest() {
		$this->logInAsAdmin();

		$request = new \WP_REST_Request( 'POST', '/wp/v2/service-accounts' );
		$request->set_param( 'name', 'REST test bot' );
		$request->set_param( 'description', 'REST test service account' );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( 'REST test bot', $data['name'] );
		$this->assertNotEmpty( $data['email'] );
		$this->assertContains( Service_Account::ROLE, $data['roles'] );
		$this->assertArrayNotHasKey( 'app_password', $data );
		$this->assertTrue( $data['meta']['is_service_account'] );
	}

	/**
	 * Test getting service accounts via REST API.
	 *
	 * @since 0.3.0
	 */
	public function test_get_service_accounts_via_rest() {
		$this->logInAsAdmin();

		$service_account_id = $this->factory->user->create( array( 'role' => Service_Account::ROLE ) );
		update_user_meta( $service_account_id, Service_Account::META_KEY, true );

		$request  = new \WP_REST_Request( 'GET', '/wp/v2/service-accounts' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertIsArray( $data );
		$this->assertGreaterThanOrEqual( 1, count( $data ) );

		$this->assertNotEmpty( $response->get_headers()['X-WP-Total'] );
		$this->assertNotEmpty( $response->get_headers()['X-WP-TotalPages'] );
	}

	/**
	 * Test updating a service account via REST API.
	 *
	 * @since 0.3.0
	 */
	public function test_update_service_account_via_rest() {
		$this->logInAsAdmin();

		$user = $this->manager->create_service_account(
			array(
				'name' => 'Update test bot',
			)
		);

		$request = new \WP_REST_Request( 'PUT', '/wp/v2/service-accounts/' . $user->ID );
		$request->set_param( 'description', 'Updated description' );
		$request->set_param( 'name', 'Updated Bot' );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'Updated description', $data['description'] );
		$this->assertEquals( 'Updated Bot', $data['name'] );
	}

	/**
	 * Test deleting a service account via REST API.
	 *
	 * @since 0.3.0
	 */
	public function test_delete_service_account_via_rest() {
		$this->logInAsAdmin();

		$service_account_id = $this->factory->user->create( array( 'role' => Service_Account::ROLE ) );
		update_user_meta( $service_account_id, Service_Account::META_KEY, true );

		$request = new \WP_REST_Request( 'DELETE', '/wp/v2/service-accounts/' . $service_account_id );
		$request->set_param( 'force', true );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['deleted'] );
		$this->assertFalse( get_user_by( 'id', $service_account_id ) );
	}

	/**
	 * Test that delete requires force=true.
	 *
	 * @since 0.3.0
	 */
	public function test_delete_requires_force() {
		$this->logInAsAdmin();

		$service_account_id = $this->factory->user->create( array( 'role' => Service_Account::ROLE ) );
		update_user_meta( $service_account_id, Service_Account::META_KEY, true );

		$request  = new \WP_REST_Request( 'DELETE', '/wp/v2/service-accounts/' . $service_account_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 501, $response->get_status() );
	}

	/**
	 * Test REST permission denies non-admin access.
	 *
	 * @since 0.3.0
	 */
	public function test_rest_permission_denies_non_admin() {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request  = new \WP_REST_Request( 'GET', '/wp/v2/service-accounts' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Test the service_account_capabilities filter.
	 *
	 * @since 0.3.0
	 */
	public function test_service_account_capabilities_filter() {
		$service_account_id = $this->factory->user->create( array( 'role' => Service_Account::ROLE ) );
		update_user_meta( $service_account_id, Service_Account::META_KEY, true );

		add_filter(
			'service_account_capabilities',
			function ( $allcaps, $user ) {
				$allcaps['edit_others_posts'] = true;
				return $allcaps;
			},
			10,
			2
		);

		wp_set_current_user( $service_account_id );

		$this->assertTrue( current_user_can( 'edit_others_posts' ) );

		remove_all_filters( 'service_account_capabilities' );
	}

	/**
	 * Test the service_account_default_role_capabilities filter.
	 *
	 * @since 0.3.0
	 */
	public function test_default_role_capabilities_filter() {
		remove_role( Service_Account::ROLE );

		add_filter(
			'service_account_default_role_capabilities',
			function ( $capabilities ) {
				$capabilities['upload_files'] = true;
				return $capabilities;
			}
		);

		$this->manager->register_role();

		$role = get_role( Service_Account::ROLE );
		$this->assertTrue( $role->has_cap( 'upload_files' ) );
	}

	/**
	 * Test the service_account_restricted_capabilities filter.
	 *
	 * @since 0.3.0
	 */
	public function test_restricted_capabilities_filter() {
		$service_account_id = $this->factory->user->create( array( 'role' => Service_Account::ROLE ) );
		update_user_meta( $service_account_id, Service_Account::META_KEY, true );

		wp_set_current_user( $service_account_id );
		$this->assertFalse( current_user_can( 'list_users' ) );

		add_filter(
			'service_account_restricted_capabilities',
			function ( $restricted ) {
				return array_diff( $restricted, array( 'list_users' ) );
			}
		);

		$role = get_role( Service_Account::ROLE );
		$role->add_cap( 'list_users' );

		$user = wp_get_current_user();
		$user->get_role_caps();

		$this->assertTrue( current_user_can( 'list_users' ) );

		$role->remove_cap( 'list_users' );
	}

	/**
	 * Test the is_service_account filter.
	 *
	 * @since 0.3.0
	 */
	public function test_is_service_account_filter() {
		$regular_user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$this->assertFalse( $this->manager->is_service_account( $regular_user_id ) );

		add_filter(
			'is_service_account',
			function ( $is_service_account, $user ) {
				if ( in_array( 'editor', $user->roles, true ) ) {
					return true;
				}
				return $is_service_account;
			},
			10,
			2
		);

		$this->assertTrue( $this->manager->is_service_account( $regular_user_id ) );
	}

	/**
	 * Test regenerating application password.
	 *
	 * @since 0.3.0
	 */
	public function test_regenerate_app_password() {
		$this->logInAsAdmin();

		$user = $this->manager->create_service_account(
			array(
				'name' => 'App password test',
			)
		);

		$request = new \WP_REST_Request( 'POST', '/wp/v2/service-accounts/' . $user->ID . '/app-password' );
		$request->set_param( 'name', 'Test Password' );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'password', $data );
		$this->assertArrayHasKey( 'uuid', $data );
		$this->assertEquals( 'Test Password', $data['name'] );
	}

	/**
	 * Logs in a user with administrator privileges.
	 *
	 * @since 0.3.0
	 */
	protected function logInAsAdmin(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}
}

<?php
/**
 * Integration tests for the Roles_Users_Controller class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\REST
 */

namespace WordPress\AI\Tests\Integration\Includes\REST;

use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\REST\Roles_Users_Controller;

/**
 * Roles_Users_Controller test case.
 *
 * @since x.x.x
 */
class Roles_Users_ControllerTest extends WP_UnitTestCase {

	/**
	 * Controller instance under test.
	 *
	 * @var \WordPress\AI\REST\Roles_Users_Controller
	 */
	private Roles_Users_Controller $controller;

	/**
	 * Sets up test environment and registers REST routes.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();
		$this->controller = new Roles_Users_Controller();
		$this->controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Registering routes on core REST hook.
		do_action( 'rest_api_init' );
	}

	/**
	 * Test that the route is registered correctly with GET method and search schema.
	 *
	 * @since x.x.x
	 */
	public function test_route_registration_and_schema(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/ai/v1/roles-users', $routes );
		$route = $routes['/ai/v1/roles-users'][0];

		$this->assertArrayHasKey( 'GET', $route['methods'] );
		$this->assertArrayHasKey( 'search', $route['args'] );
		$this->assertSame( 'string', $route['args']['search']['type'] );
		$this->assertSame( 'sanitize_text_field', $route['args']['search']['sanitize_callback'] );
	}

	/**
	 * Test security permissions across unauthenticated and non-admin users.
	 *
	 * @since x.x.x
	 */
	public function test_permission_checks(): void {
		// 1. Unauthenticated request.
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/ai/v1/roles-users' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertContains( $response->get_status(), array( 401, 403 ), 'Unauthenticated requests must be rejected.' );

		// 2. Authenticated Subscriber.
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status(), 'Subscribers must be denied access.' );

		// 3. Authenticated Editor.
		$editor_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status(), 'Editors without manage_options must be denied access.' );

		// 4. Authenticated Administrator.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), 'Administrators must be granted access.' );
	}

	/**
	 * Test that response structure contains all registered WordPress roles with correct schema.
	 *
	 * @since x.x.x
	 */
	public function test_get_roles_users_response_structure_and_roles_data(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/ai/v1/roles-users' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'roles', $data );
		$this->assertArrayHasKey( 'users', $data );

		$expected_roles    = array_keys( wp_roles()->roles );
		$returned_role_ids = array_column( $data['roles'], 'id' );

		foreach ( $expected_roles as $role_id ) {
			$this->assertContains( $role_id, $returned_role_ids, "Response should include registered role {$role_id}." );
		}

		foreach ( $data['roles'] as $role ) {
			$this->assertArrayHasKey( 'id', $role );
			$this->assertArrayHasKey( 'name', $role );
			$this->assertIsString( $role['id'] );
			$this->assertIsString( $role['name'] );
		}
	}

	/**
	 * Test user listing, search filtering, and result capping (MAX_USERS = 10).
	 *
	 * @since x.x.x
	 */
	public function test_get_roles_users_user_search_and_limits(): void {
		$admin_id = $this->factory->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'TargetAdminUser',
				'user_login'   => 'targetadminuser',
			)
		);
		wp_set_current_user( $admin_id );

		// Create 11 extra users to verify the 10-user limit.
		for ( $i = 1; $i <= 11; $i++ ) {
			$this->factory->user->create(
				array(
					'role'         => 'subscriber',
					'display_name' => "BatchUser{$i}",
				)
			);
		}

		// 1. Unfiltered request should cap users at 10.
		$request  = new WP_REST_Request( 'GET', '/ai/v1/roles-users' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 10, $data['users'], 'Unfiltered user results must be capped at MAX_USERS (10).' );

		foreach ( $data['users'] as $user ) {
			$this->assertIsInt( $user['id'] );
			$this->assertIsString( $user['name'] );
		}

		// 2. Search filtered request.
		$request->set_param( 'search', 'TargetAdminUser' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$user_names = array_column( $data['users'], 'name' );
		$this->assertContains( 'TargetAdminUser', $user_names );
		$this->assertNotContains( 'BatchUser1', $user_names );
	}
}

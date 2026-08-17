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
	 * Test that the roles-users REST route is registered.
	 *
	 * @since x.x.x
	 */
	public function test_register_routes_registers_roles_users_route(): void {
		$controller = new Roles_Users_Controller();
		$controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/ai/v1/roles-users', $routes );
		$this->assertArrayHasKey( 'methods', $routes['/ai/v1/roles-users'][0] );
	}

	/**
	 * Test that the roles-users route is registered with the GET method.
	 *
	 * @since x.x.x
	 */
	public function test_register_routes_registers_get_method(): void {
		$controller = new Roles_Users_Controller();
		$controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( 'GET', $routes['/ai/v1/roles-users'][0]['methods'] );
	}

	/**
	 * Test that the roles-users route declares the expected search argument schema.
	 *
	 * @since x.x.x
	 */
	public function test_register_routes_declares_search_argument_schema(): void {
		$controller = new Roles_Users_Controller();
		$controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );

		$routes          = rest_get_server()->get_routes();
		$search_argument = $routes['/ai/v1/roles-users'][0]['args']['search'];

		$this->assertSame( 'string', $search_argument['type'] );
		$this->assertSame( '', $search_argument['default'] );
		$this->assertSame( 'sanitize_text_field', $search_argument['sanitize_callback'] );
	}

	/**
	 * Test that the roles-users endpoint allows users with manage_options.
	 *
	 * @since x.x.x
	 */
	public function test_check_permission_allows_manage_options_users(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$controller = new Roles_Users_Controller();

		$this->assertTrue( $controller->check_permission() );
	}

	/**
	 * Test that the roles-users endpoint denies users without manage_options.
	 *
	 * @since x.x.x
	 */
	public function test_check_permission_denies_non_admin_users(): void {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$controller = new Roles_Users_Controller();

		$this->assertFalse( $controller->check_permission() );
	}

	/**
	 * Test that the roles-users endpoint returns 403 for non-admin users.
	 *
	 * @since x.x.x
	 */
	public function test_roles_users_route_requires_manage_options(): void {
		$controller = new Roles_Users_Controller();
		$controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );

		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/ai/v1/roles-users' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test that the roles-users endpoint returns 200 with correct top-level structure.
	 *
	 * @since x.x.x
	 */
	public function test_roles_users_route_returns_correct_structure(): void {
		$controller = new Roles_Users_Controller();
		$controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/ai/v1/roles-users' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'roles', $data );
		$this->assertArrayHasKey( 'users', $data );
		$this->assertIsArray( $data['roles'] );
		$this->assertIsArray( $data['users'] );
	}

	/**
	 * Test that each role entry contains the expected id and name keys.
	 *
	 * @since x.x.x
	 */
	public function test_roles_users_route_returns_roles_with_id_and_name(): void {
		$controller = new Roles_Users_Controller();
		$controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/ai/v1/roles-users' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertNotEmpty( $data['roles'] );

		foreach ( $data['roles'] as $role ) {
			$this->assertArrayHasKey( 'id', $role );
			$this->assertArrayHasKey( 'name', $role );
			$this->assertIsString( $role['id'] );
			$this->assertIsString( $role['name'] );
		}
	}

	/**
	 * Test that each user entry contains the expected id and name keys.
	 *
	 * @since x.x.x
	 */
	public function test_roles_users_route_returns_users_with_id_and_name(): void {
		$controller = new Roles_Users_Controller();
		$controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );

		$admin_id = $this->factory->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Test Admin',
			)
		);
		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/ai/v1/roles-users' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertNotEmpty( $data['users'] );

		foreach ( $data['users'] as $user ) {
			$this->assertArrayHasKey( 'id', $user );
			$this->assertArrayHasKey( 'name', $user );
			$this->assertIsInt( $user['id'] );
			$this->assertIsString( $user['name'] );
		}
	}

	/**
	 * Test that the search param filters users by matching display name.
	 *
	 * @since x.x.x
	 */
	public function test_roles_users_route_filters_users_by_search_term(): void {
		$controller = new Roles_Users_Controller();
		$controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );

		$admin_id = $this->factory->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'UniqueAdminUser',
				'user_login'   => 'uniqueadminuser',
			)
		);
		$this->factory->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'SomeOtherEditor',
				'user_login'   => 'someothereditor',
			)
		);

		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/ai/v1/roles-users' );
		$request->set_param( 'search', 'UniqueAdminUser' );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		$user_names = array_column( $data['users'], 'name' );
		$this->assertContains( 'UniqueAdminUser', $user_names );
		$this->assertNotContains( 'SomeOtherEditor', $user_names );
	}

	/**
	 * Test that an empty search param returns users without filtering.
	 *
	 * @since x.x.x
	 */
	public function test_roles_users_route_returns_all_users_when_search_is_empty(): void {
		$controller = new Roles_Users_Controller();
		$controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->factory->user->create( array( 'role' => 'editor' ) );
		$this->factory->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/ai/v1/roles-users' );
		$request->set_param( 'search', '' );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		// At least the 3 users created above should be returned (no search filter applied).
		$this->assertGreaterThanOrEqual( 3, count( $data['users'] ) );
	}

	/**
	 * Test that the endpoint returns all registered WordPress roles.
	 *
	 * @since x.x.x
	 */
	public function test_roles_users_route_returns_all_registered_roles(): void {
		$controller = new Roles_Users_Controller();
		$controller->init();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/ai/v1/roles-users' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$expected_role_ids = array_keys( wp_roles()->roles );
		$returned_role_ids = array_column( $data['roles'], 'id' );

		foreach ( $expected_role_ids as $role_id ) {
			$this->assertContains( $role_id, $returned_role_ids );
		}
	}
}

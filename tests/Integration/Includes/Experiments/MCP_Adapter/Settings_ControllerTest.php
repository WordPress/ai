<?php
/**
 * Integration tests for the MCP Adapter Settings_Controller class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\MCP_Adapter
 */

namespace WordPress\AI\Tests\Integration\Experiments\MCP_Adapter;

use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\Experiments\MCP_Adapter\Exposure_Overrides;
use WordPress\AI\Experiments\MCP_Adapter\Settings_Controller;

/**
 * Settings_Controller test case.
 */
class Settings_ControllerTest extends WP_UnitTestCase {
	/**
	 * Administrator user id.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Subscriber user id.
	 *
	 * @var int
	 */
	private static $subscriber_id;

	/**
	 * Creates shared fixtures.
	 *
	 * @param \WP_UnitTest_Factory $factory Factory instance.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();

		add_action(
			'rest_api_init',
			static function (): void {
				( new Settings_Controller() )->register_routes();
			}
		);

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		delete_option( Exposure_Overrides::OPTION_NAME );

		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tearDown();
	}

	/**
	 * Tests that the settings route is registered.
	 */
	public function test_route_is_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/ai/v1/mcp/settings', $routes );
	}

	/**
	 * Tests that unauthenticated requests are rejected.
	 */
	public function test_get_requires_authentication() {
		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ai/v1/mcp/settings' ) );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Tests that non-admin users are rejected.
	 */
	public function test_get_requires_manage_options() {
		wp_set_current_user( self::$subscriber_id );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ai/v1/mcp/settings' ) );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Tests the GET response shape.
	 */
	public function test_get_returns_settings() {
		wp_set_current_user( self::$admin_id );
		update_option( Exposure_Overrides::OPTION_NAME, array( 'ai/get-post' => false ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ai/v1/mcp/settings' ) );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'adapter_active', $data );
		$this->assertArrayHasKey( 'abilities', $data );
		$this->assertIsArray( $data['abilities'] );
		$this->assertArrayHasKey( 'overrides', $data );
		$this->assertSame( array( 'ai/get-post' => false ), $data['overrides'] );
	}

	/**
	 * Tests that POST persists overrides.
	 */
	public function test_post_saves_overrides() {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/ai/v1/mcp/settings' );
		$request->set_body_params(
			array(
				'overrides' => array(
					'ai/get-post'    => true,
					'ai/delete-post' => false,
				),
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$this->assertSame(
			array(
				'ai/get-post'    => true,
				'ai/delete-post' => false,
			),
			get_option( Exposure_Overrides::OPTION_NAME )
		);
	}

	/**
	 * Tests that a null override removes the stored entry.
	 */
	public function test_post_null_removes_override() {
		wp_set_current_user( self::$admin_id );
		update_option( Exposure_Overrides::OPTION_NAME, array( 'ai/get-post' => false ) );

		$request = new WP_REST_Request( 'POST', '/ai/v1/mcp/settings' );
		$request->set_body_params( array( 'overrides' => array( 'ai/get-post' => null ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), get_option( Exposure_Overrides::OPTION_NAME ) );
	}

	/**
	 * Tests that invalid ability names are rejected.
	 */
	public function test_post_rejects_invalid_ability_name() {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/ai/v1/mcp/settings' );
		$request->set_body_params( array( 'overrides' => array( 'Not A Valid Name!' => true ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
	}
}

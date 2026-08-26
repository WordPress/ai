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
		remove_all_filters( 'wp_register_ability_args' );

		if ( wp_get_ability( 'ai-test/mcp-settings' ) ) {
			wp_unregister_ability( 'ai-test/mcp-settings' );
		}

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
	 * Registers a test ability inside the abilities API init context.
	 *
	 * @param array<string, mixed> $meta Ability meta.
	 */
	private function register_test_ability( array $meta = array() ): void {
		global $wp_current_filter;

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.

		try {
			wp_register_ability(
				'ai-test/mcp-settings',
				array(
					'label'               => 'MCP settings test ability',
					'description'         => 'Test ability for the MCP settings controller.',
					'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
					'execute_callback'    => '__return_true',
					'permission_callback' => '__return_true',
					'meta'                => $meta,
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Returns the payload row for the test ability from a response.
	 *
	 * @param \WP_REST_Response $response The response to search.
	 *
	 * @return array<string, mixed>|null The ability row, or null.
	 */
	private function find_test_ability( $response ): ?array {
		foreach ( $response->get_data()['abilities'] as $ability ) {
			if ( 'ai-test/mcp-settings' === $ability['name'] ) {
				return $ability;
			}
		}

		return null;
	}

	/**
	 * Tests that abilities report both effective and default exposure.
	 */
	public function test_get_reports_effective_and_default_exposure() {
		wp_set_current_user( self::$admin_id );
		update_option( Exposure_Overrides::OPTION_NAME, array( 'ai-test/mcp-settings' => false ) );
		add_filter( 'wp_register_ability_args', array( Exposure_Overrides::class, 'filter_ability_args' ), 100, 2 );

		$this->register_test_ability( array( 'public' => true ) );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ai/v1/mcp/settings' ) );
		$ability  = $this->find_test_ability( $response );

		$this->assertNotNull( $ability );
		$this->assertFalse( $ability['exposed'], 'Effective exposure should honor the override.' );
		$this->assertTrue( $ability['default'], 'Default exposure should be the registration-time value.' );
	}

	/**
	 * Tests that removing an override restores the registration default in the same response.
	 */
	public function test_post_null_removal_reports_fresh_exposure() {
		wp_set_current_user( self::$admin_id );
		update_option( Exposure_Overrides::OPTION_NAME, array( 'ai-test/mcp-settings' => false ) );
		add_filter( 'wp_register_ability_args', array( Exposure_Overrides::class, 'filter_ability_args' ), 100, 2 );

		// Force registration before the save, baking the pre-save override into ability meta.
		$this->register_test_ability( array( 'public' => true ) );
		wp_get_abilities();

		$request = new WP_REST_Request( 'POST', '/ai/v1/mcp/settings' );
		$request->set_body_params( array( 'overrides' => array( 'ai-test/mcp-settings' => null ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$ability = $this->find_test_ability( $response );
		$this->assertNotNull( $ability );
		$this->assertTrue( $ability['exposed'], 'Removing the override should restore the registration default in the response.' );
	}

	/**
	 * Tests that string booleans from form-encoded requests are accepted and coerced.
	 */
	public function test_post_accepts_string_booleans() {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/ai/v1/mcp/settings' );
		$request->set_body_params(
			array(
				'overrides' => array(
					'ai/get-post'    => 'true',
					'ai/delete-post' => '0',
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
	 * Tests that the payload describes the companion plugin's install state.
	 */
	public function test_get_reports_plugin_install_state() {
		wp_set_current_user( self::$admin_id );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ai/v1/mcp/settings' ) );
		$plugin   = $response->get_data()['plugin'];

		$this->assertSame( 'mcp-adapter', $plugin['slug'] );
		$this->assertSame( 'missing', $plugin['status'], 'The adapter is not installed in the test environment.' );
		$this->assertNull( $plugin['file'] );
		$this->assertIsBool( $plugin['can_install'] );
		$this->assertIsBool( $plugin['can_activate'] );
		$this->assertTrue( $plugin['can_install'], 'Admins with file mods allowed should be able to install.' );
	}

	/**
	 * Tests that the companion plugin slug is filterable.
	 */
	public function test_plugin_slug_is_filterable() {
		wp_set_current_user( self::$admin_id );
		add_filter(
			'wpai_mcp_adapter_plugin_slug',
			static function (): string {
				return 'hello-dolly';
			}
		);

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ai/v1/mcp/settings' ) );
		$this->assertSame( 'hello-dolly', $response->get_data()['plugin']['slug'] );

		remove_all_filters( 'wpai_mcp_adapter_plugin_slug' );
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

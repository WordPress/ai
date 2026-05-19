<?php
/**
 * Integration tests for the AI Request Logging experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\AI_Request_Logging
 */

namespace WordPress\AI\Tests\Integration\Experiments\AI_Request_Logging;

use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Request_Logging\AI_Request_Logging;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;
use WordPress\AI\Logging\AI_Request_Log_Manager;

/**
 * AI_Request_Logging experiment test case.
 *
 * @since x.x.x
 */
class AI_Request_LoggingTest extends WP_UnitTestCase {
	/**
	 * The experiment instance under test.
	 *
	 * @var \WordPress\AI\Experiments\AI_Request_Logging\AI_Request_Logging
	 */
	private AI_Request_Logging $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_ai-request-logging_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();
		do_action( 'rest_api_init', rest_get_server() );

		$experiment = $registry->get_feature( 'ai-request-logging' );
		$this->assertInstanceOf( AI_Request_Logging::class, $experiment );

		$this->experiment = $experiment;
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_ai-request-logging_enabled' );
		wp_clear_scheduled_hook( 'wpai_request_logs_cleanup' );

		parent::tearDown();
	}

	/**
	 * Tests that the experiment is registered correctly.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_registration() {
		$this->assertSame( 'ai-request-logging', $this->experiment->get_id() );
		$this->assertSame( 'AI Request Logging', $this->experiment->get_label() );
		$this->assertSame( Experiment_Category::ADMIN, $this->experiment->get_category() );
		$this->assertTrue( $this->experiment->is_enabled() );
	}

	/**
	 * Tests that the Tools submenu entry is registered.
	 *
	 * @since x.x.x
	 */
	public function test_admin_menu_is_registered_under_tools() {
		global $submenu;

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$tools_submenus = $submenu['tools.php'] ?? array();
		$submenu_slugs  = array_column( $tools_submenus, 2 );

		$this->assertContains( 'ai-request-logs', $submenu_slugs );
	}

	/**
	 * Tests that the REST routes are registered.
	 *
	 * @since x.x.x
	 */
	public function test_rest_routes_are_registered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/ai/v1/logs', $routes );
		$this->assertArrayHasKey( '/ai/v1/logs/summary', $routes );
		$this->assertArrayHasKey( '/ai/v1/logs/filters', $routes );
	}

	/**
	 * Tests that the summary endpoint accepts short periods supported by the repository.
	 *
	 * @since x.x.x
	 */
	public function test_summary_endpoint_accepts_minute_and_hour_periods() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		foreach ( array( 'minute', 'hour' ) as $period ) {
			$request = new \WP_REST_Request( 'GET', '/ai/v1/logs/summary' );
			$request->set_param( 'period', $period );
			$response = rest_get_server()->dispatch( $request );

			$this->assertSame( 200, $response->get_status() );
		}
	}

	/**
	 * Tests that administrators can access the logs endpoint.
	 *
	 * @since x.x.x
	 */
	public function test_logs_endpoint_is_accessible_for_administrators() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request  = new \WP_REST_Request( 'GET', '/ai/v1/logs' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Tests that subscribers cannot access the logs endpoint.
	 *
	 * @since x.x.x
	 */
	public function test_logs_endpoint_requires_manage_options() {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request  = new \WP_REST_Request( 'GET', '/ai/v1/logs' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}
}

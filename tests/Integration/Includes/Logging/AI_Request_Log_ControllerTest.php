<?php
/**
 * Integration tests for the AI request log REST controller.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Schema;
use WordPress\AI\Logging\REST\AI_Request_Log_Controller;

/**
 * AI_Request_Log_Controller test case.
 *
 * @since 1.0.0
 *
 * @covers \WordPress\AI\Logging\REST\AI_Request_Log_Controller
 */
class AI_Request_Log_ControllerTest extends WP_UnitTestCase {

	/**
	 * Log manager instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager
	 */
	private AI_Request_Log_Manager $manager;

	/**
	 * REST controller instance.
	 *
	 * @var \WordPress\AI\Logging\REST\AI_Request_Log_Controller
	 */
	private AI_Request_Log_Controller $controller;

	/**
	 * Set up test case.
	 *
	 * @since 1.0.0
	 */
	protected function setUp(): void {
		parent::setUp();

		// Force schema recreation in case a prior test's TRUNCATE broke the table state.
		delete_option( 'wpai_request_logs_schema_version' );

		$this->manager = new AI_Request_Log_Manager();
		$this->manager->init();

		$this->controller = new AI_Request_Log_Controller( $this->manager );

		add_action(
			'rest_api_init',
			array( $this->controller, 'register_routes' )
		);
		do_action( 'rest_api_init', rest_get_server() );

		global $wpdb;
		$table = $wpdb->prefix . AI_Request_Log_Schema::TABLE_NAME;
		$wpdb->query( "DELETE FROM {$table} WHERE 1=1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.0.0
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		wp_clear_scheduled_hook( 'wpai_request_logs_cleanup' );

		parent::tearDown();
	}

	/**
	 * Inserts a sample log entry via the manager and returns its ID.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $overrides Optional field overrides.
	 * @return string The log ID.
	 */
	private function insert_log( array $overrides = array() ): string {
		$defaults = array(
			'type'          => 'ai_client',
			'operation'     => 'openai:completions',
			'provider'      => 'openai',
			'model'         => 'gpt-4o',
			'duration_ms'   => 100,
			'tokens_input'  => 100,
			'tokens_output' => 50,
			'status'        => 'success',
		);

		$log_id = $this->manager->log( array_merge( $defaults, $overrides ) );
		$this->assertIsString( $log_id );

		return $log_id;
	}

	/**
	 * Tests that get_logs returns items with pagination headers.
	 *
	 * @since 1.0.0
	 */
	public function test_get_logs_returns_items_with_headers(): void {
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->insert_log();
		$this->insert_log();

		$request  = new WP_REST_Request( 'GET', '/ai/v1/logs' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '1', $response->get_headers()['X-WP-TotalPages'] );
	}

	/**
	 * Tests that get_logs returns cursor headers when items exist.
	 *
	 * @since 1.0.0
	 */
	public function test_get_logs_includes_cursor_headers(): void {
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->insert_log();

		$request  = new WP_REST_Request( 'GET', '/ai/v1/logs' );
		$response = rest_get_server()->dispatch( $request );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'X-WP-NextCursorId', $headers );
		$this->assertArrayHasKey( 'X-WP-NextCursorTimestamp', $headers );
	}

	/**
	 * Tests that get_logs applies filters from query parameters.
	 *
	 * @since 1.0.0
	 */
	public function test_get_logs_filters_by_provider(): void {
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->insert_log( array( 'provider' => 'openai' ) );
		$this->insert_log( array( 'provider' => 'anthropic' ) );

		$request = new WP_REST_Request( 'GET', '/ai/v1/logs' );
		$request->set_param( 'provider', 'anthropic' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
	}

	/**
	 * Tests that get_log returns a single entry.
	 *
	 * @since 1.0.0
	 */
	public function test_get_log_returns_entry(): void {
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$log_id = $this->insert_log();

		$request = new WP_REST_Request( 'GET', '/ai/v1/logs/' . $log_id );
		$request->set_param( 'id', $log_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( $log_id, $data['id'] );
	}

	/**
	 * Tests that get_log returns 404 for a nonexistent entry.
	 *
	 * @since 1.0.0
	 */
	public function test_get_log_returns_404_for_nonexistent(): void {
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/ai/v1/logs/00000000-0000-0000-0000-000000000000' );
		$request->set_param( 'id', '00000000-0000-0000-0000-000000000000' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Tests that get_summary returns expected structure.
	 *
	 * @since 1.0.0
	 */
	public function test_get_summary_returns_stats(): void {
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->insert_log();

		$request = new WP_REST_Request( 'GET', '/ai/v1/logs/summary' );
		$request->set_param( 'period', 'all' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'total_requests', $data );
		$this->assertSame( 1, $data['total_requests'] );
	}

	/**
	 * Tests that get_filters returns filter options.
	 *
	 * @since 1.0.0
	 */
	public function test_get_filters_returns_options(): void {
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->insert_log();

		$request  = new WP_REST_Request( 'GET', '/ai/v1/logs/filters' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'types', $data );
		$this->assertArrayHasKey( 'providers', $data );
		$this->assertContains( 'openai', $data['providers'] );
	}

	/**
	 * Tests that purge_logs returns a success response structure.
	 *
	 * This test uses TRUNCATE internally via purge_all(), so it must
	 * run last in the class to avoid breaking the transaction.
	 *
	 * @since 1.0.0
	 */
	public function test_purge_logs_returns_success(): void {
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->insert_log();

		$request  = new WP_REST_Request( 'DELETE', '/ai/v1/logs' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'deleted', $data );
		$this->assertArrayHasKey( 'message', $data );
	}

	/**
	 * Tests that subscribers cannot purge logs.
	 *
	 * @since 1.0.0
	 */
	public function test_purge_logs_requires_manage_options(): void {
		$subscriber_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request  = new WP_REST_Request( 'DELETE', '/ai/v1/logs' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Tests that get_collection_params returns all expected parameter definitions.
	 *
	 * @since 1.0.0
	 */
	public function test_get_collection_params_returns_expected_keys(): void {
		$params = $this->controller->get_collection_params();

		$expected_keys = array(
			'type',
			'status',
			'provider',
			'user_id',
			'date_from',
			'date_to',
			'search',
			'tokens_filter',
			'page',
			'per_page',
			'orderby',
			'order',
			'cursor_id',
			'cursor_timestamp',
		);

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $params );
		}
	}
}

<?php
/**
 * Integration tests for the REST_Controller class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Connector_Approval
 */

namespace WordPress\AI\Tests\Integration\Includes\Connector_Approval;

use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\Connector_Approval\Approvals_Store;
use WordPress\AI\Connector_Approval\REST_Controller;

/**
 * REST_Controller test case.
 *
 * @since 1.0.0
 */
class REST_ControllerTest extends WP_UnitTestCase {
	/**
	 * Store instance under test.
	 *
	 * @since 1.0.0
	 *
	 * @var \WordPress\AI\Connector_Approval\Approvals_Store
	 */
	private Approvals_Store $store;

	/**
	 * REST controller under test.
	 *
	 * @since 1.0.0
	 *
	 * @var \WordPress\AI\Connector_Approval\REST_Controller
	 */
	private REST_Controller $controller;

	/**
	 * Administrator user ID.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private int $subscriber_id;

	/**
	 * Set up test case.
	 *
	 * @since 1.0.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->store      = new Approvals_Store();
		$this->controller = new REST_Controller( $this->store );
		add_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invokes a WordPress core hook in test setup.
		do_action( 'rest_api_init' );

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.0.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( Approvals_Store::OPTION_APPROVALS );
		delete_option( Approvals_Store::OPTION_PENDING );
		remove_all_actions( 'rest_api_init' );
		parent::tearDown();
	}

	/**
	 * Test that admin can read connector approval state.
	 *
	 * @since 1.0.0
	 */
	public function test_get_state_as_admin() {
		wp_set_current_user( $this->admin_id );

		$this->store->record_pending(
			array(
				'type'     => 'plugin',
				'basename' => 'example/example.php',
				'name'     => 'Example',
			),
			'openai'
		);

		$request  = new WP_REST_Request( 'GET', '/ai/v1/connector-approvals' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'connectors', $data );
		$this->assertArrayHasKey( 'approvals', $data );
		$this->assertArrayHasKey( 'pending', $data );
		$this->assertArrayHasKey( 'plugins', $data );
		$this->assertArrayHasKey( 'themes', $data );
		$this->assertCount( 1, $data['pending'] );
	}

	/**
	 * Test that connector approval state includes the active theme.
	 *
	 * @since 1.0.0
	 */
	public function test_get_state_includes_active_theme() {
		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'GET', '/ai/v1/connector-approvals' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'themes', $data );
		$this->assertSame(
			array(
				array(
					'basename' => get_stylesheet(),
					'name'     => wp_get_theme()->get( 'Name' ),
				),
			),
			$data['themes']
		);
	}

	/**
	 * Test that non-admin users cannot access endpoints.
	 *
	 * @since 1.0.0
	 */
	public function test_permission_denied_for_non_admin() {
		wp_set_current_user( $this->subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/ai/v1/connector-approvals' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Test that approval updates persist and clear matching pending requests.
	 *
	 * @since 1.0.0
	 */
	public function test_update_approval_approves_and_clears_pending() {
		wp_set_current_user( $this->admin_id );

		$this->store->record_pending(
			array(
				'type'     => 'plugin',
				'basename' => 'example/example.php',
				'name'     => 'Example',
			),
			'openai'
		);

		$request = new WP_REST_Request( 'POST', '/ai/v1/connector-approvals' );
		$request->set_body_params(
			array(
				'plugin_basename' => 'example/example.php',
				'connector_id'    => 'openai',
				'approved'        => true,
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $this->store->is_approved( 'example/example.php', 'openai' ) );
		$this->assertCount( 0, $data['pending'] );
	}

	/**
	 * Test that update approval validates required fields.
	 *
	 * @since 1.0.0
	 */
	public function test_update_approval_requires_fields() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/ai/v1/connector-approvals' );
		$request->set_body_params(
			array(
				'plugin_basename' => '',
				'connector_id'    => '',
				'approved'        => true,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wpai_invalid_approval', $response->get_data()['code'] );
	}

	/**
	 * Test deleting a pending request entry.
	 *
	 * @since 1.0.0
	 */
	public function test_delete_pending_entry() {
		wp_set_current_user( $this->admin_id );

		$this->store->record_pending(
			array(
				'type'     => 'plugin',
				'basename' => 'example/example.php',
				'name'     => 'Example',
			),
			'openai'
		);

		$key     = $this->store->pending_key( 'example/example.php', 'openai' );
		$request = new WP_REST_Request( 'DELETE', '/ai/v1/connector-approvals/pending' );
		$request->set_query_params( array( 'key' => $key ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->store->get_pending() );
	}
}

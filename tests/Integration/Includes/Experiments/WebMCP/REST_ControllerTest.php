<?php
/**
 * Integration tests for the WebMCP REST routes.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\WebMCP
 */

namespace WordPress\AI\Tests\Integration\Experiments\WebMCP;

use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\Experiments\WebMCP\REST_Controller;
use WordPress\AI\Experiments\WebMCP\Tool_Curator;
use WordPress\AI\Experiments\WebMCP\WebMCP;

/**
 * REST_Controller test case.
 *
 * @since x.x.x
 */
class REST_ControllerTest extends WP_UnitTestCase {

	/**
	 * Abilities registered by a test, unregistered on teardown.
	 *
	 * @var list<string>
	 */
	private array $registered = array();

	/**
	 * Registers the routes and two abilities: one exposed to admins, one to visitors.
	 */
	public function setUp(): void {
		parent::setUp();

		$controller = new REST_Controller( new Tool_Curator( 'webmcp' ) );
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		do_action( 'rest_api_init', rest_get_server() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook fired to register the routes under test.

		$this->register_ability(
			'webmcp-test/echo',
			array(
				'input_schema'     => array(
					'type'       => 'object',
					'properties' => array( 'text' => array( 'type' => 'string' ) ),
				),
				'execute_callback' => static function ( $input ) {
					return array( 'echo' => $input['text'] ?? '' );
				},
				'meta'             => array( 'webmcp' => 'admin' ),
			)
		);
		$this->register_ability( 'webmcp-test/public', array( 'meta' => array( 'webmcp' => 'visitor' ) ) );
	}

	/**
	 * Cleans up.
	 */
	public function tearDown(): void {
		foreach ( $this->registered as $name ) {
			wp_unregister_ability( $name );
		}
		$this->registered = array();
		wp_set_current_user( 0 );
		remove_all_actions( 'rest_api_init' );
		parent::tearDown();
	}

	/**
	 * Registers a test ability inside the abilities init context.
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Overrides.
	 */
	private function register_ability( string $name, array $args = array() ): void {
		global $wp_current_filter;

		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register a test ability.

		try {
			wp_register_ability(
				$name,
				array_merge(
					array(
						'label'               => 'Test ' . $name,
						'description'         => 'Test ability ' . $name,
						'category'            => WPAI_DEFAULT_ABILITY_CATEGORY,
						'execute_callback'    => static function () use ( $name ) {
							return array( 'ran' => $name );
						},
						'permission_callback' => '__return_true',
					),
					$args
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->registered[] = $name;
	}

	/**
	 * Tests that the tools route filters by context and returns tokens.
	 */
	public function test_tools_route_lists_per_context(): void {
		$request = new WP_REST_Request( 'GET', '/ai/v1/webmcp/tools' );
		$request->set_param( 'context', WebMCP::CONTEXT_ADMIN );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( WebMCP::CONTEXT_ADMIN, $data['context'] );
		$this->assertSame( array( 'webmcp-test__echo' ), array_column( $data['tools'], 'name' ) );
		$this->assertSame( 0, $data['truncated'] );
		$this->assertSame( 30, $data['maxTools'] );
		$this->assertNotEmpty( $data['nonce'] );
		$this->assertNotEmpty( $data['restNonce'] );

		$request = new WP_REST_Request( 'GET', '/ai/v1/webmcp/tools' );
		$request->set_param( 'context', WebMCP::CONTEXT_VISITOR );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( array( 'webmcp-test__public' ), array_column( $data['tools'], 'name' ) );
	}

	/**
	 * Tests that an unknown context is rejected by the schema.
	 */
	public function test_tools_route_rejects_unknown_context(): void {
		$request = new WP_REST_Request( 'GET', '/ai/v1/webmcp/tools' );
		$request->set_param( 'context', 'editor' );

		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * Tests that execution without the experiment's token is refused.
	 */
	public function test_execute_requires_the_experiment_token(): void {
		$request = new WP_REST_Request( 'POST', '/ai/v1/webmcp/execute' );
		$request->set_body_params( array( 'tool' => 'webmcp-test__echo' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wpai_webmcp_invalid_nonce', $response->as_error()->get_error_code() );
	}

	/**
	 * Tests a full execution: name mapping, input, result.
	 */
	public function test_execute_runs_an_exposed_ability(): void {
		$request = new WP_REST_Request( 'POST', '/ai/v1/webmcp/execute' );
		$request->set_header( REST_Controller::NONCE_HEADER, wp_create_nonce( REST_Controller::NONCE_ACTION ) );
		$request->set_body_params(
			array(
				'tool'    => 'webmcp-test__echo',
				'context' => WebMCP::CONTEXT_ADMIN,
				'input'   => array( 'text' => 'hello' ),
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'webmcp-test/echo', $response->get_data()['ability'] );
		$this->assertSame( array( 'echo' => 'hello' ), $response->get_data()['result'] );
	}

	/**
	 * Tests that a tool not exposed in the requested context cannot be run through the route.
	 */
	public function test_execute_refuses_a_tool_outside_its_context(): void {
		$request = new WP_REST_Request( 'POST', '/ai/v1/webmcp/execute' );
		$request->set_header( REST_Controller::NONCE_HEADER, wp_create_nonce( REST_Controller::NONCE_ACTION ) );
		$request->set_body_params(
			array(
				'tool'    => 'webmcp-test__echo',
				'context' => WebMCP::CONTEXT_VISITOR,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wpai_webmcp_tool_not_exposed', $response->as_error()->get_error_code() );
	}

	/**
	 * Tests that the ability's own permission callback still decides.
	 */
	public function test_execute_returns_the_permission_error_of_the_ability(): void {
		$this->register_ability(
			'webmcp-test/locked',
			array(
				'meta'                => array( 'webmcp' => 'admin' ),
				'permission_callback' => '__return_false',
			)
		);

		$request = new WP_REST_Request( 'POST', '/ai/v1/webmcp/execute' );
		$request->set_header( REST_Controller::NONCE_HEADER, wp_create_nonce( REST_Controller::NONCE_ACTION ) );
		$request->set_body_params(
			array(
				'tool'    => 'webmcp-test__locked',
				'context' => WebMCP::CONTEXT_ADMIN,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Tests the nonce route.
	 */
	public function test_nonce_route_issues_both_tokens(): void {
		$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ai/v1/webmcp/nonce' ) )->get_data();

		$this->assertSame( 1, wp_verify_nonce( $data['nonce'], REST_Controller::NONCE_ACTION ) );
		$this->assertSame( 1, wp_verify_nonce( $data['restNonce'], 'wp_rest' ) );
	}
}

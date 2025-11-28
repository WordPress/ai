<?php
/**
 * REST API surface for the MCP Server admin screen.
 *
 * @package WordPress\AI\MCP
 */

declare( strict_types=1 );

namespace WordPress\AI\MCP\REST;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WordPress\AI\MCP\MCP_Server_Manager;

use function current_user_can;
use function rest_ensure_response;
use function __;

/**
 * Provides `/ai/v1/mcp-server` routes so the React UI can manage settings.
 *
 * @since 0.1.0
 */
class Mcp_Server_Controller extends WP_REST_Controller {

	/**
	 * Domain layer shared with the admin page.
	 */
	private MCP_Server_Manager $manager;

	/**
	 * Constructor.
	 *
	 * @param MCP_Server_Manager $manager Server manager.
	 */
	public function __construct( MCP_Server_Manager $manager ) {
		$this->namespace = 'ai/v1';
		$this->rest_base = 'mcp-server';
		$this->manager   = $manager;
	}

	/**
	 * Registers routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'get_overview' ),
					'methods'             => WP_REST_Server::READABLE,
				),
				array(
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'update_settings' ),
					'methods'             => WP_REST_Server::EDITABLE,
					'args'                => array(
						'enabled' => array(
							'type'    => 'boolean',
							'required'=> false,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tools',
			array(
				array(
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'update_tools' ),
					'methods'             => WP_REST_Server::EDITABLE,
					'args'                => array(
						'tools' => array(
							'type'    => 'array',
							'required'=> false,
							'items'   => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/test',
			array(
				array(
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'test_connection' ),
					'methods'             => WP_REST_Server::CREATABLE,
					'args'                => array(
						'method'  => array( 'type' => 'string', 'required' => false ),
						'headers' => array( 'type' => 'object', 'required' => false ),
						'body'    => array( 'type' => 'object', 'required' => false ),
					),
				),
			)
		);
	}

	/**
	 * Basic permission check – restricted to administrators.
	 */
	public function permissions_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns server status, available tools, and config templates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_overview( WP_REST_Request $request ): WP_REST_Response {
		$data = array(
			'enabled'          => $this->manager->is_server_enabled(),
			'server'           => $this->manager->get_server_details(),
			'tools'            => $this->manager->get_available_tools(),
			'configTemplates'  => $this->manager->get_client_templates(),
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Allows toggling the global server enable flag.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		if ( $request->has_param( 'enabled' ) ) {
			$this->manager->set_server_enabled( (bool) $request->get_param( 'enabled' ) );
			// Attempt to spin up the adapter immediately so status updates without a reload.
			$this->manager->bootstrap_adapter();
		}

		return $this->get_overview( $request );
	}

	/**
	 * Persists the tool allow-list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_tools( WP_REST_Request $request ) {
		$tools = $request->get_param( 'tools' );

		if ( null !== $tools && ! is_array( $tools ) ) {
			return new WP_Error( 'ai_invalid_tools', __( 'Tools must be provided as an array of ability names.', 'ai' ) );
		}

		$this->manager->update_enabled_tools( $tools ?? array() );

		return $this->get_overview( $request );
	}

	/**
	 * Calls the HTTP endpoint and reports reachability back to the UI.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function test_connection( WP_REST_Request $request ): WP_REST_Response {
		$payload = array(
			'method'  => $request->get_param( 'method' ),
			'headers' => $request->get_param( 'headers' ),
			'body'    => $request->get_param( 'body' ),
		);

		$result = $this->manager->test_http_endpoint( $payload );

		return rest_ensure_response( $result );
	}
}

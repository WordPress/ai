<?php
/**
 * REST controller for MCP experiment.
 *
 * @package WordPress\AI\Experiments\MCP\REST
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\MCP\REST;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WordPress\AI\Experiments\MCP\Manager;

use function __;
use function current_user_can;
use function rest_ensure_response;
use function sanitize_text_field;

/**
 * REST API controller powering the MCP admin UI.
 *
 * @since 0.1.0
 */
class MCP_Controller extends WP_REST_Controller {

	private Manager $manager;

	/**
	 * Constructor.
	 */
	public function __construct( Manager $manager ) {
		$this->namespace = 'ai/v1';
		$this->rest_base = 'mcp';
		$this->manager   = $manager;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_overview' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'server_id' => array(
							'type' => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/enabled',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_enabled' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/server',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_server' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/server/add',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add_server' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/tools',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_tools' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'serverId' => array(
							'type'     => 'string',
							'required' => true,
						),
						'tools'    => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'string' ),
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
					'methods'             => 'POST',
					'callback'            => array( $this, 'test_connection' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'serverId' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Capability check.
	 */
	public function permissions_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Overview endpoint.
	 */
	public function get_overview( WP_REST_Request $request ): WP_REST_Response {
		$server_id = $request->get_param( 'server_id' );

		return rest_ensure_response( $this->manager->build_overview_payload( $server_id ) );
	}

	/**
	 * Toggle global enable flag.
	 */
	public function update_enabled( WP_REST_Request $request ) {
		$enabled = (bool) $request->get_param( 'enabled' );
		$this->manager->set_enabled( $enabled );

		return $this->get_overview( $request );
	}

	/**
	 * Create a server.
	 */
	public function add_server( WP_REST_Request $request ) {
		$data = (array) $request->get_param( 'server' );
		$this->manager->add_server( $data );

		return $this->get_overview( $request );
	}

	/**
	 * Update server fields.
	 */
	public function save_server( WP_REST_Request $request ) {
		$server = (array) $request->get_param( 'server' );
		$id     = sanitize_text_field( (string) ( $server['id'] ?? '' ) );

		if ( '' === $id ) {
			return new WP_Error( 'ai_mcp_missing_id', __( 'Server ID is required.', 'ai' ) );
		}

		$this->manager->update_server( $id, $server );

		$request->set_param( 'server_id', $id );

		return $this->get_overview( $request );
	}

	/**
	 * Update ability allow-list for a server.
	 */
	public function update_tools( WP_REST_Request $request ) {
		$server_id = sanitize_text_field( (string) $request->get_param( 'serverId' ) );
		$tools     = (array) $request->get_param( 'tools' );

		$updated = $this->manager->update_server_tools( $server_id, $tools );

		if ( null === $updated ) {
			return new WP_Error( 'ai_mcp_server_missing', __( 'Server not found.', 'ai' ) );
		}

		$request->set_param( 'server_id', $server_id );

		return $this->get_overview( $request );
	}

	/**
	 * Test endpoint connectivity for a server.
	 */
	public function test_connection( WP_REST_Request $request ): WP_REST_Response {
		$server_id = sanitize_text_field( (string) $request->get_param( 'serverId' ) );
		$result    = $this->manager->test_http_endpoint( $server_id );

		return rest_ensure_response( $result );
	}
}

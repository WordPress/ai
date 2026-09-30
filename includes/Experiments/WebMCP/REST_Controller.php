<?php
/**
 * REST routes the WebMCP bridge script talks to.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\WebMCP;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three routes: list the tools a context exposes, execute one, refresh tokens.
 *
 * Authentication is the usual REST cookie session, which needs the `wp_rest`
 * nonce in `X-WP-Nonce`. Core rejects any other nonce in that header before a
 * route runs, so the experiment's own token travels in a second header. Both
 * are issued with the page and can be refreshed from the nonce route when a
 * page stays open long enough for them to expire.
 *
 * Execution never bypasses the ability: `WP_Ability::execute()` runs the
 * ability's permission callback and input validation on the server.
 *
 * @since x.x.x
 */
class REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @since x.x.x
	 */
	public const NAMESPACE = 'ai/v1';

	/**
	 * Nonce action for the experiment's own token.
	 *
	 * @since x.x.x
	 */
	public const NONCE_ACTION = 'wpai_webmcp_execute';

	/**
	 * Header the experiment's token travels in.
	 *
	 * @since x.x.x
	 */
	public const NONCE_HEADER = 'X-WPAI-WebMCP-Nonce';

	/**
	 * Exposure rules.
	 *
	 * @since x.x.x
	 * @var \WordPress\AI\Experiments\WebMCP\Tool_Curator
	 */
	private Tool_Curator $curator;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\WebMCP\Tool_Curator $curator Exposure rules.
	 */
	public function __construct( Tool_Curator $curator ) {
		$this->curator = $curator;
	}

	/**
	 * Registers the routes.
	 *
	 * @since x.x.x
	 */
	public function register_routes(): void {
		$context_arg = array(
			'type'    => 'string',
			'enum'    => Tool_Curator::get_contexts(),
			'default' => WebMCP::CONTEXT_ADMIN,
		);

		register_rest_route(
			self::NAMESPACE,
			'/webmcp/tools',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_tools' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'context' => $context_arg ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/webmcp/execute',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'check_execute_nonce' ),
				'args'                => array(
					'tool'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'input'   => array(
						'type'    => array( 'object', 'array', 'null' ),
						'default' => null,
					),
					'context' => $context_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/webmcp/nonce',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_nonces' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Lists the tools a context exposes for the current user.
	 *
	 * The list is public in the sense that anyone may ask; what comes back is
	 * filtered by the context's exposure rules and by each ability's own
	 * permission callback for the requesting user.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response Tools, how many the cap removed, and fresh tokens.
	 */
	public function get_tools( WP_REST_Request $request ): WP_REST_Response {
		$context = (string) $request->get_param( 'context' );
		$result  = $this->curator->get_tools( $context );

		return new WP_REST_Response(
			array(
				'context'   => $context,
				'tools'     => $result['tools'],
				'truncated' => $result['truncated'],
				'maxTools'  => $this->curator->get_max_tools(),
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Issues fresh tokens for a page that has stayed open.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_REST_Response Tokens.
	 */
	public function get_nonces(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Checks the experiment's own token before an execution runs.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error True to proceed.
	 */
	public function check_execute_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_header( self::NONCE_HEADER );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return new WP_Error(
				'wpai_webmcp_invalid_nonce',
				__( 'The WebMCP token is missing or has expired. Reload the page and try again.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Executes one exposed ability.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error Result, or the ability's own error.
	 */
	public function execute( WP_REST_Request $request ) {
		$tool    = (string) $request->get_param( 'tool' );
		$context = (string) $request->get_param( 'context' );
		$name    = Tool_Curator::to_ability_name( $tool );

		if ( ! $this->curator->is_exposed( $name, $context ) ) {
			return new WP_Error(
				'wpai_webmcp_tool_not_exposed',
				sprintf(
					/* translators: %s: tool name */
					__( 'The tool "%s" is not exposed on this page.', 'ai' ),
					$tool
				),
				array( 'status' => 404 )
			);
		}

		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			return new WP_Error(
				'wpai_webmcp_ability_not_found',
				sprintf(
					/* translators: %s: ability name */
					__( 'The ability "%s" is not registered.', 'ai' ),
					$name
				),
				array( 'status' => 404 )
			);
		}

		$input        = $request->get_param( 'input' );
		$input_schema = $ability->get_input_schema();

		if ( empty( $input_schema ) ) {
			$result = $ability->execute();
		} else {
			$result = $ability->execute( is_array( $input ) ? $input : array() );
		}

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
				$status = false !== strpos( (string) $result->get_error_code(), 'permission' ) ? 403 : 400;
				$result->add_data( array( 'status' => $status ) );
			}

			return $result;
		}

		return new WP_REST_Response(
			array(
				'tool'    => $tool,
				'ability' => $name,
				'result'  => $result,
			)
		);
	}
}

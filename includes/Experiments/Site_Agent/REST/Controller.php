<?php
/**
 * REST controller for Site Agent turns and decisions.
 *
 * @package WordPress\AI\Experiments\Site_Agent
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\Site_Agent\REST;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WordPress\AI\Experiments\Site_Agent\Agent;
use WordPress\AI\Experiments\Site_Agent\Site_Agent;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provides the Site Agent routes.
 *
 * Every route carries a capability check that runs on every request. Cookie
 * authenticated requests additionally clear core's REST nonce check before
 * the callback is reached.
 *
 * @since x.x.x
 */
final class Controller {

	/**
	 * The REST API namespace.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const API_NAMESPACE = 'ai/v1';

	/**
	 * Full path of the turn route, as advertised to the client.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const TURN_ROUTE = 'ai/v1/site-agent/turn';

	/**
	 * Full path of the approval route, as advertised to the client.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const APPROVE_ROUTE = 'ai/v1/site-agent/approve';

	/**
	 * Full path of the decline route, as advertised to the client.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const DECLINE_ROUTE = 'ai/v1/site-agent/decline';

	/**
	 * Hooks route registration.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the Site Agent routes.
	 *
	 * @since x.x.x
	 */
	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/site-agent/turn',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'turn' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'prompt'          => array(
							'type'     => 'string',
							'required' => true,
						),
						'conversation_id' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/site-agent/approve',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'approve' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'proposal_id'     => array(
							'type'     => 'string',
							'required' => true,
						),
						'conversation_id' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/site-agent/decline',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'decline' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'proposal_id'     => array(
							'type'     => 'string',
							'required' => true,
						),
						'conversation_id' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Checks whether the current user may use the Site Agent.
	 *
	 * @since x.x.x
	 *
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public function check_permission() {
		if ( ! Site_Agent::is_active() ) {
			return new WP_Error(
				'site_agent_disabled',
				__( 'The Site Agent is disabled.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$capability = Site_Agent::get_screen_capability();

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Filtered capability, mirrored by the screen check.
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to use the Site Agent.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Runs a conversation turn.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function turn( WP_REST_Request $request ) {
		$result = ( new Agent() )->run(
			(string) $request->get_param( 'conversation_id' ),
			(string) $request->get_param( 'prompt' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Approves and executes a stored proposal.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function approve( WP_REST_Request $request ) {
		$result = ( new Agent() )->approve(
			(string) $request->get_param( 'proposal_id' ),
			(string) $request->get_param( 'conversation_id' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Declines a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function decline( WP_REST_Request $request ) {
		$result = ( new Agent() )->decline(
			(string) $request->get_param( 'proposal_id' ),
			(string) $request->get_param( 'conversation_id' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'declined' => true ), 200 );
	}
}

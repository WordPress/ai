<?php
/**
 * REST controller for Site Agent turns, proposals, and conversations.
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
use WordPress\AI\Experiments\Site_Agent\Conversation_Store;
use WordPress\AI\Experiments\Site_Agent\Proposal_Store;
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
	 * Full path of the proposal edit route, as advertised to the client.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const UPDATE_PROPOSAL_ROUTE = 'ai/v1/site-agent/update-proposal';

	/**
	 * Full path of the conversation collection, as advertised to the client.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const CONVERSATIONS_ROUTE = 'ai/v1/site-agent/conversations';

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

		register_rest_route(
			self::API_NAMESPACE,
			'/site-agent/update-proposal',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_proposal' ),
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
						'args'            => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/site-agent/conversations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_conversations' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'include_archived' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/site-agent/conversations/(?P<id>[0-9a-fA-F-]{36})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conversation' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_conversation' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/site-agent/conversations/(?P<id>[0-9a-fA-F-]{36})/rename',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rename_conversation' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'    => array(
							'type'     => 'string',
							'required' => true,
						),
						'title' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/site-agent/conversations/(?P<id>[0-9a-fA-F-]{36})/archive',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'archive_conversation' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'       => array(
							'type'     => 'string',
							'required' => true,
						),
						'archived' => array(
							'type'    => 'boolean',
							'default' => true,
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
		$conversation_id = (string) $request->get_param( 'conversation_id' );
		$result          = ( new Agent() )->decline(
			(string) $request->get_param( 'proposal_id' ),
			$conversation_id
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$conversation = ( new Conversation_Store() )->get( $conversation_id, get_current_user_id() );

		return new WP_REST_Response(
			array(
				'declined'        => true,
				'conversation_id' => $conversation_id,
				'transcript'      => null === $conversation ? array() : $this->normalize_transcript( $conversation['transcript'] ?? array() ),
			),
			200
		);
	}

	/**
	 * Updates a pending proposal with edited arguments.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function update_proposal( WP_REST_Request $request ) {
		$args = $request->get_param( 'args' );

		$result = ( new Agent() )->update_proposal(
			(string) $request->get_param( 'proposal_id' ),
			(string) $request->get_param( 'conversation_id' ),
			is_array( $args ) ? $args : array()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Lists the current user's conversations.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response The response.
	 */
	public function list_conversations( WP_REST_Request $request ): WP_REST_Response {
		$include_archived = (bool) $request->get_param( 'include_archived' );
		$conversations    = ( new Conversation_Store() )->all( get_current_user_id() );

		if ( ! $include_archived ) {
			$conversations = array_values(
				array_filter(
					$conversations,
					static function ( array $conversation ): bool {
						return empty( $conversation['archived'] );
					}
				)
			);
		}

		return new WP_REST_Response( array( 'conversations' => $conversations ), 200 );
	}

	/**
	 * Returns one conversation and its pending proposal.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function get_conversation( WP_REST_Request $request ) {
		$user_id      = get_current_user_id();
		$conversation = ( new Conversation_Store() )->get( (string) $request->get_param( 'id' ), $user_id );

		if ( null === $conversation ) {
			return new WP_Error( 'site_agent_conversation_not_found', __( 'That conversation was not found.', 'ai' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->conversation_payload( $conversation, $user_id ), 200 );
	}

	/**
	 * Deletes one conversation and any proposal attached to it.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function delete_conversation( WP_REST_Request $request ) {
		$user_id      = get_current_user_id();
		$id           = (string) $request->get_param( 'id' );
		$conversation = ( new Conversation_Store() )->get( $id, $user_id );

		if ( null === $conversation ) {
			return new WP_Error( 'site_agent_conversation_not_found', __( 'That conversation was not found.', 'ai' ), array( 'status' => 404 ) );
		}

		if ( ! empty( $conversation['pending_proposal_id'] ) ) {
			( new Proposal_Store() )->delete( (string) $conversation['pending_proposal_id'], $user_id );
		}

		( new Conversation_Store() )->delete( $id, $user_id );

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Renames one conversation.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function rename_conversation( WP_REST_Request $request ) {
		$result = ( new Conversation_Store() )->rename(
			(string) $request->get_param( 'id' ),
			get_current_user_id(),
			(string) $request->get_param( 'title' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'id'       => (string) $result['id'],
				'title'    => (string) $result['title'],
				'created'  => (int) $result['created'],
				'updated'  => (int) $result['updated'],
				'archived' => (bool) $result['archived'],
				'preview'  => '',
			),
			200
		);
	}

	/**
	 * Archives or unarchives one conversation.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function archive_conversation( WP_REST_Request $request ) {
		$result = ( new Conversation_Store() )->archive(
			(string) $request->get_param( 'id' ),
			get_current_user_id(),
			(bool) $request->get_param( 'archived' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'id'       => (string) $result['id'],
				'title'    => (string) $result['title'],
				'created'  => (int) $result['created'],
				'updated'  => (int) $result['updated'],
				'archived' => (bool) $result['archived'],
				'preview'  => '',
			),
			200
		);
	}

	/**
	 * Builds the full conversation payload.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $conversation The conversation.
	 * @param int                  $user_id      The current user.
	 * @return array<string, mixed> The payload.
	 */
	private function conversation_payload( array $conversation, int $user_id ): array {
		$proposal    = null;
		$proposal_id = (string) ( $conversation['pending_proposal_id'] ?? '' );

		if ( '' !== $proposal_id ) {
			$stored = ( new Proposal_Store() )->get( $proposal_id, $user_id );

			if ( null !== $stored && 'pending' === ( $stored['status'] ?? '' ) ) {
				$proposal = Agent::describe_proposal( $stored );
			}
		}

		return array(
			'id'         => (string) ( $conversation['id'] ?? '' ),
			'title'      => (string) ( $conversation['title'] ?? '' ),
			'created'    => (int) ( $conversation['created'] ?? 0 ),
			'updated'    => (int) ( $conversation['updated'] ?? 0 ),
			'archived'   => (bool) ( $conversation['archived'] ?? false ),
			'transcript' => $this->normalize_transcript( $conversation['transcript'] ?? array() ),
			'proposal'   => $proposal,
		);
	}

	/**
	 * Ensures transcript entries have the expected shape.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $transcript The stored transcript.
	 * @return list<array<string, string>> The normalized transcript.
	 */
	private function normalize_transcript( $transcript ): array {
		if ( ! is_array( $transcript ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $transcript as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['text'] ) || ! is_scalar( $entry['text'] ) ) {
				continue;
			}

			$role = isset( $entry['role'] ) && is_scalar( $entry['role'] ) ? (string) $entry['role'] : 'system';

			if ( ! in_array( $role, array( 'user', 'assistant', 'system' ), true ) ) {
				$role = 'system';
			}

			$normalized[] = array(
				'id'   => isset( $entry['id'] ) && is_scalar( $entry['id'] ) ? (string) $entry['id'] : wp_generate_uuid4(),
				'role' => $role,
				'text' => (string) $entry['text'],
			);
		}

		return $normalized;
	}
}

<?php
/**
 * REST controller for AI Workspace conversation turns.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace\REST;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WordPress\AI\Experiments\AI_Workspace\Conversation_Store;
use WordPress\AI\Experiments\AI_Workspace\Model_Client_Interface;
use WordPress\AI\Experiments\AI_Workspace\Prompt_Model_Client;
use WordPress\AI\Experiments\AI_Workspace\Tool_Selector;
use WordPress\AI\Experiments\AI_Workspace\Turn_Runner;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provides the `/ai/v1/workspace/messages` routes.
 *
 * Both routes carry a capability check that runs on every request and does not
 * depend on nonce validation (R3); cookie-authenticated requests additionally
 * have to clear core's REST nonce check before the callback is reached.
 *
 * Cancellation is a second route rather than client-abort detection. PHP only
 * observes a disconnected client after it writes output, so a buffered turn
 * cannot detect one at all, and the test suite's strict no-output setting forbids
 * the write that would make detection testable. The cancel route writes a marker
 * that the turn loop re-reads between rounds (R9).
 *
 * @since x.x.x
 */
final class Turn_Controller {

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
	public const MESSAGES_ROUTE = 'ai/v1/workspace/messages';

	/**
	 * Full path of the cancellation route, as advertised to the client.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const CANCEL_ROUTE = 'ai/v1/workspace/messages/cancel';

	/**
	 * Capability required to use the workspace, matching the admin screen.
	 *
	 * The check itself passes the literal so the capability sniff can verify it;
	 * this constant documents it for callers.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Status returned when Site Context has no tools to offer.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const STATUS_TOOLS_UNAVAILABLE = 'tools_unavailable';

	/**
	 * Status returned when no model can serve the request.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const STATUS_MODEL_UNAVAILABLE = 'model_unavailable';

	/**
	 * The conversation store.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Conversation_Store
	 */
	private Conversation_Store $store;

	/**
	 * The tool selector.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Tool_Selector
	 */
	private Tool_Selector $selector;

	/**
	 * The model client.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Model_Client_Interface
	 */
	private Model_Client_Interface $client;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\AI_Workspace\Conversation_Store|null     $store    The conversation store.
	 * @param \WordPress\AI\Experiments\AI_Workspace\Tool_Selector|null          $selector The tool selector.
	 * @param \WordPress\AI\Experiments\AI_Workspace\Model_Client_Interface|null $client   The model client.
	 */
	public function __construct(
		?Conversation_Store $store = null,
		?Tool_Selector $selector = null,
		?Model_Client_Interface $client = null
	) {
		$this->store    = null === $store ? new Conversation_Store() : $store;
		$this->selector = null === $selector ? new Tool_Selector() : $selector;
		$this->client   = null === $client ? new Prompt_Model_Client() : $client;
	}

	/**
	 * Hooks route registration.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the workspace routes.
	 *
	 * @since x.x.x
	 */
	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/workspace/messages',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_turn' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'message'         => array(
							'type'     => 'string',
							'required' => true,
						),
						'conversation_id' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'scope'           => array(
							'type'    => 'string',
							'enum'    => array( Tool_Selector::SCOPE_SITE, Tool_Selector::SCOPE_GENERAL ),
							'default' => Tool_Selector::SCOPE_SITE,
						),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/workspace/messages/cancel',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'cancel_turn' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'conversation_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Checks whether the current user may use the workspace.
	 *
	 * @since x.x.x
	 *
	 * @return true|\WP_Error True when permitted, WP_Error otherwise.
	 */
	public function check_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to use the AI Workspace.', 'ai' ),
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
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function run_turn( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$message = (string) $request->get_param( 'message' );
		$scope   = (string) $request->get_param( 'scope' );

		if ( '' === trim( $message ) ) {
			return new WP_Error(
				'workspace_empty_message',
				__( 'A message is required.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$conversation = $this->resolve_conversation( (string) $request->get_param( 'conversation_id' ), $user_id, $scope );

		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}

		$tools = $this->selector->get_tool_names( $scope );

		if ( Tool_Selector::SCOPE_SITE === $scope && array() === $tools ) {
			// Site Context says why it cannot use tools instead of quietly
			// answering from base knowledge as though it were General Knowledge (R7).
			return $this->unavailable_response(
				$conversation,
				self::STATUS_TOOLS_UNAVAILABLE,
				$this->selector->get_unavailability_reason( $scope )
			);
		}

		if ( ! $this->client->supports_text_generation() ) {
			return $this->unavailable_response( $conversation, self::STATUS_MODEL_UNAVAILABLE, 'no_text_generation' );
		}

		// The function-calling gate runs before the first model call, not after a
		// failed one (R4, KTD4).
		if ( array() !== $tools && ! $this->client->supports_function_calling() ) {
			return $this->unavailable_response( $conversation, self::STATUS_MODEL_UNAVAILABLE, 'no_function_calling' );
		}

		$runner = new Turn_Runner( $this->selector, $this->store, $this->client );

		/**
		 * Filters the callback that receives assistant text deltas as they stream.
		 *
		 * Returning a callable turns the turn's model call into a streaming one.
		 * The workspace's own transcript surface supplies the emitter; this route
		 * writes no output of its own.
		 *
		 * @since x.x.x
		 *
		 * @param callable|null    $emitter The text delta callback, or null for a buffered turn.
		 * @param \WP_REST_Request $request The REST request.
		 */
		$emitter = apply_filters( 'wpai_workspace_stream_emitter', null, $request );

		$result = $runner->run( $conversation, $message, is_callable( $emitter ) ? $emitter : null );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'conversation_id' => $result['conversation']['id'],
				'scope'           => $scope,
				'status'          => $result['status'],
				'rounds'          => $result['rounds'],
				'max_rounds'      => Turn_Runner::DEFAULT_MAX_ROUNDS,
				'tools'           => $result['tools'],
				/*
				 * Each record names the ability, its outcome and its duration,
				 * and carries the ability's own result under `result` so the
				 * transcript can render it. That value is null unless the call
				 * succeeded, and is never widened past what the ability
				 * returned under the caller's own capabilities. `retrieval`
				 * summarizes the same result in one shape common to every
				 * ability, so the transcript can describe a lookup without
				 * knowing any ability's output; it is null when there is
				 * nothing to summarize.
				 */
				'tool_calls'      => $result['tool_calls'],
				'messages'        => $result['messages'],
				'text'            => $result['text'],
			),
			200
		);
	}

	/**
	 * Marks a conversation's in-flight turn as cancelled.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function cancel_turn( WP_REST_Request $request ) {
		$user_id         = get_current_user_id();
		$conversation_id = (string) $request->get_param( 'conversation_id' );

		if ( null === $this->store->get( $conversation_id, $user_id ) ) {
			return new WP_Error(
				'workspace_conversation_not_found',
				__( 'That conversation was not found.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		$this->store->cancel( $conversation_id, $user_id );

		return new WP_REST_Response(
			array(
				'conversation_id' => $conversation_id,
				'cancelled'       => true,
			),
			200
		);
	}

	/**
	 * Loads the requested conversation, or starts a new one.
	 *
	 * A conversation ID that does not resolve for this user is reported as not
	 * found rather than silently starting a new conversation, so one person's
	 * conversation ID can never read another person's transcript.
	 *
	 * @since x.x.x
	 *
	 * @param string $conversation_id The requested conversation ID, or an empty string.
	 * @param int    $user_id         The requesting user ID.
	 * @param string $scope           The requested scope.
	 * @return array<string, mixed>|\WP_Error The conversation, or an error.
	 */
	private function resolve_conversation( string $conversation_id, int $user_id, string $scope ) {
		if ( '' === $conversation_id ) {
			return $this->store->create( $user_id, $scope );
		}

		$conversation = $this->store->get( $conversation_id, $user_id );

		if ( null === $conversation ) {
			return new WP_Error(
				'workspace_conversation_not_found',
				__( 'That conversation was not found.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		$conversation['scope'] = $scope;

		return $conversation;
	}

	/**
	 * Builds the response used when the workspace cannot run a turn.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $conversation The conversation.
	 * @param string               $status       The status code.
	 * @param string               $reason       The reason code.
	 * @return \WP_REST_Response The response.
	 */
	private function unavailable_response( array $conversation, string $status, string $reason ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'conversation_id' => $conversation['id'],
				'status'          => $status,
				'reason'          => $reason,
				'rounds'          => 0,
				'tools'           => array(),
				'tool_calls'      => array(),
				'messages'        => array(),
				'text'            => '',
			),
			200
		);
	}
}

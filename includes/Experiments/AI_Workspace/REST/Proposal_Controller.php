<?php
/**
 * REST controller for AI Workspace write proposals.
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
use WordPress\AI\Experiments\AI_Workspace\Draft_Writer;
use WordPress\AI\Experiments\AI_Workspace\Proposal_Store;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provides the `/ai/v1/workspace/proposals` routes.
 *
 * This is the only path in the feature that writes content, and it is reached
 * only by an authenticated request a person made after seeing the stored values.
 *
 * @since x.x.x
 */
final class Proposal_Controller {

	/**
	 * The REST API namespace.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const API_NAMESPACE = 'ai/v1';

	/**
	 * Full base path of the proposal routes, as advertised to the client.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const PROPOSALS_ROUTE = 'ai/v1/workspace/proposals';

	/**
	 * Capability required to use the workspace, matching the admin screen.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * The proposal store.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Proposal_Store
	 */
	private Proposal_Store $store;

	/**
	 * The conversation store.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Conversation_Store
	 */
	private Conversation_Store $conversations;

	/**
	 * The draft writer.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Draft_Writer
	 */
	private Draft_Writer $writer;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\AI_Workspace\Proposal_Store|null     $store         The proposal store.
	 * @param \WordPress\AI\Experiments\AI_Workspace\Conversation_Store|null $conversations The conversation store.
	 * @param \WordPress\AI\Experiments\AI_Workspace\Draft_Writer|null       $writer        The draft writer.
	 */
	public function __construct(
		?Proposal_Store $store = null,
		?Conversation_Store $conversations = null,
		?Draft_Writer $writer = null
	) {
		$this->store         = null === $store ? new Proposal_Store() : $store;
		$this->conversations = null === $conversations ? new Conversation_Store() : $conversations;
		$this->writer        = null === $writer ? new Draft_Writer() : $writer;
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
	 * Registers the proposal routes.
	 *
	 * @since x.x.x
	 */
	public function register_routes(): void {
		$id_arg = array(
			'id' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/workspace/proposals/(?P<id>[A-Za-z0-9\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_proposal' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'decline_proposal' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/workspace/proposals/(?P<id>[A-Za-z0-9\-]+)/execute',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'execute_proposal' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array_merge(
						$id_arg,
						array(
							'conversation_id' => array(
								'type'              => 'string',
								'required'          => true,
								'sanitize_callback' => 'sanitize_text_field',
							),
							'selected'        => array(
								'type'     => 'array',
								'required' => true,
								'items'    => array( 'type' => 'string' ),
							),
						)
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
	 * Returns the stored resolved values of a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function get_proposal( WP_REST_Request $request ) {
		$proposal = $this->load( (string) $request->get_param( 'id' ) );

		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		return new WP_REST_Response( $this->present( $proposal ), 200 );
	}

	/**
	 * Declines a proposal, writing nothing.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function decline_proposal( WP_REST_Request $request ) {
		$proposal_id = (string) $request->get_param( 'id' );
		$proposal    = $this->load( $proposal_id );

		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		$this->store->delete( $proposal_id, get_current_user_id() );

		return new WP_REST_Response(
			array(
				'proposal_id' => $proposal_id,
				'declined'    => true,
			),
			200
		);
	}

	/**
	 * Executes the approved items of a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function execute_proposal( WP_REST_Request $request ) {
		$proposal_id = (string) $request->get_param( 'id' );
		$proposal    = $this->load( $proposal_id );

		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}

		/*
		 * The conversation is compared independently of the capability check.
		 * Capability is not identity, and a proposal approved in one conversation
		 * must not be executable from another one — including by the same person
		 * in a different thread, where the values on screen are not these values.
		 */
		$conversation_id = isset( $proposal['conversation_id'] ) && is_string( $proposal['conversation_id'] )
			? $proposal['conversation_id']
			: '';

		if ( '' === $conversation_id || $conversation_id !== (string) $request->get_param( 'conversation_id' ) ) {
			return new WP_Error(
				'workspace_proposal_conversation_mismatch',
				__( 'That proposal belongs to a different conversation.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$selected = $this->normalize_selected( $request->get_param( 'selected' ) );

		if ( array() === $selected ) {
			return new WP_Error(
				'workspace_proposal_nothing_selected',
				__( 'Select at least one item to create.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$known = $this->item_keys( $proposal );

		foreach ( $selected as $key ) {
			if ( ! in_array( $key, $known, true ) ) {
				return new WP_Error(
					'workspace_proposal_unknown_item',
					__( 'That proposal does not contain one of the selected items.', 'ai' ),
					array( 'status' => 400 )
				);
			}
		}

		$results = $this->writer->write( $proposal, $selected );

		$proposal['status']  = 'executed';
		$proposal['results'] = $results['items'];
		$this->store->save( $proposal );

		$this->report_to_model( $conversation_id, $proposal_id, $results );

		return new WP_REST_Response(
			array_merge(
				array(
					'proposal_id'     => $proposal_id,
					'conversation_id' => $conversation_id,
				),
				$results
			),
			200
		);
	}

	/**
	 * Loads a proposal for the current user.
	 *
	 * A proposal that does not resolve for this user is reported as not found
	 * whatever the reason — it never existed, it belongs to someone else, or it
	 * expired — so a proposal ID cannot be used to probe for another person's
	 * pending writes.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @return array<string, mixed>|\WP_Error The proposal, or an error.
	 */
	private function load( string $proposal_id ) {
		$proposal = $this->store->get( $proposal_id, get_current_user_id() );

		if ( null === $proposal ) {
			return new WP_Error(
				'workspace_proposal_not_found',
				__( 'That proposal was not found.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		return $proposal;
	}

	/**
	 * Returns the item keys a proposal carries.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @return list<string> The item keys.
	 */
	private function item_keys( array $proposal ): array {
		$keys  = array();
		$items = isset( $proposal['items'] ) && is_array( $proposal['items'] ) ? $proposal['items'] : array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['key'] ) || ! is_string( $item['key'] ) ) {
				continue;
			}

			$keys[] = $item['key'];
		}

		return $keys;
	}

	/**
	 * Normalizes the selected item keys.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $selected The raw selection.
	 * @return list<string> The selected keys.
	 */
	private function normalize_selected( $selected ): array {
		if ( ! is_array( $selected ) ) {
			return array();
		}

		$keys = array();

		foreach ( $selected as $key ) {
			if ( ! is_string( $key ) || '' === $key || in_array( $key, $keys, true ) ) {
				continue;
			}

			$keys[] = $key;
		}

		return $keys;
	}

	/**
	 * Presents a proposal as the confirmation surface reads it.
	 *
	 * Only the stored resolved values are returned. There is no field here for a
	 * summary, and the store dropped anything the model supplied beyond the
	 * declared item fields, so the confirmation cannot render the assistant's
	 * description of a write in place of the write itself (R16).
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @return array<string, mixed> The presented proposal.
	 */
	private function present( array $proposal ): array {
		$items  = isset( $proposal['items'] ) && is_array( $proposal['items'] ) ? $proposal['items'] : array();
		$fields = Proposal_Store::item_fields();

		$presented = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$row = array( 'key' => isset( $item['key'] ) && is_string( $item['key'] ) ? $item['key'] : '' );

			foreach ( $fields as $field ) {
				$row[ $field ] = isset( $item[ $field ] ) && is_string( $item[ $field ] ) ? $item[ $field ] : '';
			}

			$presented[] = $row;
		}

		return array(
			'proposal_id'     => isset( $proposal['id'] ) && is_string( $proposal['id'] ) ? $proposal['id'] : '',
			'conversation_id' => isset( $proposal['conversation_id'] ) && is_string( $proposal['conversation_id'] )
				? $proposal['conversation_id']
				: '',
			'status'          => isset( $proposal['status'] ) && is_string( $proposal['status'] ) ? $proposal['status'] : 'pending',
			'expires'         => isset( $proposal['expires'] ) ? (int) $proposal['expires'] : 0,
			'max_items'       => Proposal_Store::MAX_ITEMS,
			'items'           => $presented,
		);
	}

	/**
	 * Tells the model what actually happened, without retrying anything.
	 *
	 * The outcome is appended to the conversation as data, in the same untrusted
	 * envelope shape tool results use, so the next turn's history states which
	 * items exist and which do not. Nothing here retries a failed item: R17
	 * makes that the person's decision.
	 *
	 * @since x.x.x
	 *
	 * @param string               $conversation_id The conversation ID.
	 * @param string               $proposal_id     The proposal ID.
	 * @param array<string, mixed> $results         The write results.
	 */
	private function report_to_model( string $conversation_id, string $proposal_id, array $results ): void {
		if ( '' === $conversation_id ) {
			return;
		}

		$user_id      = get_current_user_id();
		$conversation = $this->conversations->get( $conversation_id, $user_id );

		if ( null === $conversation ) {
			return;
		}

		$items = array();

		foreach ( $results['items'] as $item ) {
			$items[] = array(
				'key'        => $item['key'],
				'title'      => $item['title'],
				'outcome'    => $item['outcome'],
				'post_id'    => $item['post_id'],
				'error_code' => $item['error_code'],
			);
		}

		$envelope = array(
			'wp_write_result' => array(
				'operation'   => Draft_Writer::LOG_OPERATION,
				'proposal_id' => $proposal_id,
				'approved_by' => array( 'user_id' => $user_id ),
				'created'     => $results['created'],
				'failed'      => $results['failed'],
				'denied'      => $results['denied'],
				'duplicate'   => $results['duplicate'],
				'deselected'  => $results['deselected'],
				'items'       => $items,
				'note'        => __( 'This is the authoritative outcome of the write. Do not retry failed or deselected items on your own; report what happened and let the person decide.', 'ai' ),
			),
		);

		$message = new UserMessage( array( new MessagePart( (string) wp_json_encode( $envelope ) ) ) );

		$messages = isset( $conversation['messages'] ) && is_array( $conversation['messages'] )
			? array_values( $conversation['messages'] )
			: array();

		$messages[] = $message->toArray();

		$conversation['messages'] = $messages;

		$this->conversations->save( $conversation );
	}
}

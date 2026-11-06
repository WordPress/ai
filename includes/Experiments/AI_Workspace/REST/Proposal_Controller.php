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
use WordPress\AI\Experiments\AI_Workspace\Proposal_Kind;
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
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\AI_Workspace\Proposal_Store|null     $store         The proposal store, which also holds each kind's writer.
	 * @param \WordPress\AI\Experiments\AI_Workspace\Conversation_Store|null $conversations The conversation store.
	 */
	public function __construct(
		?Proposal_Store $store = null,
		?Conversation_Store $conversations = null
	) {
		$this->store         = null === $store ? new Proposal_Store() : $store;
		$this->conversations = null === $conversations ? new Conversation_Store() : $conversations;
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
							/*
							 * The person's edits to proposed values, keyed by
							 * item key. Each edited item is validated again by
							 * its kind before anything is written.
							 */
							'edits'           => array(
								'type'     => 'object',
								'required' => false,
								'default'  => array(),
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

		/*
		 * An executed proposal has already written, and one that is executing or
		 * failed part way may have. Discarding it now would tell the model
		 * nothing was written, which may be the opposite of what happened.
		 */
		if ( isset( $proposal['status'] ) && in_array( $proposal['status'], array( 'executed', 'executing', 'failed' ), true ) ) {
			return new WP_Error(
				'workspace_proposal_already_executed',
				__( 'That proposal has already been approved, so it can no longer be discarded.', 'ai' ),
				array( 'status' => 409 )
			);
		}

		$this->store->delete( $proposal_id, get_current_user_id() );

		$conversation_id = isset( $proposal['conversation_id'] ) && is_string( $proposal['conversation_id'] )
			? $proposal['conversation_id']
			: '';

		return new WP_REST_Response(
			array(
				'proposal_id'     => $proposal_id,
				'conversation_id' => $conversation_id,
				'declined'        => true,
				// Whether the client may ask the assistant to follow up.
				'follow_up'       => $this->report_discard_to_model( $conversation_id, $proposal_id, $proposal ),
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

		$user_id = get_current_user_id();

		/*
		 * A proposal belongs to its chat. Once the chat is gone, deleted by its
		 * owner or erased with their personal data, nothing it proposed may be
		 * written: the person can no longer see what they would be approving.
		 */
		if ( null === $this->conversations->get( $conversation_id, $user_id ) ) {
			$this->store->delete( $proposal_id, $user_id );

			return new WP_Error(
				'workspace_proposal_chat_deleted',
				__( 'The chat this proposal belongs to was deleted, so it can no longer be approved.', 'ai' ),
				array( 'status' => 410 )
			);
		}

		// Approved once already, being approved now, or interrupted: write nothing.
		if ( ! $this->is_pending( $proposal ) ) {
			return $this->stored_response( $proposal );
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

		$kind = $this->store->kind_of( $proposal );

		if ( null === $kind ) {
			return new WP_Error(
				'workspace_proposal_invalid_kind',
				__( 'That kind of proposal is not supported.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$edits = $request->get_param( 'edits' );

		if ( null !== $edits && ! is_array( $edits ) ) {
			return new WP_Error(
				'workspace_proposal_invalid_edit',
				__( 'Edits must be keyed by item.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		// Validated before the claim, so a refused edit leaves the proposal as it was.
		$edited = $this->store->apply_edits( $proposal, is_array( $edits ) ? $edits : array() );

		if ( is_wp_error( $edited ) ) {
			return $edited;
		}

		if ( ! $this->store->claim( $proposal_id, $user_id ) ) {
			$current = $this->store->find( $proposal_id, $user_id, true );

			return $this->stored_response( null === $current ? $proposal : $current );
		}

		/*
		 * Read again past this request's cache now the claim is held: a request
		 * that finished and released the claim between this one's first read and
		 * its claim has already written.
		 */
		$current = $this->store->find( $proposal_id, $user_id, true );

		if ( null === $current || ! $this->is_pending( $current ) ) {
			$this->store->release( $proposal_id, $user_id );

			return $this->stored_response( null === $current ? $proposal : $current );
		}

		$proposal = $edited;

		$proposal['status']          = 'executing';
		$proposal['executing_since'] = time();
		$proposal['results']         = array();
		$this->store->save( $proposal );

		$store = $this->store;

		// Each outcome is saved as soon as it is known, so an interrupted write keeps them.
		$results = $kind->write(
			$proposal,
			$selected,
			static function ( array $outcome ) use ( &$proposal, $store ): void {
				$proposal['results'][] = $outcome;
				$store->save( $proposal );
			}
		);

		$proposal['status']  = 'executed';
		$proposal['results'] = $results['items'];
		unset( $proposal['executing_since'] );
		$this->store->save( $proposal );
		$this->store->release( $proposal_id, $user_id );

		$counts   = $this->counts( $results );
		$reported = $this->report_to_model( $conversation_id, $proposal_id, $kind->log_operation(), $results['items'], $counts );

		return new WP_REST_Response(
			array_merge(
				array(
					'proposal_id'     => $proposal_id,
					'conversation_id' => $conversation_id,
					// Whether the client may ask the assistant to follow up.
					'follow_up'       => $reported,
					'items'           => $results['items'],
				),
				$counts
			),
			200
		);
	}

	/**
	 * Reports whether a proposal may still be approved.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @return bool True while nothing has started writing it.
	 */
	private function is_pending( array $proposal ): bool {
		return ! isset( $proposal['status'] ) || 'pending' === $proposal['status'];
	}

	/**
	 * Builds the response for an approval that wrote nothing because the proposal was already settled.
	 *
	 * It carries the results stored so far, in the envelope a first approval
	 * returns, so a second tab shows what the first one wrote. No follow-up is
	 * owed: the first approval already reported the outcome to the model.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The stored proposal.
	 * @return \WP_REST_Response The response.
	 */
	private function stored_response( array $proposal ): WP_REST_Response {
		return new WP_REST_Response( $this->stored_results( $proposal ), 200 );
	}

	/**
	 * Builds the envelope of the results a proposal has stored so far.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The stored proposal.
	 * @return array<string, mixed> The envelope a first approval returns, owing no follow-up.
	 */
	private function stored_results( array $proposal ): array {
		$items = isset( $proposal['results'] ) && is_array( $proposal['results'] ) ? array_values( $proposal['results'] ) : array();

		// Outcomes that are not strings cannot be counted; `counts()` drops unknown ones.
		$tally = array_count_values( array_filter( array_column( $items, 'outcome' ), 'is_string' ) );

		return array_merge(
			array(
				'proposal_id'     => isset( $proposal['id'] ) && is_string( $proposal['id'] ) ? $proposal['id'] : '',
				'conversation_id' => isset( $proposal['conversation_id'] ) && is_string( $proposal['conversation_id'] ) ? $proposal['conversation_id'] : '',
				'follow_up'       => false,
				'items'           => $items,
			),
			$this->counts( $tally )
		);
	}

	/**
	 * Reads every outcome count from a kind's write result, zero for any it left out.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $results The write result.
	 * @return array<string, int> The counts, keyed and ordered as {@see Proposal_Kind::OUTCOMES}.
	 */
	private function counts( array $results ): array {
		$counts = array();

		foreach ( Proposal_Kind::OUTCOMES as $outcome ) {
			$counts[ $outcome ] = isset( $results[ $outcome ] ) ? (int) $results[ $outcome ] : 0;
		}

		return $counts;
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
		$user_id  = get_current_user_id();
		$proposal = $this->store->find( $proposal_id, $user_id );

		if ( null === $proposal ) {
			return new WP_Error(
				'workspace_proposal_not_found',
				__( 'That proposal was not found.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		/*
		 * The stored record outlives its expiry by a grace period, so an expired
		 * proposal is told apart from a missing one before it is deleted.
		 */
		if ( $this->store->is_expired( $proposal ) ) {
			$this->store->delete( $proposal_id, $user_id );

			return new WP_Error(
				'workspace_proposal_expired',
				__( 'That proposal has expired. Ask the assistant to propose it again.', 'ai' ),
				array( 'status' => 410 )
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
	 * A draft proposal carries no `kind`, exactly as before proposals had kinds,
	 * and the card reads a missing kind as drafts.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @return array<string, mixed> The presented proposal.
	 */
	private function present( array $proposal ): array {
		$items = isset( $proposal['items'] ) && is_array( $proposal['items'] ) ? $proposal['items'] : array();
		$kind  = $this->store->kind_of( $proposal );

		$presented = array();

		foreach ( $items as $item ) {
			if ( null === $kind || ! is_array( $item ) ) {
				continue;
			}

			$presented[] = array_merge(
				array( 'key' => isset( $item['key'] ) && is_string( $item['key'] ) ? $item['key'] : '' ),
				$kind->present_item( $item )
			);
		}

		$presentation = array(
			'proposal_id'     => isset( $proposal['id'] ) && is_string( $proposal['id'] ) ? $proposal['id'] : '',
			'conversation_id' => isset( $proposal['conversation_id'] ) && is_string( $proposal['conversation_id'] )
				? $proposal['conversation_id']
				: '',
			'status'          => isset( $proposal['status'] ) && is_string( $proposal['status'] ) ? $proposal['status'] : 'pending',
			'expires'         => isset( $proposal['expires'] ) ? (int) $proposal['expires'] : 0,
			'max_items'       => null === $kind ? 0 : $kind->max_items(),
			'items'           => $presented,
		);

		if ( null !== $kind && Draft_Writer::KIND !== $kind->name() ) {
			$presentation['kind'] = $kind->name();
		}

		/*
		 * Once approval has started, the card shows what was written rather than
		 * asking again: a second tab, a page loaded while another window is still
		 * writing, and an interrupted write all read their outcome from here.
		 */
		if ( ! $this->is_pending( $proposal ) ) {
			$presentation['results'] = $this->stored_results( $proposal );
		}

		return $presentation;
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
	 * @param string                     $conversation_id The conversation ID.
	 * @param string                     $proposal_id     The proposal ID.
	 * @param string                     $operation       The operation the kind's writer logs.
	 * @param list<array<string, mixed>> $items           The per-item outcomes.
	 * @param array<string, int>         $counts          The outcome counts.
	 * @return bool True when the outcome was recorded and a follow-up is owed.
	 */
	private function report_to_model( string $conversation_id, string $proposal_id, string $operation, array $items, array $counts ): bool {
		$reported = array();

		foreach ( $items as $item ) {
			$row = array(
				'key'        => isset( $item['key'] ) ? $item['key'] : '',
				'title'      => isset( $item['title'] ) ? $item['title'] : '',
				'outcome'    => isset( $item['outcome'] ) ? $item['outcome'] : '',
				'post_id'    => isset( $item['post_id'] ) ? $item['post_id'] : 0,
				'error_code' => isset( $item['error_code'] ) ? $item['error_code'] : '',
			);

			/*
			 * An update kind also names the field it changed and either the value
			 * it wrote or, for long values such as content, only its length.
			 */
			foreach ( array( 'field', 'value', 'length' ) as $detail ) {
				if ( ! isset( $item[ $detail ] ) ) {
					continue;
				}

				$row[ $detail ] = $item[ $detail ];
			}

			$reported[] = $row;
		}

		$envelope = array(
			'wp_write_result' => array_merge(
				array(
					'operation'   => $operation,
					'proposal_id' => $proposal_id,
					'approved_by' => array( 'user_id' => get_current_user_id() ),
				),
				$counts,
				array(
					'items' => $reported,
					'note'  => __( 'This is the authoritative outcome of the write. Do not retry failed or deselected items on your own; report what happened and let the person decide.', 'ai' ),
				)
			),
		);

		return $this->append_outcome( $conversation_id, $envelope );
	}

	/**
	 * Tells the model the person discarded a proposal.
	 *
	 * Without this the next turn's history would still show the proposal as
	 * pending, and the model could not tell a discard from no answer at all.
	 *
	 * @since x.x.x
	 *
	 * @param string               $conversation_id The conversation ID.
	 * @param string               $proposal_id     The proposal ID.
	 * @param array<string, mixed> $proposal        The discarded proposal.
	 * @return bool True when the outcome was recorded and a follow-up is owed.
	 */
	private function report_discard_to_model( string $conversation_id, string $proposal_id, array $proposal ): bool {
		$items = array();

		foreach ( $this->present( $proposal )['items'] as $item ) {
			$items[] = array(
				'key'       => $item['key'],
				'post_type' => $item['post_type'],
				'title'     => $item['title'],
			);
		}

		return $this->append_outcome(
			$conversation_id,
			array(
				'wp_proposal_discarded' => array(
					'proposal_id'  => $proposal_id,
					'discarded_by' => array( 'user_id' => get_current_user_id() ),
					'items'        => $items,
					'note'         => __( 'The person discarded this proposal. Nothing was written. Do not propose it again unless they ask.', 'ai' ),
				),
			)
		);
	}

	/**
	 * Appends a proposal outcome to the conversation and owes it one follow-up.
	 *
	 * The outcome goes in as data, in the same untrusted envelope shape tool
	 * results use. Nothing is recorded, and no follow-up is owed, when the
	 * conversation has expired or was never there.
	 *
	 * @since x.x.x
	 *
	 * @param string               $conversation_id The conversation ID.
	 * @param array<string, mixed> $envelope        The outcome envelope.
	 * @return bool True when the outcome was recorded.
	 */
	private function append_outcome( string $conversation_id, array $envelope ): bool {
		if ( '' === $conversation_id ) {
			return false;
		}

		$conversation = $this->conversations->get( $conversation_id, get_current_user_id() );

		if ( null === $conversation ) {
			return false;
		}

		$message = new UserMessage( array( new MessagePart( (string) wp_json_encode( $envelope ) ) ) );

		$messages = isset( $conversation['messages'] ) && is_array( $conversation['messages'] )
			? array_values( $conversation['messages'] )
			: array();

		$messages[] = $message->toArray();

		$conversation['messages'] = $messages;

		$conversation[ Conversation_Store::FOLLOW_UP_OWED ] = true;

		return $this->conversations->save( $conversation );
	}
}

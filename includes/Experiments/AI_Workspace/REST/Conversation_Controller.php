<?php
/**
 * REST controller for the AI Workspace chat history.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace\REST;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WordPress\AI\Experiments\AI_Workspace\Conversation_Store;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provides the `/ai/v1/workspace/conversations` routes.
 *
 * A user lists, opens, renames, archives, restores and deletes their own chats
 * here. Every route checks the same capability as the turn route before it looks
 * at a chat, and a chat the caller does not own is reported as not found rather
 * than forbidden, so a conversation ID never confirms that someone else's chat
 * exists.
 *
 * @since x.x.x
 */
final class Conversation_Controller {

	/**
	 * The REST API namespace.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const API_NAMESPACE = 'ai/v1';

	/**
	 * Full path of the conversations route, as advertised to the client.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const CONVERSATIONS_ROUTE = 'ai/v1/workspace/conversations';

	/**
	 * Default number of chats per page.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const DEFAULT_PER_PAGE = 20;

	/**
	 * Most chats a page may hold.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const MAX_PER_PAGE = 50;

	/**
	 * The conversation store.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\AI_Workspace\Conversation_Store
	 */
	private Conversation_Store $store;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Experiments\AI_Workspace\Conversation_Store|null $store The conversation store.
	 */
	public function __construct( ?Conversation_Store $store = null ) {
		$this->store = null === $store ? new Conversation_Store() : $store;
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
	 * Registers the conversations routes.
	 *
	 * @since x.x.x
	 */
	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/workspace/conversations',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_conversations' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'archived' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::MAX_PER_PAGE,
							'default' => self::DEFAULT_PER_PAGE,
						),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/workspace/conversations/(?P<id>[A-Za-z0-9\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conversation' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_conversation' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'name'     => array(
							'type'      => 'string',
							'maxLength' => Conversation_Store::MAX_NAME_LENGTH,
						),
						'archived' => array(
							'type' => 'boolean',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_conversation' ),
					'permission_callback' => array( $this, 'check_permission' ),
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
	 * Lists the current user's chats, most recently active first.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response The response.
	 */
	public function list_conversations( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request->get_param( 'per_page' );
		$page     = $this->store->list_for_user(
			get_current_user_id(),
			(bool) $request->get_param( 'archived' ),
			(int) $request->get_param( 'page' ),
			$per_page
		);

		$total_pages = (int) ceil( $page['total'] / max( 1, $per_page ) );

		$response = new WP_REST_Response(
			array(
				'conversations' => $page['items'],
				'total'         => $page['total'],
				'total_pages'   => $total_pages,
			),
			200
		);

		$response->header( 'X-WP-Total', (string) $page['total'] );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * Returns one of the current user's chats, with its whole transcript.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function get_conversation( WP_REST_Request $request ) {
		$conversation = $this->store->get( (string) $request->get_param( 'id' ), get_current_user_id() );

		if ( null === $conversation ) {
			return $this->not_found();
		}

		return new WP_REST_Response( $this->present( $conversation ), 200 );
	}

	/**
	 * Renames, archives or restores one of the current user's chats.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function update_conversation( WP_REST_Request $request ) {
		$conversation_id = (string) $request->get_param( 'id' );
		$user_id         = get_current_user_id();

		if ( null === $this->store->get( $conversation_id, $user_id ) ) {
			return $this->not_found();
		}

		$name     = $request->get_param( 'name' );
		$archived = $request->get_param( 'archived' );

		if ( null === $name && null === $archived ) {
			return new WP_Error(
				'workspace_conversation_nothing_to_update',
				__( 'Give the chat a new name, or say whether it is archived.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( null !== $name ) {
			$name = trim( sanitize_text_field( (string) $name ) );

			if ( '' === $name ) {
				return new WP_Error(
					'workspace_conversation_empty_name',
					__( 'A chat needs a name.', 'ai' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( null !== $name && ! $this->store->rename( $conversation_id, $user_id, $name ) ) {
			return $this->not_saved();
		}

		if ( null !== $archived && ! $this->store->set_archived( $conversation_id, $user_id, (bool) $archived ) ) {
			return $this->not_saved();
		}

		$conversation = $this->store->get( $conversation_id, $user_id );

		if ( null === $conversation ) {
			return $this->not_found();
		}

		return new WP_REST_Response( $this->summarize( $conversation ), 200 );
	}

	/**
	 * Deletes one of the current user's chats for good.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The response.
	 */
	public function delete_conversation( WP_REST_Request $request ) {
		$conversation_id = (string) $request->get_param( 'id' );
		$user_id         = get_current_user_id();

		if ( null === $this->store->get( $conversation_id, $user_id ) ) {
			return $this->not_found();
		}

		$this->store->delete( $conversation_id, $user_id );

		return new WP_REST_Response(
			array(
				'id'      => $conversation_id,
				'deleted' => true,
			),
			200
		);
	}

	/**
	 * Builds the summary a chat is listed with.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $conversation The conversation.
	 * @return array<string, mixed> The summary.
	 */
	private function summarize( array $conversation ): array {
		return array(
			'id'       => $conversation['id'],
			'name'     => $conversation['name'],
			'archived' => $conversation['archived'],
			'created'  => $conversation['created'],
			'updated'  => $conversation['updated'],
		);
	}

	/**
	 * Builds the full view of a chat, from which the transcript is redrawn.
	 *
	 * The transcript is the entry records, one per turn, rather than the model
	 * messages, which hold provider-shaped tool traffic. The one thing a turn's
	 * entry cannot hold is what happened after it ended: a proposal approved later
	 * reports its outcome into the messages as a `wp_write_result` record, so those
	 * records are returned alongside the entries.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $conversation The conversation.
	 * @return array<string, mixed> The view.
	 */
	private function present( array $conversation ): array {
		return array_merge(
			$this->summarize( $conversation ),
			array(
				'scope'         => isset( $conversation['scope'] ) && is_string( $conversation['scope'] ) ? $conversation['scope'] : '',
				'entries'       => $conversation['entries'],
				'write_results' => $this->write_results( $conversation['messages'] ),
			)
		);
	}

	/**
	 * Collects the proposal outcomes reported into a chat's messages.
	 *
	 * Each item that names a post is given that post's type, status and edit link
	 * as they are now, so a reopened chat can link to what was written. The link
	 * is given only when the reader may edit the post, as core's own edit link is.
	 *
	 * @since x.x.x
	 *
	 * @param list<mixed> $messages The stored model messages.
	 * @return list<array<string, mixed>> The `wp_write_result` records, in order.
	 */
	private function write_results( array $messages ): array {
		$results = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['parts'] ) || ! is_array( $message['parts'] ) ) {
				continue;
			}

			/*
			 * The server appends an approval's outcome as a user message. A model
			 * reply that happens to look like one is the model's text, and is
			 * never shown as an outcome.
			 */
			if ( ! isset( $message['role'] ) || 'user' !== $message['role'] ) {
				continue;
			}

			foreach ( $message['parts'] as $part ) {
				$text    = is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ? $part['text'] : '';
				$decoded = '' !== $text && str_starts_with( $text, '{"wp_write_result"' ) ? json_decode( $text, true ) : null;

				if ( ! is_array( $decoded ) || ! isset( $decoded['wp_write_result'] ) || ! is_array( $decoded['wp_write_result'] ) ) {
					continue;
				}

				$result = $decoded['wp_write_result'];

				if ( isset( $result['items'] ) && is_array( $result['items'] ) ) {
					$result['items'] = array_map( array( $this, 'describe_written_item' ), $result['items'] );
				}

				$results[] = $result;
			}
		}

		return $results;
	}

	/**
	 * Adds the current state of the post an outcome item names.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $item The reported item.
	 * @return mixed The item, with the post's current `post_type`, `status` and `edit_link` when it names a post.
	 */
	private function describe_written_item( $item ) {
		if ( ! is_array( $item ) ) {
			return $item;
		}

		$post_id = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		// An item that wrote nothing keeps what was reported for it.
		if ( ! $post instanceof WP_Post ) {
			return array_merge( array( 'edit_link' => '' ), $item );
		}

		return array_merge(
			$item,
			array(
				'post_type' => $post->post_type,
				'status'    => $post->post_status,
				'edit_link' => (string) get_edit_post_link( $post, 'raw' ),
			)
		);
	}

	/**
	 * Builds the error returned for a chat the caller cannot see.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Error The error.
	 */
	private function not_found(): WP_Error {
		return new WP_Error(
			'workspace_conversation_not_found',
			__( 'That conversation was not found.', 'ai' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Builds the error returned when a change to a chat could not be saved.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Error The error.
	 */
	private function not_saved(): WP_Error {
		return new WP_Error(
			'workspace_conversation_not_saved',
			__( 'The chat could not be saved.', 'ai' ),
			array( 'status' => 500 )
		);
	}
}

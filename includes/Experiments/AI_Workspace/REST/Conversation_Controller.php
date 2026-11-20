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
use WordPress\AI\Access_Control\Feature_Access;
use WordPress\AI\Experiments\AI_Workspace\AI_Workspace;
use WordPress\AI\Experiments\AI_Workspace\Conversation_Store;
use WordPress\AI\Experiments\AI_Workspace\Turn_Runner;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Provides the `/ai/v1/workspace/conversations` routes.
 *
 * Another user's chat is reported as not found, not forbidden, so an ID never confirms it exists.
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

		// Closes a question without a model call.
		register_rest_route(
			self::API_NAMESPACE,
			'/workspace/conversations/(?P<id>[A-Za-z0-9\-]+)/dismiss',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'dismiss_question' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'turn_id' => array(
							'type'     => 'string',
							'format'   => 'uuid',
							'required' => true,
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

		// The routes are not abilities, so they apply the feature's access control themselves.
		if ( ! current_user_can( Feature_Access::CAPABILITY, AI_Workspace::get_id() ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Plugin meta capability, mapped in Feature_Access::map_meta_cap().
			return new WP_Error(
				'wpai_feature_access_denied',
				esc_html__( 'You do not have permission to use this feature.', 'ai' ),
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
				__( 'Give the space a new name, or say whether it is archived.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( null !== $name ) {
			$name = trim( sanitize_text_field( (string) $name ) );

			if ( '' === $name ) {
				return new WP_Error(
					'workspace_conversation_empty_name',
					__( 'A space needs a name.', 'ai' ),
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
	 * Closes the open question a turn asked, storing a dismissed entry and nothing for the model.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error The stored entry, or an error.
	 */
	public function dismiss_question( WP_REST_Request $request ) {
		$turn_id      = (string) $request->get_param( 'turn_id' );
		$conversation = $this->store->get( (string) $request->get_param( 'id' ), get_current_user_id() );

		if ( null === $conversation ) {
			return $this->not_found();
		}

		if ( ! $this->is_open_question( $conversation['entries'], $turn_id ) ) {
			return new WP_Error(
				'workspace_question_not_open',
				__( 'That question has already been answered or closed.', 'ai' ),
				array( 'status' => 409 )
			);
		}

		$entry                   = Turn_Runner::dismissal_entry( $turn_id );
		$conversation['entries'] = array_merge( $conversation['entries'], array( $entry ) );

		if ( ! $this->store->save( $conversation ) ) {
			return $this->not_saved();
		}

		return new WP_REST_Response( $entry, 200 );
	}

	/**
	 * Reports whether a turn's question is still open: asked, then followed only by follow-ups.
	 *
	 * @since x.x.x
	 *
	 * @param list<mixed> $entries The stored entry records.
	 * @param string      $turn_id The ID of the turn that asked.
	 * @return bool True when the question can still be answered.
	 */
	private function is_open_question( array $entries, string $turn_id ): bool {
		$open = false;

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$status = isset( $entry['status'] ) && is_string( $entry['status'] ) ? $entry['status'] : '';

			if ( Turn_Runner::STATUS_AWAITING_USER === $status && isset( $entry['turn_id'] ) && $turn_id === $entry['turn_id'] ) {
				$open = true;
				continue;
			}

			// The person's next message, or a dismissal, closes it.
			if ( ! empty( $entry['follow_up'] ) ) {
				continue;
			}

			$open = false;
		}

		return $open;
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
	 * Builds the full view of a chat, with proposal outcomes recorded after their turn ended.
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
	 * @since x.x.x
	 *
	 * @param list<mixed> $messages The stored model messages.
	 * @return list<array<string, mixed>> The `wp_write_result` and discard records, in order.
	 */
	private function write_results( array $messages ): array {
		$results = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['parts'] ) || ! is_array( $message['parts'] ) ) {
				continue;
			}

			// Outcomes are appended as user messages; model text that mimics one is ignored.
			if ( ! isset( $message['role'] ) || 'user' !== $message['role'] ) {
				continue;
			}

			foreach ( $message['parts'] as $part ) {
				$result = $this->outcome_record( $part );

				if ( null === $result ) {
					continue;
				}

				if ( isset( $result['items'] ) && is_array( $result['items'] ) ) {
					$result['items'] = array_map( array( $this, 'describe_written_item' ), $result['items'] );
				}

				$results[] = $result;
			}
		}

		return $results;
	}

	/**
	 * Reads the proposal outcome one message part reports, if it reports one.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $part The message part.
	 * @return array<string, mixed>|null The outcome record, or null when the part is not one.
	 */
	private function outcome_record( $part ): ?array {
		$text = is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ? $part['text'] : '';

		if ( str_starts_with( $text, '{"wp_write_result"' ) ) {
			$decoded = json_decode( $text, true );

			return is_array( $decoded ) && isset( $decoded['wp_write_result'] ) && is_array( $decoded['wp_write_result'] )
				? $decoded['wp_write_result']
				: null;
		}

		if ( ! str_starts_with( $text, '{"wp_proposal_discarded"' ) ) {
			return null;
		}

		$decoded = json_decode( $text, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['wp_proposal_discarded'] ) || ! is_array( $decoded['wp_proposal_discarded'] ) ) {
			return null;
		}

		$result = $decoded['wp_proposal_discarded'];
		$items  = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();

		$result['discarded'] = true;
		$result['items']     = array_map(
			static function ( $item ) {
				return is_array( $item ) ? array_merge( $item, array( 'outcome' => 'discarded' ) ) : $item;
			},
			$items
		);

		return $result;
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
			__( 'The space could not be saved.', 'ai' ),
			array( 'status' => 500 )
		);
	}
}

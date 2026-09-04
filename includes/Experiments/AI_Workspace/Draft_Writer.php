<?php
/**
 * Executes a confirmed AI Workspace proposal.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WP_Post_Type;

use function WordPress\AI\log_ai_request;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Writes the items of a confirmed proposal, one log row per attempt.
 *
 * There is deliberately no ability wrapping this class, and nothing but
 * {@see REST\Proposal_Controller} calls it. A registered write ability would be
 * reachable by every ability consumer on the site — the MCP surface, the
 * Abilities Explorer, any third-party caller — none of which has a confirm gate,
 * which would make the gate a property of one controller rather than of the
 * write path. Keeping the write private is what makes R15 hold everywhere.
 *
 * Three properties are load bearing:
 *
 * - **Capability is re-checked per item at write time.** The proposal was
 *   validated when it was made, and a capability can be revoked in between.
 * - **Items are independent.** One item that cannot be written does not stop the
 *   others, and each reports its own outcome (R17). Nothing is retried here.
 * - **Writes are idempotent per item.** Each created post carries the item's
 *   idempotency token in meta, so re-executing the same proposal — a double
 *   submit, a retried request — finds the existing post instead of writing a
 *   second one.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Draft_Writer {

	/**
	 * Operation name recorded on every log row this class writes.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const LOG_OPERATION = 'ai/create-drafts';

	/**
	 * Post meta holding the idempotency token of the item that created a post.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const IDEMPOTENCY_META = '_wpai_workspace_proposal_item';

	/**
	 * Writes the selected items of a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The stored proposal.
	 * @param array<int, string>   $selected The item keys the person approved.
	 * @return array{items: list<array<string, mixed>>, created: int, failed: int, denied: int, duplicate: int, deselected: int} The per-item outcomes.
	 */
	public function write( array $proposal, array $selected ): array {
		$items           = isset( $proposal['items'] ) && is_array( $proposal['items'] ) ? $proposal['items'] : array();
		$conversation_id = isset( $proposal['conversation_id'] ) && is_string( $proposal['conversation_id'] )
			? $proposal['conversation_id']
			: '';
		$proposal_id     = isset( $proposal['id'] ) && is_string( $proposal['id'] ) ? $proposal['id'] : '';

		$outcomes = array();
		$counts   = array(
			'created'    => 0,
			'failed'     => 0,
			'denied'     => 0,
			'duplicate'  => 0,
			'deselected' => 0,
		);

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$key = isset( $item['key'] ) && is_string( $item['key'] ) ? $item['key'] : '';

			if ( ! in_array( $key, $selected, true ) ) {
				++$counts['deselected'];
				$outcomes[] = $this->outcome( $item, 'deselected', 0, '', '' );
				continue;
			}

			$outcome = $this->write_item( $item, $proposal_id, $conversation_id );

			++$counts[ $outcome['outcome'] ];
			$outcomes[] = $outcome;
		}

		return array(
			'items'      => $outcomes,
			'created'    => $counts['created'],
			'failed'     => $counts['failed'],
			'denied'     => $counts['denied'],
			'duplicate'  => $counts['duplicate'],
			'deselected' => $counts['deselected'],
		);
	}

	/**
	 * Writes one item.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item            The proposal item.
	 * @param string               $proposal_id     The proposal ID.
	 * @param string               $conversation_id The conversation ID.
	 * @return array<string, mixed> The item outcome.
	 */
	private function write_item( array $item, string $proposal_id, string $conversation_id ): array {
		$key       = isset( $item['key'] ) && is_string( $item['key'] ) ? $item['key'] : '';
		$post_type = isset( $item['post_type'] ) && is_string( $item['post_type'] ) ? $item['post_type'] : 'post';
		$status    = isset( $item['status'] ) && is_string( $item['status'] ) ? $item['status'] : 'draft';

		$started = microtime( true );

		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object instanceof WP_Post_Type || empty( $post_type_object->show_in_abilities ) ) {
			$this->log( $item, $proposal_id, $conversation_id, 'error', 0, 'invalid_post_type', 'The post type is no longer writable.' );

			return $this->outcome( $item, 'failed', 0, 'invalid_post_type', __( 'That post type can no longer be written to.', 'ai' ) );
		}

		/*
		 * Re-checked here, not only when the proposal was made: a capability can
		 * be revoked between the two requests, and this is the one that writes.
		 */
		$denied = $this->denial_reason( $post_type_object, $status );

		if ( '' !== $denied ) {
			$this->log( $item, $proposal_id, $conversation_id, Turn_Runner::LOG_STATUS_DENIED, 0, $denied, 'The user may no longer write this item.' );

			return $this->outcome( $item, 'denied', 0, $denied, __( 'You no longer have permission to create this item.', 'ai' ) );
		}

		$existing = $this->existing_post_id( $post_type, $this->token( $proposal_id, $key ) );

		if ( $existing > 0 ) {
			$this->log( $item, $proposal_id, $conversation_id, 'skipped', 0, 'already_written', '' );

			return $this->outcome( $item, 'duplicate', $existing, '', '' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => $status,
				'post_title'   => isset( $item['title'] ) && is_string( $item['title'] ) ? $item['title'] : '',
				'post_content' => isset( $item['content'] ) && is_string( $item['content'] ) ? $item['content'] : '',
				'post_excerpt' => isset( $item['excerpt'] ) && is_string( $item['excerpt'] ) ? $item['excerpt'] : '',
				'post_author'  => get_current_user_id(),
				'meta_input'   => array( self::IDEMPOTENCY_META => $this->token( $proposal_id, $key ) ),
			),
			true
		);

		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $post_id ) ) {
			$code = (string) $post_id->get_error_code();

			$this->log( $item, $proposal_id, $conversation_id, 'error', $duration, $code, $post_id->get_error_message() );

			return $this->outcome( $item, 'failed', 0, $code, $post_id->get_error_message() );
		}

		$this->log( $item, $proposal_id, $conversation_id, 'success', $duration, '', '' );

		return $this->outcome( $item, 'created', (int) $post_id, '', '' );
	}

	/**
	 * Returns the reason the current user may not write an item, if there is one.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @param string        $status           The requested post status.
	 * @return string The denial reason code, or an empty string when the write may proceed.
	 */
	private function denial_reason( WP_Post_Type $post_type_object, string $status ): string {
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
		if ( ! current_user_can( $this->post_type_cap( $post_type_object, 'create_posts' ) ) ) {
			return 'cannot_create_posts';
		}

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
		if ( in_array( $status, array( 'publish', 'private' ), true ) && ! current_user_can( $this->post_type_cap( $post_type_object, 'publish_posts' ) ) ) {
			return 'cannot_publish_posts';
		}

		return '';
	}

	/**
	 * Finds the post an item already created, if it created one.
	 *
	 * The token lives in post meta rather than only on the stored proposal, so
	 * idempotency survives the proposal transient being evicted between two
	 * executions of the same request.
	 *
	 * @since x.x.x
	 *
	 * @param string $post_type The post type to look in.
	 * @param string $token     The item's idempotency token.
	 * @return int The existing post ID, or 0 when the item has not been written.
	 */
	private function existing_post_id( string $post_type, string $token ): int {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.get_posts_get_posts -- A bounded single-row idempotency lookup that must see the live row, not a cached one.
		$found = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The meta key is indexed by the lookup and the query is bounded to one row.
				'meta_key'               => self::IDEMPOTENCY_META,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Matching one exact token.
				'meta_value'             => $token,
			)
		);

		return array() === $found ? 0 : (int) $found[0];
	}

	/**
	 * Builds the idempotency token stored on a created post.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @param string $key         The item key.
	 * @return string The token.
	 */
	public function token( string $proposal_id, string $key ): string {
		return $proposal_id . ':' . $key;
	}

	/**
	 * Builds one item outcome record.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item          The proposal item.
	 * @param string               $outcome       The outcome name.
	 * @param int                  $post_id       The created post ID, when there is one.
	 * @param string               $error_code    The error code, when there is one.
	 * @param string               $error_message The error message, when there is one.
	 * @return array<string, mixed> The outcome record.
	 */
	private function outcome( array $item, string $outcome, int $post_id, string $error_code, string $error_message ): array {
		return array(
			'key'           => isset( $item['key'] ) && is_string( $item['key'] ) ? $item['key'] : '',
			'title'         => isset( $item['title'] ) && is_string( $item['title'] ) ? $item['title'] : '',
			'post_type'     => isset( $item['post_type'] ) && is_string( $item['post_type'] ) ? $item['post_type'] : '',
			'status'        => isset( $item['status'] ) && is_string( $item['status'] ) ? $item['status'] : '',
			'outcome'       => $outcome,
			'post_id'       => $post_id,
			'edit_link'     => $post_id > 0 ? (string) get_edit_post_link( $post_id, 'raw' ) : '',
			'error_code'    => $error_code,
			'error_message' => $error_message,
		);
	}

	/**
	 * Writes the single log row for one write attempt.
	 *
	 * The shape matches the rows {@see Turn_Runner} writes for tool calls, so
	 * reads and writes from one conversation join in a single log view (KTD10).
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $item            The proposal item.
	 * @param string               $proposal_id     The proposal ID.
	 * @param string               $conversation_id The conversation ID.
	 * @param string               $status          The outcome status.
	 * @param int                  $duration        Duration in milliseconds.
	 * @param string               $error_code      The error code, when there is one.
	 * @param string               $error_message   The error message, when there is one.
	 */
	private function log(
		array $item,
		string $proposal_id,
		string $conversation_id,
		string $status,
		int $duration,
		string $error_code,
		string $error_message
	): void {
		$context = array(
			'surface'         => Turn_Runner::LOG_SURFACE,
			'conversation_id' => $conversation_id,
			'round'           => 0,
			'tool'            => array(
				'ability'  => self::LOG_OPERATION,
				'function' => self::LOG_OPERATION,
				'call_id'  => isset( $item['key'] ) && is_string( $item['key'] ) ? $item['key'] : '',
			),
			'proposal'        => array(
				'id'        => $proposal_id,
				'item_key'  => isset( $item['key'] ) && is_string( $item['key'] ) ? $item['key'] : '',
				'post_type' => isset( $item['post_type'] ) && is_string( $item['post_type'] ) ? $item['post_type'] : '',
				'status'    => isset( $item['status'] ) && is_string( $item['status'] ) ? $item['status'] : '',
			),
		);

		if ( '' !== $error_code ) {
			$context['error_code'] = $error_code;
		}

		if ( Turn_Runner::LOG_STATUS_DENIED === $status ) {
			$context['denial_reason'] = $error_code;
		}

		$log_data = array(
			'type'        => 'ability',
			'operation'   => self::LOG_OPERATION,
			'status'      => $status,
			'user_id'     => get_current_user_id(),
			'context'     => $context,
			'duration_ms' => $duration,
		);

		if ( '' !== $error_message ) {
			$log_data['error_message'] = $error_message;
		}

		log_ai_request( $log_data );
	}

	/**
	 * Resolves a capability name from a post type's capability map.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @param string        $capability       The capability key.
	 * @return string The resolved capability, or 'do_not_allow' when unresolved.
	 */
	private function post_type_cap( WP_Post_Type $post_type_object, string $capability ): string {
		$cap = $post_type_object->cap->$capability ?? null;

		return is_string( $cap ) && '' !== $cap ? $cap : 'do_not_allow';
	}
}

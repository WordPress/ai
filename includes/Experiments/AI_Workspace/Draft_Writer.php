<?php
/**
 * The `draft` kind of AI Workspace proposal.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WP_Error;
use WP_Post_Type;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Proposes and writes posts of any type exposed to abilities.
 *
 * This is the kind {@see Propose_Drafts} stores, and the kind a proposal stored
 * before proposals had kinds is read as.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Draft_Writer extends Proposal_Writer {

	/**
	 * The kind name.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const KIND = 'draft';

	/**
	 * Operation name recorded on every log row this class writes.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const LOG_OPERATION = 'ai/create-drafts';

	/**
	 * The only fields a draft item may carry.
	 *
	 * @since x.x.x
	 *
	 * @var array<int, string>
	 */
	private const ITEM_FIELDS = array( 'post_type', 'status', 'title', 'content', 'excerpt' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * Post statuses a proposal may ask for.
	 *
	 * @since x.x.x
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_STATUSES = array( 'draft', 'pending', 'private', 'publish' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * Statuses that additionally require publish access.
	 *
	 * @since x.x.x
	 *
	 * @var array<int, string>
	 */
	private const PUBLISH_STATUSES = array( 'private', 'publish' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is a single array constant.

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function name(): string {
		return self::KIND;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function max_items(): int {
		return Proposal_Store::MAX_ITEMS;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function log_operation(): string {
		return self::LOG_OPERATION;
	}

	/**
	 * Normalizes and validates one proposed draft.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $item The raw item.
	 * @return array<string, string>|\WP_Error The normalized item, or an error.
	 */
	public function normalize_item( array $item ) {
		$post_type = isset( $item['post_type'] ) && is_string( $item['post_type'] )
			? sanitize_key( $item['post_type'] )
			: 'post';

		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object instanceof WP_Post_Type || empty( $post_type_object->show_in_abilities ) ) {
			return new WP_Error(
				'workspace_proposal_invalid_post_type',
				__( 'That post type cannot be written to by the assistant.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
		if ( ! current_user_can( $this->post_type_cap( $post_type_object, 'create_posts' ) ) ) {
			return new WP_Error(
				'workspace_proposal_post_type_denied',
				__( 'You cannot create content of that type.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$status = isset( $item['status'] ) && is_string( $item['status'] ) ? sanitize_key( $item['status'] ) : 'draft';

		if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			return new WP_Error(
				'workspace_proposal_invalid_status',
				__( 'That post status cannot be proposed.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * A status the user cannot reach fails the proposal outright. Silently
		 * downgrading it to a draft would write something other than what the
		 * person was shown and approved.
		 */
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
		if ( in_array( $status, self::PUBLISH_STATUSES, true ) && ! current_user_can( $this->post_type_cap( $post_type_object, 'publish_posts' ) ) ) {
			return new WP_Error(
				'workspace_proposal_status_denied',
				__( 'You cannot publish content, so a proposal asking for a published post is refused.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$title = isset( $item['title'] ) && is_string( $item['title'] ) ? trim( $item['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error(
				'workspace_proposal_missing_title',
				__( 'Each proposed item needs a title.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * Body text is sanitized here, when it is stored, and not later when it is
		 * written. The confirmation screen renders the stored values, so filtering
		 * at store time keeps what the person was shown identical to what is
		 * written (R16); filtering at write time would show one thing and write
		 * another.
		 *
		 * It is unconditional, and deliberately not left to WordPress. The screen
		 * is gated on `manage_options`, so the approving user almost always holds
		 * `unfiltered_html` and core's own `content_save_pre` kses pass never runs
		 * for them — yet this text is model output derived from site content a
		 * lower-privileged author can write, so its provenance is not the
		 * approver's. The approver is also not a reliable filter: the body sits
		 * behind a collapsed `<details>`, so it may be approved unread.
		 *
		 * `wp_kses_post()` is the right ruleset rather than something stricter:
		 * it strips scripts and event handlers while leaving block delimiter
		 * comments intact, so a proposed block document survives it, and it is the
		 * same ruleset core would apply to both fields on save for a user without
		 * `unfiltered_html`.
		 */
		return array(
			'post_type' => $post_type,
			'status'    => $status,
			'title'     => $title,
			'content'   => isset( $item['content'] ) && is_string( $item['content'] ) ? wp_kses_post( $item['content'] ) : '',
			'excerpt'   => isset( $item['excerpt'] ) && is_string( $item['excerpt'] ) ? wp_kses_post( $item['excerpt'] ) : '',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function present_item( array $item ): array {
		$row = array();

		foreach ( self::ITEM_FIELDS as $field ) {
			$row[ $field ] = isset( $item[ $field ] ) && is_string( $item[ $field ] ) ? $item[ $field ] : '';
		}

		return $row;
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
	protected function write_item( array $item, string $proposal_id, string $conversation_id ): array {
		$key       = isset( $item['key'] ) && is_string( $item['key'] ) ? $item['key'] : '';
		$post_type = $this->item_post_type( $item );
		$status    = $this->item_status( $item );

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
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function item_post_type( array $item ): string {
		return isset( $item['post_type'] ) && is_string( $item['post_type'] ) ? $item['post_type'] : 'post';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function item_status( array $item ): string {
		return isset( $item['status'] ) && is_string( $item['status'] ) ? $item['status'] : 'draft';
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
		if ( in_array( $status, self::PUBLISH_STATUSES, true ) && ! current_user_can( $this->post_type_cap( $post_type_object, 'publish_posts' ) ) ) {
			return 'cannot_publish_posts';
		}

		return '';
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

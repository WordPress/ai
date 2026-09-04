<?php
/**
 * Server-side storage for AI Workspace write proposals.
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
 * Persists a proposed set of drafts until a person approves or declines it.
 *
 * Four properties are load bearing.
 *
 * **The medium is a user-scoped transient**, for the same reasons
 * {@see Conversation_Store} gives: a proposal is session lifetime, belongs to an
 * unproven experiment, and holds generated body text that should expire on its
 * own rather than sit in an autoloaded option. It shares the plugin's `wpai_`
 * transient prefix, so {@see \WordPress\AI\Admin\Uninstall::delete_transients()}
 * removes it without naming it.
 *
 * **A proposal belongs to one user and one conversation.** Capability is not
 * identity: on a site with two administrators, a capability check alone would
 * let the second one execute the first one's stored proposal by ID, and the
 * resolved values that person approved on screen would not be the ones written.
 * The transient key is derived from the owner's user ID, the stored owner is
 * compared again on load, and the conversation is compared at execution time —
 * three checks that are independent of the capability check.
 *
 * **A proposal expires.** The expiry is stored on the record and compared on
 * every read rather than left to the transient's own lifetime, so an object
 * cache that serves a stale row cannot make an old confirmation executable.
 *
 * **A proposal is bounded.** {@see self::MAX_ITEMS} caps how much a person is
 * asked to approve at once, in the same spirit as the search ability's row
 * bound. Set approval is the weakest point of the write path: the deeper the
 * list, the less of it anybody reads, and injected instructions win by appending
 * to an otherwise legitimate batch.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Proposal_Store {

	/**
	 * Transient name prefix for stored proposals.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const TRANSIENT_PREFIX = 'wpai_workspace_proposal_';

	/**
	 * How long an unconfirmed proposal remains executable, in seconds.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Maximum number of items a single proposal may carry.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const MAX_ITEMS = 20;

	/**
	 * The only fields a proposal item may carry.
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
	 * Creates and stores a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param int                $user_id         The proposing user.
	 * @param string             $conversation_id The conversation the proposal belongs to.
	 * @param array<int, mixed>  $items           The proposed items.
	 * @return array<string, mixed>|\WP_Error The stored proposal, or an error.
	 */
	public function create( int $user_id, string $conversation_id, array $items ) {
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'workspace_proposal_no_owner',
				__( 'A proposal must belong to a signed-in user.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		$normalized = $this->normalize_items( $items );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$now = time();

		$proposal = array(
			'id'              => wp_generate_uuid4(),
			'user_id'         => $user_id,
			'conversation_id' => $conversation_id,
			'created'         => $now,
			'expires'         => $now + self::TTL,
			'status'          => 'pending',
			'items'           => $normalized,
			'results'         => array(),
		);

		if ( ! $this->save( $proposal ) ) {
			return new WP_Error(
				'workspace_proposal_not_stored',
				__( 'The proposal could not be stored.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		return $proposal;
	}

	/**
	 * Loads a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @param int    $user_id     The requesting user ID.
	 * @return array<string, mixed>|null The proposal, or null when it cannot be read.
	 */
	public function get( string $proposal_id, int $user_id ): ?array {
		if ( '' === $proposal_id || $user_id <= 0 ) {
			return null;
		}

		$stored = get_transient( $this->transient_name( $proposal_id, $user_id ) );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		/*
		 * The key already scopes the read to one user; the stored owner is
		 * compared again so a hand-written transient cannot hand over a
		 * proposal either.
		 */
		if ( ! isset( $stored['user_id'] ) || (int) $stored['user_id'] !== $user_id ) {
			return null;
		}

		if ( $this->is_expired( $stored ) ) {
			$this->delete( $proposal_id, $user_id );

			return null;
		}

		return $stored;
	}

	/**
	 * Reports whether a stored proposal has passed its expiry.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @return bool True when the proposal may no longer be executed.
	 */
	public function is_expired( array $proposal ): bool {
		$expires = isset( $proposal['expires'] ) ? (int) $proposal['expires'] : 0;

		return $expires <= 0 || $expires <= time();
	}

	/**
	 * Saves a proposal, refreshing its expiry window.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @return bool True when it was written.
	 */
	public function save( array $proposal ): bool {
		$proposal_id = isset( $proposal['id'] ) && is_string( $proposal['id'] ) ? $proposal['id'] : '';
		$user_id     = isset( $proposal['user_id'] ) ? (int) $proposal['user_id'] : 0;

		if ( '' === $proposal_id || $user_id <= 0 ) {
			return false;
		}

		/*
		 * The transient's own lifetime is a floor, not the authority. The
		 * `expires` value stored on the record is what {@see self::get()}
		 * enforces, so re-saving an executed proposal cannot extend the window
		 * in which it could be executed again.
		 */
		return set_transient( $this->transient_name( $proposal_id, $user_id ), $proposal, self::TTL );
	}

	/**
	 * Deletes a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @param int    $user_id     The owning user ID.
	 */
	public function delete( string $proposal_id, int $user_id ): void {
		if ( '' === $proposal_id || $user_id <= 0 ) {
			return;
		}

		delete_transient( $this->transient_name( $proposal_id, $user_id ) );
	}

	/**
	 * Builds the transient name holding a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @param int    $user_id     The owning user ID.
	 * @return string The transient name.
	 */
	private function transient_name( string $proposal_id, int $user_id ): string {
		return self::TRANSIENT_PREFIX . md5( $user_id . '|' . $proposal_id );
	}

	/**
	 * Normalizes and validates the proposed items.
	 *
	 * @since x.x.x
	 *
	 * @param array<int, mixed> $items The raw items.
	 * @return list<array<string, mixed>>|\WP_Error The normalized items, or an error.
	 */
	private function normalize_items( array $items ) {
		$items = array_values( $items );

		if ( array() === $items ) {
			return new WP_Error(
				'workspace_proposal_empty',
				__( 'A proposal must contain at least one item.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $items ) > self::MAX_ITEMS ) {
			return new WP_Error(
				'workspace_proposal_too_large',
				sprintf(
					/* translators: %d: the maximum number of items a proposal may contain. */
					__( 'A proposal may contain at most %d items so a person can review every one of them before approving.', 'ai' ),
					self::MAX_ITEMS
				),
				array( 'status' => 400 )
			);
		}

		$normalized = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error(
					'workspace_proposal_invalid_item',
					__( 'Each proposed item must be an object.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			$prepared = $this->normalize_item( $item );

			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			$normalized[] = $prepared;
		}

		return $normalized;
	}

	/**
	 * Normalizes and validates one proposed item.
	 *
	 * Only the declared fields survive. A model that supplies extra properties —
	 * a prose summary of what it claims it will write, for instance — has them
	 * dropped here, so the confirmation surface can only ever render values that
	 * are going to be written (R16).
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $item The raw item.
	 * @return array<string, mixed>|\WP_Error The normalized item, or an error.
	 */
	private function normalize_item( array $item ) {
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

		return array(
			'key'       => wp_generate_uuid4(),
			'post_type' => $post_type,
			'status'    => $status,
			'title'     => $title,
			'content'   => isset( $item['content'] ) && is_string( $item['content'] ) ? $item['content'] : '',
			'excerpt'   => isset( $item['excerpt'] ) && is_string( $item['excerpt'] ) ? $item['excerpt'] : '',
		);
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

	/**
	 * Returns the item field allowlist.
	 *
	 * @since x.x.x
	 *
	 * @return array<int, string> The field names an item may carry.
	 */
	public static function item_fields(): array {
		return self::ITEM_FIELDS;
	}
}

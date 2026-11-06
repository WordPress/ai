<?php
/**
 * Server-side storage for AI Workspace write proposals.
 *
 * @package WordPress\AI\Experiments\AI_Workspace
 */

declare( strict_types=1 );

namespace WordPress\AI\Experiments\AI_Workspace;

use WP_Error;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Persists a proposal — a set of drafts, or a template — until a person approves or declines it.
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
	 * The kinds of proposal this store accepts, keyed by name.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, \WordPress\AI\Experiments\AI_Workspace\Proposal_Kind>
	 */
	private array $kinds = array();

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AI\Experiments\AI_Workspace\Proposal_Kind>|null $kinds Optional. The accepted kinds. Default drafts and templates.
	 */
	public function __construct( ?array $kinds = null ) {
		foreach ( null === $kinds ? array( new Draft_Writer(), new Template_Writer() ) : $kinds as $kind ) {
			$this->kinds[ $kind->name() ] = $kind;
		}
	}

	/**
	 * Returns the kind a stored proposal belongs to.
	 *
	 * A proposal stored before proposals had kinds carries none, and is a draft.
	 * Drafts are still stored without one, so their records stay exactly as they
	 * were.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The stored proposal.
	 * @return \WordPress\AI\Experiments\AI_Workspace\Proposal_Kind|null The kind, or null when it is not one this store accepts.
	 */
	public function kind_of( array $proposal ): ?Proposal_Kind {
		$name = isset( $proposal['kind'] ) && is_string( $proposal['kind'] ) ? $proposal['kind'] : Draft_Writer::KIND;

		return $this->kinds[ $name ] ?? null;
	}

	/**
	 * Creates and stores a proposal.
	 *
	 * @since x.x.x
	 *
	 * @param int                $user_id         The proposing user.
	 * @param string             $conversation_id The conversation the proposal belongs to.
	 * @param array<int, mixed>  $items           The proposed items.
	 * @param string             $kind            Optional. The kind of proposal. Default a set of drafts.
	 * @return array<string, mixed>|\WP_Error The stored proposal, or an error.
	 */
	public function create( int $user_id, string $conversation_id, array $items, string $kind = Draft_Writer::KIND ) {
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'workspace_proposal_no_owner',
				__( 'A proposal must belong to a signed-in user.', 'ai' ),
				array( 'status' => 403 )
			);
		}

		if ( ! isset( $this->kinds[ $kind ] ) ) {
			return new WP_Error(
				'workspace_proposal_invalid_kind',
				__( 'That kind of proposal is not supported.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$normalized = $this->normalize_items( $this->kinds[ $kind ], $items );

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

		if ( Draft_Writer::KIND !== $kind ) {
			$proposal['kind'] = $kind;
		}

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
	 * @param \WordPress\AI\Experiments\AI_Workspace\Proposal_Kind $kind  The kind of proposal.
	 * @param array<int, mixed>                                    $items The raw items.
	 * @return list<array<string, mixed>>|\WP_Error The normalized items, or an error.
	 */
	private function normalize_items( Proposal_Kind $kind, array $items ) {
		$items = array_values( $items );

		if ( array() === $items ) {
			return new WP_Error(
				'workspace_proposal_empty',
				__( 'A proposal must contain at least one item.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $items ) > $kind->max_items() ) {
			return new WP_Error(
				'workspace_proposal_too_large',
				sprintf(
					/* translators: %d: the maximum number of items a proposal may contain. */
					__( 'A proposal may contain at most %d items so a person can review every one of them before approving.', 'ai' ),
					$kind->max_items()
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

			/*
			 * Only the kind's own fields survive. A model that supplies extra
			 * properties — a prose summary of what it claims it will write, for
			 * instance — has them dropped here, so the confirmation surface can only
			 * ever render values that are going to be written (R16).
			 */
			$prepared = $kind->normalize_item( $item );

			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			$normalized[] = array_merge( array( 'key' => wp_generate_uuid4() ), $prepared );
		}

		return $normalized;
	}
}

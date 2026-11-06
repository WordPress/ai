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
 * **A proposal is approved at most once.** {@see self::claim()} inserts a
 * per-proposal lock row with `INSERT IGNORE`, as core's
 * `WP_Upgrader::create_lock()` does, so of two approvals racing from two tabs
 * only the one whose insert lands writes. Re-reading the transient cannot
 * settle that race on its own: each request reads its own object cache. A proposal left `executing` for longer than
 * {@see self::LOCK_TTL} was interrupted part way, and reads as `failed` from
 * then on, with whatever results it saved.
 *
 * **The kinds are a registry.** The tool list, the abilities that end a turn,
 * the abilities a follow-up withholds and the system instruction are built from
 * {@see self::kinds()}, which is filterable and which the write gate narrows.
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
	 * How long a stored proposal outlives its expiry, in seconds.
	 *
	 * The transient is kept this much longer than {@see self::TTL}, so a read
	 * after `expires` still finds the record, can tell it expired, and deletes it,
	 * rather than finding nothing and calling it not found.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const GRACE = HOUR_IN_SECONDS;

	/**
	 * Option name prefix for a proposal's approval lock.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	public const LOCK_PREFIX = 'wpai_workspace_proposal_lock_';

	/**
	 * How long an approval may hold its lock, and stay `executing`, in seconds.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public const LOCK_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * The kinds this store was built with, before the filter and the write gate.
	 *
	 * @since x.x.x
	 *
	 * @var list<\WordPress\AI\Experiments\AI_Workspace\Proposal_Kind>
	 */
	private array $base_kinds;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param list<\WordPress\AI\Experiments\AI_Workspace\Proposal_Kind>|null $kinds Optional. The accepted kinds. Default drafts, templates, post-field changes and site identity changes.
	 */
	public function __construct( ?array $kinds = null ) {
		$this->base_kinds = null === $kinds ? array( new Draft_Writer(), new Template_Writer(), new Post_Field_Writer(), new Site_Identity_Writer() ) : $kinds;
	}

	/**
	 * Returns the kinds of proposal this store accepts, keyed by name.
	 *
	 * Read on every call rather than once, so a kind registered after the store
	 * was built is accepted, and turning write proposals off takes effect at once,
	 * for proposals already stored as much as for new ones.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, \WordPress\AI\Experiments\AI_Workspace\Proposal_Kind> The kinds, in registration order.
	 */
	public function kinds(): array {
		/**
		 * Filters the kinds of proposal the AI Workspace accepts.
		 *
		 * Each kind brings its own propose ability, capability and instruction
		 * sentence, so adding one here is what offers it to the model.
		 *
		 * @since x.x.x
		 *
		 * @param list<\WordPress\AI\Experiments\AI_Workspace\Proposal_Kind> $kinds The kinds.
		 */
		$filtered = apply_filters( 'wpai_workspace_proposal_kinds', $this->base_kinds );

		if ( ! is_array( $filtered ) ) {
			return array();
		}

		/**
		 * Filters whether the agent may propose changes to existing content and settings.
		 *
		 * Off, the kinds that change something that exists are left out: their
		 * propose abilities are not offered, the instruction does not name them,
		 * and a proposal of one already stored can no longer be approved. Kinds
		 * that only create, such as drafts, are not affected.
		 *
		 * @since x.x.x
		 *
		 * @param bool $enabled Whether write proposals are allowed. Default true.
		 */
		$writes = (bool) apply_filters( 'wpai_workspace_write_proposals_enabled', true );

		$kinds = array();

		foreach ( $filtered as $kind ) {
			if ( ! $kind instanceof Proposal_Kind || ( ! $writes && $kind->is_update() ) ) {
				continue;
			}

			$kinds[ $kind->name() ] = $kind;
		}

		return $kinds;
	}

	/**
	 * Returns the propose abilities of the accepted kinds.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> The ability names, in registration order.
	 */
	public function proposal_abilities(): array {
		$abilities = array();

		foreach ( $this->kinds() as $kind ) {
			$abilities[] = $kind->ability();
		}

		return array_values( array_unique( $abilities ) );
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

		return $this->kinds()[ $name ] ?? null;
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

		$kinds = $this->kinds();

		if ( ! isset( $kinds[ $kind ] ) ) {
			return new WP_Error(
				'workspace_proposal_invalid_kind',
				__( 'That kind of proposal is not supported.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$normalized = $this->normalize_items( $kinds[ $kind ], $items );

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
	 * Loads a proposal that has not expired.
	 *
	 * An expired proposal is deleted here and reported as missing.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @param int    $user_id     The requesting user ID.
	 * @return array<string, mixed>|null The proposal, or null when it cannot be read.
	 */
	public function get( string $proposal_id, int $user_id ): ?array {
		$stored = $this->find( $proposal_id, $user_id );

		if ( null === $stored ) {
			return null;
		}

		if ( $this->is_expired( $stored ) ) {
			$this->delete( $proposal_id, $user_id );

			return null;
		}

		return $stored;
	}

	/**
	 * Loads a proposal, expired or not, for its owner.
	 *
	 * A proposal still `executing` after {@see self::LOCK_TTL} was interrupted
	 * part way, and is returned, and saved, as `failed`, keeping the results it
	 * saved before it stopped.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @param int    $user_id     The requesting user ID.
	 * @param bool   $fresh       Optional. Whether to read past this request's object cache. Default false.
	 * @return array<string, mixed>|null The proposal, or null when there is none for this user.
	 */
	public function find( string $proposal_id, int $user_id, bool $fresh = false ): ?array {
		if ( '' === $proposal_id || $user_id <= 0 ) {
			return null;
		}

		$stored = $this->read( $this->transient_name( $proposal_id, $user_id ), $fresh );

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

		$since = isset( $stored['executing_since'] ) ? (int) $stored['executing_since'] : 0;

		if ( isset( $stored['status'] ) && 'executing' === $stored['status'] && $since + self::LOCK_TTL <= time() ) {
			$stored['status'] = 'failed';
			$this->save( $stored );
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
		return set_transient( $this->transient_name( $proposal_id, $user_id ), $proposal, self::TTL + self::GRACE );
	}

	/**
	 * Claims a proposal for one approval.
	 *
	 * The lock is one `INSERT IGNORE` into the options table, the pattern core's
	 * `WP_Upgrader::create_lock()` uses. The unique `option_name` key lets only
	 * one insert land, so of two requests claiming the same proposal at once
	 * exactly one holds it. `add_option()` is not used because it writes with
	 * `ON DUPLICATE KEY UPDATE`, which lets a second insert of a different value
	 * succeed.
	 *
	 * A lock older than {@see self::LOCK_TTL} belongs to a request that died. It
	 * is read straight from the table, since the insert bypasses the options
	 * cache, then deleted and claimed again.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @param int    $user_id     The owning user ID.
	 * @return bool True when this request holds the claim.
	 */
	public function claim( string $proposal_id, int $user_id ): bool {
		if ( '' === $proposal_id || $user_id <= 0 ) {
			return false;
		}

		$name = $this->lock_name( $proposal_id, $user_id );

		if ( $this->insert_lock( $name ) ) {
			return true;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The lock row is written outside the options cache; read it the same way.
		$held = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		$at   = is_string( $held ) && str_starts_with( $held, 'running:' ) ? (int) substr( $held, strlen( 'running:' ) ) : 0;

		if ( time() - $at <= self::LOCK_TTL ) {
			return false;
		}

		delete_option( $name );

		return $this->insert_lock( $name );
	}

	/**
	 * Inserts a lock row, failing when one already exists.
	 *
	 * @since x.x.x
	 *
	 * @param string $name The lock's option name.
	 * @return bool True when this request's row was inserted.
	 */
	private function insert_lock( string $name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- An atomic insert is the lock; the options API cannot fail on an existing row.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				$name,
				'running:' . time()
			)
		);

		return 1 === $inserted;
	}

	/**
	 * Releases a proposal's approval claim.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @param int    $user_id     The owning user ID.
	 */
	public function release( string $proposal_id, int $user_id ): void {
		if ( '' === $proposal_id || $user_id <= 0 ) {
			return;
		}

		delete_option( $this->lock_name( $proposal_id, $user_id ) );
	}

	/**
	 * Applies the person's edits to a proposal's items.
	 *
	 * An edit may change only an item's `value`: never its key, its snapshot,
	 * or what it targets (a post, a field, an option). Each edited item goes
	 * back through its kind's `normalize_item()`, so an edit meets exactly the
	 * rules the model's values met. The item keeps the snapshot it was proposed with,
	 * so a stale check still compares against the value the person was shown.
	 * Nothing is stored here; the caller saves the result.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $proposal The stored proposal.
	 * @param array<mixed>         $edits    Edited fields keyed by item key.
	 * @return array<string, mixed>|\WP_Error The proposal with its edited items, or an error.
	 */
	public function apply_edits( array $proposal, array $edits ) {
		if ( array() === $edits ) {
			return $proposal;
		}

		$kind = $this->kind_of( $proposal );

		if ( null === $kind ) {
			return new WP_Error(
				'workspace_proposal_invalid_kind',
				__( 'That kind of proposal is not supported.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $kind->accepts_edits() ) {
			return new WP_Error(
				'workspace_proposal_edits_not_accepted',
				__( 'This kind of proposal cannot be edited. Ask the assistant to propose it again instead.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$items = isset( $proposal['items'] ) && is_array( $proposal['items'] ) ? array_values( $proposal['items'] ) : array();
		$index = array();

		foreach ( $items as $position => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['key'] ) || ! is_string( $item['key'] ) ) {
				continue;
			}

			$index[ $item['key'] ] = $position;
		}

		foreach ( $edits as $key => $fields ) {
			$key = (string) $key;

			if ( ! isset( $index[ $key ] ) ) {
				return new WP_Error(
					'workspace_proposal_unknown_item',
					__( 'That proposal does not contain one of the edited items.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			if ( ! is_array( $fields ) ) {
				return new WP_Error(
					'workspace_proposal_invalid_edit',
					__( 'Each edit must be a set of fields.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			// An edit changes what is written, never what it is written to.
			if ( array() !== array_diff( array_keys( $fields ), array( 'value' ) ) ) {
				return new WP_Error(
					'workspace_proposal_invalid_edit',
					__( 'Only the proposed value can be edited.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			$stored = $items[ $index[ $key ] ];

			$input = array_merge( $stored, $fields );
			unset( $input['key'] );

			$normalized = $kind->normalize_item( $input );

			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}

			if ( array_key_exists( Proposal_Kind::SNAPSHOT_FIELD, $stored ) ) {
				$normalized[ Proposal_Kind::SNAPSHOT_FIELD ] = $stored[ Proposal_Kind::SNAPSHOT_FIELD ];
			}

			$items[ $index[ $key ] ] = array_merge( array( 'key' => $key ), $normalized );
		}

		$proposal['items'] = $items;

		return $proposal;
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
	 * Builds the option name of a proposal's approval lock.
	 *
	 * @since x.x.x
	 *
	 * @param string $proposal_id The proposal ID.
	 * @param int    $user_id     The owning user ID.
	 * @return string The option name.
	 */
	private function lock_name( string $proposal_id, int $user_id ): string {
		return self::LOCK_PREFIX . md5( $user_id . '|' . $proposal_id );
	}

	/**
	 * Reads a stored proposal's transient.
	 *
	 * A fresh read goes past this request's own object cache, so a request that
	 * just took the lock sees what the request before it saved rather than the
	 * copy it read itself before taking the lock.
	 *
	 * @since x.x.x
	 *
	 * @param string $name  The transient name.
	 * @param bool   $fresh Whether to read past this request's object cache.
	 * @return mixed The stored value, or false.
	 */
	private function read( string $name, bool $fresh ) {
		if ( $fresh ) {
			if ( wp_using_ext_object_cache() ) {
				return wp_cache_get( $name, 'transient', true );
			}

			wp_cache_delete( '_transient_' . $name, 'options' );
			wp_cache_delete( '_transient_timeout_' . $name, 'options' );
		}

		return get_transient( $name );
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

<?php
/**
 * Per-target backfill state.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Stores each target's backfill cursor and counters in its own option.
 *
 * @since x.x.x
 */
class Backfill_Manager {

	/**
	 * Option name prefix; the target key follows.
	 *
	 * @since x.x.x
	 */
	public const OPTION_PREFIX = 'wpai_embedding_backfill_';

	/**
	 * The worker advances it.
	 *
	 * @since x.x.x
	 */
	public const STATUS_RUNNING = 'running';

	/**
	 * Every covered object has been visited.
	 *
	 * @since x.x.x
	 */
	public const STATUS_COMPLETE = 'complete';

	/**
	 * Stopped on request; `start()` resumes from the cursor.
	 *
	 * @since x.x.x
	 */
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Counter keys accepted by `advance()`.
	 *
	 * @var list<string>
	 */
	private const COUNTERS = array( 'embedded', 'skipped', 'removed', 'failed' ); // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.

	/**
	 * Starts, resumes or no-ops a backfill.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target The target.
	 * @param int|null                                       $now    Optional. Unix time. Default now.
	 * @return array<string, mixed> The state.
	 */
	public function start( Embedding_Target $target, ?int $now = null ): array {
		$key   = $target->get_key();
		$state = $this->get_fresh( $key );

		if ( null !== $state && self::STATUS_RUNNING === $state['status'] ) {
			return $state;
		}

		if ( null !== $state && self::STATUS_CANCELLED === $state['status'] ) {
			$state['status'] = self::STATUS_RUNNING;
		} else {
			$state = array(
				'status'        => self::STATUS_RUNNING,
				'provider'      => $target->get_provider(),
				'model'         => $target->get_model(),
				'cursors'       => array(),
				'done_types'    => array(),
				'sweep_cursors' => array(),
				'swept_types'   => array(),
				'subtypes'      => self::subtypes_of( $target ),
				'processed'     => 0,
				'embedded'      => 0,
				'skipped'       => 0,
				'removed'       => 0,
				'failed'        => 0,
				'started_at'    => $now ?? time(),
				'completed_at'  => null,
			);
		}

		$this->save( $key, $state );

		return $state;
	}

	/**
	 * Cancels a running backfill.
	 *
	 * @since x.x.x
	 *
	 * @param string $key Target key.
	 * @return bool True when a running backfill was cancelled.
	 */
	public function cancel( string $key ): bool {
		$state = $this->get_fresh( $key );

		if ( null === $state || self::STATUS_RUNNING !== $state['status'] ) {
			return false;
		}

		$state['status'] = self::STATUS_CANCELLED;
		$this->save( $key, $state );

		return true;
	}

	/**
	 * Deletes a backfill's state so the next start begins at ID 0.
	 *
	 * @since x.x.x
	 *
	 * @param string $key Target key.
	 */
	public function reset( string $key ): void {
		delete_option( self::OPTION_PREFIX . $key );
	}

	/**
	 * Returns a backfill's state.
	 *
	 * @since x.x.x
	 *
	 * @param string $key Target key.
	 * @return array<string, mixed>|null The state, or null when none exists.
	 */
	public function get( string $key ): ?array {
		$state = get_option( self::OPTION_PREFIX . $key, null );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Checks whether a backfill is running.
	 *
	 * @since x.x.x
	 *
	 * @param string $key Target key.
	 * @return bool True when running.
	 */
	public function is_running( string $key ): bool {
		$state = $this->get_fresh( $key );

		return null !== $state && self::STATUS_RUNNING === $state['status'];
	}

	/**
	 * Returns the next object type and cursor to process.
	 *
	 * @since x.x.x
	 *
	 * @param string                                         $key    Target key.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target The target.
	 * @return array{object_type: string, cursor: int}|null The position, or null when every type is done.
	 */
	public function get_position( string $key, Embedding_Target $target ): ?array {
		$state = $this->get_fresh( $key );

		if ( null === $state ) {
			return null;
		}

		if ( self::STATUS_COMPLETE !== $state['status'] ) {
			$state = $this->reconcile_subtypes( $key, $state, $target );
		}

		return self::next_position( $state, $target );
	}

	/**
	 * Checks whether a backfill's embedding pass has visited every covered object type.
	 *
	 * @since x.x.x
	 *
	 * @param string                                         $key    Target key.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target The target.
	 * @return bool True when no embedding position remains; false too when there is no backfill.
	 */
	public function is_embedding_pass_done( string $key, Embedding_Target $target ): bool {
		$state = $this->get_fresh( $key );

		if ( null === $state ) {
			return false;
		}

		if ( self::STATUS_COMPLETE !== $state['status'] ) {
			$state = self::apply_subtypes( $state, $target ) ?? $state;
		}

		return null === self::next_position( $state, $target );
	}

	/**
	 * Records a processed batch.
	 *
	 * @since x.x.x
	 *
	 * @param string             $key         Target key.
	 * @param string             $object_type Object type of the batch.
	 * @param int                $cursor      Highest object ID processed.
	 * @param array<string, int> $counts      Counts keyed by embedded, skipped, removed, failed.
	 * @return bool True when recorded.
	 */
	public function advance( string $key, string $object_type, int $cursor, array $counts ): bool {
		$state = $this->get_fresh( $key );

		if ( null === $state || self::STATUS_RUNNING !== $state['status'] ) {
			return false;
		}

		$state['cursors'][ $object_type ] = $cursor;

		foreach ( self::COUNTERS as $counter ) {
			$value              = (int) ( $counts[ $counter ] ?? 0 );
			$state[ $counter ]  = (int) $state[ $counter ] + $value;
			$state['processed'] = (int) $state['processed'] + $value;
		}

		$this->save( $key, $state );

		return true;
	}

	/**
	 * Marks an object type as fully visited.
	 *
	 * @since x.x.x
	 *
	 * @param string $key         Target key.
	 * @param string $object_type Object type.
	 */
	public function finish_type( string $key, string $object_type ): void {
		$state = $this->get_fresh( $key );

		if ( null === $state || self::STATUS_RUNNING !== $state['status'] ) {
			return;
		}

		$state['done_types'] = (array) $state['done_types'];

		if ( in_array( $object_type, $state['done_types'], true ) ) {
			return;
		}

		$state['done_types'][] = $object_type;
		$this->save( $key, $state );
	}

	/**
	 * Returns the next object type and cursor for the end-of-backfill orphan sweep.
	 *
	 * @since x.x.x
	 *
	 * @param string       $key          Target key.
	 * @param list<string> $object_types Object types to sweep, in order.
	 * @return array{object_type: string, cursor: int}|null The position, or null when every type is swept.
	 */
	public function get_sweep_position( string $key, array $object_types ): ?array {
		$state = $this->get_fresh( $key );

		if ( null === $state ) {
			return null;
		}

		foreach ( $object_types as $object_type ) {
			if ( in_array( $object_type, (array) ( $state['swept_types'] ?? array() ), true ) ) {
				continue;
			}

			return array(
				'object_type' => $object_type,
				'cursor'      => (int) ( $state['sweep_cursors'][ $object_type ] ?? 0 ),
			);
		}

		return null;
	}

	/**
	 * Records a swept page.
	 *
	 * @since x.x.x
	 *
	 * @param string $key         Target key.
	 * @param string $object_type Object type of the page.
	 * @param int    $cursor      Highest object ID swept.
	 * @param int    $removed     Objects whose vectors were deleted.
	 * @return bool True when recorded.
	 */
	public function advance_sweep( string $key, string $object_type, int $cursor, int $removed ): bool {
		$state = $this->get_fresh( $key );

		if ( null === $state || self::STATUS_RUNNING !== $state['status'] ) {
			return false;
		}

		$state['sweep_cursors']                 = (array) ( $state['sweep_cursors'] ?? array() );
		$state['sweep_cursors'][ $object_type ] = $cursor;
		$state['removed']                       = (int) $state['removed'] + max( 0, $removed );

		$this->save( $key, $state );

		return true;
	}

	/**
	 * Marks an object type as fully swept.
	 *
	 * @since x.x.x
	 *
	 * @param string $key         Target key.
	 * @param string $object_type Object type.
	 */
	public function finish_sweep_type( string $key, string $object_type ): void {
		$state = $this->get_fresh( $key );

		if ( null === $state || self::STATUS_RUNNING !== $state['status'] ) {
			return;
		}

		$state['swept_types'] = (array) ( $state['swept_types'] ?? array() );

		if ( in_array( $object_type, $state['swept_types'], true ) ) {
			return;
		}

		$state['swept_types'][] = $object_type;
		$this->save( $key, $state );
	}

	/**
	 * Marks a backfill complete and announces it.
	 *
	 * @since x.x.x
	 *
	 * @param string                                         $key    Target key.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target The target.
	 * @param int|null                                       $now    Optional. Unix time. Default now.
	 */
	public function complete( string $key, Embedding_Target $target, ?int $now = null ): void {
		$state = $this->get_fresh( $key );

		if ( null === $state || self::STATUS_RUNNING !== $state['status'] ) {
			return;
		}

		$state['status']       = self::STATUS_COMPLETE;
		$state['completed_at'] = $now ?? time();
		$this->save( $key, $state );

		/**
		 * Fires when a backfill has visited every covered object.
		 *
		 * @since x.x.x
		 *
		 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target The target.
		 */
		do_action( 'wpai_embedding_sync_backfill_completed', $target );
	}

	/**
	 * Restarts the scan of every object type whose covered subtypes changed, and saves it.
	 *
	 * @since x.x.x
	 *
	 * @param string                                         $key    Target key.
	 * @param array<string, mixed>                           $state  The state.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target The target.
	 * @return array<string, mixed> The state, saved when it changed.
	 */
	private function reconcile_subtypes( string $key, array $state, Embedding_Target $target ): array {
		$reconciled = self::apply_subtypes( $state, $target );

		if ( null === $reconciled ) {
			return $state;
		}

		$this->save( $key, $reconciled );

		return $reconciled;
	}

	/**
	 * Applies a change of covered subtypes to a state, without saving it.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed>                           $state  The state.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target The target.
	 * @return array<string, mixed>|null The changed state, or null when the subtypes are unchanged.
	 */
	private static function apply_subtypes( array $state, Embedding_Target $target ): ?array {
		$stored  = (array) ( $state['subtypes'] ?? array() );
		$current = self::subtypes_of( $target );
		$changed = false;

		foreach ( $current as $object_type => $subtypes ) {
			if ( isset( $stored[ $object_type ] ) && $stored[ $object_type ] === $subtypes ) {
				continue;
			}

			unset( $state['cursors'][ $object_type ] );
			$state['done_types']    = array_values( array_diff( (array) $state['done_types'], array( $object_type ) ) );
			$stored[ $object_type ] = $subtypes;
			$changed                = true;
		}

		foreach ( array_keys( $stored ) as $object_type ) {
			if ( isset( $current[ $object_type ] ) ) {
				continue;
			}

			unset( $stored[ $object_type ], $state['cursors'][ $object_type ] );
			$state['done_types'] = array_values( array_diff( (array) $state['done_types'], array( $object_type ) ) );
			$changed             = true;
		}

		if ( ! $changed ) {
			return null;
		}

		$state['subtypes']      = $stored;
		$state['sweep_cursors'] = array();
		$state['swept_types']   = array();

		return $state;
	}

	/**
	 * Returns the next object type and cursor of the embedding pass.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed>                           $state  The state.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target The target.
	 * @return array{object_type: string, cursor: int}|null The position, or null when every type is done.
	 */
	private static function next_position( array $state, Embedding_Target $target ): ?array {
		foreach ( $target->get_object_types() as $object_type ) {
			if ( in_array( $object_type, (array) $state['done_types'], true ) ) {
				continue;
			}

			return array(
				'object_type' => $object_type,
				'cursor'      => (int) ( $state['cursors'][ $object_type ] ?? 0 ),
			);
		}

		return null;
	}

	/**
	 * Returns a target's covered subtypes, sorted, keyed by object type.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target The target.
	 * @return array<string, list<string>> The subtypes.
	 */
	private static function subtypes_of( Embedding_Target $target ): array {
		$subtypes = array();

		foreach ( $target->get_object_types() as $object_type ) {
			$list = $target->get_subtypes_for( $object_type );
			sort( $list );
			$subtypes[ $object_type ] = $list;
		}

		return $subtypes;
	}

	/**
	 * Reads the state bypassing this request's cached copy.
	 *
	 * @since x.x.x
	 *
	 * @param string $key Target key.
	 * @return array<string, mixed>|null The state, or null.
	 */
	private function get_fresh( string $key ): ?array {
		wp_cache_delete( self::OPTION_PREFIX . $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return $this->get( $key );
	}

	/**
	 * Writes the state.
	 *
	 * @since x.x.x
	 *
	 * @param string               $key   Target key.
	 * @param array<string, mixed> $state The state.
	 */
	private function save( string $key, array $state ): void {
		update_option( self::OPTION_PREFIX . $key, $state, false );
	}
}

<?php
/**
 * Background worker for embedding sync.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * One run: take the lock, drain the live queue, advance running backfills, reschedule.
 *
 * @since x.x.x
 */
class Sync_Worker {

	/**
	 * WP-Cron hook that runs the worker.
	 *
	 * @since x.x.x
	 */
	public const CRON_HOOK = 'wpai_embedding_sync_run';

	/**
	 * Option recording the last run's Unix time.
	 *
	 * @since x.x.x
	 */
	public const LAST_RUN_OPTION = 'wpai_embedding_sync_last_run';

	/**
	 * Default seconds per cron run.
	 *
	 * @since x.x.x
	 */
	public const DEFAULT_TIME_BUDGET = 20;

	/**
	 * Default objects per batch.
	 *
	 * @since x.x.x
	 */
	public const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Default attempts before a queue row is marked failed.
	 *
	 * @since x.x.x
	 */
	public const DEFAULT_MAX_ATTEMPTS = 5;

	/**
	 * Longest retry delay, in seconds (6 hours).
	 */
	private const MAX_RETRY_DELAY = 21600;

	/**
	 * Consumer registry.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Consumer_Registry
	 */
	private Consumer_Registry $registry;

	/**
	 * Sources keyed by object type.
	 *
	 * @var array<string, \WordPress\AI\Embeddings\Sync\Embedding_Source_Interface>
	 */
	private array $sources;

	/**
	 * Queue.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Sync_Queue
	 */
	private Sync_Queue $queue;

	/**
	 * Object processor.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Object_Processor
	 */
	private Object_Processor $processor;

	/**
	 * Backfill state.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Backfill_Manager
	 */
	private Backfill_Manager $backfills;

	/**
	 * Provider backoff.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Provider_Backoff
	 */
	private Provider_Backoff $backoff;

	/**
	 * Lock.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Sync_Lock
	 */
	private Sync_Lock $lock;

	/**
	 * Whether this run lost the lock to another runner.
	 *
	 * @var bool
	 */
	private bool $lock_lost = false;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Consumer_Registry                         $registry  Consumer registry.
	 * @param array<string, \WordPress\AI\Embeddings\Sync\Embedding_Source_Interface> $sources   Sources keyed by object type.
	 * @param \WordPress\AI\Embeddings\Sync\Sync_Queue                                $queue     Queue.
	 * @param \WordPress\AI\Embeddings\Sync\Object_Processor                          $processor Object processor.
	 * @param \WordPress\AI\Embeddings\Sync\Backfill_Manager                          $backfills Backfill state.
	 * @param \WordPress\AI\Embeddings\Sync\Provider_Backoff                          $backoff   Provider backoff.
	 * @param \WordPress\AI\Embeddings\Sync\Sync_Lock                                 $lock      Lock.
	 */
	public function __construct(
		Consumer_Registry $registry,
		array $sources,
		Sync_Queue $queue,
		Object_Processor $processor,
		Backfill_Manager $backfills,
		Provider_Backoff $backoff,
		Sync_Lock $lock
	) {
		$this->registry  = $registry;
		$this->sources   = $sources;
		$this->queue     = $queue;
		$this->processor = $processor;
		$this->backfills = $backfills;
		$this->backoff   = $backoff;
		$this->lock      = $lock;
	}

	/**
	 * Schedules a worker run now unless one is already due.
	 *
	 * @since x.x.x
	 */
	public static function wake(): void {
		self::schedule_at( time() );
	}

	/**
	 * Returns the delay before retrying after a transient failure.
	 *
	 * @since x.x.x
	 *
	 * @param int $attempts Attempts made, including the one that just failed.
	 * @return int Seconds: 2^attempts minutes, capped at 6 hours.
	 */
	public static function retry_delay( int $attempts ): int {
		return (int) min( self::MAX_RETRY_DELAY, ( 2 ** min( $attempts, 20 ) ) * MINUTE_IN_SECONDS );
	}

	/**
	 * Runs the worker once.
	 *
	 * @since x.x.x
	 *
	 * @param int|null $time_budget Optional. Seconds to work for; 0 means no limit. Default the filtered cron budget.
	 * @return array{ran: bool, queue: int, backfill: int} Whether it ran, and objects processed from each source of work.
	 */
	public function run( ?int $time_budget = null ): array {
		$stats = array(
			'ran'      => false,
			'queue'    => 0,
			'backfill' => 0,
		);

		if ( ! $this->registry->has_consumers() ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );

			return $stats;
		}

		if ( ! $this->lock->acquire() ) {
			if ( null !== $this->get_next_run_at() ) {
				self::schedule_at( time() + Sync_Lock::TTL );
			}

			return $stats;
		}

		$stats['ran']    = true;
		$this->lock_lost = false;

		self::schedule_at( time() + Sync_Lock::TTL + MINUTE_IN_SECONDS );

		try {
			update_option( self::LAST_RUN_OPTION, time(), false );

			if ( null === $time_budget ) {
				/**
				 * Filters how long one WP-Cron run of the embedding sync worker may work.
				 *
				 * @since x.x.x
				 *
				 * @param int $seconds Seconds. Default 20.
				 */
				$time_budget = (int) apply_filters( 'wpai_embedding_sync_time_budget', self::DEFAULT_TIME_BUDGET );
			}

			$deadline = $time_budget > 0 ? microtime( true ) + $time_budget : null;

			$stats['queue']    = $this->drain_queue( $deadline );
			$stats['backfill'] = $this->run_backfills( $deadline );
		} catch ( Throwable $e ) {
			wp_trigger_error(
				__METHOD__,
				esc_html(
					sprintf(
						/* translators: %s: Error message. */
						__( 'The embedding sync run stopped early: %s', 'ai' ),
						$e->getMessage()
					)
				),
				E_USER_WARNING
			);
		} finally {
			$this->lock->release();

			wp_clear_scheduled_hook( self::CRON_HOOK );
			$this->schedule_next();
		}

		return $stats;
	}

	/**
	 * Returns when there is next work to do.
	 *
	 * @since x.x.x
	 *
	 * @param int|null $now Optional. Unix time. Default now.
	 * @return int|null Unix time of the next due work, or null when there is none.
	 */
	public function get_next_run_at( ?int $now = null ): ?int {
		$now  = $now ?? time();
		$next = null;

		$queued = $this->queue->get_next_available_at();

		if ( null !== $queued ) {
			$next = max( $now, $queued );
		}

		foreach ( $this->registry->get_targets() as $key => $target ) {
			if ( ! $this->backfills->is_running( $key ) ) {
				continue;
			}

			$at   = $this->backoff->get_until( $target->get_provider(), $now ) ?? $now;
			$next = null === $next ? $at : min( $next, $at );
		}

		return $next;
	}

	/**
	 * Schedules the next run if there is work and no run is scheduled at or before it.
	 *
	 * @since x.x.x
	 */
	public function schedule_next(): void {
		if ( ! $this->registry->has_consumers() ) {
			return;
		}

		$next = $this->get_next_run_at();

		if ( null === $next ) {
			return;
		}

		self::schedule_at( $next );
	}

	/**
	 * Schedules a run at a time unless one is already scheduled at or before it.
	 *
	 * @since x.x.x
	 *
	 * @param int $timestamp Unix time the run should happen.
	 */
	private static function schedule_at( int $timestamp ): void {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $scheduled ) {
			if ( $scheduled <= $timestamp ) {
				return;
			}

			wp_unschedule_event( $scheduled, self::CRON_HOOK );
		}

		wp_schedule_single_event( $timestamp, self::CRON_HOOK );
	}

	/**
	 * Processes due queue rows until none are left or time runs out.
	 *
	 * @since x.x.x
	 *
	 * @param float|null $deadline Microtime deadline, or null for none.
	 * @return int Rows processed.
	 */
	private function drain_queue( ?float $deadline ): int {
		$processed  = 0;
		$batch_size = $this->get_batch_size();
		$seen       = array();

		while ( ! $this->should_stop( $deadline ) ) {
			$items = $this->queue->claim_due( $batch_size );
			$fresh = array_filter(
				$items,
				static fn( Sync_Queue_Item $item ): bool => ! isset( $seen[ $item->get_id() . ':' . $item->get_version() ] )
			);

			// Only rows this run already handled came back: stop rather than spin.
			if ( array() === $fresh ) {
				break;
			}

			$by_type = array();

			foreach ( $fresh as $item ) {
				$seen[ $item->get_id() . ':' . $item->get_version() ] = true;
				$by_type[ $item->get_object_type() ][]                = $item;
			}

			foreach ( $by_type as $object_type => $type_items ) {
				$results = $this->processor->process(
					$object_type,
					array_map( static fn( Sync_Queue_Item $item ): int => $item->get_object_id(), $type_items )
				);

				foreach ( $type_items as $item ) {
					$this->apply_result( $item, $results[ $item->get_object_id() ] ?? Object_Result::skipped() );
				}
			}

			$processed += count( $fresh );
			$this->between_batches();
		}

		return $processed;
	}

	/**
	 * Writes a processing result back to the queue.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Sync_Queue_Item $item   The claimed row.
	 * @param \WordPress\AI\Embeddings\Sync\Object_Result   $result The result.
	 */
	private function apply_result( Sync_Queue_Item $item, Object_Result $result ): void {
		if ( Object_Result::DEFERRED === $result->get_status() ) {
			$this->queue->postpone( $item, (int) $result->get_retry_at() );
			return;
		}

		if ( Object_Result::FAILED !== $result->get_status() ) {
			$this->queue->complete( $item );
			return;
		}

		$attempts = $item->get_attempts() + 1;

		if ( Embedding_Client_Exception::ITEM !== $result->get_error_class() && $attempts < $this->get_max_attempts() ) {
			$this->queue->defer( $item, time() + self::retry_delay( $attempts ), $result->get_message() );
			return;
		}

		if ( ! $this->queue->fail( $item, $result->get_message() ) ) {
			return;
		}

		/**
		 * Fires when a queued object is marked permanently failed.
		 *
		 * @since x.x.x
		 *
		 * @param string $object_type Object type.
		 * @param int    $object_id   Object ID.
		 * @param string $error       The last error message.
		 */
		do_action( 'wpai_embedding_sync_object_failed', $item->get_object_type(), $item->get_object_id(), $result->get_message() );
	}

	/**
	 * Advances every running backfill while time remains.
	 *
	 * @since x.x.x
	 *
	 * @param float|null $deadline Microtime deadline, or null for none.
	 * @return int Objects processed.
	 */
	private function run_backfills( ?float $deadline ): int {
		$processed  = 0;
		$batch_size = $this->get_batch_size();

		foreach ( $this->registry->get_targets() as $key => $target ) {
			while ( ! $this->should_stop( $deadline ) && $this->backfills->is_running( $key ) ) {
				$step = $this->backfill_step( $key, $target, $batch_size );

				if ( null === $step ) {
					break;
				}

				$processed += $step;
				$this->between_batches();
			}
		}

		return $processed;
	}

	/**
	 * Processes one backfill batch for a target.
	 *
	 * @since x.x.x
	 *
	 * @param string                                         $key        Target key.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target     The target.
	 * @param int                                            $batch_size Objects per batch.
	 * @return int|null Objects processed, or null when the target's provider is paused.
	 */
	private function backfill_step( string $key, Embedding_Target $target, int $batch_size ): ?int {
		if ( $this->backoff->is_paused( $target->get_provider() ) ) {
			return null;
		}

		$position = $this->backfills->get_position( $key, $target );

		if ( null === $position ) {
			$this->backfills->complete( $key, $target );

			return 0;
		}

		$object_type = $position['object_type'];
		$source      = $this->sources[ $object_type ] ?? null;
		$ids         = null === $source ? array() : $source->get_ids_after( $position['cursor'], $target->get_subtypes_for( $object_type ), $batch_size );

		if ( array() === $ids ) {
			$this->backfills->finish_type( $key, $object_type );

			return 0;
		}

		$counts = array(
			'embedded' => 0,
			'skipped'  => 0,
			'removed'  => 0,
			'failed'   => 0,
		);
		$failed = array();

		foreach ( $this->processor->process( $object_type, $ids, $target ) as $object_id => $result ) {
			switch ( $result->get_status() ) {
				case Object_Result::DEFERRED:
					// The whole batch is retried after the pause.
					return null;
				case Object_Result::FAILED:
					$failed[] = $object_id;
					++$counts['failed'];
					break;
				case Object_Result::DONE:
					++$counts['embedded'];
					break;
				case Object_Result::REMOVED:
					++$counts['removed'];
					break;
				default:
					++$counts['skipped'];
			}
		}

		// One retry mechanism for both paths: the live queue owns retries.
		foreach ( $failed as $object_id ) {
			try {
				$this->queue->enqueue( $object_type, $object_id );
			} catch ( RuntimeException $e ) {
				wp_trigger_error( __METHOD__, esc_html( $e->getMessage() ), E_USER_WARNING );
			}
		}

		$this->backfills->advance( $key, $object_type, (int) max( $ids ), $counts );

		return count( $ids );
	}

	/**
	 * Keeps the lock fresh and memory flat between batches.
	 *
	 * @since x.x.x
	 */
	private function between_batches(): void {
		if ( ! $this->lock->refresh() ) {
			$this->lock_lost = true;
		}

		wp_cache_flush_runtime();
	}

	/**
	 * Checks whether the run should stop: the lock was lost or the deadline has passed.
	 *
	 * @since x.x.x
	 *
	 * @param float|null $deadline Microtime deadline, or null for none.
	 * @return bool True when the run should stop.
	 */
	private function should_stop( ?float $deadline ): bool {
		return $this->lock_lost || ( null !== $deadline && microtime( true ) >= $deadline );
	}

	/**
	 * Returns the batch size.
	 *
	 * @since x.x.x
	 *
	 * @return int Objects per batch.
	 */
	private function get_batch_size(): int {
		/**
		 * Filters how many objects the embedding sync worker handles per batch.
		 *
		 * @since x.x.x
		 *
		 * @param int $batch_size Objects per batch. Default 50.
		 */
		return max( 1, (int) apply_filters( 'wpai_embedding_sync_batch_size', self::DEFAULT_BATCH_SIZE ) );
	}

	/**
	 * Returns the attempt cap.
	 *
	 * @since x.x.x
	 *
	 * @return int Attempts before a row is marked failed.
	 */
	private function get_max_attempts(): int {
		/**
		 * Filters how many attempts a queued object gets before it is marked failed.
		 *
		 * @since x.x.x
		 *
		 * @param int $max_attempts Attempts. Default 5.
		 */
		return max( 1, (int) apply_filters( 'wpai_embedding_sync_max_attempts', self::DEFAULT_MAX_ATTEMPTS ) );
	}
}

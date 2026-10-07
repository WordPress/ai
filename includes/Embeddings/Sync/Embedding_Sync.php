<?php
/**
 * Bootstrap and public API for embedding sync.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

use WordPress\AI\Embeddings\Embedding_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the sync layer together and exposes the API features use.
 *
 * @since x.x.x
 */
final class Embedding_Sync {

	/**
	 * Shared instance.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Embedding_Sync|null
	 */
	private static ?self $instance = null;

	/**
	 * Consumer registry.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Consumer_Registry
	 */
	private Consumer_Registry $registry;

	/**
	 * Embedding client.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Embedding_Client_Interface
	 */
	private Embedding_Client_Interface $client;

	/**
	 * Embeddings repository.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Repository
	 */
	private Embedding_Repository $repository;

	/**
	 * Queue.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Sync_Queue
	 */
	private Sync_Queue $queue;

	/**
	 * Provider backoff.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Provider_Backoff
	 */
	private Provider_Backoff $backoff;

	/**
	 * Backfill state.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Backfill_Manager
	 */
	private Backfill_Manager $backfills;

	/**
	 * Sources keyed by object type.
	 *
	 * @var array<string, \WordPress\AI\Embeddings\Sync\Embedding_Source_Interface>
	 */
	private array $sources;

	/**
	 * Worker, built on first use.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Sync_Worker|null
	 */
	private ?Sync_Worker $worker = null;

	/**
	 * Whether init() has run.
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Whether hooks and tables are set up.
	 *
	 * @var bool
	 */
	private bool $active = false;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Consumer_Registry|null          $registry   Optional. Registry. Default a new one.
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Client_Interface|null $client     Optional. Client. Default the SDK client.
	 * @param \WordPress\AI\Embeddings\Embedding_Repository|null            $repository Optional. Repository. Default a new one.
	 * @param \WordPress\AI\Embeddings\Sync\Sync_Queue|null                 $queue      Optional. Queue. Default a new one.
	 */
	public function __construct(
		?Consumer_Registry $registry = null,
		?Embedding_Client_Interface $client = null,
		?Embedding_Repository $repository = null,
		?Sync_Queue $queue = null
	) {
		$this->registry   = $registry ?? new Consumer_Registry();
		$this->client     = $client ?? new Embedding_Client();
		$this->repository = $repository ?? new Embedding_Repository();
		$this->queue      = $queue ?? new Sync_Queue();
		$this->backoff    = new Provider_Backoff();
		$this->backfills  = new Backfill_Manager();
		$this->sources    = array(
			Post_Source::OBJECT_TYPE => new Post_Source(),
			Term_Source::OBJECT_TYPE => new Term_Source(),
		);
	}

	/**
	 * Returns the shared instance.
	 *
	 * @since x.x.x
	 *
	 * @return self The instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Replaces the shared instance; null resets it.
	 *
	 * @since x.x.x
	 *
	 * @param self|null $instance The instance.
	 */
	public static function set_instance( ?self $instance ): void {
		self::$instance = $instance;
	}

	/**
	 * Initializes sync on `init` priority 20, after features have registered at 15.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		$this->registry->remove_unknown_subtypes(
			function ( string $object_type, string $subtype ): bool {
				return isset( $this->sources[ $object_type ] ) && $this->sources[ $object_type ]->subtype_exists( $subtype );
			}
		);

		$this->maybe_activate();
	}

	/**
	 * Runs the worker; the WP-Cron callback.
	 *
	 * @since x.x.x
	 */
	public function run_worker(): void {
		$this->get_worker()->run();
	}

	/**
	 * Checks whether sync is active.
	 *
	 * @since x.x.x
	 *
	 * @return bool True once a consumer is registered and init has run.
	 */
	public function is_active(): bool {
		return $this->active;
	}

	/**
	 * Returns the worker.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Embeddings\Sync\Sync_Worker The worker.
	 */
	public function get_worker(): Sync_Worker {
		if ( null === $this->worker ) {
			$this->worker = new Sync_Worker(
				$this->registry,
				$this->sources,
				$this->queue,
				new Object_Processor( $this->registry, $this->sources, $this->repository, $this->client, $this->backoff ),
				$this->backfills,
				$this->backoff,
				new Sync_Lock(),
				new Orphan_Sweeper( $this->sources, $this->repository, $this->registry )
			);
		}

		return $this->worker;
	}

	/**
	 * Returns the registry.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Embeddings\Sync\Consumer_Registry The registry.
	 */
	public function get_registry(): Consumer_Registry {
		return $this->registry;
	}

	/**
	 * Returns the provider backoff.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Embeddings\Sync\Provider_Backoff The backoff.
	 */
	public function get_backoff(): Provider_Backoff {
		return $this->backoff;
	}

	/**
	 * Returns the embeddings repository.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Embeddings\Embedding_Repository The repository.
	 */
	public function get_repository(): Embedding_Repository {
		return $this->repository;
	}

	/**
	 * Registers a consumer of synchronized embeddings.
	 *
	 * Call from a feature's `register()` (which runs on `init` priority 15). Live sync starts
	 * immediately; existing content is only embedded once `start_backfill()` is called.
	 *
	 * @since x.x.x
	 *
	 * @param string               $id   Unique consumer ID.
	 * @param array<string, mixed> $args See {@see Consumer_Registry::register()}.
	 * @return bool True when registered.
	 */
	public static function register_consumer( string $id, array $args ): bool {
		$sync = self::instance();

		if ( ! $sync->registry->register( $id, $args ) ) {
			return false;
		}

		$sync->maybe_activate();

		return true;
	}

	/**
	 * Starts (or resumes) the backfill for a consumer's target.
	 *
	 * @since x.x.x
	 *
	 * @param string $consumer_id Consumer ID.
	 * @return bool False for an unknown consumer.
	 */
	public static function start_backfill( string $consumer_id ): bool {
		$sync   = self::instance();
		$target = $sync->registry->get_target_for_consumer( $consumer_id );

		if ( null === $target ) {
			return false;
		}

		$sync->backfills->start( $target );
		Sync_Worker::wake();

		return true;
	}

	/**
	 * Cancels the running backfill for a consumer's target.
	 *
	 * @since x.x.x
	 *
	 * @param string $consumer_id Consumer ID.
	 * @return bool True when a running backfill was cancelled.
	 */
	public static function cancel_backfill( string $consumer_id ): bool {
		$sync   = self::instance();
		$target = $sync->registry->get_target_for_consumer( $consumer_id );

		return null !== $target && $sync->backfills->cancel( $target->get_key() );
	}

	/**
	 * Forgets the backfill state for a consumer's target; vectors are kept.
	 *
	 * @since x.x.x
	 *
	 * @param string $consumer_id Consumer ID.
	 * @return bool False for an unknown consumer.
	 */
	public static function reset_backfill( string $consumer_id ): bool {
		$sync   = self::instance();
		$target = $sync->registry->get_target_for_consumer( $consumer_id );

		if ( null === $target ) {
			return false;
		}

		$sync->backfills->reset( $target->get_key() );

		return true;
	}

	/**
	 * Returns sync status for a consumer.
	 *
	 * @since x.x.x
	 *
	 * @param string $consumer_id Consumer ID.
	 * @return array{target: array{provider: string, model: string, dimensions: int|null}, coverage: array<string, array<string, array{indexed: int, indexable: int}>>, backfill: array<string, mixed>|null, queue: array{pending: int, failed: int}, backoff: int|null, provider_error: string|null, last_run: int|null}|null The status, or null for an unknown consumer.
	 */
	public static function get_status( string $consumer_id ): ?array {
		$sync     = self::instance();
		$consumer = $sync->registry->get_consumer( $consumer_id );
		$target   = $sync->registry->get_target_for_consumer( $consumer_id );

		if ( null === $consumer || null === $target ) {
			return null;
		}

		$coverage = array();

		foreach ( $consumer['objects'] as $object_type => $subtypes ) {
			$source = $sync->sources[ $object_type ] ?? null;

			if ( null === $source ) {
				continue;
			}

			$indexed   = $sync->repository->count_objects_by_subtype( $object_type, $target->get_provider(), $target->get_model() );
			$indexable = $source->count_indexable( $subtypes );

			foreach ( $subtypes as $subtype ) {
				$coverage[ $object_type ][ $subtype ] = array(
					'indexed'   => $indexed[ $subtype ] ?? 0,
					'indexable' => $indexable[ $subtype ] ?? 0,
				);
			}
		}

		$last_run = (int) get_option( Sync_Worker::LAST_RUN_OPTION, 0 );
		$error    = null;
		$now      = time();

		foreach ( array( $sync->backoff->get( $target->get_provider(), $target->get_model() ), $sync->backoff->get( $target->get_provider() ) ) as $state ) {
			// The stored state outlives its pause, so an error is only reported while it still holds.
			if ( null !== $state && Embedding_Client_Exception::PROVIDER === $state['error_class'] && $state['until'] > $now ) {
				$error = $state['error'];
				break;
			}
		}

		return array(
			'target'         => array(
				'provider'   => $target->get_provider(),
				'model'      => $target->get_model(),
				'dimensions' => $target->get_dimensions(),
			),
			'coverage'       => $coverage,
			'backfill'       => $sync->backfills->get( $target->get_key() ),
			'queue'          => $sync->queue->count_by_status(),
			'backoff'        => $sync->backoff->get_until_for( $target, $now ),
			'provider_error' => $error,
			'last_run'       => $last_run > 0 ? $last_run : null,
		);
	}

	/**
	 * Requeues every failed object.
	 *
	 * @since x.x.x
	 *
	 * @return int Objects requeued.
	 */
	public static function retry_failed(): int {
		$count = self::instance()->queue->retry_failed();

		if ( $count > 0 ) {
			Sync_Worker::wake();
		}

		return $count;
	}

	/**
	 * Deletes every vector of a model and its backfill state.
	 *
	 * @since x.x.x
	 *
	 * @param string $provider Provider ID.
	 * @param string $model    Model ID.
	 * @return int Rows deleted.
	 */
	public static function prune_target( string $provider, string $model ): int {
		$sync    = self::instance();
		$deleted = $sync->repository->delete_for_model( $provider, $model );

		$sync->backfills->reset( Embedding_Target::key_for( $provider, $model ) );

		return $deleted;
	}

	/**
	 * Sets up tables and hooks once init has run and a consumer exists.
	 *
	 * @since x.x.x
	 */
	private function maybe_activate(): void {
		if ( $this->active || ! $this->initialized || ! $this->registry->has_consumers() ) {
			return;
		}

		$this->active = true;

		// Option-only checks: an active site pays no SHOW TABLES per request.
		$schema = $this->repository->get_schema();

		if ( ! $schema->is_version_current() ) {
			$schema->maybe_upgrade_table();
		}

		$queue_schema = $this->queue->get_schema();

		if ( ! $queue_schema->is_version_current() ) {
			$queue_schema->maybe_upgrade_table();
		}

		$listener = new Change_Listener( $this->registry, $this->sources, $this->queue, $this->repository );

		foreach ( $this->sources as $source ) {
			$source->register_hooks( $listener );
		}

		add_action( Sync_Worker::CRON_HOOK, array( $this, 'run_worker' ) );
	}
}

<?php
/**
 * Manages AI request logging for observability and debugging.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Facade for AI request logging functionality.
 *
 * Coordinates schema management and repository operations while providing
 * a simple public API for logging AI requests.
 *
 * @since 1.0.0
 */
class AI_Request_Log_Manager {

	/**
	 * Cron hook used for log cleanup.
	 */
	private const CLEANUP_HOOK = 'wpai_request_logs_cleanup';

	/**
	 * Whether initialization hooks have already been registered.
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * The schema manager instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Schema
	 */
	private AI_Request_Log_Schema $schema;

	/**
	 * The repository instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Repository
	 */
	private AI_Request_Log_Repository $repository;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Schema|null     $schema     Optional schema manager.
	 * @param \WordPress\AI\Logging\AI_Request_Log_Repository|null $repository Optional repository.
	 */
	public function __construct(
		?AI_Request_Log_Schema $schema = null,
		?AI_Request_Log_Repository $repository = null
	) {
		$this->schema     = $schema ?? new AI_Request_Log_Schema();
		$this->repository = $repository ?? new AI_Request_Log_Repository( $this->schema );
	}

	/**
	 * Initializes the log manager.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		$this->schema->maybe_upgrade_table();

		add_action( self::CLEANUP_HOOK, array( $this, 'handle_cleanup_old_logs' ) );

		// Only register the cleanup cron job if maximum retention is enabled.
		$retention_days = $this->get_retention_days();
		$is_scheduled   = (bool) wp_next_scheduled( self::CLEANUP_HOOK );

		if ( $retention_days > 0 && ! $is_scheduled ) {
			wp_schedule_event( time(), 'daily', self::CLEANUP_HOOK );
		} elseif ( 0 === $retention_days && $is_scheduled ) {
			wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		}

		$this->initialized = true;
	}

	/**
	 * Gets the retention period in days.
	 *
	 * Logs are retained indefinitely by default.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of days to retain logs, or 0 to retain forever.
	 */
	public function get_retention_days(): int {
		/**
		 * Filters the retention period for AI request logs.
		 *
		 * Return a positive integer to enable time-based cleanup (e.g. 30 to
		 * keep the last 30 days). Return 0 to retain logs indefinitely.
		 *
		 * @since 1.0.0
		 *
		 * @param int $retention_days Number of days to retain logs (0 = forever).
		 */
		return (int) apply_filters( 'wpai_request_log_retention_days', 0 );
	}

	/**
	 * Starts a timer for measuring request duration.
	 *
	 * @since 1.0.0
	 *
	 * @return array{start: int} Timer data.
	 */
	public function start_timer(): array {
		return array(
			'start' => hrtime( true ),
		);
	}

	/**
	 * Ends a timer and returns duration in milliseconds.
	 *
	 * @since 1.0.0
	 *
	 * @param array{start: int} $timer Timer data from start_timer().
	 * @return int Duration in milliseconds.
	 */
	public function end_timer( array $timer ): int {
		$end = hrtime( true );
		return (int) ( ( $end - $timer['start'] ) / 1e6 );
	}

	/**
	 * Logs an AI request.
	 *
	 * @since 1.0.0
	 *
	 * @param array{
	 *     type: string,
	 *     operation: string,
	 *     provider?: string,
	 *     model?: string,
	 *     duration_ms?: int,
	 *     tokens_input?: int,
	 *     tokens_output?: int,
	 *     status: string,
	 *     error_message?: string,
	 *     user_id?: int,
	 *     context?: array<string, mixed>
	 * } $data Log data.
	 * @return string|false The log ID on success, false on failure.
	 */
	public function log( array $data ) {
		return $this->repository->insert( $data );
	}

	/**
	 * Retrieves a single log entry by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $log_id The log identifier.
	 * @return array<string, mixed>|null The log entry or null if not found.
	 */
	public function get_log( string $log_id ): ?array {
		return $this->repository->find( $log_id );
	}

	/**
	 * Retrieves logs with filtering and pagination.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array{items: list<array<string, mixed>>, total: int, pages: int, next_cursor?: array{id: int, timestamp: string}} Results.
	 */
	public function get_logs( array $args = array() ): array {
		return $this->repository->query( $args );
	}

	/**
	 * Gets aggregate statistics for the dashboard.
	 *
	 * @since 1.0.0
	 *
	 * @param string $period        Time period: 'day', 'week', 'month', or 'all'.
	 * @param bool   $force_refresh Whether to bypass the cache.
	 * @return array<string, mixed> Aggregated statistics.
	 */
	public function get_summary( string $period = 'day', bool $force_refresh = false ): array {
		return $this->repository->get_summary( $period, $force_refresh );
	}

	/**
	 * Gets distinct values for filter dropdowns.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $force_refresh Whether to bypass the cache.
	 * @return array{types: list<string>, providers: list<string>, statuses: list<string>, operations: list<string>, users: list<array{value: string, label: string}>} Filter options.
	 */
	public function get_filter_options( bool $force_refresh = false ): array {
		$options = $this->repository->get_filter_options( $force_refresh );

		return $this->resolve_user_filter_options( $options );
	}

	/**
	 * Replaces the raw `user_ids` list with a `users` list of value/label pairs.
	 *
	 * Display names are looked up via WordPress; absent users fall back to a
	 * "User #N" label so the chip stays informative if a user has been deleted.
	 *
	 * @since 1.0.0
	 *
	 * @param array{types: list<string>, providers: list<string>, statuses: list<string>, operations: list<string>, user_ids: list<int>} $options Filter options from the repository.
	 * @return array{types: list<string>, providers: list<string>, statuses: list<string>, operations: list<string>, users: list<array{value: string, label: string}>}
	 */
	private function resolve_user_filter_options( array $options ): array {
		$user_ids = $options['user_ids'] ?? array();
		unset( $options['user_ids'] );

		$users = array();

		foreach ( (array) $user_ids as $user_id ) {
			$user_id = (int) $user_id;

			if ( $user_id <= 0 ) {
				continue;
			}

			$user  = get_user_by( 'id', $user_id );
			$label = $user ? $user->display_name : sprintf(
				/* translators: %d: user ID. */
				__( 'User #%d', 'ai' ),
				$user_id
			);

			$users[] = array(
				'value' => (string) $user_id,
				'label' => $label,
			);
		}

		$options['users'] = $users;

		return $options;
	}

	/**
	 * Deletes logs older than the retention period.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of logs deleted.
	 */
	public function cleanup_old_logs(): int {
		$total_deleted = $this->repository->cleanup_by_retention( $this->get_retention_days() );

		if ( $total_deleted > 0 ) {
			$this->repository->invalidate_caches();
		}

		return $total_deleted;
	}

	/**
	 * Runs cleanup when invoked by the scheduled action.
	 *
	 * @since 1.0.0
	 */
	public function handle_cleanup_old_logs(): void {
		$this->cleanup_old_logs();
	}

	/**
	 * Purges all logs from the database.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of logs deleted.
	 */
	public function purge_all_logs(): int {
		return $this->repository->purge_all();
	}

	/**
	 * Invalidates the filter options cache.
	 *
	 * @since 1.0.0
	 */
	public function invalidate_filter_cache(): void {
		$this->repository->invalidate_filter_cache();
	}

	/**
	 * Invalidates the summary cache.
	 *
	 * @since 1.0.0
	 */
	public function invalidate_summary_cache(): void {
		$this->repository->invalidate_summary_cache();
	}

	/**
	 * Invalidates all caches.
	 *
	 * @since 1.0.0
	 */
	public function invalidate_caches(): void {
		$this->repository->invalidate_caches();
	}

	/**
	 * Returns the schema manager for direct access if needed.
	 *
	 * @since 1.0.0
	 *
	 * @return \WordPress\AI\Logging\AI_Request_Log_Schema The schema manager.
	 */
	public function get_schema(): AI_Request_Log_Schema {
		return $this->schema;
	}

	/**
	 * Returns the repository for direct access if needed.
	 *
	 * @since 1.0.0
	 *
	 * @return \WordPress\AI\Logging\AI_Request_Log_Repository The repository.
	 */
	public function get_repository(): AI_Request_Log_Repository {
		return $this->repository;
	}
}

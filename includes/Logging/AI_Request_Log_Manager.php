<?php
/**
 * Manages AI request logging for observability and cost tracking.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

use function add_action;
use function get_option;
use function max;
use function update_option;
use function wp_next_scheduled;
use function wp_schedule_event;

/**
 * Facade for AI request logging functionality.
 *
 * Coordinates schema management, repository operations, and cost calculation
 * while providing a simple public API for logging AI requests.
 *
 * @since 0.1.0
 */
class AI_Request_Log_Manager {

	/**
	 * Option key for log retention days.
	 */
	public const OPTION_RETENTION_DAYS = 'wpai_request_logs_retention_days';

	/**
	 * Option key for logging enabled.
	 */
	public const OPTION_LOGGING_ENABLED = 'wpai_request_logs_enabled';

	/**
	 * Option key for max rows limit.
	 */
	public const OPTION_MAX_ROWS = 'wpai_request_logs_max_rows';

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
	 * Default retention period in days.
	 */
	public const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Default maximum number of rows.
	 */
	public const DEFAULT_MAX_ROWS = 100000;

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
	 * The cost calculator instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Cost_Calculator
	 */
	private AI_Request_Cost_Calculator $cost_calculator;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Schema|null      $schema          Optional schema manager.
	 * @param \WordPress\AI\Logging\AI_Request_Log_Repository|null  $repository      Optional repository.
	 * @param \WordPress\AI\Logging\AI_Request_Cost_Calculator|null $cost_calculator Optional cost calculator.
	 */
	public function __construct(
		?AI_Request_Log_Schema $schema = null,
		?AI_Request_Log_Repository $repository = null,
		?AI_Request_Cost_Calculator $cost_calculator = null
	) {
		$this->schema          = $schema ?? new AI_Request_Log_Schema();
		$this->cost_calculator = $cost_calculator ?? new AI_Request_Cost_Calculator();
		$this->repository      = $repository ?? new AI_Request_Log_Repository( $this->schema, $this->cost_calculator );
	}

	/**
	 * Initializes the log manager.
	 *
	 * @since 0.1.0
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		add_action( self::CLEANUP_HOOK, array( $this, 'handle_cleanup_old_logs' ) );

		$this->schema->maybe_upgrade_table();

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CLEANUP_HOOK );
		}

		$this->initialized = true;
	}

	/**
	 * Whether logging is currently enabled.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True if logging is enabled.
	 */
	public function is_logging_enabled(): bool {
		return (bool) get_option( self::OPTION_LOGGING_ENABLED, true );
	}

	/**
	 * Enables or disables logging.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $enabled Whether to enable logging.
	 */
	public function set_logging_enabled( bool $enabled ): void {
		$current_value = get_option( self::OPTION_LOGGING_ENABLED, null );

		// get_option() returns false when the option is missing, so explicitly check for null.
		if ( null === $current_value ) {
			add_option( self::OPTION_LOGGING_ENABLED, $enabled, '', false );
			return;
		}

		update_option( self::OPTION_LOGGING_ENABLED, $enabled, false );
	}

	/**
	 * Gets the retention period in days.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of days to retain logs.
	 */
	public function get_retention_days(): int {
		return (int) get_option( self::OPTION_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS );
	}

	/**
	 * Sets the retention period in days.
	 *
	 * @since 0.1.0
	 *
	 * @param int $days Number of days to retain logs.
	 */
	public function set_retention_days( int $days ): void {
		update_option( self::OPTION_RETENTION_DAYS, max( 1, $days ), false );
	}

	/**
	 * Gets the maximum number of rows to retain.
	 *
	 * @since 0.1.0
	 *
	 * @return int Maximum rows.
	 */
	public function get_max_rows(): int {
		return (int) get_option( self::OPTION_MAX_ROWS, self::DEFAULT_MAX_ROWS );
	}

	/**
	 * Sets the maximum number of rows to retain.
	 *
	 * @since 0.1.0
	 *
	 * @param int $max_rows Maximum rows.
	 */
	public function set_max_rows( int $max_rows ): void {
		update_option( self::OPTION_MAX_ROWS, max( 1000, $max_rows ), false );
	}

	/**
	 * Starts a timer for measuring request duration.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
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
	 * @since 0.1.0
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
		if ( ! $this->is_logging_enabled() ) {
			return false;
		}

		return $this->repository->insert( $data );
	}

	/**
	 * Retrieves a single log entry by ID.
	 *
	 * @since 0.1.0
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
	 * @since 0.1.0
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
	 * @since 0.1.0
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
	 * @since 0.1.0
	 *
	 * @param bool $force_refresh Whether to bypass the cache.
	 * @return array{types: list<string>, providers: list<string>, statuses: list<string>, operations: list<string>} Filter options.
	 */
	public function get_filter_options( bool $force_refresh = false ): array {
		return $this->repository->get_filter_options( $force_refresh );
	}

	/**
	 * Deletes logs older than the retention period.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of logs deleted.
	 */
	public function cleanup_old_logs(): int {
		$total_deleted  = $this->repository->cleanup_by_retention( $this->get_retention_days() );
		$total_deleted += $this->repository->cleanup_by_max_rows( $this->get_max_rows() );

		if ( $total_deleted > 0 ) {
			$this->repository->invalidate_caches();
		}

		return $total_deleted;
	}

	/**
	 * Runs cleanup when invoked by the scheduled action.
	 *
	 * @since x.x.x
	 */
	public function handle_cleanup_old_logs(): void {
		$this->cleanup_old_logs();
	}

	/**
	 * Purges all logs from the database.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of logs deleted.
	 */
	public function purge_all_logs(): int {
		return $this->repository->purge_all();
	}

	/**
	 * Estimates the cost of an AI request based on token usage.
	 *
	 * @since 0.1.0
	 *
	 * @param string $provider      The AI provider.
	 * @param string $model         The model identifier.
	 * @param int    $tokens_input  Number of input tokens.
	 * @param int    $tokens_output Number of output tokens.
	 * @return float|null Estimated cost in USD, or null if unknown.
	 */
	public function estimate_cost( string $provider, string $model, int $tokens_input, int $tokens_output ): ?float {
		return $this->cost_calculator->estimate( $provider, $model, $tokens_input, $tokens_output );
	}

	/**
	 * Invalidates the filter options cache.
	 *
	 * @since 0.1.0
	 */
	public function invalidate_filter_cache(): void {
		$this->repository->invalidate_filter_cache();
	}

	/**
	 * Invalidates the summary cache.
	 *
	 * @since 0.1.0
	 */
	public function invalidate_summary_cache(): void {
		$this->repository->invalidate_summary_cache();
	}

	/**
	 * Invalidates all caches.
	 *
	 * @since 0.1.0
	 */
	public function invalidate_caches(): void {
		$this->repository->invalidate_caches();
	}

	/**
	 * Returns the schema manager for direct access if needed.
	 *
	 * @since 0.1.0
	 *
	 * @return \WordPress\AI\Logging\AI_Request_Log_Schema The schema manager.
	 */
	public function get_schema(): AI_Request_Log_Schema {
		return $this->schema;
	}

	/**
	 * Returns the repository for direct access if needed.
	 *
	 * @since 0.1.0
	 *
	 * @return \WordPress\AI\Logging\AI_Request_Log_Repository The repository.
	 */
	public function get_repository(): AI_Request_Log_Repository {
		return $this->repository;
	}

	/**
	 * Returns the cost calculator for direct access if needed.
	 *
	 * @since 0.1.0
	 *
	 * @return \WordPress\AI\Logging\AI_Request_Cost_Calculator The cost calculator.
	 */
	public function get_cost_calculator(): AI_Request_Cost_Calculator {
		return $this->cost_calculator;
	}
}

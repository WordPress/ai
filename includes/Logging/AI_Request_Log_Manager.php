<?php
/**
 * Manages AI request logging for observability and cost tracking.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

/**
 * Handles storage, retrieval, and aggregation of AI request logs.
 *
 * @since 0.1.0
 */
class AI_Request_Log_Manager {

	/**
	 * Database table name (without prefix).
	 */
	private const TABLE_NAME = 'ai_request_logs';

	/**
	 * Option key for database version.
	 */
	private const DB_VERSION_OPTION = 'ai_request_logs_db_version';

	/**
	 * Current database schema version.
	 */
	private const DB_VERSION = '1.0.0';

	/**
	 * Option key for log retention days.
	 */
	public const OPTION_RETENTION_DAYS = 'ai_request_logs_retention_days';

	/**
	 * Option key for logging enabled.
	 */
	public const OPTION_LOGGING_ENABLED = 'ai_request_logs_enabled';

	/**
	 * Default retention period in days.
	 */
	public const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Model pricing registry (per 1K tokens in USD).
	 *
	 * @var array<string, array<string, array{input: float, output: float}>>
	 */
	private static array $model_costs = array(
		'openai'    => array(
			'gpt-5.1'           => array(
				'input'  => 0.00125,
				'output' => 0.01,
			),
			'gpt-5-mini'        => array(
				'input'  => 0.00025,
				'output' => 0.002,
			),
			'gpt-5-nano'        => array(
				'input'  => 0.00005,
				'output' => 0.0004,
			),
			'gpt-5-pro'         => array(
				'input'  => 0.015,
				'output' => 0.12,
			),
			'gpt-4'              => array(
				'input'  => 0.03,
				'output' => 0.06,
			),
			'gpt-4-turbo'        => array(
				'input'  => 0.01,
				'output' => 0.03,
			),
			'gpt-4o'             => array(
				'input'  => 0.005,
				'output' => 0.015,
			),
			'gpt-4o-mini'        => array(
				'input'  => 0.00015,
				'output' => 0.0006,
			),
			'gpt-3.5-turbo'      => array(
				'input'  => 0.0005,
				'output' => 0.0015,
			),
		),
		'anthropic' => array(
			'claude-4.5-opus'   => array(
				'input'  => 0.005,
				'output' => 0.025,
			),
			'claude-4.5-sonnet' => array(
				'input'  => 0.003,
				'output' => 0.015,
			),
			'claude-4.5-haiku'  => array(
				'input'  => 0.001,
				'output' => 0.005,
			),
			'claude-3-opus'      => array(
				'input'  => 0.015,
				'output' => 0.075,
			),
			'claude-3-5-sonnet'  => array(
				'input'  => 0.003,
				'output' => 0.015,
			),
			'claude-3-sonnet'    => array(
				'input'  => 0.003,
				'output' => 0.015,
			),
			'claude-3-haiku'     => array(
				'input'  => 0.00025,
				'output' => 0.00125,
			),
		),
		'google'    => array(
			'gemini-3-pro-preview'               => array(
				'input'  => 0.002,
				'output' => 0.012,
			),
			'gemini-3-pro-preview-high-context'  => array(
				'input'  => 0.004,
				'output' => 0.018,
			),
			'gemini-2.5-pro'                     => array(
				'input'  => 0.00125,
				'output' => 0.01,
			),
			'gemini-2.5-pro-high-context'        => array(
				'input'  => 0.0025,
				'output' => 0.015,
			),
			'gemini-2.5-flash'                   => array(
				'input'  => 0.0003,
				'output' => 0.0025,
			),
			'gemini-2.5-flash-lite'              => array(
				'input'  => 0.0001,
				'output' => 0.0004,
			),
			'gemini-1.5-pro'     => array(
				'input'  => 0.00125,
				'output' => 0.005,
			),
			'gemini-1.5-flash'   => array(
				'input'  => 0.000075,
				'output' => 0.0003,
			),
		),
	);

	/**
	 * Initializes the log manager.
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'maybe_create_table' ) );
		add_action( 'ai_request_logs_cleanup', array( $this, 'cleanup_old_logs' ) );

		// Ensure the logs table exists even before an admin request runs.
		$this->maybe_create_table();

		// Schedule cleanup if not already scheduled.
		if ( ! wp_next_scheduled( 'ai_request_logs_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'ai_request_logs_cleanup' );
		}
	}

	/**
	 * Creates the database table if needed.
	 */
	public function maybe_create_table(): void {
		$installed_version = get_option( self::DB_VERSION_OPTION, '' );

		if ( $installed_version === self::DB_VERSION ) {
			return;
		}

		$this->create_table();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Creates the logs database table.
	 */
	private function create_table(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			log_id VARCHAR(36) NOT NULL,
			timestamp DATETIME NOT NULL,
			type VARCHAR(32) NOT NULL,
			operation VARCHAR(255) NOT NULL,
			provider VARCHAR(64) DEFAULT NULL,
			model VARCHAR(128) DEFAULT NULL,
			duration_ms INT UNSIGNED DEFAULT NULL,
			tokens_input INT UNSIGNED DEFAULT NULL,
			tokens_output INT UNSIGNED DEFAULT NULL,
			tokens_total INT UNSIGNED DEFAULT NULL,
			cost_estimate DECIMAL(10, 6) DEFAULT NULL,
			status VARCHAR(32) NOT NULL,
			error_message TEXT DEFAULT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			context JSON DEFAULT NULL,
			INDEX idx_timestamp (timestamp),
			INDEX idx_type (type),
			INDEX idx_status (status),
			INDEX idx_user_id (user_id),
			INDEX idx_log_id (log_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Whether logging is currently enabled.
	 */
	public function is_logging_enabled(): bool {
		return (bool) get_option( self::OPTION_LOGGING_ENABLED, true );
	}

	/**
	 * Enables or disables logging.
	 *
	 * @param bool $enabled Whether to enable logging.
	 */
	public function set_logging_enabled( bool $enabled ): void {
		update_option( self::OPTION_LOGGING_ENABLED, $enabled, false );
	}

	/**
	 * Gets the retention period in days.
	 */
	public function get_retention_days(): int {
		return (int) get_option( self::OPTION_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS );
	}

	/**
	 * Sets the retention period in days.
	 *
	 * @param int $days Number of days to retain logs.
	 */
	public function set_retention_days( int $days ): void {
		update_option( self::OPTION_RETENTION_DAYS, max( 1, $days ), false );
	}

	/**
	 * Starts a timer for measuring request duration.
	 *
	 * @return array{start: int, memory_start: int} Timer data.
	 */
	public function start_timer(): array {
		return array(
			'start'        => hrtime( true ),
			'memory_start' => memory_get_usage(),
		);
	}

	/**
	 * Ends a timer and returns duration in milliseconds.
	 *
	 * @param array{start: int, memory_start: int} $timer Timer data from start_timer().
	 * @return int Duration in milliseconds.
	 */
	public function end_timer( array $timer ): int {
		$end = hrtime( true );
		return (int) ( ( $end - $timer['start'] ) / 1e6 );
	}

	/**
	 * Logs an AI request.
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

		global $wpdb;

		$log_id       = wp_generate_uuid4();
		$tokens_total = ( $data['tokens_input'] ?? 0 ) + ( $data['tokens_output'] ?? 0 );

		$cost_estimate = $this->estimate_cost(
			$data['provider'] ?? '',
			$data['model'] ?? '',
			$data['tokens_input'] ?? 0,
			$data['tokens_output'] ?? 0
		);

		$insert_data = array(
			'log_id'        => $log_id,
			'timestamp'     => current_time( 'mysql', true ),
			'type'          => $data['type'],
			'operation'     => $data['operation'],
			'provider'      => $data['provider'] ?? null,
			'model'         => $data['model'] ?? null,
			'duration_ms'   => $data['duration_ms'] ?? null,
			'tokens_input'  => $data['tokens_input'] ?? null,
			'tokens_output' => $data['tokens_output'] ?? null,
			'tokens_total'  => $tokens_total > 0 ? $tokens_total : null,
			'cost_estimate' => $cost_estimate,
			'status'        => $data['status'],
			'error_message' => $data['error_message'] ?? null,
			'user_id'       => $data['user_id'] ?? get_current_user_id(),
			'context'       => isset( $data['context'] ) ? wp_json_encode( $data['context'] ) : null,
		);

		$result = $wpdb->insert(
			$wpdb->prefix . self::TABLE_NAME,
			$insert_data,
			array(
				'%s', // log_id.
				'%s', // timestamp.
				'%s', // type.
				'%s', // operation.
				'%s', // provider.
				'%s', // model.
				'%d', // duration_ms.
				'%d', // tokens_input.
				'%d', // tokens_output.
				'%d', // tokens_total.
				'%f', // cost_estimate.
				'%s', // status.
				'%s', // error_message.
				'%d', // user_id.
				'%s', // context.
			)
		);

		if ( false === $result ) {
			return false;
		}

		/**
		 * Fires after an AI request is logged.
		 *
		 * @since 0.1.0
		 *
		 * @param string $log_id The unique log identifier.
		 * @param array  $data   The log data.
		 */
		do_action( 'ai_request_logged', $log_id, $insert_data );

		return $log_id;
	}

	/**
	 * Estimates the cost of an AI request based on token usage.
	 *
	 * @param string $provider      The AI provider.
	 * @param string $model         The model identifier.
	 * @param int    $tokens_input  Number of input tokens.
	 * @param int    $tokens_output Number of output tokens.
	 * @return float|null Estimated cost in USD, or null if unknown.
	 */
	public function estimate_cost( string $provider, string $model, int $tokens_input, int $tokens_output ): ?float {
		/**
		 * Filters the model cost registry.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, array<string, array{input: float, output: float}>> $model_costs Model pricing data.
		 */
		$costs = apply_filters( 'ai_model_costs', self::$model_costs );

		$provider_lower = strtolower( $provider );
		$model_lower    = strtolower( $model );

		// Try exact match first.
		if ( isset( $costs[ $provider_lower ][ $model_lower ] ) ) {
			$pricing = $costs[ $provider_lower ][ $model_lower ];
			return ( ( $tokens_input / 1000 ) * $pricing['input'] ) +
				   ( ( $tokens_output / 1000 ) * $pricing['output'] );
		}

		// Try prefix match for model variants (e.g., gpt-4-turbo-preview -> gpt-4-turbo).
		if ( isset( $costs[ $provider_lower ] ) ) {
			foreach ( $costs[ $provider_lower ] as $model_key => $pricing ) {
				if ( str_starts_with( $model_lower, $model_key ) ) {
					return ( ( $tokens_input / 1000 ) * $pricing['input'] ) +
						   ( ( $tokens_output / 1000 ) * $pricing['output'] );
				}
			}
		}

		return null;
	}

	/**
	 * Retrieves a single log entry by ID.
	 *
	 * @param string $log_id The log identifier.
	 * @return array<string, mixed>|null The log entry or null if not found.
	 */
	public function get_log( string $log_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}" . self::TABLE_NAME . ' WHERE log_id = %s',
				$log_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->format_log_row( $row );
	}

	/**
	 * Retrieves logs with filtering and pagination.
	 *
	 * @param array{
	 *     type?: string,
	 *     status?: string,
	 *     provider?: string,
	 *     user_id?: int,
	 *     date_from?: string,
	 *     date_to?: string,
	 *     search?: string,
	 *     page?: int,
	 *     per_page?: int,
	 *     orderby?: string,
	 *     order?: string
	 * } $args Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int} Results with pagination info.
	 */
	public function get_logs( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'type'      => '',
			'status'    => '',
			'provider'  => '',
			'operation' => '',
			'user_id'   => 0,
			'date_from' => '',
			'date_to'   => '',
			'search'    => '',
			'page'      => 1,
			'per_page'  => 25,
			'orderby'   => 'timestamp',
			'order'     => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$where      = array( '1=1' );
		$values     = array();

		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type = %s';
			$values[] = $args['type'];
		}

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['provider'] ) ) {
			$where[]  = 'provider = %s';
			$values[] = $args['provider'];
		}

		if ( ! empty( $args['operation'] ) ) {
			// Support comma-separated list for multi-select filter
			$operations = array_filter( array_map( 'trim', explode( ',', $args['operation'] ) ) );
			if ( count( $operations ) === 1 ) {
				$where[]  = 'operation = %s';
				$values[] = $operations[0];
			} elseif ( count( $operations ) > 1 ) {
				$placeholders = implode( ', ', array_fill( 0, count( $operations ), '%s' ) );
				$where[]      = "operation IN ( $placeholders )";
				$values       = array_merge( $values, $operations );
			}
		}

		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = $args['user_id'];
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'timestamp >= %s';
			$values[] = $args['date_from'];
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'timestamp <= %s';
			$values[] = $args['date_to'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(operation LIKE %s OR error_message LIKE %s)';
			$search   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$values[] = $search;
			$values[] = $search;
		}

		$where_clause = implode( ' AND ', $where );

		// Sanitize orderby.
		$allowed_orderby = array( 'timestamp', 'type', 'operation', 'duration_ms', 'tokens_total', 'cost_estimate', 'status' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'timestamp';
		$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		// Get total count.
		$count_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";
		if ( ! empty( $values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Calculate pagination.
		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$pages    = (int) ceil( $total / $per_page );
		$page     = max( 1, min( $pages ?: 1, (int) $args['page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		// Get items.
		$sql = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$values[] = $per_page;
		$values[] = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, $values ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$items = array_map( array( $this, 'format_log_row' ), $rows ?: array() );

		return array(
			'items' => $items,
			'total' => $total,
			'pages' => max( 1, $pages ),
		);
	}

	/**
	 * Gets aggregate statistics for the dashboard.
	 *
	 * @param string $period Time period: 'day', 'week', 'month', or 'all'.
	 * @return array{
	 *     total_requests: int,
	 *     total_tokens: int,
	 *     total_cost: float,
	 *     avg_duration_ms: float,
	 *     success_rate: float,
	 *     by_type: array<string, int>,
	 *     by_provider: array<string, int>,
	 *     by_status: array<string, int>
	 * } Aggregated statistics.
	 */
	public function get_summary( string $period = 'day' ): array {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$date_condition = '';
		switch ( $period ) {
			case 'day':
				$date_condition = 'AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
				break;
			case 'week':
				$date_condition = 'AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 WEEK)';
				break;
			case 'month':
				$date_condition = 'AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 MONTH)';
				break;
		}

		// Main aggregates.
		$sql = "SELECT
			COUNT(*) as total_requests,
			COALESCE(SUM(tokens_total), 0) as total_tokens,
			COALESCE(SUM(cost_estimate), 0) as total_cost,
			COALESCE(AVG(duration_ms), 0) as avg_duration_ms,
			SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count
			FROM {$table_name}
			WHERE 1=1 {$date_condition}";

		$main = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$total_requests = (int) ( $main['total_requests'] ?? 0 );
		$success_count  = (int) ( $main['success_count'] ?? 0 );
		$success_rate   = $total_requests > 0 ? ( $success_count / $total_requests ) * 100 : 0;

		// By type.
		$by_type_sql = "SELECT type, COUNT(*) as count FROM {$table_name} WHERE 1=1 {$date_condition} GROUP BY type";
		$by_type_raw = $wpdb->get_results( $by_type_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$by_type     = array();
		foreach ( $by_type_raw ?: array() as $row ) {
			$by_type[ $row['type'] ] = (int) $row['count'];
		}

		// By provider.
		$by_provider_sql = "SELECT provider, COUNT(*) as count FROM {$table_name} WHERE provider IS NOT NULL {$date_condition} GROUP BY provider";
		$by_provider_raw = $wpdb->get_results( $by_provider_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$by_provider     = array();
		foreach ( $by_provider_raw ?: array() as $row ) {
			$by_provider[ $row['provider'] ] = (int) $row['count'];
		}

		// By status.
		$by_status_sql = "SELECT status, COUNT(*) as count FROM {$table_name} WHERE 1=1 {$date_condition} GROUP BY status";
		$by_status_raw = $wpdb->get_results( $by_status_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$by_status     = array();
		foreach ( $by_status_raw ?: array() as $row ) {
			$by_status[ $row['status'] ] = (int) $row['count'];
		}

		return array(
			'total_requests'  => $total_requests,
			'total_tokens'    => (int) ( $main['total_tokens'] ?? 0 ),
			'total_cost'      => (float) ( $main['total_cost'] ?? 0 ),
			'avg_duration_ms' => round( (float) ( $main['avg_duration_ms'] ?? 0 ), 2 ),
			'success_rate'    => round( $success_rate, 2 ),
			'by_type'         => $by_type,
			'by_provider'     => $by_provider,
			'by_status'       => $by_status,
		);
	}

	/**
	 * Deletes logs older than the retention period.
	 *
	 * @return int Number of logs deleted.
	 */
	public function cleanup_old_logs(): int {
		global $wpdb;

		$retention_days = $this->get_retention_days();
		$table_name     = $wpdb->prefix . self::TABLE_NAME;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$retention_days
			)
		);

		return $deleted ?: 0;
	}

	/**
	 * Purges all logs from the database.
	 *
	 * @return int Number of logs deleted.
	 */
	public function purge_all_logs(): int {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;
		$deleted    = $wpdb->query( "TRUNCATE TABLE {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $deleted ?: 0;
	}

	/**
	 * Gets distinct values for filter dropdowns.
	 *
	 * @return array{types: array<string>, providers: array<string>, statuses: array<string>, operations: array<string>} Filter options.
	 */
	public function get_filter_options(): array {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$types      = $wpdb->get_col( "SELECT DISTINCT type FROM {$table_name} ORDER BY type" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$providers  = $wpdb->get_col( "SELECT DISTINCT provider FROM {$table_name} WHERE provider IS NOT NULL ORDER BY provider" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$statuses   = $wpdb->get_col( "SELECT DISTINCT status FROM {$table_name} ORDER BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$operations = $wpdb->get_col( "SELECT DISTINCT operation FROM {$table_name} WHERE operation IS NOT NULL ORDER BY operation" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'types'      => $types ?: array(),
			'providers'  => $providers ?: array(),
			'statuses'   => $statuses ?: array(),
			'operations' => $operations ?: array(),
		);
	}

	/**
	 * Formats a raw database row into a structured log entry.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed> Formatted log entry.
	 */
	private function format_log_row( array $row ): array {
		return array(
			'id'            => $row['log_id'],
			'timestamp'     => $row['timestamp'],
			'type'          => $row['type'],
			'operation'     => $row['operation'],
			'provider'      => $row['provider'],
			'model'         => $row['model'],
			'duration_ms'   => $row['duration_ms'] ? (int) $row['duration_ms'] : null,
			'tokens_input'  => $row['tokens_input'] ? (int) $row['tokens_input'] : null,
			'tokens_output' => $row['tokens_output'] ? (int) $row['tokens_output'] : null,
			'tokens_total'  => $row['tokens_total'] ? (int) $row['tokens_total'] : null,
			'cost_estimate' => $row['cost_estimate'] ? (float) $row['cost_estimate'] : null,
			'status'        => $row['status'],
			'error_message' => $row['error_message'],
			'user_id'       => $row['user_id'] ? (int) $row['user_id'] : null,
			'context'       => $row['context'] ? json_decode( $row['context'], true ) : null,
		);
	}
}

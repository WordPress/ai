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
	private const DB_VERSION = '1.2.0';

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
	 * Option key for max rows limit.
	 */
	public const OPTION_MAX_ROWS = 'ai_request_logs_max_rows';

	/**
	 * Default maximum number of rows.
	 */
	public const DEFAULT_MAX_ROWS = 100000;

	/**
	 * Chunk size for batched delete operations.
	 */
	private const DELETE_BATCH_SIZE = 5000;

	/**
	 * Cache group for transient caching.
	 */
	private const CACHE_GROUP = 'ai_request_logs';

	/**
	 * Cache expiration for filter options (1 hour).
	 */
	private const FILTER_CACHE_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Cache expiration for summary stats (5 minutes).
	 */
	private const SUMMARY_CACHE_EXPIRATION = 5 * MINUTE_IN_SECONDS;

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
			'claude-4.5-opus'    => array(
				'input'  => 0.005,
				'output' => 0.025,
			),
			'claude-4.5-sonnet'  => array(
				'input'  => 0.003,
				'output' => 0.015,
			),
			'claude-4.5-haiku'   => array(
				'input'  => 0.001,
				'output' => 0.005,
			),
			'claude-haiku-4-5'   => array(
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
			request_preview TEXT DEFAULT NULL,
			response_preview TEXT DEFAULT NULL,
			INDEX idx_timestamp (timestamp),
			INDEX idx_type (type),
			INDEX idx_status (status),
			INDEX idx_user_id (user_id),
			INDEX idx_log_id (log_id),
			INDEX idx_provider (provider),
			INDEX idx_operation (operation(191)),
			INDEX idx_timestamp_type_status (timestamp, type, status),
			INDEX idx_timestamp_provider (timestamp, provider)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Add columns and indexes that dbDelta may not handle well for existing tables.
		$this->maybe_add_columns();
		$this->maybe_add_indexes();
	}

	/**
	 * Adds missing columns to existing tables.
	 *
	 * dbDelta doesn't always add new columns to existing tables,
	 * so we check and add them manually if needed.
	 */
	private function maybe_add_columns(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// Get existing columns.
		$existing_columns = array();
		$columns          = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $columns ) {
			foreach ( $columns as $column ) {
				$existing_columns[ $column['Field'] ] = true;
			}
		}

		// Add request_preview column if missing.
		if ( ! isset( $existing_columns['request_preview'] ) ) {
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN request_preview TEXT DEFAULT NULL AFTER context" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Add response_preview column if missing.
		if ( ! isset( $existing_columns['response_preview'] ) ) {
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN response_preview TEXT DEFAULT NULL AFTER request_preview" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Adds missing indexes to existing tables.
	 *
	 * dbDelta doesn't always add new indexes to existing tables,
	 * so we check and add them manually if needed.
	 */
	private function maybe_add_indexes(): void {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// Get existing indexes.
		$existing_indexes = array();
		$indexes          = $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $indexes ) {
			foreach ( $indexes as $index ) {
				$existing_indexes[ $index['Key_name'] ] = true;
			}
		}

		// Define indexes to add.
		$indexes_to_add = array(
			'idx_provider'              => "ALTER TABLE {$table_name} ADD INDEX idx_provider (provider)",
			'idx_operation'             => "ALTER TABLE {$table_name} ADD INDEX idx_operation (operation(191))",
			'idx_timestamp_type_status' => "ALTER TABLE {$table_name} ADD INDEX idx_timestamp_type_status (timestamp, type, status)",
			'idx_timestamp_provider'    => "ALTER TABLE {$table_name} ADD INDEX idx_timestamp_provider (timestamp, provider)",
		);

		foreach ( $indexes_to_add as $index_name => $sql ) {
			if ( ! isset( $existing_indexes[ $index_name ] ) ) {
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}

		// Add FULLTEXT index for search if MySQL supports it (5.6+ for InnoDB).
		// This is optional - search will fall back to LIKE if not available.
		if ( ! isset( $existing_indexes['ft_search'] ) ) {
			// Check MySQL version for InnoDB FULLTEXT support.
			$mysql_version = $wpdb->get_var( 'SELECT VERSION()' );
			if ( version_compare( $mysql_version, '5.6', '>=' ) ) {
				// Suppress errors as FULLTEXT may not be supported on all configurations.
				$wpdb->suppress_errors( true );
				$wpdb->query( "ALTER TABLE {$table_name} ADD FULLTEXT INDEX ft_search (operation, request_preview, response_preview)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->suppress_errors( false );
			}
		}
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

		// Extract preview content for searchable columns.
		$context          = $data['context'] ?? array();
		$request_preview  = $context['input_preview'] ?? null;
		$response_preview = $context['output_preview'] ?? null;

		$insert_data = array(
			'log_id'           => $log_id,
			'timestamp'        => current_time( 'mysql', true ),
			'type'             => $data['type'],
			'operation'        => $data['operation'],
			'provider'         => $data['provider'] ?? null,
			'model'            => $data['model'] ?? null,
			'duration_ms'      => $data['duration_ms'] ?? null,
			'tokens_input'     => $data['tokens_input'] ?? null,
			'tokens_output'    => $data['tokens_output'] ?? null,
			'tokens_total'     => $tokens_total > 0 ? $tokens_total : null,
			'cost_estimate'    => $cost_estimate,
			'status'           => $data['status'],
			'error_message'    => $data['error_message'] ?? null,
			'user_id'          => $data['user_id'] ?? get_current_user_id(),
			'context'          => ! empty( $context ) ? wp_json_encode( $context ) : null,
			'request_preview'  => $request_preview,
			'response_preview' => $response_preview,
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
				'%s', // request_preview.
				'%s', // response_preview.
			)
		);

		if ( false === $result ) {
			return false;
		}

		// Invalidate summary cache since new data was added.
		// Note: We don't invalidate filter cache on every insert for performance;
		// new filter options will appear after cache expires (1 hour).
		$this->invalidate_summary_cache();

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
	 * Supports both offset-based pagination (for early pages) and cursor-based
	 * pagination (for deep pages) to maintain performance at scale.
	 *
	 * @param array{
	 *     type?: string,
	 *     status?: string,
	 *     provider?: string,
	 *     operation?: string,
	 *     operation_pattern?: string,
	 *     user_id?: int,
	 *     date_from?: string,
	 *     date_to?: string,
	 *     search?: string,
	 *     page?: int,
	 *     per_page?: int,
	 *     orderby?: string,
	 *     order?: string,
	 *     cursor_id?: int,
	 *     cursor_timestamp?: string
	 * } $args Query arguments.
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int, next_cursor?: array{id: int, timestamp: string}} Results with pagination info.
	 */
	public function get_logs( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'type'              => '',
			'status'            => '',
			'provider'          => '',
			'operation'         => '',
			'operation_pattern' => '',
			'tokens_gt'         => null,
			'tokens_lt'         => null,
			'tokens_filter'     => '',
			'user_id'           => 0,
			'date_from'         => '',
			'date_to'           => '',
			'search'            => '',
			'page'              => 1,
			'per_page'          => 25,
			'orderby'           => 'timestamp',
			'order'             => 'DESC',
			'cursor_id'         => null,
			'cursor_timestamp'  => null,
		);

		$args = wp_parse_args( $args, $defaults );

		// Use cursor-based pagination for deep pages (page > 10) when ordering by timestamp.
		$use_cursor = $args['orderby'] === 'timestamp'
			&& $args['cursor_id'] !== null
			&& $args['cursor_timestamp'] !== null;

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

		if ( ! empty( $args['operation_pattern'] ) ) {
			// MySQL REGEXP for pattern matching on operations (e.g., ":completions$")
			$where[]  = 'operation REGEXP %s';
			$values[] = $args['operation_pattern'];
		}

		if ( isset( $args['tokens_gt'] ) && is_numeric( $args['tokens_gt'] ) ) {
			$where[]  = 'tokens_total > %d';
			$values[] = (int) $args['tokens_gt'];
		}

		if ( isset( $args['tokens_lt'] ) && is_numeric( $args['tokens_lt'] ) ) {
			$where[]  = 'tokens_total < %d';
			$values[] = (int) $args['tokens_lt'];
		}

		if ( ! empty( $args['tokens_filter'] ) ) {
			$filter = $args['tokens_filter'];
			if ( 'none' === $filter ) {
				$where[] = '(tokens_total IS NULL OR tokens_total = 0)';
			} elseif ( str_starts_with( $filter, 'gt:' ) ) {
				$value    = (int) substr( $filter, 3 );
				$where[]  = 'tokens_total > %d';
				$values[] = $value;
			} elseif ( str_starts_with( $filter, 'lt:' ) ) {
				$value    = (int) substr( $filter, 3 );
				$where[]  = 'tokens_total < %d';
				$values[] = $value;
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
			// Search across operation, error_message, request_preview, and response_preview.
			// Try FULLTEXT search first (faster for larger tables), fall back to LIKE.
			$search_like = '%' . $wpdb->esc_like( $args['search'] ) . '%';

			if ( $this->has_fulltext_index() ) {
				$boolean_query = $this->build_fulltext_search_query( $args['search'] );

				if ( '' !== $boolean_query ) {
					// Use MATCH...AGAINST for FULLTEXT search.
					$where[]  = '(MATCH(operation, request_preview, response_preview) AGAINST(%s IN BOOLEAN MODE) OR error_message LIKE %s)';
					$values[] = $boolean_query;
					$values[] = $search_like;
				} else {
					// All tokens were too short for FULLTEXT, fall back to LIKE.
					$where[]  = '(operation LIKE %s OR error_message LIKE %s OR request_preview LIKE %s OR response_preview LIKE %s)';
					$values[] = $search_like;
					$values[] = $search_like;
					$values[] = $search_like;
					$values[] = $search_like;
				}
			} else {
				// Fallback to LIKE search (works on all MySQL versions).
				$where[]  = '(operation LIKE %s OR error_message LIKE %s OR request_preview LIKE %s OR response_preview LIKE %s)';
				$values[] = $search_like;
				$values[] = $search_like;
				$values[] = $search_like;
				$values[] = $search_like;
			}
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

		// Build query based on pagination method.
		if ( $use_cursor ) {
			// Cursor-based pagination: more efficient for deep pages.
			// Uses (timestamp, id) as a composite cursor for stable ordering.
			$cursor_values = $values;

			if ( 'DESC' === $order ) {
				// For DESC: get rows where (timestamp < cursor_timestamp) OR (timestamp = cursor_timestamp AND id < cursor_id).
				$cursor_values[] = $args['cursor_timestamp'];
				$cursor_values[] = $args['cursor_timestamp'];
				$cursor_values[] = (int) $args['cursor_id'];
				$cursor_condition = '((timestamp < %s) OR (timestamp = %s AND id < %d))';
			} else {
				// For ASC: get rows where (timestamp > cursor_timestamp) OR (timestamp = cursor_timestamp AND id > cursor_id).
				$cursor_values[] = $args['cursor_timestamp'];
				$cursor_values[] = $args['cursor_timestamp'];
				$cursor_values[] = (int) $args['cursor_id'];
				$cursor_condition = '((timestamp > %s) OR (timestamp = %s AND id > %d))';
			}

			$sql = "SELECT * FROM {$table_name} WHERE {$where_clause} AND {$cursor_condition} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d";
			$cursor_values[] = $per_page;

			$rows = $wpdb->get_results(
				$wpdb->prepare( $sql, $cursor_values ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
		} else {
			// Offset-based pagination: simpler but slower for deep pages.
			$offset = ( $page - 1 ) * $per_page;

			$sql = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";

			$values[] = $per_page;
			$values[] = $offset;

			$rows = $wpdb->get_results(
				$wpdb->prepare( $sql, $values ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
		}

		$items = array_map( array( $this, 'format_log_row' ), $rows ?: array() );

		$result = array(
			'items' => $items,
			'total' => $total,
			'pages' => max( 1, $pages ),
		);

		// Include next cursor for cursor-based pagination.
		if ( ! empty( $rows ) ) {
			$last_row = end( $rows );
			$result['next_cursor'] = array(
				'id'        => (int) $last_row['id'],
				'timestamp' => $last_row['timestamp'],
			);
		}

		return $result;
	}

	/**
	 * Gets aggregate statistics for the dashboard.
	 *
	 * Results are cached for 5 minutes to reduce database load.
	 *
	 * @param string $period        Time period: 'day', 'week', 'month', or 'all'.
	 * @param bool   $force_refresh Whether to bypass the cache.
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
	public function get_summary( string $period = 'day', bool $force_refresh = false ): array {
		$cache_key = self::CACHE_GROUP . '_summary_' . $period;

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$date_condition = '';
		switch ( $period ) {
			case 'minute':
				$date_condition = 'AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)';
				break;
			case 'hour':
				$date_condition = 'AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)';
				break;
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

		// Combined query: main aggregates + breakdowns in a single scan.
		// Uses conditional aggregation to get all stats in one query instead of four.
		$sql = "SELECT
			COUNT(*) as total_requests,
			COALESCE(SUM(tokens_total), 0) as total_tokens,
			COALESCE(SUM(cost_estimate), 0) as total_cost,
			COALESCE(AVG(duration_ms), 0) as avg_duration_ms,
			SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
			type,
			provider,
			status
			FROM {$table_name}
			WHERE 1=1 {$date_condition}
			GROUP BY type, provider, status";

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Process results.
		$total_requests = 0;
		$total_tokens   = 0;
		$total_cost     = 0.0;
		$total_duration = 0.0;
		$success_count  = 0;
		$row_count      = 0;
		$by_type        = array();
		$by_provider    = array();
		$by_status      = array();

		foreach ( $rows ?: array() as $row ) {
			$count = (int) $row['total_requests'];

			$total_requests += $count;
			$total_tokens   += (int) $row['total_tokens'];
			$total_cost     += (float) $row['total_cost'];
			$total_duration += (float) $row['avg_duration_ms'] * $count;
			$success_count  += (int) $row['success_count'];
			++$row_count;

			// Aggregate by type.
			$type = $row['type'];
			if ( $type ) {
				$by_type[ $type ] = ( $by_type[ $type ] ?? 0 ) + $count;
			}

			// Aggregate by provider.
			$provider = $row['provider'];
			if ( $provider ) {
				$by_provider[ $provider ] = ( $by_provider[ $provider ] ?? 0 ) + $count;
			}

			// Aggregate by status.
			$status = $row['status'];
			if ( $status ) {
				$by_status[ $status ] = ( $by_status[ $status ] ?? 0 ) + $count;
			}
		}

		$avg_duration_ms = $total_requests > 0 ? $total_duration / $total_requests : 0;
		$success_rate    = $total_requests > 0 ? ( $success_count / $total_requests ) * 100 : 0;

		$result = array(
			'total_requests'  => $total_requests,
			'total_tokens'    => $total_tokens,
			'total_cost'      => $total_cost,
			'avg_duration_ms' => round( $avg_duration_ms, 2 ),
			'success_rate'    => round( $success_rate, 2 ),
			'by_type'         => $by_type,
			'by_provider'     => $by_provider,
			'by_status'       => $by_status,
		);

		set_transient( $cache_key, $result, self::SUMMARY_CACHE_EXPIRATION );

		return $result;
	}

	/**
	 * Deletes logs older than the retention period using batched deletes.
	 *
	 * This method deletes in chunks to avoid locking the table for extended periods.
	 *
	 * @return int Number of logs deleted.
	 */
	public function cleanup_old_logs(): int {
		$total_deleted = $this->cleanup_by_retention();
		$total_deleted += $this->cleanup_by_max_rows();

		if ( $total_deleted > 0 ) {
			$this->invalidate_caches();
		}

		return $total_deleted;
	}

	/**
	 * Deletes logs older than the retention period in batches.
	 *
	 * @return int Number of logs deleted.
	 */
	private function cleanup_by_retention(): int {
		global $wpdb;

		$retention_days = $this->get_retention_days();
		$table_name     = $wpdb->prefix . self::TABLE_NAME;
		$total_deleted  = 0;

		// Delete in batches to avoid long table locks.
		do {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table_name} WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY) LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$retention_days,
					self::DELETE_BATCH_SIZE
				)
			);

			$batch_deleted = $deleted ?: 0;
			$total_deleted += $batch_deleted;

			// Allow other queries to run between batches.
			if ( $batch_deleted >= self::DELETE_BATCH_SIZE ) {
				usleep( 100000 ); // 100ms pause.
			}
		} while ( $batch_deleted >= self::DELETE_BATCH_SIZE );

		return $total_deleted;
	}

	/**
	 * Deletes oldest logs when table exceeds max rows limit.
	 *
	 * @return int Number of logs deleted.
	 */
	private function cleanup_by_max_rows(): int {
		global $wpdb;

		$max_rows    = $this->get_max_rows();
		$table_name  = $wpdb->prefix . self::TABLE_NAME;

		// Get current row count.
		$current_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $current_count <= $max_rows ) {
			return 0;
		}

		$rows_to_delete = $current_count - $max_rows;
		$total_deleted  = 0;

		// Delete oldest rows in batches.
		while ( $rows_to_delete > 0 ) {
			$batch_size = min( $rows_to_delete, self::DELETE_BATCH_SIZE );

			// Delete the oldest rows by using a subquery to find IDs.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table_name} WHERE id IN (SELECT id FROM (SELECT id FROM {$table_name} ORDER BY timestamp ASC LIMIT %d) AS oldest)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$batch_size
				)
			);

			$batch_deleted = $deleted ?: 0;
			$total_deleted += $batch_deleted;
			$rows_to_delete -= $batch_deleted;

			// Break if we couldn't delete any rows.
			if ( 0 === $batch_deleted ) {
				break;
			}

			// Allow other queries to run between batches.
			if ( $batch_deleted >= self::DELETE_BATCH_SIZE ) {
				usleep( 100000 ); // 100ms pause.
			}
		}

		return $total_deleted;
	}

	/**
	 * Gets the maximum number of rows to retain.
	 *
	 * @return int Maximum rows.
	 */
	public function get_max_rows(): int {
		return (int) get_option( self::OPTION_MAX_ROWS, self::DEFAULT_MAX_ROWS );
	}

	/**
	 * Checks if the FULLTEXT search index exists on the table.
	 *
	 * Results are cached in a static variable to avoid repeated queries.
	 *
	 * @return bool True if FULLTEXT index exists.
	 */
	private function has_fulltext_index(): bool {
		static $has_index = null;

		if ( null !== $has_index ) {
			return $has_index;
		}

		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.statistics
				WHERE table_schema = %s
				AND table_name = %s
				AND index_name = 'ft_search'
				AND index_type = 'FULLTEXT'",
				DB_NAME,
				$table_name
			)
		);

		$has_index = (int) $result > 0;

		return $has_index;
	}

	/**
	 * Builds a boolean-mode FULLTEXT query string with partial term support.
	 *
	 * Tokens shorter than three characters are skipped because MySQL does not
	 * index them by default (innodb_ft_min_token_size / ft_min_word_len).
	 * Callers should fall back to LIKE operations when this method returns
	 * an empty string.
	 *
	 * @param string $search Raw search string.
	 * @return string Boolean FULLTEXT query or empty string when no tokens qualify.
	 */
	private function build_fulltext_search_query( string $search ): string {
		$search = trim( $search );

		if ( '' === $search ) {
			return '';
		}

		$tokens = preg_split( '/\s+/', $search );
		if ( ! $tokens ) {
			return '';
		}

		$clauses = array();

		foreach ( $tokens as $token ) {
			$token = trim( (string) $token );
			if ( '' === $token ) {
				continue;
			}

			// Remove boolean operators that would otherwise break the query.
			$token = preg_replace( '/[+\-><()~*"@]+/', ' ', $token );
			$token = trim( (string) $token );

			if ( '' === $token ) {
				continue;
			}

			$length = function_exists( 'mb_strlen' )
				? mb_strlen( $token, 'UTF-8' )
				: strlen( $token );

			if ( $length < 3 ) {
				continue;
			}

			$clauses[] = '+' . $token . '*';
		}

		return implode( ' ', $clauses );
	}

	/**
	 * Sets the maximum number of rows to retain.
	 *
	 * @param int $max_rows Maximum rows.
	 */
	public function set_max_rows( int $max_rows ): void {
		update_option( self::OPTION_MAX_ROWS, max( 1000, $max_rows ), false );
	}

	/**
	 * Gets table statistics for admin display.
	 *
	 * @return array{row_count: int, table_size_bytes: int, table_size_formatted: string, max_rows: int, retention_days: int}
	 */
	public function get_table_stats(): array {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// Get row count.
		$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Get table size.
		$table_status = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT data_length + index_length as size FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
				DB_NAME,
				$table_name
			),
			ARRAY_A
		);

		$table_size_bytes = (int) ( $table_status['size'] ?? 0 );

		return array(
			'row_count'            => $row_count,
			'table_size_bytes'     => $table_size_bytes,
			'table_size_formatted' => size_format( $table_size_bytes ),
			'max_rows'             => $this->get_max_rows(),
			'retention_days'       => $this->get_retention_days(),
		);
	}

	/**
	 * Purges all logs from the database.
	 *
	 * @return int Number of logs deleted.
	 */
	public function purge_all_logs(): int {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// Get count before truncating.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$wpdb->query( "TRUNCATE TABLE {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Invalidate all caches after purge.
		$this->invalidate_caches();

		return $count;
	}

	/**
	 * Gets distinct values for filter dropdowns.
	 *
	 * Results are cached for 1 hour to avoid expensive DISTINCT queries on every page load.
	 *
	 * @param bool $force_refresh Whether to bypass the cache.
	 * @return array{types: array<string>, providers: array<string>, statuses: array<string>, operations: array<string>} Filter options.
	 */
	public function get_filter_options( bool $force_refresh = false ): array {
		$cache_key = self::CACHE_GROUP . '_filter_options';

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// Use a single query with UNION ALL for better performance.
		// This scans the table once instead of four times.
		$sql = "SELECT 'type' as category, type as value FROM {$table_name} WHERE type IS NOT NULL GROUP BY type
				UNION ALL
				SELECT 'provider' as category, provider as value FROM {$table_name} WHERE provider IS NOT NULL GROUP BY provider
				UNION ALL
				SELECT 'status' as category, status as value FROM {$table_name} WHERE status IS NOT NULL GROUP BY status
				UNION ALL
				SELECT 'operation' as category, operation as value FROM {$table_name} WHERE operation IS NOT NULL GROUP BY operation";

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$result = array(
			'types'      => array(),
			'providers'  => array(),
			'statuses'   => array(),
			'operations' => array(),
		);

		if ( $rows ) {
			foreach ( $rows as $row ) {
				switch ( $row['category'] ) {
					case 'type':
						$result['types'][] = $row['value'];
						break;
					case 'provider':
						$result['providers'][] = $row['value'];
						break;
					case 'status':
						$result['statuses'][] = $row['value'];
						break;
					case 'operation':
						$result['operations'][] = $row['value'];
						break;
				}
			}

			// Sort the arrays.
			sort( $result['types'] );
			sort( $result['providers'] );
			sort( $result['statuses'] );
			sort( $result['operations'] );
		}

		set_transient( $cache_key, $result, self::FILTER_CACHE_EXPIRATION );

		return $result;
	}

	/**
	 * Invalidates the filter options cache.
	 *
	 * Should be called when logs are added or deleted.
	 */
	public function invalidate_filter_cache(): void {
		delete_transient( self::CACHE_GROUP . '_filter_options' );
	}

	/**
	 * Invalidates the summary cache.
	 *
	 * Should be called when logs are added or deleted.
	 */
	public function invalidate_summary_cache(): void {
		// Delete all period-specific summary caches.
		$periods = array( 'minute', 'hour', 'day', 'week', 'month', 'all' );
		foreach ( $periods as $period ) {
			delete_transient( self::CACHE_GROUP . '_summary_' . $period );
		}
	}

	/**
	 * Invalidates all caches.
	 */
	public function invalidate_caches(): void {
		$this->invalidate_filter_cache();
		$this->invalidate_summary_cache();
	}

	/**
	 * Formats a raw database row into a structured log entry.
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed> Formatted log entry.
	 */
	private function format_log_row( array $row ): array {
		$duration = $row['duration_ms'] ? (int) $row['duration_ms'] : null;
		$tokens_total = $row['tokens_total'] ? (int) $row['tokens_total'] : null;
		$tokens_per_second = null;
		if ( $tokens_total !== null && $duration && $duration > 0 ) {
			$tokens_per_second = $tokens_total / ( $duration / 1000 );
		}

		return array(
			'id'            => $row['log_id'],
			'timestamp'     => $row['timestamp'],
			'type'          => $row['type'],
			'operation'     => $row['operation'],
			'provider'      => $row['provider'],
			'model'         => $row['model'],
			'duration_ms'   => $duration,
			'tokens_input'  => $row['tokens_input'] ? (int) $row['tokens_input'] : null,
			'tokens_output' => $row['tokens_output'] ? (int) $row['tokens_output'] : null,
			'tokens_total'  => $tokens_total,
			'tokens_per_second' => $tokens_per_second !== null ? (float) $tokens_per_second : null,
			'cost_estimate' => $row['cost_estimate'] ? (float) $row['cost_estimate'] : null,
			'status'        => $row['status'],
			'error_message' => $row['error_message'],
			'user_id'       => $row['user_id'] ? (int) $row['user_id'] : null,
			'context'       => $row['context'] ? json_decode( $row['context'], true ) : null,
		);
	}
}

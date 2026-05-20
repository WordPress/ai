<?php
/**
 * Repository for AI request log CRUD operations.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Handles storage, retrieval, and aggregation of AI request logs.
 *
 * @since 1.0.0
 */
class AI_Request_Log_Repository {
	// Direct queries are intentional in this repository because it owns a dedicated log table.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Cache group for transient caching.
	 */
	private const CACHE_GROUP = 'wpai_request_logs';

	/**
	 * Cache expiration for filter options (1 hour).
	 */
	private const FILTER_CACHE_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Cache expiration for summary stats (5 minutes).
	 */
	private const SUMMARY_CACHE_EXPIRATION = 5 * MINUTE_IN_SECONDS;

	/**
	 * Chunk size for batched delete operations.
	 */
	private const DELETE_BATCH_SIZE = 5000;

	/**
	 * The schema manager instance.
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Schema
	 */
	private AI_Request_Log_Schema $schema;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param \WordPress\AI\Logging\AI_Request_Log_Schema $schema The schema manager.
	 */
	public function __construct( AI_Request_Log_Schema $schema ) {
		$this->schema = $schema;
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
	public function insert( array $data ) {
		global $wpdb;

		$log_id       = wp_generate_uuid4();
		$tokens_total = ( $data['tokens_input'] ?? 0 ) + ( $data['tokens_output'] ?? 0 );

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
			'status'           => $data['status'],
			'error_message'    => $data['error_message'] ?? null,
			'user_id'          => $data['user_id'] ?? get_current_user_id(),
			'context'          => ! empty( $context ) ? wp_json_encode( $context ) : null,
			'request_preview'  => $request_preview,
			'response_preview' => $response_preview,
		);

		$result = $wpdb->insert(
			$this->schema->get_table_name(),
			$insert_data,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			return false;
		}

		$this->invalidate_summary_cache();
		$this->invalidate_filter_cache();

		/**
		 * Fires after an AI request is logged.
		 *
		 * @since 1.0.0
		 *
		 * @param string               $log_id The unique log identifier.
		 * @param array<string, mixed> $data   The log data.
		 */
		do_action( 'wpai_request_logged', $log_id, $insert_data );

		return $log_id;
	}

	/**
	 * Retrieves a single log entry by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $log_id The log identifier.
	 * @return array<string, mixed>|null The log entry or null if not found.
	 */
	public function find( string $log_id ): ?array {
		global $wpdb;

		$table_name = $this->schema->get_table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE log_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * @since 1.0.0
	 *
	 * @param array{
	 *     type?: string,
	 *     status?: string,
	 *     provider?: string,
	 *     operation?: string,
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
	 * @return array{items: list<array<string, mixed>>, total: int, pages: int, next_cursor?: array{id: int, timestamp: string}} Results.
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'type'             => '',
			'status'           => '',
			'provider'         => '',
			'operation'        => '',
			'tokens_filter'    => '',
			'user_id'          => 0,
			'date_from'        => '',
			'date_to'          => '',
			'search'           => '',
			'page'             => 1,
			'per_page'         => 25,
			'orderby'          => 'timestamp',
			'order'            => 'DESC',
			'cursor_id'        => null,
			'cursor_timestamp' => null,
		);

		$args = wp_parse_args( $args, $defaults );

		$use_cursor = 'timestamp' === $args['orderby']
			&& null !== $args['cursor_id']
			&& null !== $args['cursor_timestamp'];

		$table_name = $this->schema->get_table_name();
		$where      = array( '1=1' );
		$values     = array();

		$this->build_where_clauses( $args, $where, $values );

		$where_clause = implode( ' AND ', $where );

		$allowed_orderby = array( 'timestamp', 'type', 'operation', 'duration_ms', 'tokens_total', 'status' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'timestamp';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$count_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$per_page = max( 1, min( 100, (int) $args['per_page'] ) );
		$pages    = (int) ceil( $total / $per_page );
		$page     = max( 1, min( ( $pages ? $pages : 1 ), (int) $args['page'] ) );

		if ( $use_cursor ) {
			$rows = $this->query_with_cursor( $table_name, $where_clause, $values, $args, $orderby, $order, $per_page );
		} else {
			$rows = $this->query_with_offset( $table_name, $where_clause, $values, $orderby, $order, $per_page, $page );
		}

		$items = array_map( array( $this, 'format_log_row' ), ( $rows ? $rows : array() ) );

		$result = array(
			'items' => $items,
			'total' => $total,
			'pages' => max( 1, $pages ),
		);

		if ( ! empty( $rows ) ) {
			$last_row              = end( $rows );
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
	 * @since 1.0.0
	 *
	 * @param string $period        Time period: 'day', 'week', 'month', or 'all'.
	 * @param bool   $force_refresh Whether to bypass the cache.
	 * @return array{
	 *     total_requests: int,
	 *     total_tokens: int,
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
			if ( is_array( $cached ) ) {
				/** @var array{total_requests: int, total_tokens: int, avg_duration_ms: float, success_rate: float, by_type: array<string, int>, by_provider: array<string, int>, by_status: array<string, int>} $cached */
				return $cached;
			}
		}

		global $wpdb;

		$table_name     = $this->schema->get_table_name();
		$date_condition = $this->get_date_condition( $period );

		$sql = "SELECT
			COUNT(*) as total_requests,
			COALESCE(SUM(tokens_total), 0) as total_tokens,
			COALESCE(AVG(duration_ms), 0) as avg_duration_ms,
			SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
			type,
			provider,
			status
			FROM {$table_name}
			WHERE 1=1 {$date_condition}
			GROUP BY type, provider, status"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$result = $this->aggregate_summary_rows( ( $rows ? $rows : array() ) );

		set_transient( $cache_key, $result, self::SUMMARY_CACHE_EXPIRATION );

		return $result;
	}

	/**
	 * Gets distinct values for filter dropdowns.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $force_refresh Whether to bypass the cache.
	 * @return array{types: list<string>, providers: list<string>, statuses: list<string>, operations: list<string>, user_ids: list<int>} Filter options.
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

		$table_name = $this->schema->get_table_name();

		$sql = "SELECT 'type' as category, type as value FROM {$table_name} WHERE type IS NOT NULL GROUP BY type
				UNION ALL
				SELECT 'provider' as category, provider as value FROM {$table_name} WHERE provider IS NOT NULL GROUP BY provider
				UNION ALL
				SELECT 'status' as category, status as value FROM {$table_name} WHERE status IS NOT NULL GROUP BY status
				UNION ALL
				SELECT 'operation' as category, operation as value FROM {$table_name} WHERE operation IS NOT NULL GROUP BY operation
				UNION ALL
				SELECT 'user' as category, user_id as value FROM {$table_name} WHERE user_id IS NOT NULL AND user_id > 0 GROUP BY user_id";

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$result = $this->aggregate_filter_options( ( $rows ? $rows : array() ) );

		set_transient( $cache_key, $result, self::FILTER_CACHE_EXPIRATION );

		return $result;
	}

	/**
	 * Deletes logs older than the retention period.
	 *
	 * @since 1.0.0
	 *
	 * @param int $retention_days Number of days to retain logs.
	 * @return int Number of logs deleted.
	 */
	public function cleanup_by_retention( int $retention_days ): int {
		// Retention <= 0 means logs are kept indefinitely.
		if ( $retention_days <= 0 ) {
			return 0;
		}

		global $wpdb;

		$table_name    = $this->schema->get_table_name();
		$total_deleted = 0;

		do {
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table_name} WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY) LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$retention_days,
					self::DELETE_BATCH_SIZE
				)
			);

			$batch_deleted  = ( $deleted ? $deleted : 0 );
			$total_deleted += $batch_deleted;

			if ( $batch_deleted < self::DELETE_BATCH_SIZE ) {
				continue;
			}

			usleep( 100000 );
		} while ( $batch_deleted >= self::DELETE_BATCH_SIZE );

		return $total_deleted;
	}

	/**
	 * Purges all logs from the database.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of logs deleted.
	 */
	public function purge_all(): int {
		global $wpdb;

		$table_name = $this->schema->get_table_name();

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$wpdb->query( "TRUNCATE TABLE {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->invalidate_caches();

		return $count;
	}

	/**
	 * Invalidates all caches.
	 *
	 * @since 1.0.0
	 */
	public function invalidate_caches(): void {
		$this->invalidate_filter_cache();
		$this->invalidate_summary_cache();
	}

	/**
	 * Invalidates the filter options cache.
	 *
	 * @since 1.0.0
	 */
	public function invalidate_filter_cache(): void {
		delete_transient( self::CACHE_GROUP . '_filter_options' );
	}

	/**
	 * Invalidates the summary cache.
	 *
	 * @since 1.0.0
	 */
	public function invalidate_summary_cache(): void {
		$periods = array( 'minute', 'hour', 'day', 'week', 'month', 'all' );
		foreach ( $periods as $period ) {
			delete_transient( self::CACHE_GROUP . '_summary_' . $period );
		}
	}

	/**
	 * Builds WHERE clauses for the query.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args   Query arguments.
	 * @param list<string>         $where  WHERE clauses array (modified by reference).
	 * @param list<mixed>          $values Values array for prepared statement (modified by reference).
	 */
	private function build_where_clauses( array $args, array &$where, array &$values ): void {
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
			$operations = array_filter( array_map( 'trim', explode( ',', $args['operation'] ) ) );
			if ( 1 === count( $operations ) ) {
				$where[]  = 'operation = %s';
				$values[] = $operations[0];
			} elseif ( count( $operations ) > 1 ) {
				$placeholders = implode( ', ', array_fill( 0, count( $operations ), '%s' ) );
				$where[]      = "operation IN ( $placeholders )";
				$values       = array_merge( $values, $operations );
			}
		}

		$this->build_token_filter_clauses( $args, $where, $values );

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

		if ( empty( $args['search'] ) ) {
			return;
		}

		$this->build_search_clause( $args['search'], $where, $values );
	}

	/**
	 * Builds token filter clauses.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args   Query arguments.
	 * @param list<string>         $where  WHERE clauses array (modified by reference).
	 * @param list<mixed>          $values Values array (modified by reference).
	 */
	private function build_token_filter_clauses( array $args, array &$where, array &$values ): void {
		if ( empty( $args['tokens_filter'] ) ) {
			return;
		}

		$filter = $args['tokens_filter'];
		if ( 'none' === $filter ) {
			$where[] = '(tokens_total IS NULL OR tokens_total = 0)';
		} elseif ( 0 === strpos( $filter, 'gt:' ) ) {
			$where[]  = 'tokens_total > %d';
			$values[] = (int) substr( $filter, 3 );
		} elseif ( 0 === strpos( $filter, 'lt:' ) ) {
			$where[]  = 'tokens_total < %d';
			$values[] = (int) substr( $filter, 3 );
		}
	}

	/**
	 * Builds the search clause for full-text or LIKE search.
	 *
	 * @since 1.0.0
	 *
	 * @param string       $search Search term.
	 * @param list<string> $where  WHERE clauses array (modified by reference).
	 * @param list<mixed>  $values Values array (modified by reference).
	 */
	private function build_search_clause( string $search, array &$where, array &$values ): void {
		global $wpdb;

		$search_like = '%' . $wpdb->esc_like( $search ) . '%';

		// Only use fulltext if index exists AND autocommit is enabled (tests use transactions).
		$use_fulltext = $this->schema->has_fulltext_index() && $this->is_autocommit_enabled();

		if ( $use_fulltext ) {
			$boolean_query = $this->build_fulltext_search_query( $search );

			if ( '' !== $boolean_query ) {
				$where[]  = '(MATCH(operation, request_preview, response_preview) AGAINST(%s IN BOOLEAN MODE) OR error_message LIKE %s)';
				$values[] = $boolean_query;
				$values[] = $search_like;
				return;
			}
		}

		$where[]  = '(operation LIKE %s OR error_message LIKE %s OR request_preview LIKE %s OR response_preview LIKE %s)';
		$values[] = $search_like;
		$values[] = $search_like;
		$values[] = $search_like;
		$values[] = $search_like;
	}

	/**
	 * Builds a boolean-mode FULLTEXT query string.
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Raw search string.
	 * @return string Boolean FULLTEXT query or empty string.
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
	 * Determines if the current database connection has autocommit enabled.
	 *
	 * In transactional test environments (e.g., WP_UnitTestCase), fulltext indexes
	 * are not updated until commit, so prefer LIKE queries when autocommit is off.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if autocommit is enabled.
	 */
	private function is_autocommit_enabled(): bool {
		global $wpdb;

		$autocommit = $wpdb->get_var( 'SELECT @@autocommit' );

		if ( null === $autocommit ) {
			return true;
		}

		return 1 === (int) $autocommit;
	}

	/**
	 * Queries logs using cursor-based pagination.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $table_name   Table name.
	 * @param string               $where_clause WHERE clause.
	 * @param list<mixed>          $values       Prepared values.
	 * @param array<string, mixed> $args         Query arguments.
	 * @param string               $orderby      Order by column.
	 * @param string               $order        Sort order.
	 * @param int                  $per_page     Results per page.
	 * @return list<array<string, mixed>> Query results.
	 */
	private function query_with_cursor( string $table_name, string $where_clause, array $values, array $args, string $orderby, string $order, int $per_page ): array {
		global $wpdb;

		$cursor_values = $values;

		if ( 'DESC' === $order ) {
			$cursor_values[]  = $args['cursor_timestamp'];
			$cursor_values[]  = $args['cursor_timestamp'];
			$cursor_values[]  = (int) $args['cursor_id'];
			$cursor_condition = '((timestamp < %s) OR (timestamp = %s AND id < %d))';
		} else {
			$cursor_values[]  = $args['cursor_timestamp'];
			$cursor_values[]  = $args['cursor_timestamp'];
			$cursor_values[]  = (int) $args['cursor_id'];
			$cursor_condition = '((timestamp > %s) OR (timestamp = %s AND id > %d))';
		}

		$sql             = "SELECT * FROM {$table_name} WHERE {$where_clause} AND {$cursor_condition} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cursor_values[] = $per_page;

		$results = $wpdb->get_results(
			$wpdb->prepare( $sql, $cursor_values ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return ( $results ? $results : array() );
	}

	/**
	 * Queries logs using offset-based pagination.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $table_name   Table name.
	 * @param string      $where_clause WHERE clause.
	 * @param list<mixed> $values       Prepared values.
	 * @param string      $orderby      Order by column.
	 * @param string      $order        Sort order.
	 * @param int         $per_page     Results per page.
	 * @param int         $page         Current page number.
	 * @return list<array<string, mixed>> Query results.
	 */
	private function query_with_offset( string $table_name, string $where_clause, array $values, string $orderby, string $order, int $per_page, int $page ): array {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;

		$sql      = "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$values[] = $per_page;
		$values[] = $offset;

		$results = $wpdb->get_results(
			$wpdb->prepare( $sql, $values ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return ( $results ? $results : array() );
	}

	/**
	 * Gets the SQL date condition for a time period.
	 *
	 * @since 1.0.0
	 *
	 * @param string $period Time period.
	 * @return string SQL condition.
	 */
	private function get_date_condition( string $period ): string {
		switch ( $period ) {
			case 'minute':
				return 'AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)';
			case 'hour':
				return 'AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)';
			case 'day':
				return 'AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
			case 'week':
				return 'AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 WEEK)';
			case 'month':
				return 'AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 MONTH)';
			default:
				return '';
		}
	}

	/**
	 * Aggregates summary rows into statistics.
	 *
	 * @since 1.0.0
	 *
	 * @param list<array<string, mixed>> $rows Raw query rows.
	 * @return array{total_requests: int, total_tokens: int, avg_duration_ms: float, success_rate: float, by_type: array<string, int>, by_provider: array<string, int>, by_status: array<string, int>} Aggregated statistics.
	 */
	private function aggregate_summary_rows( array $rows ): array {
		$total_requests = 0;
		$total_tokens   = 0;
		$total_duration = 0.0;
		$success_count  = 0;
		$by_type        = array();
		$by_provider    = array();
		$by_status      = array();

		foreach ( $rows as $row ) {
			$count = (int) $row['total_requests'];

			$total_requests += $count;
			$total_tokens   += (int) $row['total_tokens'];
			$total_duration += (float) $row['avg_duration_ms'] * $count;
			$success_count  += (int) $row['success_count'];

			if ( $row['type'] ) {
				$by_type[ $row['type'] ] = ( $by_type[ $row['type'] ] ?? 0 ) + $count;
			}

			if ( $row['provider'] ) {
				$by_provider[ $row['provider'] ] = ( $by_provider[ $row['provider'] ] ?? 0 ) + $count;
			}

			if ( ! $row['status'] ) {
				continue;
			}

			$by_status[ $row['status'] ] = ( $by_status[ $row['status'] ] ?? 0 ) + $count;
		}

		$avg_duration_ms = $total_requests > 0 ? $total_duration / $total_requests : 0;
		$success_rate    = $total_requests > 0 ? $success_count / $total_requests * 100 : 0;

		return array(
			'total_requests'  => $total_requests,
			'total_tokens'    => $total_tokens,
			'avg_duration_ms' => round( $avg_duration_ms, 2 ),
			'success_rate'    => round( $success_rate, 2 ),
			'by_type'         => $by_type,
			'by_provider'     => $by_provider,
			'by_status'       => $by_status,
		);
	}

	/**
	 * Aggregates filter option rows.
	 *
	 * @since 1.0.0
	 *
	 * @param list<array<string, mixed>> $rows Raw query rows.
	 * @return array{types: list<string>, providers: list<string>, statuses: list<string>, operations: list<string>, user_ids: list<int>} Filter options.
	 */
	private function aggregate_filter_options( array $rows ): array {
		$result = array(
			'types'      => array(),
			'providers'  => array(),
			'statuses'   => array(),
			'operations' => array(),
			'user_ids'   => array(),
		);

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
				case 'user':
					$result['user_ids'][] = (int) $row['value'];
					break;
			}
		}

		sort( $result['types'] );
		sort( $result['providers'] );
		sort( $result['statuses'] );
		sort( $result['operations'] );
		sort( $result['user_ids'] );

		return $result;
	}

	/**
	 * Formats a raw database row into a structured log entry.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed> Formatted log entry.
	 */
	private function format_log_row( array $row ): array {
		$duration          = $row['duration_ms'] ? (int) $row['duration_ms'] : null;
		$tokens_total      = $row['tokens_total'] ? (int) $row['tokens_total'] : null;
		$tokens_per_second = null;

		if ( null !== $tokens_total && $duration && $duration > 0 ) {
			$tokens_per_second = $tokens_total / ( $duration / 1000 );
		}

		return array(
			'id'                => $row['log_id'],
			'timestamp'         => $row['timestamp'],
			'type'              => $row['type'],
			'operation'         => $row['operation'],
			'provider'          => $row['provider'],
			'model'             => $row['model'],
			'duration_ms'       => $duration,
			'tokens_input'      => $row['tokens_input'] ? (int) $row['tokens_input'] : null,
			'tokens_output'     => $row['tokens_output'] ? (int) $row['tokens_output'] : null,
			'tokens_total'      => $tokens_total,
			'tokens_per_second' => null !== $tokens_per_second ? (float) $tokens_per_second : null,
			'status'            => $row['status'],
			'error_message'     => $row['error_message'],
			'user_id'           => $row['user_id'] ? (int) $row['user_id'] : null,
			'context'           => $row['context'] ? json_decode( $row['context'], true ) : null,
		);
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

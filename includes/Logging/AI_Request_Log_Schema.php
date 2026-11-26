<?php
/**
 * Manages the database schema for AI request logs.
 *
 * @package WordPress\AI\Logging
 */

declare( strict_types=1 );

namespace WordPress\AI\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Handles database table creation and schema migrations for AI request logs.
 *
 * @since 1.0.0
 */
class AI_Request_Log_Schema {
	// Schema management necessarily uses direct queries against the dedicated log table.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

	/**
	 * Database table name (without prefix).
	 */
	public const TABLE_NAME = 'wpai_request_logs';

	/**
	 * Option key storing the synchronized log table schema version.
	 */
	private const SCHEMA_VERSION_OPTION = 'wpai_request_logs_schema_version';

	/**
	 * Current log table schema version.
	 */
	private const SCHEMA_VERSION = '2';

	/**
	 * Whether each log table (keyed by name) has the `source` column.
	 *
	 * @since x.x.x
	 *
	 * @var array<string, bool>
	 */
	private static array $source_column_cache = array();

	/**
	 * Ensures the request log table matches the current schema version.
	 *
	 * @since 1.0.0
	 */
	public function maybe_upgrade_table(): void {
		if ( self::SCHEMA_VERSION === get_option( self::SCHEMA_VERSION_OPTION, '' ) ) {
			return;
		}

		$this->maybe_create_table();

		// Leave the version unchanged when the table or the `source` column is missing so the upgrade is retried.
		if ( ! $this->table_exists() || ! $this->has_source_column( true ) ) {
			return;
		}

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Creates the database table if needed.
	 *
	 * @since 1.0.0
	 */
	public function maybe_create_table(): void {
		if ( ! $this->table_exists() ) {
			$this->create_table();
			return;
		}

		$this->maybe_add_columns();
		$this->maybe_add_indexes();
	}

	/**
	 * Returns the full table name with prefix.
	 *
	 * @since 1.0.0
	 *
	 * @return string The prefixed table name.
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Checks whether the current site's log table has the `source` column.
	 *
	 * @since x.x.x
	 *
	 * @param bool $force_refresh Whether to re-check the database instead of using the cached result.
	 * @return bool True when the column exists.
	 */
	public function has_source_column( bool $force_refresh = false ): bool {
		global $wpdb;

		$table_name = $this->get_table_name();

		if ( ! $force_refresh && isset( self::$source_column_cache[ $table_name ] ) ) {
			return self::$source_column_cache[ $table_name ];
		}

		$wpdb->suppress_errors( true );
		$column = $wpdb->get_var( "SHOW COLUMNS FROM {$table_name} LIKE 'source'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( false );

		self::$source_column_cache[ $table_name ] = 'source' === $column;

		return self::$source_column_cache[ $table_name ];
	}

	/**
	 * Returns the `source` column definition.
	 *
	 * Uses a binary collation so sources that differ only by case or accents are not merged.
	 *
	 * @since x.x.x
	 *
	 * @return string Column definition for CREATE TABLE and ALTER TABLE statements.
	 */
	private function get_source_column_definition(): string {
		global $wpdb;

		$collation = in_array( $wpdb->charset, array( 'utf8mb4', 'utf8' ), true )
			? " CHARACTER SET {$wpdb->charset} COLLATE {$wpdb->charset}_bin"
			: '';

		return "source VARCHAR(191){$collation} DEFAULT NULL";
	}

	/**
	 * Checks whether the request log table already exists.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the table exists.
	 */
	private function table_exists(): bool {
		global $wpdb;

		$table_name     = $this->get_table_name();
		$existing_table = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_name
			)
		);

		return $existing_table === $table_name;
	}

	/**
	 * Creates the logs database table.
	 *
	 * @since 1.0.0
	 */
	private function create_table(): void {
		global $wpdb;

		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$source_column   = $this->get_source_column_definition();

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
			status VARCHAR(32) NOT NULL,
			error_message TEXT DEFAULT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			{$source_column},
			context LONGTEXT DEFAULT NULL,
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
			INDEX idx_timestamp_provider (timestamp, provider),
			INDEX idx_timestamp_source (timestamp, source)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$this->maybe_add_columns();
		$this->maybe_add_indexes();
	}

	/**
	 * Adds missing columns to existing tables.
	 *
	 * @since 1.0.0
	 */
	private function maybe_add_columns(): void {
		global $wpdb;

		$table_name = $this->get_table_name();

		$existing_columns = array();
		$columns          = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $columns ) {
			foreach ( $columns as $column ) {
				$existing_columns[ $column['Field'] ] = true;
			}
		}

		if ( ! isset( $existing_columns['request_preview'] ) ) {
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN request_preview TEXT DEFAULT NULL AFTER context" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! isset( $existing_columns['response_preview'] ) ) {
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN response_preview TEXT DEFAULT NULL AFTER request_preview" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( isset( $existing_columns['source'] ) ) {
			return;
		}

		// Add the column and its index in one statement so large tables are rebuilt only once.
		$source_column = $this->get_source_column_definition();
		$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN {$source_column} AFTER user_id, ADD INDEX idx_timestamp_source (timestamp, source)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Adds missing indexes to existing tables.
	 *
	 * @since 1.0.0
	 */
	private function maybe_add_indexes(): void {
		global $wpdb;

		$table_name = $this->get_table_name();

		$existing_indexes = array();
		$indexes          = $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $indexes ) {
			foreach ( $indexes as $index ) {
				$existing_indexes[ $index['Key_name'] ] = true;
			}
		}

		$indexes_to_add = array(
			'idx_provider'              => "ALTER TABLE {$table_name} ADD INDEX idx_provider (provider)",
			'idx_operation'             => "ALTER TABLE {$table_name} ADD INDEX idx_operation (operation(191))",
			'idx_timestamp_type_status' => "ALTER TABLE {$table_name} ADD INDEX idx_timestamp_type_status (timestamp, type, status)",
			'idx_timestamp_provider'    => "ALTER TABLE {$table_name} ADD INDEX idx_timestamp_provider (timestamp, provider)",
			'idx_timestamp_source'      => "ALTER TABLE {$table_name} ADD INDEX idx_timestamp_source (timestamp, source)",
		);

		foreach ( $indexes_to_add as $index_name => $sql ) {
			if ( isset( $existing_indexes[ $index_name ] ) ) {
				continue;
			}

			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$this->maybe_add_fulltext_index( $existing_indexes );
	}

	/**
	 * Adds FULLTEXT index for search if MySQL supports it.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, bool> $existing_indexes Map of existing index names.
	 */
	private function maybe_add_fulltext_index( array $existing_indexes ): void {
		if ( isset( $existing_indexes['ft_search'] ) ) {
			return;
		}

		global $wpdb;

		$table_name    = $this->get_table_name();
		$mysql_version = $wpdb->get_var( 'SELECT VERSION()' );

		if ( ! version_compare( $mysql_version, '5.6', '>=' ) ) {
			return;
		}

		$wpdb->suppress_errors( true );
		$wpdb->query( "ALTER TABLE {$table_name} ADD FULLTEXT INDEX ft_search (operation, request_preview, response_preview)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( false );
	}

	/**
	 * Checks if the FULLTEXT search index exists on the table.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if FULLTEXT index exists.
	 */
	public function has_fulltext_index(): bool {
		static $has_index = null;

		if ( null !== $has_index ) {
			return $has_index;
		}

		global $wpdb;

		$table_name = $this->get_table_name();

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.statistics
				WHERE table_schema = %s
				AND table_name = %s
				AND index_name = 'ft_search'
				AND index_type = 'FULLTEXT'",
				$wpdb->dbname,
				$table_name
			)
		);

		$has_index = (int) $result > 0;

		return $has_index;
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

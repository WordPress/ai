<?php
/**
 * Database schema for queued AI jobs.
 *
 * @package WordPress\AI\Jobs
 */

declare( strict_types=1 );

namespace WordPress\AI\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the jobs table.
 *
 * @since 1.6.0
 */
class Job_Schema {
	// Schema management necessarily uses direct queries against the dedicated jobs table.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

	/**
	 * Database table name, without the prefix.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const TABLE_NAME = 'wpai_jobs';

	/**
	 * Option holding the installed schema version.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const SCHEMA_VERSION_OPTION = 'wpai_jobs_schema_version';

	/**
	 * Current schema version.
	 *
	 * @var string
	 */
	private const SCHEMA_VERSION = '1';

	/**
	 * Ensures the jobs table exists at the current schema version.
	 *
	 * @since 1.6.0
	 */
	public function maybe_upgrade_table(): void {
		if ( $this->is_version_current() && $this->table_exists() ) {
			return;
		}

		$this->create_table();

		if ( ! $this->table_exists() ) {
			return;
		}

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Returns whether the stored schema version is current.
	 *
	 * @since 1.6.0
	 *
	 * @return bool True when current.
	 */
	public function is_version_current(): bool {
		return self::SCHEMA_VERSION === get_option( self::SCHEMA_VERSION_OPTION, '' );
	}

	/**
	 * Returns the prefixed table name.
	 *
	 * @since 1.6.0
	 *
	 * @return string The table name.
	 */
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Returns whether the jobs table exists.
	 *
	 * @since 1.6.0
	 *
	 * @return bool True when it exists.
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table_name = $this->get_table_name();

		return $table_name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
	}

	/**
	 * Drops the table and forgets the schema version.
	 *
	 * @since 1.6.0
	 */
	public function drop_table(): void {
		global $wpdb;

		$table_name = $this->get_table_name();

		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( self::SCHEMA_VERSION_OPTION );
	}

	/**
	 * Creates the table, or brings an existing one up to the current definition.
	 *
	 * @since 1.6.0
	 */
	private function create_table(): void {
		global $wpdb;

		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id VARCHAR(64) NOT NULL,
			type VARCHAR(191) NOT NULL,
			args LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'enqueued',
			result LONGTEXT NULL,
			error TEXT NULL,
			error_code VARCHAR(100) NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			idempotency_key VARCHAR(191) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			started_at DATETIME NULL,
			finished_at DATETIME NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_job_id (job_id),
			KEY idx_status_created (status, created_at),
			KEY idx_user (user_id),
			KEY idx_expires (expires_at),
			KEY idx_idempotency (idempotency_key)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}

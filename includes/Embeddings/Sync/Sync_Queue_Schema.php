<?php
/**
 * Manages the database schema for the embedding sync queue.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Handles creation of the embedding sync queue table.
 *
 * @since x.x.x
 */
class Sync_Queue_Schema {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

	/**
	 * Database table name (without prefix).
	 *
	 * @since x.x.x
	 */
	public const TABLE_NAME = 'wpai_embedding_queue';

	/**
	 * Option key storing the synchronized schema version.
	 *
	 * @since x.x.x
	 */
	public const SCHEMA_VERSION_OPTION = 'wpai_embedding_queue_schema_version';

	/**
	 * Current schema version.
	 */
	private const SCHEMA_VERSION = '1';

	/**
	 * Ensures the queue table exists at the current schema version.
	 *
	 * @since x.x.x
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
	 * Checks whether the stored schema version is the current one.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the stored version is current.
	 */
	public function is_version_current(): bool {
		return self::SCHEMA_VERSION === get_option( self::SCHEMA_VERSION_OPTION, '' );
	}

	/**
	 * Returns the full table name with prefix.
	 *
	 * @since x.x.x
	 *
	 * @return string The prefixed table name.
	 */
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Checks whether the queue table exists.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the table exists.
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table_name = $this->get_table_name();

		return $table_name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
	}

	/**
	 * Drops the table and forgets the schema version.
	 *
	 * @since x.x.x
	 */
	public function drop_table(): void {
		global $wpdb;

		$table_name = $this->get_table_name();

		$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( self::SCHEMA_VERSION_OPTION );
	}

	/**
	 * Creates the queue table, or brings an existing one up to the current definition.
	 *
	 * @since x.x.x
	 */
	private function create_table(): void {
		global $wpdb;

		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			object_type VARCHAR(32) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			version INT UNSIGNED NOT NULL DEFAULT 1,
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			available_at DATETIME NOT NULL,
			last_error TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			UNIQUE KEY uniq_object (object_type, object_id),
			KEY idx_due (status, available_at, id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}

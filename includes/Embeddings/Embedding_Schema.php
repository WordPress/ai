<?php
/**
 * Manages the database schema for stored embeddings.
 *
 * @package WordPress\AI\Embeddings
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings;

defined( 'ABSPATH' ) || exit;

/**
 * Handles creation and migration of the embeddings table.
 *
 * The table is portable: it uses only column types available on every MySQL and MariaDB version
 * WordPress supports, with vectors stored as packed float32 bytes (see {@see Vector_Codec}).
 *
 * @since x.x.x
 */
class Embedding_Schema {
	// Schema management necessarily uses direct queries against the dedicated embeddings table.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

	/**
	 * Database table name (without prefix).
	 */
	public const TABLE_NAME = 'wpai_embeddings';

	/**
	 * Option key storing the synchronized schema version.
	 */
	public const SCHEMA_VERSION_OPTION = 'wpai_embeddings_schema_version';

	/**
	 * Current schema version.
	 */
	private const SCHEMA_VERSION = '1';

	/**
	 * Ensures the embeddings table matches the current schema version.
	 *
	 * @since x.x.x
	 */
	public function maybe_upgrade_table(): void {
		if (
			self::SCHEMA_VERSION === get_option( self::SCHEMA_VERSION_OPTION, '' ) &&
			$this->table_exists()
		) {
			return;
		}

		$this->maybe_create_table();

		if ( ! $this->table_exists() ) {
			return;
		}

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Creates the database table if needed.
	 *
	 * @since x.x.x
	 */
	public function maybe_create_table(): void {
		if ( $this->table_exists() ) {
			return;
		}

		$this->create_table();
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
	 * Checks whether the embeddings table exists.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when the table exists.
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table_name     = $this->get_table_name();
		$existing_table = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $table_name )
			)
		);

		return $existing_table === $table_name;
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
	 * Creates the embeddings table.
	 *
	 * One row per `(object_type, object_id, provider, model, chunk_index)` — the unique key. Every
	 * other column is an attribute of that row and is refreshed when it is re-indexed, so
	 * re-embedding an object replaces its vector rather than accumulating a second row.
	 *
	 * `embedding` holds `dimensions * 4` bytes of little-endian float32 values. `embedding_coarse`
	 * holds a binary quantization code, one bit per component, for the first pass of a two-phase
	 * similarity search.
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
			chunk_index INT UNSIGNED NOT NULL DEFAULT 0,
			provider VARCHAR(64) NOT NULL,
			model VARCHAR(128) NOT NULL,
			object_subtype VARCHAR(32) NOT NULL DEFAULT '',
			dimensions INT UNSIGNED NOT NULL,
			embedding MEDIUMBLOB NOT NULL,
			embedding_norm DOUBLE NOT NULL,
			embedding_coarse VARBINARY(512) NULL,
			content_hash VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			UNIQUE KEY uniq_object_model_chunk (object_type, object_id, provider, model, chunk_index),
			KEY idx_provider_model (provider, model),
			KEY idx_object (object_type, object_id),
			KEY idx_content_hash (content_hash)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

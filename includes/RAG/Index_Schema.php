<?php
/**
 * MariaDB vector index schema for RAG search.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

defined( 'ABSPATH' ) || exit;

/**
 * Handles database schema management for the RAG index table.
 *
 * @since 1.1.0
 */
class Index_Schema {
	// Schema management necessarily uses direct queries against the dedicated RAG table.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

	/**
	 * Database table name without prefix.
	 */
	public const TABLE_NAME = 'wpai_rag_index';

	/**
	 * Option key storing the schema version.
	 */
	private const SCHEMA_VERSION_OPTION = 'wpai_rag_index_schema_version';

	/**
	 * Current schema version.
	 */
	private const SCHEMA_VERSION = '1';

	/**
	 * Ensures the table exists and has the required indexes.
	 *
	 * @since 1.1.0
	 */
	public function maybe_upgrade_table(): void {
		if ( self::SCHEMA_VERSION === get_option( self::SCHEMA_VERSION_OPTION, '' ) && $this->table_exists() ) {
			return;
		}

		$this->maybe_create_table();

		if ( ! $this->table_exists() ) {
			return;
		}

		update_option( self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Creates the table if needed and adds missing indexes.
	 *
	 * @since 1.1.0
	 */
	public function maybe_create_table(): void {
		if ( ! $this->table_exists() ) {
			$this->create_table();
		}

		if ( ! $this->table_exists() ) {
			return;
		}

		$this->maybe_add_indexes();
	}

	/**
	 * Returns the full table name with prefix.
	 *
	 * @since 1.1.0
	 *
	 * @return string Prefixed table name.
	 */
	public function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Checks whether the table exists.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when table exists.
	 */
	public function table_exists(): bool {
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
	 * Creates the RAG index table.
	 *
	 * @since 1.1.0
	 */
	private function create_table(): void {
		global $wpdb;

		$table_name      = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			post_id BIGINT UNSIGNED NOT NULL,
			post_type VARCHAR(64) NOT NULL,
			post_status VARCHAR(20) NOT NULL,
			chunk_id CHAR(36) NOT NULL,
			chunk_index INT UNSIGNED NOT NULL,
			chunk_offset INT UNSIGNED NOT NULL DEFAULT 0,
			anchor VARCHAR(191) DEFAULT NULL,
			title TEXT DEFAULT NULL,
			permalink TEXT DEFAULT NULL,
			content MEDIUMTEXT NOT NULL,
			content_hash CHAR(64) NOT NULL,
			embedding VECTOR(1536) NOT NULL,
			embedding_model VARCHAR(64) NOT NULL,
			embedding_dimensions SMALLINT UNSIGNED NOT NULL DEFAULT 1536,
			indexed_at DATETIME NOT NULL,
			UNIQUE KEY uniq_post_chunk (post_id, chunk_id),
			INDEX idx_post_id (post_id),
			INDEX idx_post_type_status (post_type, post_status),
			INDEX idx_embedding_model (embedding_model),
			INDEX idx_indexed_at (indexed_at)
		) {$charset_collate};";

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Adds missing indexes.
	 *
	 * @since 1.1.0
	 */
	private function maybe_add_indexes(): void {
		global $wpdb;

		$table_name       = $this->get_table_name();
		$existing_indexes = $this->get_existing_indexes();

		$indexes_to_add = array(
			'idx_post_id'          => "CREATE INDEX idx_post_id ON {$table_name} (post_id)",
			'idx_post_type_status' => "CREATE INDEX idx_post_type_status ON {$table_name} (post_type, post_status)",
			'idx_embedding_model'  => "CREATE INDEX idx_embedding_model ON {$table_name} (embedding_model)",
			'idx_indexed_at'       => "CREATE INDEX idx_indexed_at ON {$table_name} (indexed_at)",
		);

		foreach ( $indexes_to_add as $index_name => $sql ) {
			if ( isset( $existing_indexes[ $index_name ] ) ) {
				continue;
			}

			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( isset( $existing_indexes['idx_embedding'] ) ) {
			return;
		}

		$wpdb->suppress_errors( true );
		$wpdb->query( "CREATE VECTOR INDEX idx_embedding ON {$table_name} (embedding) M=8 DISTANCE=COSINE" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( false );
	}

	/**
	 * Returns existing indexes keyed by name.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, bool> Existing index names.
	 */
	private function get_existing_indexes(): array {
		global $wpdb;

		$table_name       = $this->get_table_name();
		$existing_indexes = array();
		$indexes          = $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $indexes ) {
			foreach ( $indexes as $index ) {
				if ( ! isset( $index['Key_name'] ) ) {
					continue;
				}

				$existing_indexes[ (string) $index['Key_name'] ] = true;
			}
		}

		return $existing_indexes;
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

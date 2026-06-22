<?php
/**
 * MariaDB vector RAG backend.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

defined( 'ABSPATH' ) || exit;

/**
 * Owns MariaDB vector index availability and storage.
 *
 * @since 1.1.0
 */
class MariaDB_Index_Backend implements Index_Backend_Interface {
	/**
	 * Required MariaDB version for VECTOR INDEX support.
	 */
	private const MINIMUM_MARIADB_VERSION = '11.8.0';

	/**
	 * Schema manager.
	 *
	 * @var \WordPress\AI\RAG\MariaDB_Index_Schema
	 */
	private MariaDB_Index_Schema $schema;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AI\RAG\MariaDB_Index_Schema|null $schema Schema manager.
	 */
	public function __construct( ?MariaDB_Index_Schema $schema = null ) {
		$this->schema = $schema ?? new MariaDB_Index_Schema();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return Availability::BACKEND_MARIADB;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Optimal search method backed by MariaDB', 'ai' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return $this->is_mariadb_vector_index_supported();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_unavailable_reason(): string {
		return __( 'MariaDB 11.8 or newer is required for native vector indexes.', 'ai' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_repository( int $dimensions ): Index_Repository_Interface {
		return new MariaDB_Index_Repository( $this->schema, $dimensions );
	}

	/**
	 * {@inheritDoc}
	 */
	public function ensure_storage(): bool {
		$this->schema->maybe_upgrade_table();

		return $this->schema->table_exists();
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_index_data(): bool {
		return $this->schema->has_index_data();
	}

	/**
	 * {@inheritDoc}
	 */
	public function cleanup(): void {
		$this->schema->cleanup();
	}

	/**
	 * Checks whether the database is MariaDB 11.8+.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when VECTOR INDEX should be available.
	 */
	public function is_mariadb_vector_index_supported(): bool {
		$version = $this->get_database_version();

		return $this->is_supported_mariadb_version( $version );
	}

	/**
	 * Checks a raw database version string.
	 *
	 * @since 1.1.0
	 *
	 * @param string $version Raw SELECT VERSION() value.
	 * @return bool True when supported.
	 */
	public function is_supported_mariadb_version( string $version ): bool {
		if ( false === stripos( $version, 'mariadb' ) ) {
			return false;
		}

		$version = preg_replace( '/^5\.5\.5-/', '', $version ) ?? $version;

		if ( ! preg_match( '/(\d+\.\d+(?:\.\d+)?)/', $version, $matches ) ) {
			return false;
		}

		return version_compare( $matches[1], self::MINIMUM_MARIADB_VERSION, '>=' );
	}

	/**
	 * Reads the database version string.
	 *
	 * @since 1.1.0
	 *
	 * @return string Version string.
	 */
	protected function get_database_version(): string {
		global $wpdb;

		$version = $wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_string( $version ) ? $version : '';
	}
}

<?php
/**
 * Availability checks for MariaDB vector RAG search.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

use Throwable;
use function WordPress\AI\has_connector_authentication;

defined( 'ABSPATH' ) || exit;

/**
 * Determines whether native MariaDB vector search can be used.
 *
 * @since 1.1.0
 */
class Availability {
	/**
	 * OpenAI connector identifier.
	 */
	public const OPENAI_CONNECTOR_ID = 'openai';

	/**
	 * MariaDB vector index backend.
	 */
	public const BACKEND_MARIADB = 'mariadb';

	/**
	 * Compact in-memory exact-scan backend.
	 */
	public const BACKEND_MEMORY = 'memory';

	/**
	 * Option key storing the preferred backend.
	 */
	public const BACKEND_OPTION = 'wpai_feature_rag-search_field_backend';

	/**
	 * Required MariaDB version for VECTOR INDEX support.
	 */
	private const MINIMUM_MARIADB_VERSION = '11.8.0';

	/**
	 * Required embedding model.
	 */
	private const EMBEDDING_MODEL = 'text-embedding-3-small';

	/**
	 * Cached availability state.
	 *
	 * @var bool|null
	 */
	private ?bool $available = null;

	/**
	 * Cached unavailable reason.
	 *
	 * @var string
	 */
	private string $unavailable_reason = '';

	/**
	 * Checks whether the feature can run on this site.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when all runtime requirements are met.
	 */
	public function is_available(): bool {
		if ( null !== $this->available ) {
			return $this->available;
		}

		if ( empty( $this->get_available_index_backends() ) ) {
			$this->unavailable_reason = __( 'MariaDB 11.8 or newer is required when the compact memory fallback is disabled.', 'ai' );
			$this->available          = false;
			return false;
		}

		if ( ! $this->has_openai_connector_authentication() ) {
			$this->unavailable_reason = __( 'The OpenAI connector must be active and authenticated to generate embeddings.', 'ai' );
			$this->available          = false;
			return false;
		}

		if ( ! $this->supports_openai_embeddings() ) {
			$this->unavailable_reason = __( 'RAG Search currently supports OpenAI text-embedding-3-small embeddings only.', 'ai' );
			$this->available          = false;
			return false;
		}

		$this->available = true;
		return true;
	}

	/**
	 * Returns the reason the feature is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Human-readable reason.
	 */
	public function get_unavailable_reason(): string {
		if ( null === $this->available ) {
			$this->is_available();
		}

		return $this->unavailable_reason;
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
	 * Returns the active index backend.
	 *
	 * @since 1.1.0
	 *
	 * @return string Backend identifier.
	 */
	public function get_index_backend(): string {
		$available = $this->get_available_index_backends();
		$preferred = $this->get_preferred_index_backend();

		if ( in_array( $preferred, $available, true ) ) {
			return $preferred;
		}

		return $this->get_default_index_backend();
	}

	/**
	 * Returns available concrete index backends.
	 *
	 * @since 1.1.0
	 *
	 * @return list<string> Backend identifiers.
	 */
	public function get_available_index_backends(): array {
		$backends = array();

		if ( $this->is_mariadb_vector_index_supported() ) {
			$backends[] = self::BACKEND_MARIADB;
		}

		if ( $this->is_memory_fallback_enabled() ) {
			$backends[] = self::BACKEND_MEMORY;
		}

		return $backends;
	}

	/**
	 * Returns the default concrete index backend for this environment.
	 *
	 * @since 1.1.0
	 *
	 * @return string Backend identifier.
	 */
	public function get_default_index_backend(): string {
		$available = $this->get_available_index_backends();

		if ( in_array( self::BACKEND_MARIADB, $available, true ) ) {
			return self::BACKEND_MARIADB;
		}

		return $available[0] ?? self::BACKEND_MARIADB;
	}

	/**
	 * Returns a human-readable backend label.
	 *
	 * @since 1.1.0
	 *
	 * @return string Backend label.
	 */
	public function get_index_backend_label(): string {
		if ( self::BACKEND_MEMORY === $this->get_index_backend() ) {
			return __( 'Fallback in-memory search backed by PHP', 'ai' );
		}

		return __( 'Optimal search method backed by MariaDB', 'ai' );
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
	 * Returns the configured embedding model.
	 *
	 * @since 1.1.0
	 *
	 * @return string Embedding model ID.
	 */
	public function get_embedding_model(): string {
		/**
		 * Filters the OpenAI embedding model used for MariaDB RAG indexing.
		 *
		 * The table schema in this release is fixed to 1536 dimensions.
		 *
		 * @since 1.1.0
		 *
		 * @param string $model OpenAI embedding model.
		 */
		return (string) apply_filters( 'wpai_rag_embedding_model', self::EMBEDDING_MODEL );
	}

	/**
	 * Returns the embedding vector dimension count.
	 *
	 * @since 1.1.0
	 *
	 * @return int Dimension count.
	 */
	public function get_embedding_dimensions(): int {
		return 1536;
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

	/**
	 * Checks that the OpenAI connector is active and has credentials.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when OpenAI can be authenticated.
	 */
	private function has_openai_connector_authentication(): bool {
		try {
			return function_exists( 'wp_is_connector_registered' )
				&& wp_is_connector_registered( self::OPENAI_CONNECTOR_ID )
				&& has_connector_authentication( self::OPENAI_CONNECTOR_ID );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Checks whether the configured OpenAI embedding model matches the fixed schema.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when the configured model uses the expected dimensions.
	 */
	private function supports_openai_embeddings(): bool {
		return self::EMBEDDING_MODEL === $this->get_embedding_model();
	}

	/**
	 * Returns the preferred backend setting.
	 *
	 * @since 1.1.0
	 *
	 * @return string Preferred backend.
	 */
	private function get_preferred_index_backend(): string {
		$preferred = get_option( self::BACKEND_OPTION, $this->get_default_index_backend() );
		if ( ! is_string( $preferred ) ) {
			return $this->get_default_index_backend();
		}

		if ( in_array( $preferred, array( self::BACKEND_MARIADB, self::BACKEND_MEMORY ), true ) ) {
			return $preferred;
		}

		return $this->get_default_index_backend();
	}

	/**
	 * Checks whether the compact memory fallback is enabled.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when enabled.
	 */
	private function is_memory_fallback_enabled(): bool {
		/**
		 * Filters whether RAG should use compact in-memory exact scan when MariaDB vector indexes are unavailable.
		 *
		 * @since 1.1.0
		 *
		 * @param bool $enabled Whether the fallback backend is enabled.
		 */
		return (bool) apply_filters( 'wpai_rag_memory_fallback_enabled', true );
	}
}

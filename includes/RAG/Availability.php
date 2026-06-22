<?php
/**
 * Availability coordinator for RAG search.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

defined( 'ABSPATH' ) || exit;

/**
 * Determines whether RAG search can run by coordinating backends and embeddings.
 *
 * @since 1.1.0
 */
class Availability {
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
	 * Index backends keyed by ID.
	 *
	 * @var array<string, \WordPress\AI\RAG\Index_Backend_Interface>
	 */
	private array $backends;

	/**
	 * Embedding client.
	 *
	 * @var \WordPress\AI\RAG\OpenAI_Embedding_Client
	 */
	private OpenAI_Embedding_Client $embedding_client;

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
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param list<\WordPress\AI\RAG\Index_Backend_Interface>|null $backends         Index backends.
	 * @param \WordPress\AI\RAG\OpenAI_Embedding_Client|null       $embedding_client Embedding client.
	 */
	public function __construct( ?array $backends = null, ?OpenAI_Embedding_Client $embedding_client = null ) {
		$this->embedding_client = $embedding_client ?? new OpenAI_Embedding_Client();
		$this->backends         = $this->normalize_backends(
			$backends ?? array(
				new MariaDB_Index_Backend(),
				new Memory_Index_Backend(),
			)
		);
	}

	/**
	 * Checks whether the feature can run on this site.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when runtime requirements are met.
	 */
	public function is_available(): bool {
		if ( null !== $this->available ) {
			return $this->available;
		}

		if ( empty( $this->get_available_index_backends() ) ) {
			$this->unavailable_reason = $this->get_backend_unavailable_reason();
			$this->available          = false;
			return false;
		}

		if ( ! $this->embedding_client->is_available() ) {
			$this->unavailable_reason = $this->embedding_client->get_unavailable_reason();
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
		$backend = $this->backends[ self::BACKEND_MARIADB ] ?? null;

		return $backend instanceof MariaDB_Index_Backend && $backend->is_mariadb_vector_index_supported();
	}

	/**
	 * Checks a raw MariaDB version string.
	 *
	 * @since 1.1.0
	 *
	 * @param string $version Raw SELECT VERSION() value.
	 * @return bool True when supported.
	 */
	public function is_supported_mariadb_version( string $version ): bool {
		$backend = $this->backends[ self::BACKEND_MARIADB ] ?? new MariaDB_Index_Backend();

		return $backend instanceof MariaDB_Index_Backend && $backend->is_supported_mariadb_version( $version );
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
	 * Returns the active backend object.
	 *
	 * @since 1.1.0
	 *
	 * @return \WordPress\AI\RAG\Index_Backend_Interface Backend.
	 */
	public function get_index_backend_instance(): Index_Backend_Interface {
		$backend_id = $this->get_index_backend();

		return $this->backends[ $backend_id ] ?? $this->backends[ self::BACKEND_MARIADB ];
	}

	/**
	 * Returns available concrete index backends.
	 *
	 * @since 1.1.0
	 *
	 * @return list<string> Backend identifiers.
	 */
	public function get_available_index_backends(): array {
		$available = array();

		foreach ( $this->backends as $backend ) {
			if ( ! $backend->is_available() ) {
				continue;
			}

			$available[] = $backend->get_id();
		}

		return $available;
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
		return $this->get_index_backend_instance()->get_label();
	}

	/**
	 * Returns the configured embedding model.
	 *
	 * @since 1.1.0
	 *
	 * @return string Embedding model ID.
	 */
	public function get_embedding_model(): string {
		return $this->embedding_client->get_model();
	}

	/**
	 * Returns the embedding vector dimension count.
	 *
	 * @since 1.1.0
	 *
	 * @return int Dimension count.
	 */
	public function get_embedding_dimensions(): int {
		return $this->embedding_client->get_dimensions();
	}

	/**
	 * Creates the active repository.
	 *
	 * @since 1.1.0
	 *
	 * @return \WordPress\AI\RAG\Index_Repository_Interface Repository.
	 */
	public function create_index_repository(): Index_Repository_Interface {
		return $this->get_index_backend_instance()->create_repository( $this->get_embedding_dimensions() );
	}

	/**
	 * Ensures active backend storage is ready.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when ready.
	 */
	public function ensure_index_storage(): bool {
		return $this->get_index_backend_instance()->ensure_storage();
	}

	/**
	 * Checks whether any backend has stored data.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when RAG data exists.
	 */
	public function has_index_data(): bool {
		foreach ( $this->backends as $backend ) {
			if ( $backend->has_index_data() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Deletes backend-specific RAG index data.
	 *
	 * @since 1.1.0
	 */
	public function cleanup_index_backends(): void {
		foreach ( $this->backends as $backend ) {
			$backend->cleanup();
		}
	}

	/**
	 * Returns available backend labels keyed by backend ID.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, string> Labels.
	 */
	public function get_index_backend_labels(): array {
		$labels = array();

		foreach ( $this->backends as $backend ) {
			$labels[ $backend->get_id() ] = $backend->get_label();
		}

		return $labels;
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

		if ( isset( $this->backends[ $preferred ] ) ) {
			return $preferred;
		}

		return $this->get_default_index_backend();
	}

	/**
	 * Normalizes backend list.
	 *
	 * @since 1.1.0
	 *
	 * @param list<\WordPress\AI\RAG\Index_Backend_Interface> $backends Backends.
	 * @return array<string, \WordPress\AI\RAG\Index_Backend_Interface>
	 */
	private function normalize_backends( array $backends ): array {
		$normalized = array();

		foreach ( $backends as $backend ) {
			$normalized[ $backend->get_id() ] = $backend;
		}

		if ( ! isset( $normalized[ self::BACKEND_MARIADB ] ) ) {
			$normalized[ self::BACKEND_MARIADB ] = new MariaDB_Index_Backend();
		}

		return $normalized;
	}

	/**
	 * Builds an unavailable reason from backend state.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason.
	 */
	private function get_backend_unavailable_reason(): string {
		$reasons = array();

		foreach ( $this->backends as $backend ) {
			$reason = $backend->get_unavailable_reason();
			if ( '' === $reason ) {
				continue;
			}

			$reasons[] = $reason;
		}

		return empty( $reasons )
			? __( 'No RAG index backend is available.', 'ai' )
			: implode( ' ', array_values( array_unique( $reasons ) ) );
	}
}

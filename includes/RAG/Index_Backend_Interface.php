<?php
/**
 * Repository backend contract for RAG search.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

defined( 'ABSPATH' ) || exit;

/**
 * Describes one concrete RAG index backend.
 *
 * @since 1.1.0
 */
interface Index_Backend_Interface {
	/**
	 * Returns the backend identifier.
	 *
	 * @since 1.1.0
	 *
	 * @return string Backend identifier.
	 */
	public function get_id(): string;

	/**
	 * Returns the backend label.
	 *
	 * @since 1.1.0
	 *
	 * @return string Backend label.
	 */
	public function get_label(): string;

	/**
	 * Returns whether the backend can be used in this environment.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when available.
	 */
	public function is_available(): bool;

	/**
	 * Returns a human-readable unavailable reason.
	 *
	 * @since 1.1.0
	 *
	 * @return string Unavailable reason.
	 */
	public function get_unavailable_reason(): string;

	/**
	 * Creates the repository for this backend.
	 *
	 * @since 1.1.0
	 *
	 * @param int $dimensions Embedding dimensions.
	 * @return \WordPress\AI\RAG\Index_Repository_Interface Repository.
	 */
	public function create_repository( int $dimensions ): Index_Repository_Interface;

	/**
	 * Ensures persistent storage exists.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when storage is ready.
	 */
	public function ensure_storage(): bool;

	/**
	 * Checks whether this backend has stored RAG index data.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when data or storage exists.
	 */
	public function has_index_data(): bool;

	/**
	 * Deletes backend-specific RAG index data.
	 *
	 * @since 1.1.0
	 */
	public function cleanup(): void;
}

<?php
/**
 * Compact in-memory RAG backend.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

defined( 'ABSPATH' ) || exit;

/**
 * Owns compact memory fallback availability and storage.
 *
 * @since 1.1.0
 */
class Memory_Index_Backend implements Index_Backend_Interface {
	// Direct queries are used for cleanup/status over the backend-owned meta key.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return Availability::BACKEND_MEMORY;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Fallback in-memory search backed by PHP', 'ai' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		/**
		 * Filters whether RAG should use compact in-memory exact scan when MariaDB vector indexes are unavailable.
		 *
		 * @since 1.1.0
		 *
		 * @param bool $enabled Whether the fallback backend is enabled.
		 */
		return (bool) apply_filters( 'wpai_rag_memory_fallback_enabled', true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_unavailable_reason(): string {
		return __( 'The compact memory fallback is disabled.', 'ai' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function create_repository( int $dimensions ): Index_Repository_Interface {
		return new Memory_Index_Repository( $dimensions );
	}

	/**
	 * {@inheritDoc}
	 */
	public function ensure_storage(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_index_data(): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Memory_Index_Repository::META_CHUNKS
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function cleanup(): void {
		global $wpdb;

		$wpdb->delete(
			$wpdb->postmeta,
			array( 'meta_key' => Memory_Index_Repository::META_CHUNKS ),
			array( '%s' )
		);
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

<?php
/**
 * Keeps terms in sync.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

use WP_Term;

use function WordPress\AI\normalize_content;

defined( 'ABSPATH' ) || exit;

/**
 * Term adapter for the sync layer.
 *
 * @since x.x.x
 */
class Term_Source implements Embedding_Source_Interface {

	/**
	 * Object type stored in the embeddings table.
	 *
	 * @since x.x.x
	 */
	public const OBJECT_TYPE = 'term';

	/**
	 * The listener hooks report to.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Change_Listener|null
	 */
	private ?Change_Listener $listener = null;

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_object_type(): string {
		return self::OBJECT_TYPE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function subtype_exists( string $subtype ): bool {
		return taxonomy_exists( $subtype );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_subtype( int $object_id ): ?string {
		$term = get_term( $object_id );

		return $term instanceof WP_Term ? $term->taxonomy : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Every existing term is indexable unless the filter says otherwise.
	 *
	 * @since x.x.x
	 */
	public function is_indexable( int $object_id ): bool {
		$indexable = get_term( $object_id ) instanceof WP_Term;

		/** This filter is documented in includes/Embeddings/Sync/Post_Source.php */
		return (bool) apply_filters( 'wpai_embedding_sync_is_indexable', $indexable, self::OBJECT_TYPE, $object_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_text( int $object_id ): string {
		$term = get_term( $object_id );

		if ( ! $term instanceof WP_Term ) {
			return '';
		}

		$text = trim( normalize_content( $term->name ) . "\n\n" . normalize_content( $term->description ) );

		/**
		 * Filters the text embedded for a term.
		 *
		 * @since x.x.x
		 *
		 * @param string   $text The text: name, a blank line, then the description as plain text.
		 * @param \WP_Term $term The term.
		 */
		return trim( (string) apply_filters( 'wpai_embedding_sync_term_text', $text, $term ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Only the term rows: term meta is not read.
	 *
	 * @since x.x.x
	 */
	public function prime( array $object_ids ): void {
		if ( array() === $object_ids ) {
			return;
		}

		_prime_term_caches( $object_ids, false );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function forget( array $object_ids ): void {
		foreach ( $object_ids as $object_id ) {
			wp_cache_delete( $object_id, 'terms' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_ids_after( int $cursor, array $subtypes, int $limit ): array {
		global $wpdb;

		if ( array() === $subtypes || $limit <= 0 ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $subtypes ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN () list adds one %s per taxonomy.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT term_id FROM {$wpdb->term_taxonomy}
				WHERE term_id > %d AND taxonomy IN ({$placeholders})
				ORDER BY term_id ASC
				LIMIT %d",
				array_merge( array( $cursor ), $subtypes, array( $limit ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_values( array_map( 'intval', is_array( $ids ) ? $ids : array() ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function count_indexable( array $subtypes ): array {
		global $wpdb;

		$counts = array_fill_keys( $subtypes, 0 );

		if ( array() === $subtypes ) {
			return $counts;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $subtypes ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN () list adds one %s per taxonomy.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT taxonomy, COUNT(*) AS total FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ({$placeholders}) GROUP BY taxonomy",
				$subtypes
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['taxonomy'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function register_hooks( Change_Listener $listener ): void {
		$this->listener = $listener;

		add_action( 'created_term', array( $this, 'handle_saved_term' ) );
		add_action( 'edited_term', array( $this, 'handle_saved_term' ) );
		add_action( 'delete_term', array( $this, 'handle_deleted_term' ) );
	}

	/**
	 * Reports a created or edited term to the listener.
	 *
	 * @since x.x.x
	 *
	 * @param int $term_id Term ID.
	 */
	public function handle_saved_term( $term_id ): void {
		if ( null === $this->listener ) {
			return;
		}

		$this->listener->object_changed( self::OBJECT_TYPE, (int) $term_id );
	}

	/**
	 * Reports a deleted term to the listener.
	 *
	 * @since x.x.x
	 *
	 * @param int $term_id Term ID.
	 */
	public function handle_deleted_term( $term_id ): void {
		if ( null === $this->listener ) {
			return;
		}

		$this->listener->object_deleted( self::OBJECT_TYPE, (int) $term_id );
	}
}

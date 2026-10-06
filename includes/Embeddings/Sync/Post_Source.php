<?php
/**
 * Keeps posts in sync.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

use WP_Post;

use function WordPress\AI\normalize_content;

defined( 'ABSPATH' ) || exit;

/**
 * Post adapter for the sync layer.
 *
 * @since x.x.x
 */
class Post_Source implements Embedding_Source_Interface {

	/**
	 * Object type stored in the embeddings table.
	 *
	 * @since x.x.x
	 */
	public const OBJECT_TYPE = 'post';

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
		return post_type_exists( $subtype );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_subtype( int $object_id ): ?string {
		$post_type = get_post_type( $object_id );

		return is_string( $post_type ) && '' !== $post_type ? $post_type : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Published by default, never password-protected,
	 * never a revision or autosave.
	 *
	 * @since x.x.x
	 */
	public function is_indexable( int $object_id ): bool {
		$post = get_post( $object_id );

		$indexable = $post instanceof WP_Post
			&& ! wp_is_post_revision( $post )
			&& ! wp_is_post_autosave( $post )
			&& in_array( $post->post_status, $this->get_indexable_statuses(), true )
			&& '' === $post->post_password;

		/**
		 * Filters whether an object should have synchronized embeddings.
		 *
		 * Returning false for an object that has vectors deletes them on its next change.
		 *
		 * @since x.x.x
		 *
		 * @param bool   $indexable   Whether the object is indexable.
		 * @param string $object_type Object type: `post` or `term`.
		 * @param int    $object_id   Object ID.
		 */
		return (bool) apply_filters( 'wpai_embedding_sync_is_indexable', $indexable, self::OBJECT_TYPE, $object_id );
	}

	/**
	 * Returns the post statuses that are indexable.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> Post statuses.
	 */
	public function get_indexable_statuses(): array {
		/**
		 * Filters the post statuses whose posts get synchronized embeddings.
		 *
		 * Widening this beyond `publish` puts non-public content into similarity results.
		 *
		 * @since x.x.x
		 *
		 * @param list<string> $statuses Post statuses. Default `array( 'publish' )`.
		 */
		$statuses = apply_filters( 'wpai_embedding_sync_indexable_post_statuses', array( 'publish' ) );

		return is_array( $statuses ) ? array_values( array_filter( $statuses, 'is_string' ) ) : array( 'publish' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The title and the raw post content, both normalized to plain text. `the_content` filters are
	 * deliberately not applied: they run shortcodes and third-party code inside a cron request.
	 *
	 * @since x.x.x
	 */
	public function get_text( int $object_id ): string {
		$post = get_post( $object_id );

		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$text = trim( normalize_content( $post->post_title ) . "\n\n" . normalize_content( $post->post_content ) );

		/**
		 * Filters the text embedded for a post.
		 *
		 * @since x.x.x
		 *
		 * @param string   $text The text: title, a blank line, then the content as plain text.
		 * @param \WP_Post $post The post.
		 */
		return trim( (string) apply_filters( 'wpai_embedding_sync_post_text', $text, $post ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	public function get_ids_after( int $cursor, array $subtypes, int $limit ): array {
		global $wpdb;

		$statuses = $this->get_indexable_statuses();

		if ( array() === $subtypes || array() === $statuses || $limit <= 0 ) {
			return array();
		}

		$type_placeholders   = implode( ', ', array_fill( 0, count( $subtypes ), '%s' ) );
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN () lists add one %s per subtype or status.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE ID > %d AND post_type IN ({$type_placeholders}) AND post_status IN ({$status_placeholders}) AND post_password = ''
				ORDER BY ID ASC
				LIMIT %d",
				array_merge( array( $cursor ), $subtypes, $statuses, array( $limit ) )
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

		$counts   = array_fill_keys( $subtypes, 0 );
		$statuses = $this->get_indexable_statuses();

		if ( array() === $subtypes || array() === $statuses ) {
			return $counts;
		}

		$type_placeholders   = implode( ', ', array_fill( 0, count( $subtypes ), '%s' ) );
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN () lists add one %s per subtype or status.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_type, COUNT(*) AS total FROM {$wpdb->posts}
				WHERE post_type IN ({$type_placeholders}) AND post_status IN ({$status_placeholders}) AND post_password = ''
				GROUP BY post_type",
				array_merge( $subtypes, $statuses )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['post_type'] ] = (int) $row['total'];
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

		// After terms and meta are saved, so block-editor saves are complete.
		add_action( 'wp_after_insert_post', array( $this, 'handle_after_insert_post' ), 20, 4 );
		add_action( 'deleted_post', array( $this, 'handle_deleted_post' ) );
	}

	/**
	 * Reports a saved post to the listener.
	 *
	 * @since x.x.x
	 *
	 * @param int           $post_id     Post ID.
	 * @param \WP_Post      $post        Post object.
	 * @param bool          $update      Whether this is an update.
	 * @param \WP_Post|null $post_before The post before the update, or null for a new post.
	 */
	public function handle_after_insert_post( $post_id, $post, $update, $post_before ): void {
		unset( $update );

		if ( null === $this->listener || ! $post instanceof WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$previous_subtype = $post_before instanceof WP_Post ? $post_before->post_type : null;

		$this->listener->object_changed( self::OBJECT_TYPE, (int) $post_id, $previous_subtype );
	}

	/**
	 * Reports a deleted post to the listener.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id Post ID.
	 */
	public function handle_deleted_post( $post_id ): void {
		if ( null === $this->listener ) {
			return;
		}

		$this->listener->object_deleted( self::OBJECT_TYPE, (int) $post_id );
	}
}

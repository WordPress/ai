<?php
/**
 * RAG indexing lifecycle manager.
 *
 * @package WordPress\AI\RAG
 */

declare( strict_types=1 );

namespace WordPress\AI\RAG;

use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates post hooks and cron indexing.
 *
 * @since 1.1.0
 */
class Index_Manager {
	/**
	 * Cron hook.
	 */
	public const CRON_HOOK = 'wpai_rag_index_dirty_posts';

	/**
	 * RAG status post meta key.
	 */
	public const META_STATUS = '_ai_rag_status';

	/**
	 * Last RAG error post meta key.
	 */
	public const META_ERROR = '_ai_rag_error';

	/**
	 * Indexed timestamp post meta key.
	 */
	public const META_INDEXED_AT = '_ai_rag_indexed_at';

	/**
	 * Content hash post meta key.
	 */
	public const META_CONTENT_HASH = '_ai_rag_content_hash';

	/**
	 * Dirty status.
	 */
	public const STATUS_DIRTY = 'dirty';

	/**
	 * Processing status.
	 */
	public const STATUS_PROCESSING = 'processing';

	/**
	 * Clean status.
	 */
	public const STATUS_CLEAN = 'clean';

	/**
	 * Error status.
	 */
	public const STATUS_ERROR = 'error';

	/**
	 * Indexing lock transient.
	 */
	private const LOCK_TRANSIENT = 'wpai_rag_indexing_lock';

	/**
	 * Availability service.
	 *
	 * @var \WordPress\AI\RAG\Availability
	 */
	private Availability $availability;

	/**
	 * Schema manager.
	 *
	 * @var \WordPress\AI\RAG\MariaDB_Index_Schema|null
	 */
	private ?MariaDB_Index_Schema $schema;

	/**
	 * Repository.
	 *
	 * @var \WordPress\AI\RAG\Index_Repository_Interface
	 */
	private Index_Repository_Interface $repository;

	/**
	 * Chunker.
	 *
	 * @var \WordPress\AI\RAG\Post_Chunker
	 */
	private Post_Chunker $chunker;

	/**
	 * Embedding client.
	 *
	 * @var \WordPress\AI\RAG\OpenAI_Embedding_Client
	 */
	private OpenAI_Embedding_Client $embedding_client;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AI\RAG\Availability|null             $availability     Availability service.
	 * @param \WordPress\AI\RAG\MariaDB_Index_Schema|null    $schema           Schema manager.
	 * @param \WordPress\AI\RAG\Index_Repository_Interface|null $repository       Repository.
	 * @param \WordPress\AI\RAG\Post_Chunker|null            $chunker          Chunker.
	 * @param \WordPress\AI\RAG\OpenAI_Embedding_Client|null $embedding_client Embedding client.
	 */
	public function __construct(
		?Availability $availability = null,
		?MariaDB_Index_Schema $schema = null,
		?Index_Repository_Interface $repository = null,
		?Post_Chunker $chunker = null,
		?OpenAI_Embedding_Client $embedding_client = null
	) {
		$this->availability     = $availability ?? new Availability();
		$this->schema           = $schema;
		$this->repository       = $repository ?? $this->create_repository();
		$this->chunker          = $chunker ?? new Post_Chunker();
		$this->embedding_client = $embedding_client ?? new OpenAI_Embedding_Client();
	}

	/**
	 * Registers lifecycle hooks.
	 *
	 * @since 1.1.0
	 */
	public function init(): void {
		if ( ! $this->ensure_index_storage() ) {
			return;
		}

		add_action( 'save_post', array( $this, 'handle_save_post' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'handle_post_status_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'handle_before_delete_post' ) );
		add_action( self::CRON_HOOK, array( $this, 'handle_cron' ) );
	}

	/**
	 * Ensures the RAG index storage exists.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when index storage is ready.
	 */
	public function ensure_index_storage(): bool {
		if ( $this->schema instanceof MariaDB_Index_Schema && Availability::BACKEND_MARIADB === $this->availability->get_index_backend() ) {
			$this->schema->maybe_upgrade_table();

			return $this->schema->table_exists();
		}

		return $this->availability->ensure_index_storage();
	}

	/**
	 * Returns the active repository.
	 *
	 * @since 1.1.0
	 *
	 * @return \WordPress\AI\RAG\Index_Repository_Interface Repository.
	 */
	private function create_repository(): Index_Repository_Interface {
		if ( $this->schema instanceof MariaDB_Index_Schema && Availability::BACKEND_MARIADB === $this->availability->get_index_backend() ) {
			return new MariaDB_Index_Repository( $this->schema, $this->availability->get_embedding_dimensions() );
		}

		return $this->availability->create_index_repository();
	}

	/**
	 * Marks eligible posts as dirty for indexing.
	 *
	 * @since 1.1.0
	 *
	 * @param list<int> $post_ids Optional explicit post IDs. Empty means all eligible posts.
	 * @param int       $limit    Maximum posts to mark when no explicit IDs are supplied.
	 * @return array{marked: int, skipped: int, removed: int} Marking statistics.
	 */
	public function mark_posts_dirty_for_indexing( array $post_ids = array(), int $limit = 0 ): array {
		if ( empty( $post_ids ) ) {
			$post_ids = $this->get_eligible_post_ids( $limit );
		}

		$stats = array(
			'marked'  => 0,
			'skipped' => 0,
			'removed' => 0,
		);

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post ) {
				++$stats['skipped'];
				continue;
			}

			if ( $this->should_index_post( $post ) ) {
				$this->mark_post_dirty( (int) $post->ID );
				++$stats['marked'];
				continue;
			}

			$this->remove_post_from_index( (int) $post->ID );
			++$stats['removed'];
		}

		return $stats;
	}

	/**
	 * Runs one synchronous indexing batch.
	 *
	 * @since 1.1.0
	 *
	 * @param int  $limit         Maximum dirty posts to process.
	 * @param bool $schedule_tail Whether to schedule another run when dirty posts remain.
	 * @return array{processed: int, clean: int, error: int, removed: int, remaining: int}|\WP_Error Batch statistics or lock error.
	 */
	public function run_indexing_batch( int $limit = 0, bool $schedule_tail = true ) {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return new WP_Error( 'wpai_rag_indexing_locked', __( 'RAG indexing is already running.', 'ai' ) );
		}

		set_transient( self::LOCK_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );

		$stats = array(
			'processed' => 0,
			'clean'     => 0,
			'error'     => 0,
			'removed'   => 0,
			'remaining' => 0,
		);

		try {
			$post_ids = $this->get_dirty_post_ids( $limit > 0 ? $limit : $this->get_batch_size() );

			foreach ( $post_ids as $post_id ) {
				$result = $this->process_post( $post_id );
				++$stats['processed'];

				if ( self::STATUS_CLEAN === $result ) {
					++$stats['clean'];
				} elseif ( self::STATUS_ERROR === $result ) {
					++$stats['error'];
				} elseif ( 'removed' === $result ) {
					++$stats['removed'];
				}
			}

			$stats['remaining'] = $this->count_dirty_posts();

			if ( $schedule_tail && $stats['remaining'] > 0 ) {
				$this->schedule_indexing();
			}
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}

		return $stats;
	}

	/**
	 * Schedules an indexing run.
	 *
	 * @since 1.1.0
	 *
	 * @param int $delay Delay in seconds.
	 */
	public function schedule_indexing( int $delay = 1 ): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + max( 1, $delay ), self::CRON_HOOK );
	}

	/**
	 * Returns status counts for RAG post meta.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, int> Counts keyed by status.
	 */
	public function get_status_counts(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CLI/status utility reads a small aggregate over RAG status post meta.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS status, COUNT(*) AS total
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s
				GROUP BY meta_value", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::META_STATUS
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$counts = array(
			self::STATUS_DIRTY      => 0,
			self::STATUS_PROCESSING => 0,
			self::STATUS_CLEAN      => 0,
			self::STATUS_ERROR      => 0,
		);

		if ( ! $rows ) {
			return $counts;
		}

		foreach ( $rows as $row ) {
			$status = isset( $row['status'] ) ? (string) $row['status'] : '';
			if ( '' === $status ) {
				continue;
			}

			$counts[ $status ] = isset( $row['total'] ) ? (int) $row['total'] : 0;
		}

		return $counts;
	}

	/**
	 * Counts dirty posts in the current indexing scope.
	 *
	 * @since 1.1.0
	 *
	 * @return int Dirty post count.
	 */
	public function count_dirty_posts(): int {
		$scope = $this->get_indexing_scope();
		if ( empty( $scope ) ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'fields'                 => 'ids',
				'post_type'              => array_keys( $scope ),
				'post_status'            => $this->get_scope_post_statuses( $scope ),
				'posts_per_page'         => 1,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- RAG indexing state is intentionally stored as post meta.
				'meta_query'             => array(
					array(
						'key'   => self::META_STATUS,
						'value' => self::STATUS_DIRTY,
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Returns the next scheduled indexing timestamp.
	 *
	 * @since 1.1.0
	 *
	 * @return int|null Unix timestamp or null when unscheduled.
	 */
	public function get_next_scheduled_indexing(): ?int {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		return $timestamp ? (int) $timestamp : null;
	}

	/**
	 * Checks whether RAG index data or scheduled work exists.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when RAG data exists.
	 */
	public function has_index_data(): bool {
		$counts = $this->get_status_counts();

		return $this->availability->has_index_data()
			|| array_sum( $counts ) > 0
			|| null !== $this->get_next_scheduled_indexing();
	}

	/**
	 * Deletes all RAG index data and scheduled work.
	 *
	 * @since 1.1.0
	 */
	public function cleanup_index_data(): void {
		$this->availability->cleanup_index_backends();
		$this->delete_common_index_meta();
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_option( Availability::BACKEND_OPTION );
	}

	/**
	 * Handles post saves.
	 *
	 * @since 1.1.0
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 */
	public function handle_save_post( int $post_id, WP_Post $post, bool $update ): void {
		unset( $update );

		if ( $this->should_skip_post_save( $post_id ) ) {
			return;
		}

		if ( $this->should_index_post( $post ) ) {
			$this->mark_post_dirty( $post_id );
			return;
		}

		$this->remove_post_from_index( $post_id );
	}

	/**
	 * Handles status transitions.
	 *
	 * @since 1.1.0
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 */
	public function handle_post_status_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( $new_status === $old_status ) {
			return;
		}

		if ( $this->should_index_post( $post ) ) {
			$this->mark_post_dirty( (int) $post->ID );
			return;
		}

		$this->remove_post_from_index( (int) $post->ID );
	}

	/**
	 * Removes deleted posts from the index.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	public function handle_before_delete_post( int $post_id ): void {
		$this->remove_post_from_index( $post_id );
	}

	/**
	 * Processes dirty posts.
	 *
	 * @since 1.1.0
	 */
	public function handle_cron(): void {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			$this->schedule_indexing( 5 * MINUTE_IN_SECONDS );
			return;
		}

		set_transient( self::LOCK_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );

		try {
			$batch_size = $this->get_batch_size();
			$post_ids   = $this->get_dirty_post_ids( $batch_size );

			foreach ( $post_ids as $post_id ) {
				$this->process_post( $post_id );
			}

			if ( $this->has_dirty_posts() ) {
				$this->schedule_indexing();
			}
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Checks whether a post is eligible for indexing.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool True when the post should be indexed.
	 */
	public function should_index_post( WP_Post $post ): bool {
		$scope = $this->get_indexing_scope();

		$should_index = isset( $scope[ $post->post_type ] )
			&& in_array( $post->post_status, $scope[ $post->post_type ], true );

		/**
		 * Filters whether an individual post should be indexed for RAG search.
		 *
		 * @since 1.1.0
		 *
		 * @param bool     $should_index Whether the post should be indexed.
		 * @param \WP_Post $post         Post object.
		 */
		return (bool) apply_filters( 'wpai_rag_should_index_post', $should_index, $post );
	}

	/**
	 * Returns the default and filtered indexing scope.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, list<string>> Post type to statuses map.
	 */
	public function get_indexing_scope(): array {
		$scope = array(
			'post' => array( 'publish' ),
			'page' => array( 'publish' ),
		);

		/**
		 * Filters the post types and statuses indexed for RAG search.
		 *
		 * Return an array keyed by post type with a list of statuses for each type.
		 *
		 * @since 1.1.0
		 *
		 * @param array<string, list<string>> $scope Indexing scope.
		 */
		$scope = apply_filters( 'wpai_rag_indexing_scope', $scope );

		if ( ! is_array( $scope ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $scope as $post_type => $statuses ) {
			if ( ! is_string( $post_type ) || ! post_type_exists( $post_type ) || ! is_array( $statuses ) ) {
				continue;
			}

			$post_type = sanitize_key( $post_type );
			$statuses  = array_values(
				array_filter(
					array_map( 'sanitize_key', $statuses )
				)
			);

			if ( empty( $statuses ) ) {
				continue;
			}

			$normalized[ $post_type ] = array_values( array_unique( $statuses ) );
		}

		return $normalized;
	}

	/**
	 * Marks a post dirty and schedules delayed indexing.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	private function mark_post_dirty( int $post_id ): void {
		update_post_meta( $post_id, self::META_STATUS, self::STATUS_DIRTY );
		delete_post_meta( $post_id, self::META_ERROR );
		$this->schedule_indexing( HOUR_IN_SECONDS );
	}

	/**
	 * Removes all index state for a post.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	private function remove_post_from_index( int $post_id ): void {
		$this->repository->delete_post_chunks( $post_id );
		delete_post_meta( $post_id, self::META_STATUS );
		delete_post_meta( $post_id, self::META_ERROR );
		delete_post_meta( $post_id, self::META_INDEXED_AT );
		delete_post_meta( $post_id, self::META_CONTENT_HASH );
	}

	/**
	 * Processes one post.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post ID.
	 */
	private function process_post( int $post_id ): string {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! $this->should_index_post( $post ) ) {
			$this->remove_post_from_index( $post_id );
			return 'removed';
		}

		update_post_meta( $post_id, self::META_STATUS, self::STATUS_PROCESSING );

		try {
			$content_hash = $this->build_content_hash( $post );
			$chunks       = $this->chunker->chunk_post( $post );

			if ( empty( $chunks ) ) {
				$this->repository->delete_post_chunks( $post_id );
				$this->mark_post_clean( $post_id, $content_hash );
				return self::STATUS_CLEAN;
			}

			$texts = array();
			foreach ( $chunks as $chunk ) {
				$texts[] = isset( $chunk['embedding_text'] ) ? (string) $chunk['embedding_text'] : (string) $chunk['content'];
			}

			$embeddings = $this->embedding_client->embed( $texts );

			if ( is_wp_error( $embeddings ) ) {
				$this->mark_post_error( $post_id, $embeddings );
				return self::STATUS_ERROR;
			}

			$this->repository->replace_post_chunks(
				$post,
				$chunks,
				$embeddings,
				$this->availability->get_embedding_model(),
				$content_hash
			);

			$this->mark_post_clean( $post_id, $content_hash );
			return self::STATUS_CLEAN;
		} catch ( \Throwable $e ) {
			$this->mark_post_error(
				$post_id,
				new WP_Error( 'wpai_rag_indexing_error', $e->getMessage() )
			);
		}

		return self::STATUS_ERROR;
	}

	/**
	 * Marks a post clean.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $content_hash Content hash.
	 */
	private function mark_post_clean( int $post_id, string $content_hash ): void {
		update_post_meta( $post_id, self::META_STATUS, self::STATUS_CLEAN );
		update_post_meta( $post_id, self::META_CONTENT_HASH, $content_hash );
		update_post_meta( $post_id, self::META_INDEXED_AT, current_time( 'mysql', true ) );
		delete_post_meta( $post_id, self::META_ERROR );
	}

	/**
	 * Marks a post as failed.
	 *
	 * @since 1.1.0
	 *
	 * @param int       $post_id Post ID.
	 * @param \WP_Error $error   Error.
	 */
	private function mark_post_error( int $post_id, WP_Error $error ): void {
		update_post_meta( $post_id, self::META_STATUS, self::STATUS_ERROR );
		update_post_meta( $post_id, self::META_ERROR, substr( $error->get_error_message(), 0, 1000 ) );
	}

	/**
	 * Gets dirty post IDs.
	 *
	 * @since 1.1.0
	 *
	 * @param int $limit Maximum posts.
	 * @return list<int> Post IDs.
	 */
	private function get_dirty_post_ids( int $limit ): array {
		$scope = $this->get_indexing_scope();
		if ( empty( $scope ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'fields'                 => 'ids',
				'post_type'              => array_keys( $scope ),
				'post_status'            => $this->get_scope_post_statuses( $scope ),
				'posts_per_page'         => $limit,
				'orderby'                => 'modified',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- RAG indexing state is intentionally stored as post meta.
				'meta_query'             => array(
					array(
						'key'   => self::META_STATUS,
						'value' => self::STATUS_DIRTY,
					),
				),
			)
		);

		$post_ids = array();
		foreach ( $query->posts as $post_id ) {
			$post_id = $this->normalize_query_post_id( $post_id );
			if ( $post_id <= 0 ) {
				continue;
			}

			$post_ids[] = $post_id;
		}

		return $post_ids;
	}

	/**
	 * Gets eligible post IDs.
	 *
	 * @since 1.1.0
	 *
	 * @param int $limit Maximum posts, or 0 for all.
	 * @return list<int> Post IDs.
	 */
	private function get_eligible_post_ids( int $limit = 0 ): array {
		$scope = $this->get_indexing_scope();
		if ( empty( $scope ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'fields'                 => 'ids',
				'post_type'              => array_keys( $scope ),
				'post_status'            => $this->get_scope_post_statuses( $scope ),
				'posts_per_page'         => $limit > 0 ? $limit : -1,
				'orderby'                => 'modified',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$post_ids = array();
		foreach ( $query->posts as $post_id ) {
			$post_id = $this->normalize_query_post_id( $post_id );
			if ( $post_id <= 0 ) {
				continue;
			}

			$post_ids[] = $post_id;
		}

		return $post_ids;
	}

	/**
	 * Normalizes a WP_Query post result into a post ID.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $post Query post result.
	 * @return int Post ID, or 0 when invalid.
	 */
	private function normalize_query_post_id( $post ): int {
		if ( $post instanceof WP_Post ) {
			return (int) $post->ID;
		}

		if ( is_numeric( $post ) ) {
			return (int) $post;
		}

		return 0;
	}

	/**
	 * Returns the statuses included in an indexing scope.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, list<string>> $scope Indexing scope.
	 * @return list<string> Statuses.
	 */
	private function get_scope_post_statuses( array $scope ): array {
		$post_statuses = array();
		foreach ( $scope as $statuses ) {
			$post_statuses = array_merge( $post_statuses, $statuses );
		}

		return array_values( array_unique( $post_statuses ) );
	}

	/**
	 * Checks whether dirty posts remain.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True when work remains.
	 */
	private function has_dirty_posts(): bool {
		return ! empty( $this->get_dirty_post_ids( 1 ) );
	}

	/**
	 * Returns batch size.
	 *
	 * @since 1.1.0
	 *
	 * @return int Batch size.
	 */
	private function get_batch_size(): int {
		/**
		 * Filters the number of dirty posts indexed per cron run.
		 *
		 * @since 1.1.0
		 *
		 * @param int $batch_size Batch size.
		 */
		return max( 1, min( 200, (int) apply_filters( 'wpai_rag_index_batch_size', 50 ) ) );
	}

	/**
	 * Builds a content hash for indexing idempotency.
	 *
	 * @since 1.1.0
	 *
	 * @param \WP_Post $post Post object.
	 * @return string Hash.
	 */
	private function build_content_hash( WP_Post $post ): string {
		$data = array(
			'post_id'      => (int) $post->ID,
			'post_title'   => (string) $post->post_title,
			'post_content' => (string) $post->post_content,
			'post_excerpt' => (string) $post->post_excerpt,
			'model'        => $this->availability->get_embedding_model(),
			'dimensions'   => $this->availability->get_embedding_dimensions(),
		);

		return hash( 'sha256', (string) wp_json_encode( $data ) );
	}

	/**
	 * Checks whether to ignore a save_post call.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return bool True to skip.
	 */
	private function should_skip_post_save( int $post_id ): bool {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return true;
		}

		return defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE;
	}

	/**
	 * Deletes common RAG post meta.
	 *
	 * @since 1.1.0
	 */
	private function delete_common_index_meta(): void {
		global $wpdb;

		$meta_keys = array(
			self::META_STATUS,
			self::META_ERROR,
			self::META_INDEXED_AT,
			self::META_CONTENT_HASH,
		);

		$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit cleanup removes RAG-owned post meta in one query.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...$meta_keys
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}

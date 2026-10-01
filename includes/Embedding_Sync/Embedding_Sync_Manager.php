<?php
/**
 * Keeps stored post embeddings in sync with post content, in the background.
 *
 * @package WordPress\AI\Embedding_Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embedding_Sync;

use WP_Error;
use WP_Post;
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Embedding_Repository;
use WordPress\AI\Embeddings\Embedding_Repository_Interface;

use function WordPress\AI\generate_embeddings;
use function WordPress\AI\get_feature_developer_model_config;
use function WordPress\AI\normalize_content;

defined( 'ABSPATH' ) || exit;

/**
 * Queues posts for re-embedding when their content changes, and drains that queue in small
 * batches on a recurring cron event.
 *
 * Nothing is embedded synchronously while a post is being saved: `save_post` only records that the
 * post needs attention, and the actual provider request happens later, in bounded batches, so a
 * large site is never blocked re-indexing its whole corpus in one request. A post already up to
 * date for the configured provider and model (per {@see Embedding_Repository::get_content_hash()})
 * is skipped without a provider request.
 *
 * @since x.x.x
 */
class Embedding_Sync_Manager {

	/**
	 * Cron hook that drains the sync queue.
	 */
	public const QUEUE_HOOK = 'wpai_embedding_sync_process_queue';

	/**
	 * Custom cron schedule used for the queue hook.
	 */
	public const CRON_SCHEDULE = 'wpai_embedding_sync';

	/**
	 * Option storing the queue of post IDs awaiting (re-)embedding.
	 */
	public const QUEUE_OPTION = 'wpai_embedding_sync_queue';

	/**
	 * Upper bound on how many post IDs the queue option is allowed to hold.
	 *
	 * Protects a single option row from growing without bound on a site that edits far faster
	 * than its embedding provider can keep up. Oldest entries are dropped first; a dropped post
	 * is picked up again the next time it is saved.
	 */
	private const MAX_QUEUE_LENGTH = 5000;

	/**
	 * The ID of the feature whose developer model config selects the provider and model.
	 *
	 * @var string
	 */
	private string $feature_id;

	/**
	 * The embedding storage repository.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Repository_Interface
	 */
	private Embedding_Repository_Interface $repository;

	/**
	 * Whether initialization hooks have already been registered.
	 *
	 * @var bool
	 */
	private bool $initialized = false;

	/**
	 * Constructor.
	 *
	 * @since x.x.x
	 *
	 * @param string                                                         $feature_id The feature ID whose developer model config (provider + model) this manager uses.
	 * @param \WordPress\AI\Embeddings\Embedding_Repository_Interface|null $repository Optional. The embedding repository. Default a new Embedding_Repository.
	 */
	public function __construct( string $feature_id, ?Embedding_Repository_Interface $repository = null ) {
		$this->feature_id = $feature_id;
		$this->repository = $repository ?? new Embedding_Repository();
	}

	/**
	 * Registers hooks and schedules the queue-processing cron event.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		if ( $this->initialized ) {
			return;
		}

		// register_cron_schedule() floors the interval at 15 minutes, matching platform
		// guidance against sub-fifteen-minute cron schedules.
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected

		add_action( 'save_post', array( $this, 'maybe_queue_post' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'handle_post_deleted' ) );
		add_action( self::QUEUE_HOOK, array( $this, 'process_queue' ) );

		if ( ! wp_next_scheduled( self::QUEUE_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_SCHEDULE, self::QUEUE_HOOK );
		}

		$this->initialized = true;
	}

	/**
	 * Adds the custom cron interval used to drain the queue.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}> Schedules with the sync interval added.
	 */
	public function register_cron_schedule( array $schedules ): array {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => $this->get_interval_seconds(),
			'display'  => __( 'AI Embedding Sync', 'ai' ),
		);

		return $schedules;
	}

	/**
	 * Queues a post for re-embedding when it is saved.
	 *
	 * Only records that the post needs attention; the provider request happens later, in
	 * {@see self::process_queue()}, so saving a post never waits on an embedding call.
	 *
	 * @since x.x.x
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 * @param bool     $update  Whether this is an existing post being updated.
	 */
	public function maybe_queue_post( int $post_id, WP_Post $post, bool $update ): void {
		unset( $update );

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->get_synced_post_types(), true ) ) {
			return;
		}

		if ( ! in_array( $post->post_status, $this->get_synced_post_statuses(), true ) ) {
			return;
		}

		$this->add_to_queue( $post_id );
	}

	/**
	 * Removes a deleted post's queue entry and stored embeddings.
	 *
	 * @since x.x.x
	 *
	 * @param int $post_id The post ID being deleted.
	 */
	public function handle_post_deleted( int $post_id ): void {
		$this->remove_from_queue( array( $post_id ) );
		$this->repository->delete_for_object( 'post', $post_id );
	}

	/**
	 * Drains a bounded batch of the queue, embedding only posts whose content actually changed.
	 *
	 * Skips entirely, without a provider request, when no provider and model are configured for
	 * this feature yet (see {@see get_feature_developer_model_config()}), or when the queue is
	 * empty. A provider failure re-queues the whole batch for the next run.
	 *
	 * @since x.x.x
	 */
	public function process_queue(): void {
		$config   = get_feature_developer_model_config( $this->feature_id );
		$provider = $config['provider'];
		$model    = $config['model'];

		if ( '' === $provider || '' === $model ) {
			return;
		}

		$queue = $this->get_queue();

		if ( empty( $queue ) ) {
			return;
		}

		$batch_ids = array_slice( $queue, 0, $this->get_batch_size() );
		$this->remove_from_queue( $batch_ids );

		$texts   = array();
		$hashes  = array();
		$subtype = array();

		foreach ( $batch_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$text = normalize_content( (string) $post->post_content );
			$hash = hash( 'sha256', $text );

			if ( '' === $text || $hash === $this->repository->get_content_hash( 'post', $post_id, $provider, $model ) ) {
				continue;
			}

			$texts[ $post_id ]   = $text;
			$hashes[ $post_id ]  = $hash;
			$subtype[ $post_id ] = (string) $post->post_type;
		}

		if ( empty( $texts ) ) {
			return;
		}

		$post_ids = array_keys( $texts );
		$result   = $this->generate_batch( array_values( $texts ), $provider, $model );

		if ( $result instanceof WP_Error ) {
			$this->add_to_queue( ...$post_ids );

			/**
			 * Fires when a background embedding sync batch fails.
			 *
			 * @since x.x.x
			 *
			 * @param \WP_Error $error    The error returned while generating embeddings.
			 * @param list<int> $post_ids The post IDs that were being processed.
			 */
			do_action( 'wpai_embedding_sync_batch_failed', $result, $post_ids );

			return;
		}

		$records = array();

		foreach ( $post_ids as $index => $post_id ) {
			$records[] = new Embedding_Record(
				'post',
				$post_id,
				$provider,
				$model,
				$result[ $index ],
				0,
				$hashes[ $post_id ],
				0,
				$subtype[ $post_id ]
			);
		}

		$saved = $this->repository->save_many( $records );

		/**
		 * Fires after a background embedding sync batch is stored.
		 *
		 * @since x.x.x
		 *
		 * @param list<\WordPress\AI\Embeddings\Embedding_Record> $saved The stored records.
		 */
		do_action( 'wpai_embedding_sync_batch_processed', $saved );
	}

	/**
	 * Generates embedding vectors for a batch of texts.
	 *
	 * Isolated from {@see self::process_queue()} so tests can substitute a fake generator instead
	 * of making live provider requests.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $texts    Texts to embed, in order.
	 * @param string       $provider Provider ID.
	 * @param string       $model    Model ID.
	 * @return list<list<float>>|\WP_Error The vectors, positionally aligned with $texts, or an error.
	 */
	protected function generate_batch( array $texts, string $provider, string $model ) {
		$result = generate_embeddings(
			$texts,
			array(
				'provider' => $provider,
				'model'    => $model,
			)
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return array_values(
			array_map(
				static fn( $embedding ) => $embedding->getValues(),
				$result->getEmbeddings()
			)
		);
	}

	/**
	 * Returns the maximum number of posts processed per cron run.
	 *
	 * @since x.x.x
	 *
	 * @return int The batch size, always at least 1.
	 */
	private function get_batch_size(): int {
		/**
		 * Filters how many queued posts are embedded per background sync run.
		 *
		 * @since x.x.x
		 *
		 * @param int $batch_size Posts processed per run. Default 20.
		 */
		$batch_size = (int) apply_filters( 'wpai_embedding_sync_batch_size', 20 );

		return max( 1, $batch_size );
	}

	/**
	 * Returns the cron interval, in seconds, between queue-processing runs.
	 *
	 * @since x.x.x
	 *
	 * @return int Interval in seconds, always at least 60.
	 */
	private function get_interval_seconds(): int {
		/**
		 * Filters the interval, in seconds, between background embedding sync runs.
		 *
		 * @since x.x.x
		 *
		 * @param int $seconds Interval in seconds. Default 900 (fifteen minutes).
		 */
		$seconds = (int) apply_filters( 'wpai_embedding_sync_interval', 15 * MINUTE_IN_SECONDS );

		return max( 15 * MINUTE_IN_SECONDS, $seconds );
	}

	/**
	 * Returns the post types eligible for background sync.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> Post type slugs.
	 */
	private function get_synced_post_types(): array {
		/**
		 * Filters the post types kept in sync with the embeddings store.
		 *
		 * @since x.x.x
		 *
		 * @param list<string> $post_types Post type slugs. Default `[ 'post', 'page' ]`.
		 */
		return (array) apply_filters( 'wpai_embedding_sync_post_types', array( 'post', 'page' ) );
	}

	/**
	 * Returns the post statuses eligible for background sync.
	 *
	 * @since x.x.x
	 *
	 * @return list<string> Post status slugs.
	 */
	private function get_synced_post_statuses(): array {
		/**
		 * Filters the post statuses kept in sync with the embeddings store.
		 *
		 * A post that leaves this set (e.g. is unpublished) keeps whatever embedding it already
		 * has; background sync does not remove it.
		 *
		 * @since x.x.x
		 *
		 * @param list<string> $statuses Post status slugs. Default `[ 'publish' ]`.
		 */
		return (array) apply_filters( 'wpai_embedding_sync_post_statuses', array( 'publish' ) );
	}

	/**
	 * Returns the queued post IDs, oldest first.
	 *
	 * @since x.x.x
	 *
	 * @return list<int> Queued post IDs.
	 */
	private function get_queue(): array {
		$queue = get_option( self::QUEUE_OPTION, array() );

		if ( ! is_array( $queue ) ) {
			return array();
		}

		return array_values( array_map( 'intval', array_keys( $queue ) ) );
	}

	/**
	 * Adds one or more post IDs to the queue.
	 *
	 * @since x.x.x
	 *
	 * @param int ...$post_ids Post IDs to add.
	 */
	private function add_to_queue( int ...$post_ids ): void {
		if ( empty( $post_ids ) ) {
			return;
		}

		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = is_array( $queue ) ? $queue : array();

		foreach ( $post_ids as $post_id ) {
			$queue[ $post_id ] = true;
		}

		if ( count( $queue ) > self::MAX_QUEUE_LENGTH ) {
			$queue = array_slice( $queue, -self::MAX_QUEUE_LENGTH, null, true );
		}

		update_option( self::QUEUE_OPTION, $queue, false );
	}

	/**
	 * Removes post IDs from the queue.
	 *
	 * @since x.x.x
	 *
	 * @param list<int> $post_ids Post IDs to remove.
	 */
	private function remove_from_queue( array $post_ids ): void {
		$queue = get_option( self::QUEUE_OPTION, array() );

		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		foreach ( $post_ids as $post_id ) {
			unset( $queue[ $post_id ] );
		}

		update_option( self::QUEUE_OPTION, $queue, false );
	}
}

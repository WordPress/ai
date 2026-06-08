<?php
/**
 * WP-CLI utilities for RAG indexing.
 *
 * @package WordPress\AI
 *
 * @since 1.1.0
 */

declare( strict_types=1 );

namespace WordPress\AI\CLI;

use WP_CLI;
use WP_CLI\Utils;
use WordPress\AI\RAG\Availability;
use WordPress\AI\RAG\Index_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Manages RAG indexing utilities.
 *
 * @since 1.1.0
 */
class RAG_Command {
	/**
	 * Availability service.
	 *
	 * @var \WordPress\AI\RAG\Availability
	 */
	private Availability $availability;

	/**
	 * Index manager.
	 *
	 * @var \WordPress\AI\RAG\Index_Manager
	 */
	private Index_Manager $index_manager;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param \WordPress\AI\RAG\Availability|null  $availability  Availability service.
	 * @param \WordPress\AI\RAG\Index_Manager|null $index_manager Index manager.
	 */
	public function __construct( ?Availability $availability = null, ?Index_Manager $index_manager = null ) {
		$this->availability  = $availability ?? new Availability();
		$this->index_manager = $index_manager ?? new Index_Manager( $this->availability );
	}

	/**
	 * Shows RAG index status.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp ai rag status
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ): void {
		unset( $args, $assoc_args );

		$available  = $this->availability->is_available();
		$storage_ok = $available && $this->index_manager->ensure_index_storage();
		$counts     = $this->index_manager->get_status_counts();
		$scheduled  = $this->index_manager->get_next_scheduled_indexing();

		WP_CLI::log( 'RAG search status:' );
		WP_CLI::log( sprintf( '  Available: %s', $available ? 'yes' : 'no' ) );
		WP_CLI::log( sprintf( '  Backend: %s', $this->availability->get_index_backend_label() ) );

		if ( ! $available ) {
			WP_CLI::log( sprintf( '  Reason: %s', $this->availability->get_unavailable_reason() ) );
		}

		WP_CLI::log( sprintf( '  Index storage: %s', $storage_ok ? 'ready' : 'missing' ) );
		WP_CLI::log( sprintf( '  Dirty: %d', $counts[ Index_Manager::STATUS_DIRTY ] ?? 0 ) );
		WP_CLI::log( sprintf( '  Processing: %d', $counts[ Index_Manager::STATUS_PROCESSING ] ?? 0 ) );
		WP_CLI::log( sprintf( '  Clean: %d', $counts[ Index_Manager::STATUS_CLEAN ] ?? 0 ) );
		WP_CLI::log( sprintf( '  Error: %d', $counts[ Index_Manager::STATUS_ERROR ] ?? 0 ) );
		WP_CLI::log(
			sprintf(
				'  Next scheduled run: %s',
				null === $scheduled ? 'none' : gmdate( 'Y-m-d H:i:s', $scheduled ) . ' UTC'
			)
		);
	}

	/**
	 * Marks eligible posts as dirty.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Comma-separated list of post IDs to mark dirty. Defaults to all eligible posts.
	 *
	 * [--limit=<number>]
	 * : Maximum number of eligible posts to mark when --ids is not supplied.
	 *
	 * ## EXAMPLES
	 *
	 *     # Mark all eligible posts/pages dirty
	 *     $ wp ai rag mark-dirty
	 *
	 *     # Mark selected posts dirty
	 *     $ wp ai rag mark-dirty --ids=42,99
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function mark_dirty( $args, $assoc_args ): void {
		unset( $args );

		$this->ensure_available();

		$ids   = $this->parse_ids_flag( (string) Utils\get_flag_value( $assoc_args, 'ids', '' ) );
		$limit = max( 0, (int) Utils\get_flag_value( $assoc_args, 'limit', 0 ) );
		$stats = $this->index_manager->mark_posts_dirty_for_indexing( $ids, $limit );

		WP_CLI::success(
			sprintf(
				'Marked %d post(s) dirty. Skipped %d, removed %d from the index.',
				$stats['marked'],
				$stats['skipped'],
				$stats['removed']
			)
		);
	}

	/**
	 * Runs RAG indexing immediately.
	 *
	 * Processes dirty posts synchronously. Use `mark-dirty` first to bootstrap
	 * an initial index for existing content.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Number of dirty posts to process per batch.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--all]
	 * : Continue running batches until no dirty posts remain.
	 *
	 * [--schedule]
	 * : Schedule a follow-up cron event if dirty posts remain.
	 * ---
	 * default: true
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Process one dirty-post batch now
	 *     $ wp ai rag index
	 *
	 *     # Process all dirty posts now
	 *     $ wp ai rag index --all
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function index( $args, $assoc_args ): void {
		unset( $args );

		$this->ensure_available();

		$batch_size    = max( 1, min( 200, (int) Utils\get_flag_value( $assoc_args, 'batch-size', 50 ) ) );
		$process_all   = (bool) Utils\get_flag_value( $assoc_args, 'all', false );
		$schedule_tail = (bool) Utils\get_flag_value( $assoc_args, 'schedule', true );

		$totals = array(
			'processed' => 0,
			'clean'     => 0,
			'error'     => 0,
			'removed'   => 0,
			'remaining' => 0,
		);

		do {
			$stats = $this->index_manager->run_indexing_batch( $batch_size, $schedule_tail && ! $process_all );

			if ( is_wp_error( $stats ) ) {
				WP_CLI::error( $stats->get_error_message() );
				return;
			}

			foreach ( array( 'processed', 'clean', 'error', 'removed' ) as $key ) {
				$totals[ $key ] += $stats[ $key ];
			}
			$totals['remaining'] = $stats['remaining'];

			WP_CLI::log(
				sprintf(
					'Processed %d post(s): %d clean, %d error, %d removed. %d dirty post(s) remain.',
					$stats['processed'],
					$stats['clean'],
					$stats['error'],
					$stats['removed'],
					$stats['remaining']
				)
			);
		} while ( $process_all && $totals['remaining'] > 0 );

		WP_CLI::success(
			sprintf(
				'RAG indexing complete for %d post(s): %d clean, %d error, %d removed. %d dirty post(s) remain.',
				$totals['processed'],
				$totals['clean'],
				$totals['error'],
				$totals['removed'],
				$totals['remaining']
			)
		);
	}

	/**
	 * Schedules the RAG indexing cron event.
	 *
	 * ## OPTIONS
	 *
	 * [--delay=<seconds>]
	 * : Delay before the event runs.
	 * ---
	 * default: 1
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp ai rag schedule
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function schedule( $args, $assoc_args ): void {
		unset( $args );

		$this->ensure_available();

		$delay = max( 1, (int) Utils\get_flag_value( $assoc_args, 'delay', 1 ) );
		$this->index_manager->schedule_indexing( $delay );

		WP_CLI::success( sprintf( 'Scheduled RAG indexing in %d second(s).', $delay ) );
	}

	/**
	 * Ensures RAG indexing can run.
	 *
	 * @since 1.1.0
	 */
	private function ensure_available(): void {
		if ( ! $this->availability->is_available() ) {
			WP_CLI::error( $this->availability->get_unavailable_reason() );
			return;
		}

		if ( $this->index_manager->ensure_index_storage() ) {
			return;
		}

		WP_CLI::error( 'The RAG index storage could not be prepared.' );
	}

	/**
	 * Parses a comma-separated ID flag.
	 *
	 * @since 1.1.0
	 *
	 * @param string $ids_flag Comma-separated IDs.
	 * @return list<int> IDs.
	 */
	private function parse_ids_flag( string $ids_flag ): array {
		if ( '' === trim( $ids_flag ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', explode( ',', $ids_flag ) ) ) );
	}
}

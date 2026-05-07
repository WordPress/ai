<?php
/**
 * WP-CLI command for generating alt text for images in the media library.
 *
 * @package WordPress\AI
 *
 * @since 0.9.0
 */

declare( strict_types=1 );

namespace WordPress\AI\CLI;

use WP_CLI;
use WP_CLI\Utils;
use function WordPress\AI\has_valid_ai_credentials;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Manages AI-powered alt text generation for media library images.
 *
 * @since 0.9.0
 */
class Alt_Text_Command {

	/**
	 * Maximum number of rows shown by the dry-run table.
	 */
	private const DRY_RUN_PREVIEW_LIMIT = 100;

	/**
	 * Generates alt text for images in the media library using AI.
	 *
	 * Queries images that are missing alt text and generates it using the
	 * ai/alt-text-generation ability. Processes images in batches to manage
	 * memory and API rate limits.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Number of images to process per batch.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--dry-run]
	 * : Show what would be processed without making changes.
	 *
	 * [--force]
	 * : Regenerate alt text even for images that already have it.
	 *
	 * [--ids=<ids>]
	 * : Comma-separated list of specific attachment IDs to process.
	 *
	 * [--delay=<milliseconds>]
	 * : Delay in milliseconds between each API call to avoid rate limiting.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--yes]
	 * : Skip the confirmation prompt before processing.
	 *
	 * ## EXAMPLES
	 *
	 *     # Generate alt text for all images missing it
	 *     $ wp ai alt-text generate
	 *
	 *     # Dry run to see what would be processed
	 *     $ wp ai alt-text generate --dry-run
	 *
	 *     # Regenerate alt text for specific images
	 *     $ wp ai alt-text generate --ids=42,55,100 --force
	 *
	 *     # Process in small batches with custom delay, skipping confirmation
	 *     $ wp ai alt-text generate --batch-size=5 --delay=1000 --yes
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function generate( $args, $assoc_args ): void {
		$this->ensure_admin_user();

		$ability = wp_get_ability( 'ai/alt-text-generation' );
		if ( ! $ability ) {
			WP_CLI::error( 'The ai/alt-text-generation ability is not registered. Make sure the Alt Text Generation experiment is enabled in Settings > AI.' );
			return; // WP_CLI::error() exits, but this satisfies static analysis.
		}

		if ( ! has_valid_ai_credentials() ) {
			WP_CLI::error( 'No valid AI credentials found. Configure a provider in Settings > Connectors.' );
			return; // WP_CLI::error() exits, but this satisfies static analysis.
		}

		$batch_size = max( 1, (int) Utils\get_flag_value( $assoc_args, 'batch-size', 20 ) );
		$dry_run    = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$force      = (bool) Utils\get_flag_value( $assoc_args, 'force', false );
		$delay_ms   = (int) Utils\get_flag_value( $assoc_args, 'delay', 500 );
		$ids_flag   = (string) Utils\get_flag_value( $assoc_args, 'ids', '' );

		$explicit_ids = '' !== $ids_flag ? $this->parse_ids_flag( $ids_flag ) : null;
		$total        = null !== $explicit_ids
			? count( $explicit_ids )
			: $this->count_matching_attachments( $force );

		if ( 0 === $total ) {
			WP_CLI::success( 'No images found matching the criteria.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d image(s) to process.', $total ) );

		if ( $dry_run ) {
			$this->display_dry_run( $explicit_ids, $force, $total );
			return;
		}

		WP_CLI::confirm(
			sprintf( 'Generate alt text for %d image(s)? This may incur API costs.', $total ),
			$assoc_args
		);

		$stats = $this->process_images( $ability, $explicit_ids, $total, $batch_size, $delay_ms, $force );
		$this->print_summary( $stats );
	}

	/**
	 * Ensures a user with admin capabilities is set for the CLI session.
	 */
	private function ensure_admin_user(): void {
		if ( 0 !== get_current_user_id() ) {
			return;
		}

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		if ( empty( $admins ) ) {
			WP_CLI::error( 'No administrator user found. Create one or pass --user=<id>.' );
		}

		$admin_id = (int) $admins[0];
		wp_set_current_user( $admin_id );
		WP_CLI::log( sprintf( 'No --user supplied; running as administrator #%d.', $admin_id ) );
	}

	/**
	 * Parses the --ids flag into a list of valid image attachment IDs.
	 *
	 * @param string $ids_flag Comma-separated IDs from the --ids flag.
	 * @return int[] Array of attachment IDs that exist and are images.
	 */
	private function parse_ids_flag( string $ids_flag ): array {
		$ids = array_map( 'absint', explode( ',', $ids_flag ) );
		$ids = array_filter( $ids );

		return array_values(
			array_filter(
				$ids,
				static function ( int $id ): bool {
					return get_post( $id ) && wp_attachment_is_image( $id );
				}
			)
		);
	}

	/**
	 * Builds the WP_Query arguments shared by counting and batched fetching.
	 *
	 * @param bool $force Whether to include images that already have alt text.
	 * @return array<string, mixed>
	 */
	private function get_attachment_query_args( bool $force ): array {
		$query_args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		if ( ! $force ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => '_wp_attachment_image_alt',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_wp_attachment_image_alt',
					'value'   => '',
					'compare' => '=',
				),
			);
		}

		return $query_args;
	}

	/**
	 * Counts how many attachments would be processed.
	 *
	 * @param bool $force Whether to include images that already have alt text.
	 * @return int Total number of matching attachments.
	 */
	private function count_matching_attachments( bool $force ): int {
		$query_args                   = $this->get_attachment_query_args( $force );
		$query_args['posts_per_page'] = 1;
		$query_args['no_found_rows']  = false;

		$query = new \WP_Query( $query_args );

		return (int) $query->found_posts;
	}

	/**
	 * Fetches the next batch of attachment IDs, starting after a cursor.
	 *
	 * Pagination is cursor-based on the post ID so progress is stable even
	 * as records are mutated mid-run.
	 *
	 * @param bool $force      Whether to include images that already have alt text.
	 * @param int  $batch_size Maximum number of IDs to return.
	 * @param int  $cursor_id  Only return IDs greater than this. 0 to start.
	 * @return int[] Attachment IDs ordered ascending.
	 */
	private function fetch_attachment_batch( bool $force, int $batch_size, int $cursor_id ): array {
		$query_args                   = $this->get_attachment_query_args( $force );
		$query_args['posts_per_page'] = $batch_size;
		$query_args['no_found_rows']  = true;

		$where_filter = null;
		if ( $cursor_id > 0 ) {
			$where_filter = static function ( $where ) use ( $cursor_id ) {
				global $wpdb;
				return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $cursor_id );
			};
			add_filter( 'posts_where', $where_filter );
		}

		try {
			$query = new \WP_Query( $query_args );
			/** @var int[] $ids */
			$ids = $query->posts;
		} finally {
			if ( null !== $where_filter ) {
				remove_filter( 'posts_where', $where_filter );
			}
		}

		return $ids;
	}

	/**
	 * Displays the list of images that would be processed in a dry run.
	 *
	 * @param int[]|null $explicit_ids List of IDs from --ids, or null to query.
	 * @param bool       $force        Whether the live run would include images with alt text.
	 * @param int        $total        Total count of matching attachments.
	 */
	private function display_dry_run( ?array $explicit_ids, bool $force, int $total ): void {
		$preview_limit = self::DRY_RUN_PREVIEW_LIMIT;

		if ( null !== $explicit_ids ) {
			$preview = array_slice( $explicit_ids, 0, $preview_limit );
		} else {
			$preview = $this->fetch_attachment_batch( $force, $preview_limit, 0 );
		}

		$items = array();
		foreach ( $preview as $id ) {
			$alt     = get_post_meta( $id, '_wp_attachment_image_alt', true );
			$items[] = array(
				'ID'          => $id,
				'Title'       => get_the_title( $id ),
				'Current Alt' => ! empty( $alt ) ? $alt : '(empty)',
			);
		}

		Utils\format_items( 'table', $items, array( 'ID', 'Title', 'Current Alt' ) );

		if ( $total > $preview_limit ) {
			WP_CLI::log( sprintf( '... and %d more.', $total - $preview_limit ) );
		}

		WP_CLI::log( sprintf( "\nDry run complete. %d image(s) would be processed.", $total ) );
	}

	/**
	 * Processes images through the alt text generation ability.
	 *
	 * Iterates in batches without holding the full ID set in memory. For
	 * --ids mode the bounded list is sliced; otherwise the database is
	 * queried one batch at a time using a post-ID cursor.
	 *
	 * @param \WP_Ability $ability      The alt text generation ability.
	 * @param int[]|null  $explicit_ids List of IDs from --ids, or null to query.
	 * @param int         $total        Total count to process (drives the progress bar).
	 * @param int         $batch_size   Number of images per batch.
	 * @param int         $delay_ms     Delay in milliseconds between API calls.
	 * @param bool        $force        Whether to regenerate existing alt text.
	 * @return array{generated: int, decorative: int, skipped: int, failed: int}
	 */
	private function process_images( $ability, ?array $explicit_ids, int $total, int $batch_size, int $delay_ms, bool $force ): array {
		$stats = array(
			'generated'  => 0,
			'decorative' => 0,
			'skipped'    => 0,
			'failed'     => 0,
		);

		$progress  = Utils\make_progress_bar( 'Generating alt text', $total );
		$cursor    = 0;
		$processed = 0;

		while ( $processed < $total ) {
			if ( null !== $explicit_ids ) {
				$batch = array_slice( $explicit_ids, $processed, $batch_size );
			} else {
				$batch = $this->fetch_attachment_batch( $force, $batch_size, $cursor );
			}

			if ( empty( $batch ) ) {
				break;
			}

			foreach ( $batch as $id ) {
				$id = (int) $id;
				if ( $id > $cursor ) {
					$cursor = $id;
				}

				$current_alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
				if ( ! $force && '' !== $current_alt && false !== $current_alt ) {
					++$stats['skipped'];
					++$processed;
					$progress->tick();
					continue;
				}

				$result = $ability->execute( array( 'attachment_id' => $id ) );

				if ( is_wp_error( $result ) ) {
					++$stats['failed'];
					WP_CLI::warning( sprintf( 'ID %d: %s', $id, $result->get_error_message() ) );
					++$processed;
					$progress->tick();
					continue;
				}

				$alt_text      = $result['alt_text'] ?? '';
				$is_decorative = ! empty( $result['is_decorative'] );

				update_post_meta( $id, '_wp_attachment_image_alt', $alt_text );

				if ( $is_decorative ) {
					++$stats['decorative'];
				} else {
					++$stats['generated'];
				}

				++$processed;
				$progress->tick();

				if ( $delay_ms <= 0 ) {
					continue;
				}

				usleep( $delay_ms * 1000 );
			}

			$this->free_batch_memory();
		}

		$progress->finish();

		return $stats;
	}

	/**
	 * Prints the summary table after processing.
	 *
	 * @param array{generated: int, decorative: int, skipped: int, failed: int} $stats Processing statistics.
	 */
	private function print_summary( array $stats ): void {
		WP_CLI::log( '' );

		$items = array(
			array(
				'Metric' => 'Generated',
				'Count'  => $stats['generated'],
			),
			array(
				'Metric' => 'Decorative',
				'Count'  => $stats['decorative'],
			),
			array(
				'Metric' => 'Skipped',
				'Count'  => $stats['skipped'],
			),
			array(
				'Metric' => 'Failed',
				'Count'  => $stats['failed'],
			),
		);

		Utils\format_items( 'table', $items, array( 'Metric', 'Count' ) );

		$total = $stats['generated'] + $stats['decorative'];
		if ( $total > 0 ) {
			WP_CLI::success( sprintf( 'Generated alt text for %d image(s).', $total ) );
		} else {
			WP_CLI::log( 'No alt text was generated.' );
		}
	}

	/**
	 * Frees memory held between batches so long-running CLI runs do not exhaust it.
	 *
	 * Uses `wp_cache_flush_runtime()` (WP 6.0+) to drop the in-memory portion of
	 * the object cache without touching persistent backends like Redis. Falls
	 * back to a no-op when the helper is unavailable. Also resets the query log
	 * so it does not grow unbounded when `SAVEQUERIES` is enabled.
	 */
	private function free_batch_memory(): void {
		global $wpdb;

		$wpdb->queries = array();

		if ( ! function_exists( 'wp_cache_flush_runtime' ) ) {
			return;
		}

		wp_cache_flush_runtime();
	}
}

<?php
/**
 * WP-CLI command for generating alt text for images in the media library.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
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
 * @since x.x.x
 */
class Alt_Text_Command {

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
	 *     # Process in small batches with custom delay
	 *     $ wp ai alt-text generate --batch-size=5 --delay=1000
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function generate( $args, $assoc_args ): void {
		$this->ensure_admin_user();

		$ability = wp_get_ability( 'ai/alt-text-generation' );
		if ( ! $ability ) {
			WP_CLI::error( 'The ai/alt-text-generation ability is not registered. Make sure the Alt Text Generation experiment is enabled in Settings > AI.' );
		}

		if ( ! has_valid_ai_credentials() ) {
			WP_CLI::error( 'No valid AI credentials found. Configure a provider in Settings > AI.' );
		}

		$batch_size = (int) Utils\get_flag_value( $assoc_args, 'batch-size', 20 );
		$dry_run    = Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$force      = Utils\get_flag_value( $assoc_args, 'force', false );
		$delay_ms   = (int) Utils\get_flag_value( $assoc_args, 'delay', 500 );
		$ids_flag   = Utils\get_flag_value( $assoc_args, 'ids', '' );

		$attachment_ids = $this->get_attachment_ids( $ids_flag, $force );

		if ( empty( $attachment_ids ) ) {
			WP_CLI::success( 'No images found matching the criteria.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d image(s) to process.', count( $attachment_ids ) ) );

		if ( $dry_run ) {
			$this->display_dry_run( $attachment_ids );
			return;
		}

		$stats = $this->process_images( $ability, $attachment_ids, $batch_size, $delay_ms, $force );
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

		wp_set_current_user( (int) $admins[0] );
	}

	/**
	 * Gets attachment IDs to process.
	 *
	 * @param string $ids_flag Comma-separated IDs from the --ids flag.
	 * @param bool   $force    Whether to include images that already have alt text.
	 * @return int[] Array of attachment IDs.
	 */
	private function get_attachment_ids( string $ids_flag, bool $force ): array {
		if ( '' !== $ids_flag ) {
			$ids = array_map( 'absint', explode( ',', $ids_flag ) );
			$ids = array_filter( $ids );

			// Validate they exist and are images.
			return array_values(
				array_filter(
					$ids,
					static function ( int $id ): bool {
						return get_post( $id ) && wp_attachment_is_image( $id );
					}
				)
			);
		}

		$query_args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
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

		$query = new \WP_Query( $query_args );

		return array_map( 'absint', $query->posts );
	}

	/**
	 * Displays the list of images that would be processed in a dry run.
	 *
	 * @param int[] $attachment_ids Array of attachment IDs.
	 */
	private function display_dry_run( array $attachment_ids ): void {
		$items = array();
		foreach ( $attachment_ids as $id ) {
			$alt     = get_post_meta( $id, '_wp_attachment_image_alt', true );
			$items[] = array(
				'ID'          => $id,
				'Title'       => get_the_title( $id ),
				'Current Alt' => ! empty( $alt ) ? $alt : '(empty)',
			);
		}

		Utils\format_items( 'table', $items, array( 'ID', 'Title', 'Current Alt' ) );
		WP_CLI::log( sprintf( "\nDry run complete. %d image(s) would be processed.", count( $attachment_ids ) ) );
	}

	/**
	 * Processes images through the alt text generation ability.
	 *
	 * @param object $ability        The alt text generation ability.
	 * @param int[]  $attachment_ids Array of attachment IDs.
	 * @param int    $batch_size     Number of images per batch.
	 * @param int    $delay_ms       Delay in milliseconds between API calls.
	 * @param bool   $force          Whether to regenerate existing alt text.
	 * @return array{generated: int, decorative: int, skipped: int, failed: int}
	 */
	private function process_images( $ability, array $attachment_ids, int $batch_size, int $delay_ms, bool $force ): array {
		$stats    = array(
			'generated'  => 0,
			'decorative' => 0,
			'skipped'    => 0,
			'failed'     => 0,
		);
		$progress = Utils\make_progress_bar( 'Generating alt text', count( $attachment_ids ) );
		$batches  = array_chunk( $attachment_ids, $batch_size );

		foreach ( $batches as $batch ) {
			foreach ( $batch as $id ) {
				$current_alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
				if ( ! $force && '' !== $current_alt && false !== $current_alt ) {
					++$stats['skipped'];
					$progress->tick();
					continue;
				}

				$result = $ability->execute( array( 'attachment_id' => $id ) );

				if ( is_wp_error( $result ) ) {
					++$stats['failed'];
					WP_CLI::warning( sprintf( 'ID %d: %s', $id, $result->get_error_message() ) );
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

				$progress->tick();

				if ( $delay_ms <= 0 ) {
					continue;
				}

				usleep( $delay_ms * 1000 );
			}

			$this->stop_the_insanity();
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
	 * Clears the WordPress object cache to prevent memory exhaustion during batch processing.
	 */
	private function stop_the_insanity(): void {
		global $wpdb, $wp_object_cache;

		$wpdb->queries = array();

		if ( ! is_object( $wp_object_cache ) ) {
			return;
		}

		if ( property_exists( $wp_object_cache, 'group_ops' ) ) {
			$wp_object_cache->group_ops = array();
		}
		if ( property_exists( $wp_object_cache, 'stats' ) ) {
			$wp_object_cache->stats = array();
		}
		if ( property_exists( $wp_object_cache, 'memcache_debug' ) ) {
			$wp_object_cache->memcache_debug = array();
		}
		if ( property_exists( $wp_object_cache, 'cache' ) ) {
			$wp_object_cache->cache = array();
		}
		if ( ! method_exists( $wp_object_cache, '__remoteset' ) ) {
			return;
		}

		$wp_object_cache->__remoteset();
	}
}

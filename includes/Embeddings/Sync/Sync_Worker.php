<?php
/**
 * Background worker for embedding sync.
 *
 * @package WordPress\AI\Embeddings\Sync
 */

declare( strict_types=1 );

namespace WordPress\AI\Embeddings\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Processes the sync queue and running backfills.
 *
 * @since x.x.x
 */
class Sync_Worker {

	/**
	 * WP-Cron hook that runs the worker.
	 *
	 * @since x.x.x
	 */
	public const CRON_HOOK = 'wpai_embedding_sync_run';

	/**
	 * Schedules a worker run now unless one is already scheduled.
	 *
	 * @since x.x.x
	 */
	public static function wake(): void {
		if ( false !== wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time(), self::CRON_HOOK );
	}
}

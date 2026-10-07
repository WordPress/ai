<?php
/**
 * WP-CLI commands for embedding sync.
 *
 * @package WordPress\AI\CLI
 */

declare( strict_types=1 );

namespace WordPress\AI\CLI;

use WP_CLI;
use WordPress\AI\Embeddings\Sync\Embedding_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Inspects and drives background embedding sync.
 *
 * ## EXAMPLES
 *
 *     # Show coverage and queue state for every consumer.
 *     $ wp ai embeddings sync status
 *
 *     # Embed a consumer's existing content in the foreground.
 *     $ wp ai embeddings sync backfill related-posts
 *
 * @since x.x.x
 */
class Embedding_Sync_Command {

	/**
	 * Longest wait for a provider backoff before handing remaining work to WP-Cron, in seconds.
	 */
	private const MAX_WAIT = 900;

	/**
	 * Shows sync status for registered consumers.
	 *
	 * ## OPTIONS
	 *
	 * [--consumer=<id>]
	 * : Only show this consumer.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @since x.x.x
	 *
	 * @param list<string>               $args       Positional arguments.
	 * @param array<string, string|bool> $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ): void {
		unset( $args );

		$consumer = null;

		if ( isset( $assoc_args['consumer'] ) ) {
			if ( ! is_string( $assoc_args['consumer'] ) || '' === $assoc_args['consumer'] ) {
				WP_CLI::error( 'Pass a consumer ID: --consumer=<id>.' );
			}

			$consumer = (string) $assoc_args['consumer'];
		}

		$ids  = $this->resolve_consumers( $consumer );
		$rows = array();

		foreach ( $ids as $id ) {
			$status = Embedding_Sync::get_status( $id );

			if ( null === $status ) {
				continue;
			}

			foreach ( $status['coverage'] as $object_type => $subtypes ) {
				foreach ( $subtypes as $subtype => $counts ) {
					$rows[] = array(
						'consumer'  => $id,
						'model'     => $status['target']['provider'] . '/' . $status['target']['model'],
						'object'    => $object_type . ':' . $subtype,
						'indexed'   => $counts['indexed'],
						'indexable' => $counts['indexable'],
						'backfill'  => null === $status['backfill'] ? 'none' : (string) $status['backfill']['status'],
						'pending'   => $status['queue']['pending'],
						'failed'    => $status['queue']['failed'],
						'paused'    => null === $status['backoff'] ? '' : gmdate( 'Y-m-d H:i:s', $status['backoff'] ) . ' UTC',
						'error'     => (string) $status['provider_error'],
						'last_run'  => null === $status['last_run'] ? 'never' : gmdate( 'Y-m-d H:i:s', $status['last_run'] ) . ' UTC',
					);
				}
			}
		}

		WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			array( 'consumer', 'model', 'object', 'indexed', 'indexable', 'backfill', 'pending', 'failed', 'paused', 'error', 'last_run' )
		);
	}

	/**
	 * Runs the sync worker in the foreground until no work is due.
	 *
	 * Clears provider pauses first, so an explicit run retries a paused provider. Waits out
	 * rate-limit pauses of up to 15 minutes; anything due later is left to WP-Cron.
	 *
	 * ## OPTIONS
	 *
	 * [--retry-failed]
	 * : Requeue failed objects first.
	 *
	 * @since x.x.x
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function run( $args, $assoc_args ): void {
		unset( $args );

		$sync = Embedding_Sync::instance();

		if ( ! $sync->get_registry()->has_consumers() ) {
			WP_CLI::error( 'No embedding consumers are registered, so there is nothing to sync.' );
		}

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'retry-failed', false ) ) {
			WP_CLI::log( sprintf( 'Requeued %d failed object(s).', Embedding_Sync::retry_failed() ) );
		}

		foreach ( $sync->get_registry()->get_targets() as $target ) {
			$sync->get_backoff()->clear( $target->get_provider() );
			$sync->get_backoff()->clear( $target->get_provider(), $target->get_model() );
		}

		$worker = $sync->get_worker();

		while ( true ) {
			$stats = $worker->run( 0 );

			if ( ! $stats['ran'] ) {
				WP_CLI::error( 'Another sync run holds the lock. Try again in a few minutes.' );
			}

			$processed = $stats['queue'] + $stats['backfill'];
			$next      = $worker->get_next_run_at();
			$wait      = null === $next ? null : $next - time();

			// Skip the progress line when nothing was processed and the loop is about to wait.
			if ( $processed > 0 || null === $wait || $wait <= 0 || $wait > self::MAX_WAIT ) {
				WP_CLI::log( sprintf( 'Processed %d queued and %d backfill object(s).', $stats['queue'], $stats['backfill'] ) );
			}

			if ( null === $wait ) {
				break;
			}

			if ( $wait > self::MAX_WAIT ) {
				WP_CLI::warning( sprintf( 'Remaining work is not due for %d second(s); leaving it to WP-Cron.', $wait ) );
				return;
			}

			if ( $wait <= 0 ) {
				if ( 0 === $processed ) {
					WP_CLI::warning( 'Work is due but no progress was made (check the database error log); leaving it to WP-Cron.' );
					return;
				}

				continue;
			}

			WP_CLI::log( sprintf( 'Waiting %d second(s) for a provider pause to end…', $wait ) );
			sleep( $wait );
		}

		WP_CLI::success( 'Sync run finished.' );
	}

	/**
	 * Starts or resumes a consumer's backfill, then runs it in the foreground.
	 *
	 * ## OPTIONS
	 *
	 * <consumer>
	 * : Consumer ID.
	 *
	 * [--[no-]run]
	 * : Run the backfill in the foreground (default). With `--no-run`, only mark the backfill started; WP-Cron does the work.
	 *
	 * @since x.x.x
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function backfill( $args, $assoc_args ): void {
		$consumer = (string) $args[0];

		if ( ! Embedding_Sync::start_backfill( $consumer ) ) {
			WP_CLI::error( sprintf( 'Unknown embedding consumer "%s".', $consumer ) );
		}

		WP_CLI::log( sprintf( 'Backfill started for "%s".', $consumer ) );

		if ( ! WP_CLI\Utils\get_flag_value( $assoc_args, 'run', true ) ) {
			WP_CLI::success( 'WP-Cron will process it in the background.' );
			return;
		}

		$this->run( array(), array() );
	}

	/**
	 * Cancels a consumer's running backfill; `backfill` resumes it later.
	 *
	 * ## OPTIONS
	 *
	 * <consumer>
	 * : Consumer ID.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $args Positional arguments.
	 */
	public function cancel( $args ): void {
		$consumer = (string) $args[0];

		if ( ! Embedding_Sync::cancel_backfill( $consumer ) ) {
			WP_CLI::error( sprintf( 'No running backfill for "%s".', $consumer ) );
		}

		WP_CLI::success( sprintf( 'Backfill cancelled for "%s".', $consumer ) );
	}

	/**
	 * Forgets a consumer's backfill progress so the next backfill starts from the first ID.
	 *
	 * Stored vectors are kept, so a fresh backfill skips unchanged objects without API calls.
	 *
	 * ## OPTIONS
	 *
	 * <consumer>
	 * : Consumer ID.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @since x.x.x
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 */
	public function reset( $args, $assoc_args ): void {
		$consumer = (string) $args[0];

		if ( null === Embedding_Sync::instance()->get_registry()->get_consumer( $consumer ) ) {
			WP_CLI::error( sprintf( 'Unknown embedding consumer "%s".', $consumer ) );
		}

		WP_CLI::confirm( sprintf( 'Forget backfill progress for "%s"?', $consumer ), $assoc_args );

		if ( ! Embedding_Sync::reset_backfill( $consumer ) ) {
			WP_CLI::error( sprintf( 'Unknown embedding consumer "%s".', $consumer ) );
		}

		WP_CLI::success( sprintf( 'Backfill progress reset for "%s".', $consumer ) );
	}

	/**
	 * Returns the consumer IDs to report on.
	 *
	 * @since x.x.x
	 *
	 * @param string|null $consumer A single consumer, or null for all.
	 * @return list<string> Consumer IDs.
	 */
	private function resolve_consumers( ?string $consumer ): array {
		$registry = Embedding_Sync::instance()->get_registry();

		if ( null !== $consumer ) {
			if ( null === $registry->get_consumer( $consumer ) ) {
				WP_CLI::error( sprintf( 'Unknown embedding consumer "%s".', $consumer ) );
			}

			return array( $consumer );
		}

		$ids = $registry->get_consumer_ids();

		if ( array() === $ids ) {
			WP_CLI::warning( 'No embedding consumers are registered.' );
		}

		return $ids;
	}
}

<?php
/**
 * Job status values.
 *
 * @package WordPress\AI\Jobs
 */

declare( strict_types=1 );

namespace WordPress\AI\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Statuses a queued job can be in.
 *
 * @since 1.6.0
 */
final class Job_Status {

	/**
	 * The job is stored and waiting for its first run.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const ENQUEUED = 'enqueued';

	/**
	 * A runner is handling the job now.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const RUNNING = 'running';

	/**
	 * The job finished successfully.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const COMPLETED = 'completed';

	/**
	 * The job failed permanently.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const FAILED = 'failed';

	/**
	 * Returns every status.
	 *
	 * @since 1.6.0
	 *
	 * @return list<string> The statuses.
	 */
	public static function all(): array {
		return array(
			self::ENQUEUED,
			self::RUNNING,
			self::COMPLETED,
			self::FAILED,
		);
	}

	/**
	 * Returns whether a status is final.
	 *
	 * @since 1.6.0
	 *
	 * @param string $status The status.
	 * @return bool True when the job will not run again.
	 */
	public static function is_terminal( string $status ): bool {
		return in_array( $status, array( self::COMPLETED, self::FAILED ), true );
	}
}

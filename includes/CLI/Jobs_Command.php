<?php
/**
 * WP-CLI commands for async AI jobs.
 *
 * @package WordPress\AI\CLI
 */

declare( strict_types=1 );

namespace WordPress\AI\CLI;

use WP_CLI;
use WordPress\AI\Jobs\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Inspects, runs and cleans up async AI jobs.
 *
 * @since 1.6.0
 */
class Jobs_Command {

	/**
	 * Prints a job's status and result.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID returned when the job was enqueued.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp ai jobs status 2f7c8e9a
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string> $args Positional arguments.
	 */
	public function status( array $args ): void {
		$job = $this->find_job( $args );

		if ( null === $job ) {
			return;
		}

		WP_CLI::log( (string) wp_json_encode( $job->to_array(), JSON_PRETTY_PRINT ) );
	}

	/**
	 * Runs a job immediately, without waiting for WP-Cron.
	 *
	 * ## OPTIONS
	 *
	 * <job_id>
	 * : The job ID returned when the job was enqueued.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp ai jobs run 2f7c8e9a
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string> $args Positional arguments.
	 */
	public function run( array $args ): void {
		$job = $this->find_job( $args );

		if ( null === $job ) {
			return;
		}

		Jobs::instance()->get_queue()->run( $job->get_job_id() );

		$job = Jobs::instance()->get_store()->find( $job->get_job_id() );

		if ( null === $job ) {
			WP_CLI::error( 'The job disappeared while running.' );
			return;
		}

		WP_CLI::log( (string) wp_json_encode( $job->to_array(), JSON_PRETTY_PRINT ) );

		if ( $job->is_terminal() && 'completed' === $job->get_status() ) {
			WP_CLI::success( 'Job completed.' );
			return;
		}

		WP_CLI::error( sprintf( 'Job finished with status: %s', $job->get_status() ) );
	}

	/**
	 * Deletes expired jobs.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp ai jobs cleanup
	 *
	 * @when after_wp_load
	 */
	public function cleanup(): void {
		$deleted = Jobs::instance()->get_store()->delete_expired();

		WP_CLI::success( sprintf( 'Deleted %d expired job(s).', $deleted ) );
	}

	/**
	 * Resolves and validates the job ID argument.
	 *
	 * @since 1.6.0
	 *
	 * @param array<int, string> $args Positional arguments.
	 * @return \WordPress\AI\Jobs\Job|null The job, or null when invalid.
	 */
	private function find_job( array $args ) {
		$job_id = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : '';

		if ( '' === $job_id ) {
			WP_CLI::error( 'A job ID is required.' );
			return null;
		}

		$job = Jobs::instance()->get_store()->find( $job_id );

		if ( null === $job ) {
			WP_CLI::error( sprintf( 'No job exists with the ID %s.', $job_id ) );
			return null;
		}

		return $job;
	}
}

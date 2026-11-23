<?php
/**
 * Async job queue.
 *
 * @package WordPress\AI\Jobs
 */

declare( strict_types=1 );

namespace WordPress\AI\Jobs;

use Throwable;
use WP_Error;
use WordPress\AI\Background\Lock;
use WordPress\AI\Background\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues jobs and runs them from WP-Cron.
 *
 * Enqueuing writes one row and schedules one WP-Cron event, then returns
 * immediately. A cron request claims the job with a per-job lock, re-checks
 * the enqueuing user's permission, and runs the registered job type.
 *
 * @since 1.6.0
 */
class Job_Queue {

	/**
	 * WP-Cron hook that runs a job. Receives the job ID.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const RUN_HOOK = 'wpai_run_job';

	/**
	 * WP-Cron hook that purges expired job rows.
	 *
	 * @since 1.6.0
	 *
	 * @var string
	 */
	public const CLEANUP_HOOK = 'wpai_jobs_cleanup';

	/**
	 * Default seconds a finished job stays pollable.
	 *
	 * @since 1.6.0
	 *
	 * @var int
	 */
	public const DEFAULT_RETENTION = DAY_IN_SECONDS;

	/**
	 * Seconds to wait before retrying a job another runner holds.
	 *
	 * @since 1.6.0
	 *
	 * @var int
	 */
	private const LOCKED_RETRY_SECONDS = 30;

	/**
	 * Job type registry.
	 *
	 * @var \WordPress\AI\Jobs\Job_Registry
	 */
	private Job_Registry $registry;

	/**
	 * Job store.
	 *
	 * @var \WordPress\AI\Jobs\Job_Store
	 */
	private Job_Store $store;

	/**
	 * Seconds a job runner lock lives without a refresh.
	 *
	 * @var int
	 */
	private int $lock_ttl;

	/**
	 * Constructor.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AI\Jobs\Job_Registry $registry Job type registry.
	 * @param \WordPress\AI\Jobs\Job_Store    $store    Job store.
	 * @param int                             $lock_ttl Optional. Runner lock TTL in seconds. Default 300.
	 */
	public function __construct( Job_Registry $registry, Job_Store $store, int $lock_ttl = Lock::DEFAULT_TTL ) {
		$this->registry = $registry;
		$this->store    = $store;
		$this->lock_ttl = $lock_ttl;
	}

	/**
	 * Enqueues a job.
	 *
	 * @since 1.6.0
	 *
	 * @param string               $type    The registered job type.
	 * @param array<string, mixed> $args    Optional. Arguments passed to the job type. Default empty.
	 * @param array<string, mixed> $options {
	 *     Optional. Enqueue options.
	 *
	 *     @type int    $user_id         User to run as. Default the current user.
	 *     @type string $idempotency_key Client key that makes repeated requests return the same job.
	 *     @type int    $scheduled_at    Unix time the job should run. Default as soon as possible.
	 *     @type int    $ttl             Seconds the finished job stays pollable. Default one day.
	 * }
	 * @return string|\WP_Error The job ID, or a WP_Error.
	 */
	public function enqueue( string $type, array $args = array(), array $options = array() ) {
		$job_type = $this->registry->get( $type );

		if ( null === $job_type ) {
			return new WP_Error(
				'wpai_unknown_job_type',
				/* translators: %s: Job type ID. */
				sprintf( esc_html__( 'Unknown job type: %s', 'ai' ), $type ),
				array( 'status' => 400 )
			);
		}

		$user_id = isset( $options['user_id'] ) ? (int) $options['user_id'] : get_current_user_id();
		$key     = isset( $options['idempotency_key'] ) ? (string) $options['idempotency_key'] : '';
		$key     = (string) substr( (string) sanitize_text_field( $key ), 0, 191 );

		if ( '' !== $key ) {
			$existing = $this->store->find_by_idempotency_key( $type, $key, $user_id );

			if ( null !== $existing ) {
				if ( ! $existing->is_terminal() || $existing->get_expires_at() > time() ) {
					return $existing->get_job_id();
				}

				$this->store->delete( $existing->get_job_id() );
			}
		}

		if ( $user_id > 0 ) {
			$allowed = $job_type->can_enqueue( $args, $user_id );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			if ( true !== $allowed ) {
				return new WP_Error(
					'wpai_job_not_allowed',
					esc_html__( 'You are not allowed to enqueue this job.', 'ai' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}

		$now    = time();
		$job_id = $this->generate_job_id();
		$job    = new Job( $job_id, $type, $args, $user_id, $now );

		if ( '' !== $key ) {
			$job->set_idempotency_key( $key );
		}

		$ttl = isset( $options['ttl'] ) ? max( 1, (int) $options['ttl'] ) : self::DEFAULT_RETENTION;
		$job->set_expires_at( $now + $ttl );

		if ( ! $this->store->create( $job ) ) {
			return new WP_Error(
				'wpai_job_store_failed',
				esc_html__( 'The job could not be stored.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		$scheduled_at = isset( $options['scheduled_at'] ) ? max( $now + 1, (int) $options['scheduled_at'] ) : $now + 1;

		Scheduler::schedule_at( self::RUN_HOOK, $scheduled_at, array( $job_id ) );

		/**
		 * Fires after a job is stored and scheduled.
		 *
		 * @since 1.6.0
		 *
		 * @param \WordPress\AI\Jobs\Job $job The job.
		 */
		do_action( 'wpai_job_enqueued', $job );

		return $job_id;
	}

	/**
	 * Runs a job from WP-Cron.
	 *
	 * @since 1.6.0
	 *
	 * @param string $job_id The job ID.
	 */
	public function run( string $job_id ): void {
		$job = $this->store->find( $job_id );

		if ( null === $job || $job->is_terminal() ) {
			return;
		}

		$lock = new Lock( 'job_' . substr( hash( 'sha256', $job_id ), 0, 32 ), $this->lock_ttl );

		if ( ! $lock->acquire() ) {
			Scheduler::schedule_at( self::RUN_HOOK, time() + self::LOCKED_RETRY_SECONDS, array( $job_id ) );

			return;
		}

		try {
			// Re-read under the lock: another runner may have finished it
			// between the first read and acquiring the lock.
			$job = $this->store->find( $job_id );

			if ( null === $job || $job->is_terminal() ) {
				return;
			}

			$previous_status = $job->get_status();
			$job->mark_running();

			if ( ! $this->store->update( $job, $previous_status ) ) {
				return;
			}

			$job_type = $this->registry->get( $job->get_type() );

			if ( null === $job_type ) {
				$this->fail( $job, 'wpai_unknown_job_type', __( 'The job type is no longer registered.', 'ai' ) );

				return;
			}

			$previous_user = get_current_user_id();
			wp_set_current_user( $job->get_user_id() );

			try {
				$allowed = $job->get_user_id() > 0 ? $job_type->can_enqueue( $job->get_args(), $job->get_user_id() ) : true;

				if ( is_wp_error( $allowed ) ) {
					$this->fail( $job, (string) $allowed->get_error_code(), $allowed->get_error_message() );

					return;
				}

				if ( true !== $allowed ) {
					$this->fail( $job, 'wpai_job_not_allowed', __( 'The enqueuing user is no longer allowed to run this job.', 'ai' ) );

					return;
				}

				$result = $job_type->handle( $job->get_args(), $job );
			} catch ( Throwable $e ) {
				$result = new WP_Error( 'wpai_job_exception', $e->getMessage() );
			} finally {
				wp_set_current_user( $previous_user );
			}

			if ( is_wp_error( $result ) ) {
				$this->fail( $job, (string) $result->get_error_code(), $result->get_error_message() );

				return;
			}

			$job->mark_completed( $result );
			$job->set_expires_at( time() + $this->get_retention() );
			$this->store->update( $job, Job_Status::RUNNING );

			/**
			 * Fires after a job completes successfully.
			 *
			 * @since 1.6.0
			 *
			 * @param \WordPress\AI\Jobs\Job $job    The job.
			 * @param mixed                  $result The job result.
			 */
			do_action( 'wpai_job_completed', $job, $result );
		} finally {
			$lock->release();
		}
	}

	/**
	 * Marks a job as failed and updates its retention.
	 *
	 * @since 1.6.0
	 *
	 * @param \WordPress\AI\Jobs\Job $job     The job.
	 * @param string                 $code    The error code.
	 * @param string                 $message The error message.
	 */
	private function fail( Job $job, string $code, string $message ): void {
		$job->mark_failed( $code, $message );
		$job->set_expires_at( time() + $this->get_retention() );
		$this->store->update( $job, Job_Status::RUNNING );

		/**
		 * Fires after a job fails.
		 *
		 * @since 1.6.0
		 *
		 * @param \WordPress\AI\Jobs\Job $job     The job.
		 * @param string                 $code    The error code.
		 * @param string                 $message The error message.
		 */
		do_action( 'wpai_job_failed', $job, $code, $message );
	}

	/**
	 * Returns how long a finished job stays pollable.
	 *
	 * @since 1.6.0
	 *
	 * @return int Seconds.
	 */
	private function get_retention(): int {
		/**
		 * Filters how long a finished job stays pollable before cleanup.
		 *
		 * @since 1.6.0
		 *
		 * @param int $retention Seconds. Default one day.
		 */
		return max( 1, (int) apply_filters( 'wpai_job_retention', self::DEFAULT_RETENTION ) );
	}

	/**
	 * Generates a unique job ID.
	 *
	 * @since 1.6.0
	 *
	 * @return string A 32 character hex string.
	 */
	private function generate_job_id(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $e ) {
			return md5( uniqid( (string) wp_rand(), true ) );
		}
	}
}

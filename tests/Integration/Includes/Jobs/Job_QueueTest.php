<?php
/**
 * Tests for the async job queue.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Jobs
 */

namespace WordPress\AI\Tests\Integration\Includes\Jobs;

use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Jobs\Job;
use WordPress\AI\Jobs\Job_Queue;
use WordPress\AI\Jobs\Job_Registry;
use WordPress\AI\Jobs\Job_Status;
use WordPress\AI\Jobs\Job_Store;
use WordPress\AI\Jobs\Job_Type_Interface;

/**
 * A job type that records what happened.
 */
class Fake_Job_Type implements Job_Type_Interface {

	/**
	 * Number of times handle() ran.
	 *
	 * @var int
	 */
	public int $handled = 0;

	/**
	 * Result to return.
	 *
	 * @var mixed
	 */
	public $result = 'done';

	/**
	 * Error to return from handle(), if any.
	 *
	 * @var \WP_Error|null
	 */
	public $error = null;

	/**
	 * Whether the user may enqueue the job.
	 *
	 * @var bool
	 */
	public bool $allowed = true;

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'test/fake';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return 'Fake';
	}

	/**
	 * {@inheritDoc}
	 */
	public function handle( array $args, Job $job ) {
		++$this->handled;

		return null !== $this->error ? $this->error : $this->result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_enqueue( array $args, int $user_id ) {
		if ( $this->allowed ) {
			return true;
		}

		return new WP_Error( 'not_allowed', 'No.' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_input_schema(): array {
		return array( 'type' => 'object' );
	}
}

/**
 * Job_Queue test case.
 *
 * @since 1.6.0
 *
 * @covers \WordPress\AI\Jobs\Job_Queue
 * @covers \WordPress\AI\Jobs\Job
 * @covers \WordPress\AI\Jobs\Job_Store
 */
class Job_QueueTest extends WP_UnitTestCase {

	/**
	 * Registry under test.
	 *
	 * @var \WordPress\AI\Jobs\Job_Registry
	 */
	private Job_Registry $registry;

	/**
	 * Store under test.
	 *
	 * @var \WordPress\AI\Jobs\Job_Store
	 */
	private Job_Store $store;

	/**
	 * Queue under test.
	 *
	 * @var \WordPress\AI\Jobs\Job_Queue
	 */
	private Job_Queue $queue;

	/**
	 * Fake job type.
	 *
	 * @var Fake_Job_Type
	 */
	private Fake_Job_Type $type;

	/**
	 * Sets up the queue.
	 *
	 * @since 1.6.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->registry = new Job_Registry();
		$this->store    = new Job_Store();
		$this->queue    = new Job_Queue( $this->registry, $this->store );
		$this->type     = new Fake_Job_Type();

		$this->registry->register( $this->type );
	}

	/**
	 * Clears scheduled runs.
	 *
	 * @since 1.6.0
	 */
	public function tear_down(): void {
		wp_unschedule_hook( Job_Queue::RUN_HOOK );

		parent::tear_down();
	}

	/**
	 * Enqueuing stores the job and schedules a run.
	 *
	 * @since 1.6.0
	 */
	public function test_enqueue_stores_and_schedules_the_job(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$job_id = $this->queue->enqueue(
			'test/fake',
			array( 'post_id' => 12 ),
			array( 'user_id' => $user_id )
		);

		$this->assertIsString( $job_id );

		$job = $this->store->find( $job_id );

		$this->assertInstanceOf( Job::class, $job );
		$this->assertSame( Job_Status::ENQUEUED, $job->get_status() );
		$this->assertSame( array( 'post_id' => 12 ), $job->get_args() );
		$this->assertSame( $user_id, $job->get_user_id() );
		$this->assertNotFalse( wp_next_scheduled( Job_Queue::RUN_HOOK, array( $job_id ) ) );
	}

	/**
	 * An unknown job type is rejected.
	 *
	 * @since 1.6.0
	 */
	public function test_unknown_type_is_rejected(): void {
		$result = $this->queue->enqueue( 'test/missing' );

		$this->assertWPError( $result );
		$this->assertSame( 'wpai_unknown_job_type', $result->get_error_code() );
	}

	/**
	 * A repeated request with the same idempotency key reuses the job.
	 *
	 * @since 1.6.0
	 */
	public function test_idempotency_key_reuses_the_job(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$first  = $this->queue->enqueue( 'test/fake', array(), array( 'user_id' => $user_id, 'idempotency_key' => 'abc' ) );
		$second = $this->queue->enqueue( 'test/fake', array(), array( 'user_id' => $user_id, 'idempotency_key' => 'abc' ) );

		$this->assertSame( $first, $second );
	}

	/**
	 * Running a job completes it and stores the result.
	 *
	 * @since 1.6.0
	 */
	public function test_run_completes_the_job(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$job_id  = $this->queue->enqueue( 'test/fake', array(), array( 'user_id' => $user_id ) );

		$this->queue->run( $job_id );

		$job = $this->store->find( $job_id );

		$this->assertInstanceOf( Job::class, $job );
		$this->assertSame( Job_Status::COMPLETED, $job->get_status() );
		$this->assertSame( 'done', $job->get_result() );
		$this->assertSame( 1, $this->type->handled );
		$this->assertSame( 1, $job->get_attempts() );
	}

	/**
	 * A job fails when the enqueuing user no longer has permission.
	 *
	 * @since 1.6.0
	 */
	public function test_run_rechecks_permission(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$job_id  = $this->queue->enqueue( 'test/fake', array(), array( 'user_id' => $user_id ) );

		$this->type->allowed = false;

		$this->queue->run( $job_id );

		$job = $this->store->find( $job_id );

		$this->assertInstanceOf( Job::class, $job );
		$this->assertSame( Job_Status::FAILED, $job->get_status() );
		$this->assertSame( 'not_allowed', $job->get_error_code() );
		$this->assertSame( 0, $this->type->handled );
	}

	/**
	 * A WP_Error from the job type marks the job failed.
	 *
	 * @since 1.6.0
	 */
	public function test_wp_error_marks_the_job_failed(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$job_id  = $this->queue->enqueue( 'test/fake', array(), array( 'user_id' => $user_id ) );

		$this->type->error = new WP_Error( 'boom', 'Something broke.' );

		$this->queue->run( $job_id );

		$job = $this->store->find( $job_id );

		$this->assertInstanceOf( Job::class, $job );
		$this->assertSame( Job_Status::FAILED, $job->get_status() );
		$this->assertSame( 'boom', $job->get_error_code() );
		$this->assertSame( 'Something broke.', $job->get_error() );
	}

	/**
	 * Running a finished job again does nothing.
	 *
	 * @since 1.6.0
	 */
	public function test_terminal_jobs_do_not_run_again(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$job_id  = $this->queue->enqueue( 'test/fake', array(), array( 'user_id' => $user_id ) );

		$this->queue->run( $job_id );
		$this->queue->run( $job_id );

		$this->assertSame( 1, $this->type->handled );
	}

	/**
	 * Expired jobs are purged.
	 *
	 * @since 1.6.0
	 */
	public function test_expired_jobs_are_purged(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$job_id  = $this->queue->enqueue( 'test/fake', array(), array( 'user_id' => $user_id, 'ttl' => 1 ) );

		$job = $this->store->find( $job_id );
		$this->assertInstanceOf( Job::class, $job );

		$job->set_expires_at( time() - 10 );
		$this->store->update( $job );

		$this->store->delete_expired( 10 );

		$this->assertNull( $this->store->find( $job_id ) );
	}

	/**
	 * The registry refuses duplicate IDs.
	 *
	 * @since 1.6.0
	 */
	public function test_registry_refuses_duplicate_ids(): void {
		$this->assertTrue( $this->registry->register( new Fake_Job_Type() ) === false );
	}
}

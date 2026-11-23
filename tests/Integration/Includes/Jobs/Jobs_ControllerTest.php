<?php
/**
 * Tests for the async jobs REST controller.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Jobs
 */

namespace WordPress\AI\Tests\Integration\Includes\Jobs;

use WP_REST_Request;
use WP_UnitTestCase;
use WordPress\AI\Jobs\Job;
use WordPress\AI\Jobs\Job_Queue;
use WordPress\AI\Jobs\Job_Registry;
use WordPress\AI\Jobs\Job_Store;
use WordPress\AI\Jobs\Job_Type_Interface;
use WordPress\AI\Jobs\Jobs;

/**
 * A minimal job type for the REST tests.
 */
class Rest_Fake_Job_Type implements Job_Type_Interface {

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
		return 'done';
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_enqueue( array $args, int $user_id ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_input_schema(): array {
		return array( 'type' => 'object' );
	}
}

/**
 * Jobs_Controller test case.
 *
 * @since 1.6.0
 *
 * @covers \WordPress\AI\Jobs\REST\Jobs_Controller
 */
class Jobs_ControllerTest extends WP_UnitTestCase {

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
	 * Sets up the controller and routes.
	 *
	 * @since 1.6.0
	 */
	public function set_up(): void {
		parent::set_up();

		$jobs = Jobs::instance();
		$jobs->init();

		$this->registry = $jobs->get_registry();
		$this->store    = $jobs->get_store();
		$this->queue    = $jobs->get_queue();

		$this->registry->register( new Rest_Fake_Job_Type() );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Testing registration on the core REST API hook.
		do_action( 'rest_api_init' );
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
	 * POST stores a job and answers 202 with a status URL.
	 *
	 * @since 1.6.0
	 */
	public function test_create_job_returns_accepted(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/ai/v1/jobs' );
		$request->set_param( 'type', 'test/fake' );
		$request->set_param( 'args', array( 'post_id' => 7 ) );

		$response = rest_do_request( $request );

		$this->assertSame( 202, $response->get_status() );

		$data = $response->get_data();

		$this->assertIsString( $data['job_id'] );
		$this->assertSame( 'enqueued', $data['status'] );
		$this->assertStringContainsString( '/ai/v1/jobs/', $data['status_url'] );
		$this->assertNotNull( $this->store->find( $data['job_id'] ) );
	}

	/**
	 * An unknown job type is a client error.
	 *
	 * @since 1.6.0
	 */
	public function test_create_job_rejects_unknown_type(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/ai/v1/jobs' );
		$request->set_param( 'type', 'test/missing' );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Anonymous users cannot enqueue.
	 *
	 * @since 1.6.0
	 */
	public function test_create_job_requires_login(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/ai/v1/jobs' );
		$request->set_param( 'type', 'test/fake' );

		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * The enqueuing user can poll the job.
	 *
	 * @since 1.6.0
	 */
	public function test_get_job_returns_status_to_owner(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$job_id = $this->queue->enqueue( 'test/fake', array(), array( 'user_id' => $user_id ) );

		$request = new WP_REST_Request( 'GET', '/ai/v1/jobs/' . $job_id );
		$request->set_param( 'job_id', $job_id );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $job_id, $response->get_data()['job_id'] );
	}

	/**
	 * Other users cannot read a job they do not own.
	 *
	 * @since 1.6.0
	 */
	public function test_get_job_is_forbidden_for_other_users(): void {
		$owner_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$other_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$job_id = $this->queue->enqueue( 'test/fake', array(), array( 'user_id' => $owner_id ) );

		wp_set_current_user( $other_id );

		$request = new WP_REST_Request( 'GET', '/ai/v1/jobs/' . $job_id );
		$request->set_param( 'job_id', $job_id );

		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
	}
}

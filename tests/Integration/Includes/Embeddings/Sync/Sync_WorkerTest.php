<?php
/**
 * Tests for the sync worker.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Embedding_Repository;
use WordPress\AI\Embeddings\Sync\Backfill_Manager;
use WordPress\AI\Embeddings\Sync\Consumer_Registry;
use WordPress\AI\Embeddings\Sync\Embedding_Client_Exception;
use WordPress\AI\Embeddings\Sync\Object_Processor;
use WordPress\AI\Embeddings\Sync\Post_Source;
use WordPress\AI\Embeddings\Sync\Provider_Backoff;
use WordPress\AI\Embeddings\Sync\Sync_Lock;
use WordPress\AI\Embeddings\Sync\Sync_Queue;
use WordPress\AI\Embeddings\Sync\Sync_Worker;
use WordPress\AI\Embeddings\Sync\Term_Source;

/**
 * Sync_Worker test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Sync\Sync_Worker
 */
class Sync_WorkerTest extends WP_UnitTestCase {

	use Sync_Tables_Trait;

	private const MODEL = 'text-embedding-3-small';

	/**
	 * Registry.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Consumer_Registry
	 */
	private Consumer_Registry $registry;

	/**
	 * Queue.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Sync_Queue
	 */
	private Sync_Queue $queue;

	/**
	 * Repository.
	 *
	 * @var \WordPress\AI\Embeddings\Embedding_Repository
	 */
	private Embedding_Repository $repository;

	/**
	 * Fake client.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Embeddings\Sync\Fake_Embedding_Client
	 */
	private Fake_Embedding_Client $client;

	/**
	 * Backfills.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Backfill_Manager
	 */
	private Backfill_Manager $backfills;

	/**
	 * Backoff.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Provider_Backoff
	 */
	private Provider_Backoff $backoff;

	/**
	 * Creates the tables once for the class.
	 *
	 * @since x.x.x
	 */
	public static function wpSetUpBeforeClass(): void {
		self::create_sync_tables();
	}

	/**
	 * Drops the tables after the class.
	 *
	 * @since x.x.x
	 */
	public static function wpTearDownAfterClass(): void {
		self::drop_sync_tables();
	}

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->registry = new Consumer_Registry();
		$this->registry->register(
			'test',
			array(
				'provider' => 'openai',
				'model'    => self::MODEL,
				'objects'  => array( 'post' => array( 'post' ) ),
			)
		);

		$this->queue      = new Sync_Queue();
		$this->repository = new Embedding_Repository();
		$this->client     = new Fake_Embedding_Client();
		$this->backfills  = new Backfill_Manager();
		$this->backoff    = new Provider_Backoff();

		wp_clear_scheduled_hook( Sync_Worker::CRON_HOOK );
	}

	/**
	 * Builds the worker under test.
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Sync_Lock|null $lock Optional. Lock. Default a new one.
	 * @return \WordPress\AI\Embeddings\Sync\Sync_Worker The worker.
	 */
	private function worker( ?Sync_Lock $lock = null ): Sync_Worker {
		$sources = array(
			'post' => new Post_Source(),
			'term' => new Term_Source(),
		);

		return new Sync_Worker(
			$this->registry,
			$sources,
			$this->queue,
			new Object_Processor( $this->registry, $sources, $this->repository, $this->client, $this->backoff ),
			$this->backfills,
			$this->backoff,
			$lock ?? new Sync_Lock()
		);
	}

	/**
	 * Tests that with no consumers the worker does nothing and unschedules itself.
	 *
	 * @since x.x.x
	 */
	public function test_no_consumers_means_no_work(): void {
		$this->registry->unregister( 'test' );
		wp_schedule_single_event( time() + 60, Sync_Worker::CRON_HOOK );

		$stats = $this->worker()->run();

		$this->assertFalse( $stats['ran'] );
		$this->assertFalse( wp_next_scheduled( Sync_Worker::CRON_HOOK ) );
	}

	/**
	 * Tests that a second runner bails while the lock is held.
	 *
	 * @since x.x.x
	 */
	public function test_bails_when_the_lock_is_held(): void {
		$held = new Sync_Lock();
		$held->acquire();
		$this->queue->enqueue( 'post', self::factory()->post->create() );

		$stats = $this->worker()->run();
		$held->release();

		$this->assertFalse( $stats['ran'] );
		$this->assertSame( array(), $this->client->calls );
	}

	/**
	 * Tests the happy path: queued post embedded, row removed, last run recorded.
	 *
	 * @since x.x.x
	 */
	public function test_processes_the_queue(): void {
		$post_id = self::factory()->post->create();
		$this->queue->enqueue( 'post', $post_id );

		$stats = $this->worker()->run();

		$this->assertSame( 1, $stats['queue'] );
		$this->assertCount( 1, $this->repository->get( 'post', $post_id, 'openai', self::MODEL ) );
		$this->assertSame(
			array(
				'pending' => 0,
				'failed'  => 0,
			),
			$this->queue->count_by_status()
		);
		$this->assertGreaterThan( 0, (int) get_option( Sync_Worker::LAST_RUN_OPTION ) );
		$this->assertFalse( wp_next_scheduled( Sync_Worker::CRON_HOOK ) );
	}

	/**
	 * Review focus 5: an object deleted after queueing is cleaned up without an API call.
	 *
	 * @since x.x.x
	 */
	public function test_object_deleted_after_queueing_is_removed_without_api_call(): void {
		$this->queue->enqueue( 'post', 999999 );

		$this->worker()->run();

		$this->assertSame( array(), $this->client->calls );
		$this->assertSame(
			array(
				'pending' => 0,
				'failed'  => 0,
			),
			$this->queue->count_by_status()
		);
	}

	/**
	 * Tests transient retries up to the cap, then failure and the failed action.
	 *
	 * @since x.x.x
	 */
	public function test_transient_failures_retry_then_fail(): void {
		add_filter( 'wpai_embedding_sync_max_attempts', static fn(): int => 2 );
		$this->client->fail_when = static fn() => new Embedding_Client_Exception( 'Server error (503)', Embedding_Client_Exception::TRANSIENT );
		$failed                  = array();

		add_action(
			'wpai_embedding_sync_object_failed',
			static function ( string $type, int $id, string $error ) use ( &$failed ): void {
				$failed[] = $error;
			},
			10,
			3
		);

		$post_id = self::factory()->post->create();
		$this->queue->enqueue( 'post', $post_id );

		$this->worker()->run();

		$this->assertSame(
			array(
				'pending' => 1,
				'failed'  => 0,
			),
			$this->queue->count_by_status()
		);
		$this->assertSame( array(), $this->queue->claim_due( 10 ) );

		$this->queue->postpone( $this->queue->claim_due( 10, time() + DAY_IN_SECONDS )[0], time() );
		$this->worker()->run();

		$this->assertSame(
			array(
				'pending' => 0,
				'failed'  => 1,
			),
			$this->queue->count_by_status()
		);
		$this->assertSame( array( 'Server error (503)' ), $failed );
	}

	/**
	 * Tests that an item error fails at once.
	 *
	 * @since x.x.x
	 */
	public function test_item_error_fails_immediately(): void {
		$this->client->failures = array( new Embedding_Client_Exception( 'Bad Request (400)', Embedding_Client_Exception::ITEM ) );
		$this->queue->enqueue( 'post', self::factory()->post->create() );

		$this->worker()->run();

		$this->assertSame(
			array(
				'pending' => 0,
				'failed'  => 1,
			),
			$this->queue->count_by_status()
		);
	}

	/**
	 * Tests that a rate limit postpones without charging and schedules the next run after the pause.
	 *
	 * @since x.x.x
	 */
	public function test_rate_limit_postpones_without_charging(): void {
		$this->client->failures = array( new Embedding_Client_Exception( 'Too Many Requests (429)', Embedding_Client_Exception::RATE_LIMITED ) );
		$this->queue->enqueue( 'post', self::factory()->post->create() );

		$this->worker()->run();

		$items = $this->queue->claim_due( 10, time() + Provider_Backoff::MIN_DELAY + 1 );

		$this->assertCount( 1, $items );
		$this->assertSame( 0, $items[0]->get_attempts() );
		$this->assertGreaterThanOrEqual( time() + Provider_Backoff::MIN_DELAY - 1, wp_next_scheduled( Sync_Worker::CRON_HOOK ) );
	}

	/**
	 * Tests that an edit made while the worker was embedding is embedded in the same run.
	 *
	 * @since x.x.x
	 */
	public function test_edit_during_processing_is_embedded_in_the_same_run(): void {
		$post_id                 = self::factory()->post->create( array( 'post_content' => 'Original.' ) );
		$queue                   = $this->queue;
		$edited                  = false;
		$this->client->fail_when = static function () use ( $queue, $post_id, &$edited ) {
			if ( ! $edited ) {
				$edited = true;

				// The editor saves again mid-request; no listener is registered here, so queue explicitly.
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => 'Edited mid-run.',
					)
				);
				$queue->enqueue( 'post', $post_id );
			}

			return null;
		};

		$this->queue->enqueue( 'post', $post_id );
		$this->worker()->run();

		$expected_hash = Object_Processor::hash_text( ( new Post_Source() )->get_text( $post_id ), Object_Processor::DEFAULT_MAX_CHUNKS );

		$this->assertSame( $expected_hash, $this->repository->get_content_hash( 'post', $post_id, 'openai', self::MODEL ) );
		$this->assertCount( 2, $this->client->calls );
		$this->assertSame(
			array(
				'pending' => 0,
				'failed'  => 0,
			),
			$this->queue->count_by_status()
		);
	}

	/**
	 * Tests that existing content is not touched until a backfill is started.
	 *
	 * @since x.x.x
	 */
	public function test_existing_content_waits_for_an_explicit_backfill(): void {
		self::factory()->post->create_many( 3 );

		$this->worker()->run();

		$this->assertSame( array(), $this->client->calls );
	}

	/**
	 * Tests a full backfill across several batches.
	 *
	 * @since x.x.x
	 */
	public function test_backfill_runs_to_completion(): void {
		add_filter( 'wpai_embedding_sync_batch_size', static fn(): int => 2 );
		$ids    = self::factory()->post->create_many( 5 );
		$target = $this->registry->get_target_for_consumer( 'test' );

		$this->backfills->start( $target );
		$this->worker()->run();

		$state = $this->backfills->get( $target->get_key() );

		$this->assertSame( Backfill_Manager::STATUS_COMPLETE, $state['status'] );
		// At least this test's five; fixture posts left by other classes are embedded too.
		$this->assertGreaterThanOrEqual( 5, $state['embedded'] );

		foreach ( $ids as $id ) {
			$this->assertCount( 1, $this->repository->get( 'post', $id, 'openai', self::MODEL ) );
		}
	}

	/**
	 * Tests that a rate-limited backfill resumes without re-embedding finished objects.
	 *
	 * @since x.x.x
	 */
	public function test_backfill_resumes_after_a_rate_limit(): void {
		add_filter( 'wpai_embedding_sync_batch_size', static fn(): int => 2 );
		self::factory()->post->create_many( 4 );
		$target = $this->registry->get_target_for_consumer( 'test' );

		$this->client->failures = array( null, new Embedding_Client_Exception( 'Too Many Requests (429)', Embedding_Client_Exception::RATE_LIMITED ) );

		$this->backfills->start( $target );
		$this->worker()->run();

		$this->assertTrue( $this->backfills->is_running( $target->get_key() ) );
		$this->assertSame( 2, $this->backfills->get( $target->get_key() )['embedded'] );

		$this->backoff->clear( 'openai' );
		$first_batch  = $this->client->calls[0]['inputs'];
		$calls_before = count( $this->client->calls );
		$this->worker()->run();

		$this->assertSame( Backfill_Manager::STATUS_COMPLETE, $this->backfills->get( $target->get_key() )['status'] );

		foreach ( array_slice( $this->client->calls, $calls_before ) as $call ) {
			$this->assertSame( array(), array_intersect( $first_batch, $call['inputs'] ), 'Objects finished before the pause must not be embedded again.' );
		}
	}

	/**
	 * Tests that backfill failures go to the queue and the cursor moves on.
	 *
	 * @since x.x.x
	 */
	public function test_backfill_failures_are_queued(): void {
		$this->client->failures = array( new Embedding_Client_Exception( 'Server error (503)', Embedding_Client_Exception::TRANSIENT ) );
		$post_id                = self::factory()->post->create();
		$target                 = $this->registry->get_target_for_consumer( 'test' );

		$this->backfills->start( $target );
		$this->worker()->run();

		$state = $this->backfills->get( $target->get_key() );

		$this->assertSame( 1, $state['failed'] );
		$this->assertSame( $post_id, $state['cursors']['post'] );
		$this->assertSame( 1, $this->queue->count_by_status()['pending'] );
	}

	/**
	 * Tests that a backfill batch interrupted by a pause does not queue its failures yet.
	 *
	 * The batch is retried after the pause, so queueing now would re-queue (and reset) the
	 * failed object on every retry.
	 *
	 * @since x.x.x
	 */
	public function test_backfill_failures_are_not_queued_when_the_batch_is_deferred(): void {
		add_filter( 'wpai_embedding_sync_batch_size', static fn(): int => 2 );
		self::factory()->post->create_many( 2 );
		$target = $this->registry->get_target_for_consumer( 'test' );

		// The batch request fails on an item, the first object alone fails, the second hits a rate limit.
		$this->client->failures = array(
			new Embedding_Client_Exception( 'Bad Request (400)', Embedding_Client_Exception::ITEM ),
			new Embedding_Client_Exception( 'Bad Request (400)', Embedding_Client_Exception::ITEM ),
			new Embedding_Client_Exception( 'Too Many Requests (429)', Embedding_Client_Exception::RATE_LIMITED ),
		);

		$this->backfills->start( $target );
		$this->worker()->run();

		$state = $this->backfills->get( $target->get_key() );

		$this->assertTrue( $this->backfills->is_running( $target->get_key() ) );
		$this->assertSame( 0, $state['failed'] );
		$this->assertSame( 0, $this->queue->count_by_status()['pending'] );
	}

	/**
	 * Tests that the worker stops once another runner has taken the lock over.
	 *
	 * @since x.x.x
	 */
	public function test_stops_when_the_lock_is_lost(): void {
		add_filter( 'wpai_embedding_sync_batch_size', static fn(): int => 1 );

		foreach ( self::factory()->post->create_many( 3 ) as $post_id ) {
			$this->queue->enqueue( 'post', $post_id );
		}

		$lock = new class() extends Sync_Lock {
			/**
			 * Reports the lock as taken over by another runner.
			 *
			 * @since x.x.x
			 *
			 * @param int|null $now Optional. Unix time. Default now.
			 * @return bool Always false.
			 */
			public function refresh( ?int $now = null ): bool {
				return false;
			}
		};

		$stats = $this->worker( $lock )->run();

		$this->assertSame( 1, $stats['queue'] );
		$this->assertSame( 0, $stats['backfill'] );
		$this->assertCount( 1, $this->client->calls );
		$this->assertSame( 2, $this->queue->count_by_status()['pending'] );
	}

	/**
	 * Tests that waking replaces a run scheduled for later.
	 *
	 * @since x.x.x
	 */
	public function test_wake_replaces_a_later_scheduled_run(): void {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, Sync_Worker::CRON_HOOK );

		Sync_Worker::wake();

		$this->assertLessThanOrEqual( time(), wp_next_scheduled( Sync_Worker::CRON_HOOK ) );
	}

	/**
	 * Tests that waking leaves a run that is already due alone.
	 *
	 * @since x.x.x
	 */
	public function test_wake_keeps_a_due_run(): void {
		$due = time() - 10;
		wp_schedule_single_event( $due, Sync_Worker::CRON_HOOK );

		Sync_Worker::wake();

		$this->assertSame( $due, wp_next_scheduled( Sync_Worker::CRON_HOOK ) );
	}

	/**
	 * Tests that scheduling the next run replaces a run scheduled for later than the work is due.
	 *
	 * @since x.x.x
	 */
	public function test_schedule_next_replaces_a_later_scheduled_run(): void {
		$this->queue->enqueue( 'post', self::factory()->post->create() );
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, Sync_Worker::CRON_HOOK );

		$this->worker()->schedule_next();

		$this->assertLessThanOrEqual( time(), wp_next_scheduled( Sync_Worker::CRON_HOOK ) );
	}

	/**
	 * Tests that scheduling the next run keeps an earlier scheduled run.
	 *
	 * @since x.x.x
	 */
	public function test_schedule_next_keeps_an_earlier_scheduled_run(): void {
		$this->queue->enqueue( 'post', self::factory()->post->create(), time() + HOUR_IN_SECONDS );
		$earlier = time() + MINUTE_IN_SECONDS;
		wp_schedule_single_event( $earlier, Sync_Worker::CRON_HOOK );

		$this->worker()->schedule_next();

		$this->assertSame( $earlier, wp_next_scheduled( Sync_Worker::CRON_HOOK ) );
	}

	/**
	 * Tests the retry delay curve.
	 *
	 * @since x.x.x
	 */
	public function test_retry_delay(): void {
		$this->assertSame( 120, Sync_Worker::retry_delay( 1 ) );
		$this->assertSame( 240, Sync_Worker::retry_delay( 2 ) );
		$this->assertSame( 21600, Sync_Worker::retry_delay( 20 ) );
	}
}

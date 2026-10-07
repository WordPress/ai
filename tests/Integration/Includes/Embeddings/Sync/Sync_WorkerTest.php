<?php
/**
 * Tests for the sync worker.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Embedding_Repository;
use WordPress\AI\Embeddings\Sync\Backfill_Manager;
use WordPress\AI\Embeddings\Sync\Consumer_Registry;
use WordPress\AI\Embeddings\Sync\Embedding_Client_Exception;
use WordPress\AI\Embeddings\Sync\Embedding_Target;
use WordPress\AI\Embeddings\Sync\Object_Processor;
use WordPress\AI\Embeddings\Sync\Orphan_Sweeper;
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
	 * @param \WordPress\AI\Embeddings\Sync\Sync_Lock|null      $lock    Optional. Lock. Default a new one.
	 * @param \WordPress\AI\Embeddings\Sync\Orphan_Sweeper|null $sweeper Optional. Sweeper. Default one over the test's sources.
	 * @return \WordPress\AI\Embeddings\Sync\Sync_Worker The worker.
	 */
	private function worker( ?Sync_Lock $lock = null, ?Orphan_Sweeper $sweeper = null ): Sync_Worker {
		$sources = $this->sources();

		return new Sync_Worker(
			$this->registry,
			$sources,
			$this->queue,
			new Object_Processor( $this->registry, $sources, $this->repository, $this->client, $this->backoff ),
			$this->backfills,
			$this->backoff,
			$lock ?? new Sync_Lock(),
			$sweeper ?? new Orphan_Sweeper( $sources, $this->repository, $this->registry )
		);
	}

	/**
	 * Returns the sources the worker is built with.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, \WordPress\AI\Embeddings\Sync\Embedding_Source_Interface> Sources keyed by object type.
	 */
	private function sources(): array {
		return array(
			'post' => new Post_Source(),
			'term' => new Term_Source(),
		);
	}

	/**
	 * Stores a one-chunk vector for this test's model.
	 *
	 * @since x.x.x
	 *
	 * @param int    $post_id Post ID.
	 * @param string $subtype Optional. Post type. Default 'post'.
	 */
	private function seed_vector( int $post_id, string $subtype = 'post' ): void {
		$this->repository->save( new Embedding_Record( 'post', $post_id, 'openai', self::MODEL, array( 0.1, 0.2, 0.3 ), 0, 'hash', 0, $subtype ) );
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
	 * Tests that a runner bailing on a held lock with work due retries once the lock would be stale.
	 *
	 * A lock holder that dies without unwinding never reschedules, so the bailing run must.
	 *
	 * @since x.x.x
	 */
	public function test_bailing_on_a_held_lock_schedules_a_retry_after_the_ttl(): void {
		$held = new Sync_Lock();
		$held->acquire();
		$this->queue->enqueue( 'post', self::factory()->post->create() );
		wp_clear_scheduled_hook( Sync_Worker::CRON_HOOK );

		$before = time();
		$stats  = $this->worker()->run();
		$held->release();

		$this->assertFalse( $stats['ran'] );
		$this->assertGreaterThanOrEqual( $before + Sync_Lock::TTL, wp_next_scheduled( Sync_Worker::CRON_HOOK ) );
		$this->assertLessThanOrEqual( time() + Sync_Lock::TTL, wp_next_scheduled( Sync_Worker::CRON_HOOK ) );
	}

	/**
	 * Tests that a runner bailing on a held lock with no work due schedules nothing.
	 *
	 * @since x.x.x
	 */
	public function test_bailing_on_a_held_lock_without_work_schedules_nothing(): void {
		$held = new Sync_Lock();
		$held->acquire();

		$stats = $this->worker()->run();
		$held->release();

		$this->assertFalse( $stats['ran'] );
		$this->assertFalse( wp_next_scheduled( Sync_Worker::CRON_HOOK ) );
	}

	/**
	 * Tests that a watchdog run is scheduled while the worker holds the lock.
	 *
	 * If the process dies mid-run (no finally), this event is what restarts sync.
	 *
	 * @since x.x.x
	 */
	public function test_watchdog_is_scheduled_while_the_lock_is_held(): void {
		$this->queue->enqueue( 'post', self::factory()->post->create() );
		wp_clear_scheduled_hook( Sync_Worker::CRON_HOOK );

		$during                  = array();
		$this->client->fail_when = static function () use ( &$during ) {
			$during[] = wp_next_scheduled( Sync_Worker::CRON_HOOK );

			return null;
		};

		$before = time();
		$this->worker()->run();

		$this->assertCount( 1, $during );
		$this->assertGreaterThanOrEqual( $before + Sync_Lock::TTL + MINUTE_IN_SECONDS, $during[0] );
		$this->assertLessThanOrEqual( time() + Sync_Lock::TTL + MINUTE_IN_SECONDS, $during[0] );
	}

	/**
	 * Tests that a run leaving no work removes any scheduled run, including its watchdog.
	 *
	 * @since x.x.x
	 */
	public function test_run_without_remaining_work_leaves_nothing_scheduled(): void {
		$this->queue->enqueue( 'post', self::factory()->post->create() );
		wp_schedule_single_event( time() + Sync_Lock::TTL + MINUTE_IN_SECONDS, Sync_Worker::CRON_HOOK );

		$this->worker()->run();

		$this->assertFalse( wp_next_scheduled( Sync_Worker::CRON_HOOK ) );
	}

	/**
	 * Tests that a run leaving only later work replaces its watchdog with that work's time.
	 *
	 * @since x.x.x
	 */
	public function test_run_with_later_work_replaces_the_watchdog(): void {
		$later = time() + HOUR_IN_SECONDS;
		$this->queue->enqueue( 'post', self::factory()->post->create(), $later );

		$this->worker()->run();

		$this->assertSame( $later, wp_next_scheduled( Sync_Worker::CRON_HOOK ) );
	}

	/**
	 * Tests that one object whose text preparation throws is charged an attempt while the rest are embedded.
	 *
	 * @since x.x.x
	 */
	public function test_throwing_object_is_charged_and_the_rest_are_embedded(): void {
		$bad  = self::factory()->post->create();
		$good = self::factory()->post->create();

		add_filter(
			'wpai_embedding_sync_post_text',
			static function ( string $text, \WP_Post $post ) use ( $bad ): string {
				if ( $bad === $post->ID ) {
					throw new \LogicException( 'Broken text filter.' );
				}

				return $text;
			},
			10,
			2
		);

		$this->queue->enqueue( 'post', $bad );
		$this->queue->enqueue( 'post', $good );

		$stats = $this->worker()->run();
		$items = $this->queue->claim_due( 10, time() + DAY_IN_SECONDS );

		$this->assertSame( 2, $stats['queue'] );
		$this->assertCount( 1, $this->repository->get( 'post', $good, 'openai', self::MODEL ) );
		$this->assertCount( 1, $items );
		$this->assertSame( $bad, $items[0]->get_object_id() );
		$this->assertSame( 1, $items[0]->get_attempts() );
	}

	/**
	 * Tests that a throwing object_indexed subscriber neither stops the run nor un-does the object.
	 *
	 * @since x.x.x
	 */
	public function test_throwing_indexed_subscriber_does_not_stop_the_run(): void {
		$post_id = self::factory()->post->create();
		$this->queue->enqueue( 'post', $post_id );

		add_action(
			'wpai_embedding_sync_object_indexed',
			static function (): void {
				throw new \RuntimeException( 'Broken subscriber.' );
			}
		);

		$stats    = array();
		$warnings = $this->capture_warnings(
			function () use ( &$stats ): void {
				$stats = $this->worker()->run();
			}
		);

		$this->assertTrue( $stats['ran'] );
		$this->assertSame( 1, $stats['queue'] );
		$this->assertCount( 1, $this->repository->get( 'post', $post_id, 'openai', self::MODEL ) );
		$this->assertSame(
			array(
				'pending' => 0,
				'failed'  => 0,
			),
			$this->queue->count_by_status()
		);
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'Broken subscriber.', $warnings[0][1] );
	}

	/**
	 * Tests that an unexpected error inside a run is reported, not thrown, and the lock is released.
	 *
	 * @since x.x.x
	 */
	public function test_unexpected_error_is_reported_and_the_lock_released(): void {
		$this->queue->enqueue( 'post', self::factory()->post->create() );

		add_filter(
			'wpai_embedding_sync_batch_size',
			static function (): int {
				throw new \Error( 'Broken batch size filter.' );
			}
		);

		$stats    = array();
		$warnings = $this->capture_warnings(
			function () use ( &$stats ): void {
				$stats = $this->worker()->run();
			}
		);

		$this->assertTrue( $stats['ran'] );
		$this->assertCount( 1, $warnings );
		$this->assertSame( E_USER_WARNING, $warnings[0][2] );
		$this->assertStringContainsString( 'Broken batch size filter.', $warnings[0][1] );
		$this->assertTrue( ( new Sync_Lock() )->acquire(), 'The lock must be released.' );
		$this->assertNotFalse( wp_next_scheduled( Sync_Worker::CRON_HOOK ), 'The remaining work must be rescheduled.' );
	}

	/**
	 * Runs a callback and returns the warnings it reported through wp_trigger_error().
	 *
	 * @since x.x.x
	 *
	 * @param callable $callback The code to run.
	 * @return list<array{0: string, 1: string, 2: int}> Function name, message and level of each warning.
	 */
	private function capture_warnings( callable $callback ): array {
		$warnings = array();
		$capture  = static function ( string $function_name, string $message, int $error_level ) use ( &$warnings ): void {
			$warnings[] = array( $function_name, $message, $error_level );
		};

		add_action( 'wp_trigger_error_always_run', $capture, 10, 3 );
		// Keep PHPUnit from turning the reported warning into an exception; the action above captures it.
		add_filter( 'wp_trigger_error_trigger_error', '__return_false' );

		try {
			$callback();
		} finally {
			remove_action( 'wp_trigger_error_always_run', $capture, 10 );
			remove_filter( 'wp_trigger_error_trigger_error', '__return_false' );
		}

		return $warnings;
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

		$expected_hash = Object_Processor::hash_text( ( new Post_Source() )->get_text( $post_id ), Object_Processor::DEFAULT_MAX_CHUNKS, null );

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
	 * Tests that a subtype added while a backfill is running is backfilled below the cursor too.
	 *
	 * @since x.x.x
	 */
	public function test_subtype_added_mid_backfill_is_backfilled(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$post_id = self::factory()->post->create();
		$target  = $this->registry->get_target_for_consumer( 'test' );

		// The backfill has already scanned past the page.
		$this->backfills->start( $target );
		$this->backfills->advance( $target->get_key(), 'post', $post_id, array() );

		$this->registry->register(
			'pages',
			array(
				'provider' => 'openai',
				'model'    => self::MODEL,
				'objects'  => array( 'post' => array( 'page' ) ),
			)
		);

		$this->worker()->run();

		$this->assertSame( Backfill_Manager::STATUS_COMPLETE, $this->backfills->get( $target->get_key() )['status'] );
		$this->assertCount( 1, $this->repository->get( 'post', $page_id, 'openai', self::MODEL ) );
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
	 * A backfill batch cut by the deadline resumes without re-embedding.
	 *
	 * @since x.x.x
	 */
	public function test_backfill_batch_cut_by_deadline_resumes_without_reembedding(): void {
		add_filter( 'wpai_embedding_sync_request_max_inputs', static fn(): int => 1 );
		add_filter( 'wpai_embedding_sync_batch_size', static fn(): int => 3 );
		$ids    = self::factory()->post->create_many( 3 );
		$target = $this->registry->get_target_for_consumer( 'test' );
		$key    = $target->get_key();

		// Fixture posts left by other classes may sit at lower IDs: start the cursor just below
		// this test's posts, so the first batch is exactly these three.
		$cursor = min( $ids ) - 1;
		$this->backfills->start( $target );
		$this->backfills->advance( $key, 'post', $cursor, array() );

		// Make the first request slow enough to pass a 1-second budget.
		$this->client->fail_when = static function () {
			static $first = true;

			if ( $first ) {
				$first = false;
				sleep( 2 );
			}

			return null;
		};

		$this->worker()->run( 1 );

		$state = $this->backfills->get( $key );
		$this->assertCount( 1, $this->client->calls, 'Only the first request is sent once the budget is spent.' );
		$this->assertSame( Backfill_Manager::STATUS_RUNNING, $state['status'] );
		$this->assertSame( $cursor, (int) ( $state['cursors']['post'] ?? 0 ), 'A batch cut short does not advance the cursor.' );
		$this->assertSame( 0, $this->queue->count_by_status()['pending'], 'Deferred objects are not queued as failures.' );

		$calls_before = count( $this->client->calls );
		$this->worker()->run( 0 );

		$this->assertSame( 2, count( $this->client->calls ) - $calls_before, 'Only the two unstored posts are embedded on resume.' );
		$this->assertSame( Backfill_Manager::STATUS_COMPLETE, $this->backfills->get( $key )['status'] );

		foreach ( $ids as $id ) {
			$this->assertCount( 1, $this->repository->get( 'post', $id, 'openai', self::MODEL ) );
		}
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

	/**
	 * Tests that a model-scoped pause holds the backfill and sets the next run time.
	 *
	 * @since x.x.x
	 */
	public function test_model_scoped_pause_holds_the_backfill(): void {
		self::factory()->post->create();
		$target = $this->registry->get_target_for_consumer( 'test' );
		$now    = time();
		$until  = $this->backoff->record_provider_error( 'openai', 'Not Found (404)', $now, self::MODEL );

		$this->backfills->start( $target );

		$this->assertSame( $until, $this->worker()->get_next_run_at( $now ) );

		$this->worker()->run();

		$this->assertSame( array(), $this->client->calls );
		$this->assertTrue( $this->backfills->is_running( $target->get_key() ) );
	}

	/**
	 * Tests that a pause on another model of the same provider does not hold the backfill.
	 *
	 * @since x.x.x
	 */
	public function test_pause_on_another_model_does_not_hold_the_backfill(): void {
		$post_id = self::factory()->post->create();
		$target  = $this->registry->get_target_for_consumer( 'test' );
		$now     = time();

		$this->backoff->record_provider_error( 'openai', 'Not Found (404)', $now, 'text-embedding-3-large' );
		$this->backfills->start( $target );

		$this->assertSame( $now, $this->worker()->get_next_run_at( $now ) );

		$this->worker()->run();

		$this->assertSame( Backfill_Manager::STATUS_COMPLETE, $this->backfills->get( $target->get_key() )['status'] );
		$this->assertCount( 1, $this->repository->get( 'post', $post_id, 'openai', self::MODEL ) );
	}

	/**
	 * Tests that a backfill sweeps orphaned vectors before it is marked complete.
	 *
	 * @since x.x.x
	 */
	public function test_backfill_sweeps_orphans_before_completing(): void {
		$draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$page_id  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$target   = $this->registry->get_target_for_consumer( 'test' );
		$key      = $target->get_key();
		$fired    = 0;

		$this->seed_vector( $draft_id );
		$this->seed_vector( $page_id, 'page' );

		add_action(
			'wpai_embedding_sync_backfill_completed',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$this->backfills->start( $target );
		$this->worker()->run( 0 );

		$state = $this->backfills->get( $key );

		$this->assertSame( array(), $this->repository->get( 'post', $draft_id, 'openai', self::MODEL ) );
		$this->assertSame( array(), $this->repository->get( 'post', $page_id, 'openai', self::MODEL ) );
		$this->assertSame( Backfill_Manager::STATUS_COMPLETE, $state['status'] );
		$this->assertGreaterThanOrEqual( 2, $state['removed'] );
		$this->assertSame( 1, $fired );
	}

	/**
	 * Tests that a sweep cancelled between batches resumes from its cursor without API calls.
	 *
	 * The cancel is made from the `update_option_{option}` action of the backfill state, the first
	 * time the saved state carries a sweep cursor: that write is `advance_sweep()` recording the
	 * first sweep batch, so the cancel lands after exactly one batch and before the worker's
	 * `is_running()` check for the next one.
	 *
	 * @since x.x.x
	 */
	public function test_cancelled_sweep_resumes_from_its_cursor(): void {
		add_filter( 'wpai_embedding_sync_sweep_batch_size', static fn(): int => 1 );

		$orphans = self::factory()->post->create_many( 3, array( 'post_status' => 'draft' ) );
		$target  = $this->registry->get_target_for_consumer( 'test' );
		$key     = $target->get_key();
		$sweeper = new Recording_Orphan_Sweeper( $this->sources(), $this->repository, $this->registry );

		foreach ( $orphans as $id ) {
			$this->seed_vector( $id );
		}

		$backfills = $this->backfills;
		$cancelled = false;
		$cancel    = static function ( $old_value, $value ) use ( $backfills, $key, &$cancelled ): void {
			if ( $cancelled || ! is_array( $value ) || array() === (array) ( $value['sweep_cursors'] ?? array() ) ) {
				return;
			}

			$cancelled = true;
			$backfills->cancel( $key );
		};

		add_action( 'update_option_' . Backfill_Manager::OPTION_PREFIX . $key, $cancel, 10, 2 );

		$this->backfills->start( $target );
		$this->worker( null, $sweeper )->run( 0 );

		remove_action( 'update_option_' . Backfill_Manager::OPTION_PREFIX . $key, $cancel, 10 );

		$state    = $this->backfills->get( $key );
		$position = $this->backfills->get_sweep_position( $key, array( 'post', 'term' ) );

		$this->assertTrue( $cancelled );
		$this->assertSame( Backfill_Manager::STATUS_CANCELLED, $state['status'] );
		$this->assertCount( 1, $sweeper->cursors, 'Exactly one sweep batch ran before the cancel.' );
		$this->assertSame( 'post', $position['object_type'] );
		$this->assertGreaterThan( 0, $position['cursor'] );

		$remaining = array_filter(
			$orphans,
			fn( int $id ): bool => array() !== $this->repository->get( 'post', $id, 'openai', self::MODEL )
		);

		$this->assertGreaterThanOrEqual( 2, count( $remaining ), 'The cancelled sweep stopped after one batch.' );

		$calls_before     = count( $this->client->calls );
		$sweeper->cursors = array();

		$this->backfills->start( $target );
		$this->worker( null, $sweeper )->run( 0 );

		$this->assertSame( $position['cursor'], $sweeper->cursors[0], 'The resumed sweep starts at the saved cursor.' );
		$this->assertCount( $calls_before, $this->client->calls, 'Resuming the sweep makes no API calls.' );
		$this->assertSame( Backfill_Manager::STATUS_COMPLETE, $this->backfills->get( $key )['status'] );

		foreach ( $orphans as $id ) {
			$this->assertSame( array(), $this->repository->get( 'post', $id, 'openai', self::MODEL ) );
		}
	}

	/**
	 * Tests that a paused target still sweeps once its embedding pass is done.
	 *
	 * The sweep sends no API requests, so a pause has nothing to protect.
	 *
	 * @since x.x.x
	 */
	public function test_paused_target_sweeps_once_the_embedding_pass_is_done(): void {
		$draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$target   = $this->registry->get_target_for_consumer( 'test' );
		$key      = $target->get_key();
		$now      = time();

		$this->seed_vector( $draft_id );
		$this->backfills->start( $target );
		$this->backfills->finish_type( $key, 'post' );
		$this->backoff->record_provider_error( 'openai', 'Not Found (404)', $now, self::MODEL );

		$this->assertSame( $now, $this->worker()->get_next_run_at( $now ), 'A pending sweep is due despite the pause.' );

		$this->worker()->run( 0 );

		$this->assertSame( array(), $this->client->calls );
		$this->assertSame( array(), $this->repository->get( 'post', $draft_id, 'openai', self::MODEL ) );
		$this->assertSame( Backfill_Manager::STATUS_COMPLETE, $this->backfills->get( $key )['status'] );
	}

	/**
	 * Tests that working out the next run time never writes backfill state.
	 *
	 * It runs without the lock (a run that lost the lock race, after release, and from the CLI), so
	 * reconciling changed subtypes there would race the worker that holds it.
	 *
	 * @since x.x.x
	 */
	public function test_next_run_time_does_not_write_reconciled_subtypes(): void {
		$target = $this->registry->get_target_for_consumer( 'test' );
		$key    = $target->get_key();
		$now    = time();

		$this->backfills->start( $target );
		$this->backfills->finish_type( $key, 'post' );
		$until = $this->backoff->record_provider_error( 'openai', 'Not Found (404)', $now, self::MODEL );

		$this->registry->register(
			'pages',
			array(
				'provider' => 'openai',
				'model'    => self::MODEL,
				'objects'  => array( 'post' => array( 'page' ) ),
			)
		);

		$before = get_option( Backfill_Manager::OPTION_PREFIX . $key );

		$this->assertSame( $until, $this->worker()->get_next_run_at( $now ), 'The widened type reopens the embedding pass, so the pause holds.' );
		$this->assertSame( $before, $this->backfills->get( $key ), 'The stored state is unchanged.' );
	}
}

/**
 * Sweeper that records the cursor of every sweep call.
 *
 * @since x.x.x
 */
class Recording_Orphan_Sweeper extends Orphan_Sweeper {

	/**
	 * Cursors passed to sweep(), in call order.
	 *
	 * @var list<int>
	 */
	public array $cursors = array();

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 *
	 * @param \WordPress\AI\Embeddings\Sync\Embedding_Target $target      The target.
	 * @param string                                         $object_type Object type with a source.
	 * @param int                                            $cursor      Sweep IDs greater than this.
	 * @param int                                            $limit       Maximum objects to check.
	 * @return array{last_id: int|null, checked: int, removed: int} The page's last ID and counts.
	 */
	public function sweep( Embedding_Target $target, string $object_type, int $cursor, int $limit ): array {
		if ( 'post' === $object_type ) {
			$this->cursors[] = $cursor;
		}

		return parent::sweep( $target, $object_type, $cursor, $limit );
	}
}

<?php
/**
 * Tests for the embedding sync facade.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Embedding_Record;
use WordPress\AI\Embeddings\Sync\Backfill_Manager;
use WordPress\AI\Embeddings\Sync\Embedding_Sync;
use WordPress\AI\Embeddings\Sync\Embedding_Target;
use WordPress\AI\Embeddings\Sync\Sync_Worker;

use function WordPress\AI\register_embedding_consumer;

/**
 * Embedding_Sync test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Sync\Embedding_Sync
 */
class Embedding_SyncTest extends WP_UnitTestCase {

	use Sync_Tables_Trait;

	private const MODEL = 'text-embedding-3-small';

	/**
	 * Facade under test.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Embedding_Sync
	 */
	private Embedding_Sync $sync;

	/**
	 * Fake client.
	 *
	 * @var \WordPress\AI\Tests\Integration\Includes\Embeddings\Sync\Fake_Embedding_Client
	 */
	private Fake_Embedding_Client $client;

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

		$this->client = new Fake_Embedding_Client();
		$this->sync   = new Embedding_Sync( null, $this->client );
		Embedding_Sync::set_instance( $this->sync );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	protected function tearDown(): void {
		Embedding_Sync::set_instance( null );

		parent::tearDown();
	}

	/**
	 * Registers the standard test consumer.
	 *
	 * @since x.x.x
	 *
	 * @return bool Whether registration succeeded.
	 */
	private function register(): bool {
		return register_embedding_consumer(
			'related',
			array(
				'provider' => 'openai',
				'model'    => self::MODEL,
				'objects'  => array( 'post' => array( 'post' ) ),
			)
		);
	}

	/**
	 * Tests activation: hooks registered and a publish is queued.
	 *
	 * @since x.x.x
	 */
	public function test_registering_a_consumer_activates_sync(): void {
		$this->assertTrue( $this->register() );

		$this->sync->init();

		$this->assertTrue( $this->sync->is_active() );
		$this->assertNotFalse( has_action( Sync_Worker::CRON_HOOK, array( $this->sync, 'run_worker' ) ) );

		$post_id = self::factory()->post->create();

		$this->assertSame(
			array(
				'pending' => 1,
				'failed'  => 0,
			),
			$this->sync->get_queue()->count_by_status()
		);

		$this->sync->run_worker();

		$this->assertCount( 1, $this->sync->get_repository()->get( 'post', $post_id, 'openai', self::MODEL ) );
	}

	/**
	 * Tests that a consumer registered after init still activates sync.
	 *
	 * @since x.x.x
	 */
	public function test_late_registration_activates(): void {
		$this->sync->init();
		$this->assertFalse( $this->sync->is_active() );

		$this->register();

		$this->assertTrue( $this->sync->is_active() );
	}

	/**
	 * Tests the backfill API.
	 *
	 * @since x.x.x
	 */
	public function test_backfill_api(): void {
		$this->register();
		$this->sync->init();
		$key = Embedding_Target::key_for( 'openai', self::MODEL );

		$this->assertFalse( Embedding_Sync::start_backfill( 'unknown' ) );
		$this->assertTrue( Embedding_Sync::start_backfill( 'related' ) );
		$this->assertTrue( $this->sync->get_backfills()->is_running( $key ) );
		$this->assertNotFalse( wp_next_scheduled( Sync_Worker::CRON_HOOK ) );

		$this->assertTrue( Embedding_Sync::cancel_backfill( 'related' ) );
		$this->assertSame( Backfill_Manager::STATUS_CANCELLED, $this->sync->get_backfills()->get( $key )['status'] );

		$this->assertTrue( Embedding_Sync::reset_backfill( 'related' ) );
		$this->assertNull( $this->sync->get_backfills()->get( $key ) );
	}

	/**
	 * Tests the status shape and coverage numbers.
	 *
	 * @since x.x.x
	 */
	public function test_get_status(): void {
		$this->register();
		$this->sync->init();

		self::factory()->post->create_many( 2 );
		$this->sync->run_worker();

		$status = Embedding_Sync::get_status( 'related' );

		$this->assertSame(
			array(
				'provider'   => 'openai',
				'model'      => self::MODEL,
				'dimensions' => null,
			),
			$status['target']
		);
		$this->assertSame( 2, $status['coverage']['post']['post']['indexed'] );
		// Fixture posts left by other classes are indexable but were never queued.
		$this->assertGreaterThanOrEqual( 2, $status['coverage']['post']['post']['indexable'] );
		$this->assertNull( $status['backfill'] );
		$this->assertSame(
			array(
				'pending' => 0,
				'failed'  => 0,
			),
			$status['queue']
		);
		$this->assertNull( $status['backoff'] );
		$this->assertNull( $status['provider_error'] );
		$this->assertIsInt( $status['last_run'] );
		$this->assertNull( Embedding_Sync::get_status( 'unknown' ) );
	}

	/**
	 * Tests pruning a model after a switch.
	 *
	 * @since x.x.x
	 */
	public function test_prune_target(): void {
		$this->register();
		$this->sync->init();
		$this->sync->get_repository()->save( new Embedding_Record( 'post', 5, 'openai', 'old-model', array( 0.1 ), 0, 'h' ) );

		$this->assertSame( 1, Embedding_Sync::prune_target( 'openai', 'old-model' ) );
		$this->assertSame( array(), $this->sync->get_repository()->get( 'post', 5, 'openai', 'old-model' ) );
	}

	/**
	 * Tests retrying failed rows.
	 *
	 * @since x.x.x
	 */
	public function test_retry_failed(): void {
		$this->register();
		$this->sync->init();
		$this->sync->get_queue()->enqueue( 'post', 5 );
		$this->sync->get_queue()->fail( $this->sync->get_queue()->claim_due( 1 )[0], 'Bad Request (400)' );

		$this->assertSame( 1, Embedding_Sync::retry_failed() );
	}
}

<?php
/**
 * Tests for the embedding sync facade.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Sync\Embedding_Sync;

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
	 * Tests activation: a publish is queued and the worker embeds it.
	 *
	 * @since x.x.x
	 */
	public function test_registering_a_consumer_activates_sync(): void {
		$this->assertTrue( $this->register() );

		$this->sync->init();

		$this->assertTrue( $this->sync->is_active() );

		$post_id = self::factory()->post->create();

		$this->assertSame(
			array(
				'pending' => 1,
				'failed'  => 0,
			),
			Embedding_Sync::get_status( 'related' )['queue']
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
	 * Tests that status reports only a live provider error for the consumer's model.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_provider_error_status
	 *
	 * @param list<array{0: string, 1: int|null, 2: string|null}> $errors         Errors to record: message, pause end (null for the default), model.
	 * @param string|null                                          $expected_error The error the status should report.
	 */
	public function test_status_reports_model_scoped_errors( array $errors, ?string $expected_error ): void {
		$this->register();
		$this->sync->init();

		$until = null;

		foreach ( $errors as $error ) {
			$until = $this->sync->get_backoff()->record_provider_error( 'openai', $error[0], $error[1], $error[2] );
		}

		$status = Embedding_Sync::get_status( 'related' );

		$this->assertSame( $expected_error, $status['provider_error'] );
		$this->assertSame( null === $expected_error ? null : $until, $status['backoff'] );
	}

	/**
	 * Returns provider errors and the error the status should report.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: list<array{0: string, 1: int|null, 2: string|null}>, 1: string|null}> Test cases.
	 */
	public function data_provider_error_status(): array {
		return array(
			'this model'    => array(
				array( array( 'Not Found (404)', null, self::MODEL ) ),
				'Not Found (404)',
			),
			'another model' => array(
				array( array( 'Not Found (404)', null, 'text-embedding-3-large' ) ),
				null,
			),
			'expired'       => array(
				array(
					array( 'Not Found (404)', time() - 2 * HOUR_IN_SECONDS, self::MODEL ),
					array( 'Unauthorized (401)', time() - 2 * HOUR_IN_SECONDS, null ),
				),
				null,
			),
		);
	}
}

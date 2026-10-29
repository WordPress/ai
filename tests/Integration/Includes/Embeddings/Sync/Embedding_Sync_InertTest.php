<?php
/**
 * Tests that embedding sync costs nothing without consumers.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Embedding_Schema;
use WordPress\AI\Embeddings\Sync\Embedding_Sync;
use WordPress\AI\Embeddings\Sync\Sync_Queue_Schema;
use WordPress\AI\Embeddings\Sync\Sync_Worker;

/**
 * Embedding_Sync inertness test case.
 *
 * Needs the tables absent, so it drops them per test (DDL; isolation is explicit).
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Sync\Embedding_Sync
 */
class Embedding_Sync_InertTest extends WP_UnitTestCase {

	use Sync_Tables_Trait;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	protected function setUp(): void {
		parent::setUp();

		self::drop_sync_tables();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	protected function tearDown(): void {
		self::drop_sync_tables();
		Embedding_Sync::set_instance( null );

		parent::tearDown();
	}

	/**
	 * Tests that without consumers no table, hook or cron event appears.
	 *
	 * @since x.x.x
	 */
	public function test_no_consumers_means_no_tables_hooks_or_events(): void {
		$sync = new Embedding_Sync( null, new Fake_Embedding_Client() );
		Embedding_Sync::set_instance( $sync );

		// No fixture post here: the DDL in setUp() ended the test transaction, so it would leak.
		$sync->init();

		$this->assertFalse( $sync->is_active() );
		$this->assertFalse( ( new Embedding_Schema() )->table_exists() );
		$this->assertFalse( ( new Sync_Queue_Schema() )->table_exists() );
		$this->assertFalse( has_action( Sync_Worker::CRON_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Sync_Worker::CRON_HOOK ) );
	}
}

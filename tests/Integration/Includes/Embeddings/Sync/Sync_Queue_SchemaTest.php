<?php
/**
 * Tests for the sync queue schema.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Sync\Sync_Queue;
use WordPress\AI\Embeddings\Sync\Sync_Queue_Schema;

/**
 * Sync_Queue_Schema test case.
 *
 * Does real DDL per test, so isolation is explicit (see Embedding_SchemaTest::reset_storage()).
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Sync\Sync_Queue_Schema
 */
class Sync_Queue_SchemaTest extends WP_UnitTestCase {

	/**
	 * Schema under test.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Sync_Queue_Schema
	 */
	private Sync_Queue_Schema $schema;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schema = new Sync_Queue_Schema();
		$this->schema->drop_table();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	protected function tearDown(): void {
		$this->schema->drop_table();

		parent::tearDown();
	}

	/**
	 * Tests that reads never create the table and the first write does.
	 *
	 * @since x.x.x
	 */
	public function test_reads_do_not_create_table_and_first_write_does(): void {
		$queue = new Sync_Queue( $this->schema );

		$this->assertSame( array(), $queue->claim_due( 10 ) );
		$this->assertSame(
			array(
				'pending' => 0,
				'failed'  => 0,
			),
			$queue->count_by_status()
		);
		$this->assertNull( $queue->get_next_available_at() );
		$this->assertFalse( $this->schema->table_exists() );

		$queue->enqueue( 'post', 1 );

		$this->assertTrue( $this->schema->table_exists() );
	}
}

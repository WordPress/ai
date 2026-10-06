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
	 * Tests creation, version stamping and the key set.
	 *
	 * @since x.x.x
	 */
	public function test_creates_table_with_keys_and_stamps_version(): void {
		global $wpdb;

		$this->assertFalse( $this->schema->is_version_current() );

		$this->schema->maybe_upgrade_table();

		$this->assertTrue( $this->schema->table_exists() );
		$this->assertTrue( $this->schema->is_version_current() );

		$table = $this->schema->get_table_name();
		$names = array_unique( array_column( $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ), 'Key_name' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertContains( 'uniq_object', $names );
		$this->assertContains( 'idx_due', $names );
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

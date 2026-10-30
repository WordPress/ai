<?php
/**
 * Tests for the sync queue repository.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Sync\Sync_Queue;

/**
 * Sync_Queue test case.
 *
 * @since 1.5.0
 *
 * @covers \WordPress\AI\Embeddings\Sync\Sync_Queue
 * @covers \WordPress\AI\Embeddings\Sync\Sync_Queue_Item
 */
class Sync_QueueTest extends WP_UnitTestCase {

	use Sync_Tables_Trait;

	/**
	 * Queue under test.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Sync_Queue
	 */
	private Sync_Queue $queue;

	/**
	 * Creates the tables once for the class.
	 *
	 * @since 1.5.0
	 */
	public static function wpSetUpBeforeClass(): void {
		self::create_sync_tables();
	}

	/**
	 * Drops the tables after the class.
	 *
	 * @since 1.5.0
	 */
	public static function wpTearDownAfterClass(): void {
		self::drop_sync_tables();
	}

	/**
	 * Set up test case.
	 *
	 * @since 1.5.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->queue = new Sync_Queue();
	}

	/**
	 * Tests that repeated enqueues of one object collapse into one row with a rising version.
	 *
	 * @since 1.5.0
	 */
	public function test_repeated_enqueues_collapse_into_one_row(): void {
		$this->queue->enqueue( 'post', 5 );
		$this->queue->enqueue( 'post', 5 );
		$this->queue->enqueue( 'post', 5 );

		$items = $this->queue->claim_due( 10 );

		$this->assertCount( 1, $items );
		$this->assertSame( 5, $items[0]->get_object_id() );
		$this->assertSame( 3, $items[0]->get_version() );
		$this->assertSame( 0, $items[0]->get_attempts() );
	}

	/**
	 * Tests that only due pending rows are claimed, oldest first.
	 *
	 * @since 1.5.0
	 */
	public function test_claim_due_returns_due_pending_rows_oldest_first(): void {
		$now = time();

		$this->queue->enqueue( 'post', 2, $now - 10 );
		$this->queue->enqueue( 'post', 1, $now - 20 );
		$this->queue->enqueue( 'term', 3, $now + 600 );

		$items = $this->queue->claim_due( 10, $now );

		$this->assertSame( array( 1, 2 ), array_map( static fn( $item ): int => $item->get_object_id(), $items ) );
		$this->assertSame( $now - 20, $this->queue->get_next_available_at() );
	}

	/**
	 * Tests that completing with a stale version keeps the row: a save that happened mid-run wins.
	 *
	 * @since 1.5.0
	 */
	public function test_complete_with_a_stale_version_keeps_the_row(): void {
		$this->queue->enqueue( 'post', 5 );
		$claimed = $this->queue->claim_due( 1 )[0];

		$this->queue->enqueue( 'post', 5 ); // An edit while the worker was processing.

		$this->assertFalse( $this->queue->complete( $claimed ) );
		$this->assertCount( 1, $this->queue->claim_due( 10 ) );
	}

	/**
	 * Tests that completing with the current version deletes the row.
	 *
	 * @since 1.5.0
	 */
	public function test_complete_deletes_the_row(): void {
		$this->queue->enqueue( 'post', 5 );

		$this->assertTrue( $this->queue->complete( $this->queue->claim_due( 1 )[0] ) );
		$this->assertSame( array(), $this->queue->claim_due( 10 ) );
	}

	/**
	 * Tests that deferring charges an attempt and hides the row until it is due.
	 *
	 * @since 1.5.0
	 */
	public function test_defer_charges_an_attempt_and_delays(): void {
		$now = time();
		$this->queue->enqueue( 'post', 5, $now );

		$this->assertTrue( $this->queue->defer( $this->queue->claim_due( 1, $now )[0], $now + 120, 'Server error (503)' ) );
		$this->assertSame( array(), $this->queue->claim_due( 10, $now ) );

		$later = $this->queue->claim_due( 10, $now + 121 );

		$this->assertCount( 1, $later );
		$this->assertSame( 1, $later[0]->get_attempts() );
	}

	/**
	 * Tests that a failure update is version guarded too, so a fresh edit is never pushed back.
	 *
	 * @since 1.5.0
	 */
	public function test_defer_with_a_stale_version_is_ignored(): void {
		$now = time();
		$this->queue->enqueue( 'post', 5, $now );
		$claimed = $this->queue->claim_due( 1, $now )[0];

		$this->queue->enqueue( 'post', 5, $now );

		$this->assertFalse( $this->queue->defer( $claimed, $now + 3600, 'boom' ) );
		$this->assertCount( 1, $this->queue->claim_due( 10, $now ) );
	}

	/**
	 * Tests that postponing does not charge an attempt.
	 *
	 * @since 1.5.0
	 */
	public function test_postpone_does_not_charge_an_attempt(): void {
		$now = time();
		$this->queue->enqueue( 'post', 5, $now );

		$this->queue->postpone( $this->queue->claim_due( 1, $now )[0], $now + 60 );

		$this->assertSame( 0, $this->queue->claim_due( 10, $now + 61 )[0]->get_attempts() );
	}

	/**
	 * Tests that only pending rows due within the window are released.
	 *
	 * @since 1.5.0
	 */
	public function test_release_until_makes_rows_due_within_the_window(): void {
		$now = time();
		$this->queue->enqueue( 'post', 5, $now + 600 );
		$this->queue->enqueue( 'post', 6, $now + 7200 );
		$this->queue->enqueue( 'post', 7, $now + 600 );
		$this->queue->fail( $this->queue->claim_due( 1, $now + 600 )[0], 'Bad Request (400)' );

		$this->assertSame( 1, $this->queue->release_until( $now + 3600, $now ) );

		$items = $this->queue->claim_due( 10, $now );

		$this->assertCount( 1, $items );
		$this->assertSame( 7, $items[0]->get_object_id() );
	}

	/**
	 * Tests the failed lifecycle: fail, count, retry, and a new edit reviving a failed row.
	 *
	 * @since 1.5.0
	 */
	public function test_failed_rows_are_counted_skipped_and_retryable(): void {
		$this->queue->enqueue( 'post', 5 );
		$this->queue->enqueue( 'post', 6 );

		$this->assertTrue( $this->queue->fail( $this->queue->claim_due( 1 )[0], 'Bad Request (400)' ) );
		$this->assertSame(
			array(
				'pending' => 1,
				'failed'  => 1,
			),
			$this->queue->count_by_status()
		);
		$this->assertCount( 1, $this->queue->claim_due( 10 ) );

		$this->assertSame( 1, $this->queue->retry_failed() );
		$this->assertSame(
			array(
				'pending' => 2,
				'failed'  => 0,
			),
			$this->queue->count_by_status()
		);

		$this->queue->fail( $this->queue->claim_due( 1 )[0], 'Bad Request (400)' );
		$this->queue->enqueue( 'post', 5 );

		$this->assertSame(
			array(
				'pending' => 2,
				'failed'  => 0,
			),
			$this->queue->count_by_status()
		);
	}

	/**
	 * Tests removing an object's row regardless of version.
	 *
	 * @since 1.5.0
	 */
	public function test_remove_object(): void {
		$this->queue->enqueue( 'post', 5 );
		$this->queue->enqueue( 'term', 5 );

		$this->queue->remove_object( 'post', 5 );

		$items = $this->queue->claim_due( 10 );

		$this->assertCount( 1, $items );
		$this->assertSame( 'term', $items[0]->get_object_type() );
	}
}

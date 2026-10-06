<?php
/**
 * Tests for backfill state.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Sync\Backfill_Manager;
use WordPress\AI\Embeddings\Sync\Embedding_Target;

/**
 * Backfill_Manager test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Sync\Backfill_Manager
 */
class Backfill_ManagerTest extends WP_UnitTestCase {

	/**
	 * Target used by every test.
	 *
	 * @var \WordPress\AI\Embeddings\Sync\Embedding_Target
	 */
	private Embedding_Target $target;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->target = new Embedding_Target(
			'openai',
			'text-embedding-3-small',
			null,
			array(
				'post' => array( 'post' ),
				'term' => array( 'post_tag' ),
			)
		);
	}

	/**
	 * Tests start, idempotent start, and the initial position.
	 *
	 * @since x.x.x
	 */
	public function test_start_creates_running_state(): void {
		$manager = new Backfill_Manager();
		$key     = $this->target->get_key();

		$state = $manager->start( $this->target );

		$this->assertSame( Backfill_Manager::STATUS_RUNNING, $state['status'] );
		$this->assertTrue( $manager->is_running( $key ) );
		$this->assertSame(
			array(
				'object_type' => 'post',
				'cursor'      => 0,
			),
			$manager->get_position( $key, $this->target )
		);

		$manager->advance( $key, 'post', 10, array( 'embedded' => 2 ) );
		$manager->start( $this->target );

		$this->assertSame( 10, $manager->get_position( $key, $this->target )['cursor'] );
	}

	/**
	 * Tests advancing, finishing types and completing.
	 *
	 * @since x.x.x
	 */
	public function test_progresses_through_types_to_completion(): void {
		$manager   = new Backfill_Manager();
		$key       = $this->target->get_key();
		$completed = 0;

		add_action(
			'wpai_embedding_sync_backfill_completed',
			static function () use ( &$completed ): void {
				++$completed;
			}
		);

		$manager->start( $this->target );
		$manager->advance(
			$key,
			'post',
			42,
			array(
				'embedded' => 3,
				'skipped'  => 1,
				'failed'   => 1,
			)
		);
		$manager->finish_type( $key, 'post' );
		$manager->finish_type( $key, 'post' );

		$this->assertSame( array( 'post' ), $manager->get( $key )['done_types'] );

		$this->assertSame(
			array(
				'object_type' => 'term',
				'cursor'      => 0,
			),
			$manager->get_position( $key, $this->target )
		);

		$manager->finish_type( $key, 'term' );

		$this->assertNull( $manager->get_position( $key, $this->target ) );

		$manager->complete( $key, $this->target );
		$state = $manager->get( $key );

		$this->assertSame( Backfill_Manager::STATUS_COMPLETE, $state['status'] );
		$this->assertSame( 5, $state['processed'] );
		$this->assertSame( 3, $state['embedded'] );
		$this->assertSame( 1, $completed );
	}

	/**
	 * Tests that a cancel made by another request is never overwritten by the worker.
	 *
	 * @since x.x.x
	 */
	public function test_advance_after_cancel_is_ignored(): void {
		global $wpdb;

		$worker = new Backfill_Manager();
		$key    = $this->target->get_key();

		$state = $worker->start( $this->target );
		$this->assertSame( Backfill_Manager::STATUS_RUNNING, $worker->get( $key )['status'] );

		// Cancel from "another request": write to the database only, leaving this request's cache stale.
		$state['status'] = Backfill_Manager::STATUS_CANCELLED;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulates a write from another request.
			$wpdb->options,
			array( 'option_value' => maybe_serialize( $state ) ),
			array( 'option_name' => Backfill_Manager::OPTION_PREFIX . $key )
		);

		$this->assertSame( Backfill_Manager::STATUS_RUNNING, $worker->get( $key )['status'], 'The in-process cache should still hold the running state.' );
		$this->assertFalse( $worker->advance( $key, 'post', 99, array( 'embedded' => 1 ) ) );
		$this->assertSame( Backfill_Manager::STATUS_CANCELLED, $worker->get( $key )['status'] );
	}

	/**
	 * Tests that a fresh read sees state created by another request after a cached miss.
	 *
	 * @since x.x.x
	 */
	public function test_fresh_read_sees_state_created_after_cached_miss(): void {
		global $wpdb;

		$manager = new Backfill_Manager();
		$key     = $this->target->get_key();

		// Prime the notoptions cache with a miss.
		$this->assertNull( $manager->get( $key ) );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Simulates a write from another request.
			$wpdb->options,
			array(
				'option_name'  => Backfill_Manager::OPTION_PREFIX . $key,
				'option_value' => maybe_serialize( array( 'status' => Backfill_Manager::STATUS_RUNNING ) ),
				'autoload'     => 'off',
			)
		);

		$this->assertTrue( $manager->is_running( $key ) );
	}

	/**
	 * Tests resume after cancel, restart after complete, and reset.
	 *
	 * @since x.x.x
	 */
	public function test_resume_restart_and_reset(): void {
		$manager = new Backfill_Manager();
		$key     = $this->target->get_key();

		$manager->start( $this->target );
		$manager->advance( $key, 'post', 7, array() );
		$manager->cancel( $key );
		$manager->start( $this->target );

		$this->assertSame( 7, $manager->get_position( $key, $this->target )['cursor'] );

		$manager->complete( $key, $this->target );
		$manager->start( $this->target );

		$this->assertSame( 0, $manager->get_position( $key, $this->target )['cursor'] );

		$manager->reset( $key );

		$this->assertNull( $manager->get( $key ) );
	}
}

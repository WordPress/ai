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
		$manager = new Backfill_Manager();
		$key     = $this->target->get_key();

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
	 * Tests that widening a type's subtypes mid-backfill rescans that type from the start.
	 *
	 * @since x.x.x
	 */
	public function test_widened_subtypes_reset_that_type_cursor(): void {
		$manager = new Backfill_Manager();
		$key     = $this->target->get_key();

		$manager->start( $this->target );
		$manager->advance( $key, 'post', 10, array( 'embedded' => 2 ) );

		$widened = $this->target->with_subtypes( 'post', array( 'page' ) );

		$this->assertSame(
			array(
				'object_type' => 'post',
				'cursor'      => 0,
			),
			$manager->get_position( $key, $widened )
		);
		$this->assertSame( 2, $manager->get( $key )['embedded'], 'Counters are kept.' );

		// Once rescanning under the new subtypes, progress is not reset again.
		$manager->advance( $key, 'post', 5, array() );

		$this->assertSame( 5, $manager->get_position( $key, $widened )['cursor'] );
	}

	/**
	 * Tests that a finished type whose subtypes widen is visited again, and unchanged types are not.
	 *
	 * @since x.x.x
	 */
	public function test_widened_subtypes_reopen_a_finished_type_only(): void {
		$manager = new Backfill_Manager();
		$key     = $this->target->get_key();

		$manager->start( $this->target );
		$manager->advance( $key, 'post', 10, array() );
		$manager->finish_type( $key, 'post' );
		$manager->advance( $key, 'term', 7, array() );

		// Unchanged subtypes: the term scan carries on.
		$this->assertSame(
			array(
				'object_type' => 'term',
				'cursor'      => 7,
			),
			$manager->get_position( $key, $this->target )
		);

		$widened = $this->target->with_subtypes( 'post', array( 'page' ) );

		$this->assertSame(
			array(
				'object_type' => 'post',
				'cursor'      => 0,
			),
			$manager->get_position( $key, $widened )
		);
		$this->assertSame( array(), $manager->get( $key )['done_types'] );
		$this->assertSame( 7, $manager->get( $key )['cursors']['term'] );
	}

	/**
	 * Tests that a cancelled backfill resumed after its subtypes widen rescans the widened type.
	 *
	 * @since x.x.x
	 */
	public function test_resumed_backfill_rescans_widened_subtypes(): void {
		$manager = new Backfill_Manager();
		$key     = $this->target->get_key();

		$manager->start( $this->target );
		$manager->advance( $key, 'post', 10, array() );
		$manager->cancel( $key );

		$widened = $this->target->with_subtypes( 'post', array( 'page' ) );
		$manager->start( $widened );

		$this->assertSame( 0, $manager->get_position( $key, $widened )['cursor'] );
	}

	/**
	 * Tests that a complete backfill is left alone when subtypes change.
	 *
	 * @since x.x.x
	 */
	public function test_complete_backfill_is_not_reopened_by_widened_subtypes(): void {
		$manager = new Backfill_Manager();
		$key     = $this->target->get_key();

		$manager->start( $this->target );
		$manager->finish_type( $key, 'post' );
		$manager->finish_type( $key, 'term' );
		$manager->complete( $key, $this->target );

		$this->assertNull( $manager->get_position( $key, $this->target->with_subtypes( 'post', array( 'page' ) ) ) );
		$this->assertSame( Backfill_Manager::STATUS_COMPLETE, $manager->get( $key )['status'] );
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

	/**
	 * Tests that the sweep walks object types in order, records progress, then finishes.
	 *
	 * @since x.x.x
	 */
	public function test_sweep_position_walks_types_then_finishes(): void {
		$manager = new Backfill_Manager();
		$key     = $this->target->get_key();
		$types   = array( 'post', 'term' );

		$manager->start( $this->target );

		$this->assertSame(
			array(
				'object_type' => 'post',
				'cursor'      => 0,
			),
			$manager->get_sweep_position( $key, $types )
		);

		$this->assertTrue( $manager->advance_sweep( $key, 'post', 7, 2 ) );

		$this->assertSame(
			array(
				'object_type' => 'post',
				'cursor'      => 7,
			),
			$manager->get_sweep_position( $key, $types )
		);
		$this->assertSame( 2, $manager->get( $key )['removed'] );

		$manager->finish_sweep_type( $key, 'post' );
		$manager->finish_sweep_type( $key, 'post' );

		$this->assertSame( array( 'post' ), $manager->get( $key )['swept_types'] );
		$this->assertSame(
			array(
				'object_type' => 'term',
				'cursor'      => 0,
			),
			$manager->get_sweep_position( $key, $types )
		);

		$manager->finish_sweep_type( $key, 'term' );

		$this->assertNull( $manager->get_sweep_position( $key, $types ) );
	}

	/**
	 * Tests that sweep progress is not recorded once the backfill is no longer running.
	 *
	 * @since x.x.x
	 */
	public function test_sweep_progress_after_cancel_is_ignored(): void {
		$manager = new Backfill_Manager();
		$key     = $this->target->get_key();

		$manager->start( $this->target );
		$manager->cancel( $key );

		$this->assertFalse( $manager->advance_sweep( $key, 'post', 7, 2 ) );
		$manager->finish_sweep_type( $key, 'post' );

		$state = $manager->get( $key );

		$this->assertSame( array(), $state['sweep_cursors'] );
		$this->assertSame( array(), $state['swept_types'] );
		$this->assertSame( 0, $state['removed'] );
	}

	/**
	 * Tests that a change of covered subtypes restarts the sweep.
	 *
	 * @since x.x.x
	 */
	public function test_subtype_change_resets_the_sweep(): void {
		$manager = new Backfill_Manager();
		$key     = $this->target->get_key();
		$types   = array( 'post', 'term' );

		$manager->start( $this->target );
		$manager->advance_sweep( $key, 'post', 7, 1 );
		$manager->finish_sweep_type( $key, 'post' );
		$manager->advance_sweep( $key, 'term', 3, 0 );

		$manager->get_position( $key, $this->target->with_subtypes( 'post', array( 'page' ) ) );

		$this->assertSame(
			array(
				'object_type' => 'post',
				'cursor'      => 0,
			),
			$manager->get_sweep_position( $key, $types )
		);
		$this->assertSame( array(), $manager->get( $key )['swept_types'] );
		$this->assertSame( 1, $manager->get( $key )['removed'], 'Counters are kept.' );
	}

	/**
	 * Tests that the read-only embedding-pass check applies a subtype change without saving it.
	 *
	 * @since x.x.x
	 */
	public function test_is_embedding_pass_done_reconciles_in_memory_only(): void {
		$manager = new Backfill_Manager();
		$key     = $this->target->get_key();

		$this->assertFalse( $manager->is_embedding_pass_done( $key, $this->target ), 'No state means no pass to be done.' );

		$manager->start( $this->target );

		$this->assertFalse( $manager->is_embedding_pass_done( $key, $this->target ) );

		$manager->finish_type( $key, 'post' );
		$manager->finish_type( $key, 'term' );
		$manager->finish_sweep_type( $key, 'post' );

		$this->assertTrue( $manager->is_embedding_pass_done( $key, $this->target ) );

		$before  = $manager->get( $key );
		$widened = $this->target->with_subtypes( 'post', array( 'page' ) );

		$this->assertFalse( $manager->is_embedding_pass_done( $key, $widened ) );
		$this->assertSame( $before, $manager->get( $key ), 'Nothing is saved.' );
	}

	/**
	 * Tests that a type the target no longer covers resets the sweep and drops its stored subtypes.
	 *
	 * @since x.x.x
	 */
	public function test_dropped_type_resets_the_sweep(): void {
		$manager = new Backfill_Manager();
		$key     = $this->target->get_key();
		$types   = array( 'post', 'term' );

		$manager->start( $this->target );
		$manager->finish_type( $key, 'post' );
		$manager->finish_type( $key, 'term' );
		$manager->advance_sweep( $key, 'post', 9, 0 );
		$manager->finish_sweep_type( $key, 'post' );

		$posts_only = new Embedding_Target( 'openai', 'text-embedding-3-small', null, array( 'post' => array( 'post' ) ) );

		$this->assertNull( $manager->get_position( $key, $posts_only ) );

		$state = $manager->get( $key );

		$this->assertSame( array( 'post' => array( 'post' ) ), $state['subtypes'] );
		$this->assertSame( array(), $state['swept_types'] );
		$this->assertSame(
			array(
				'object_type' => 'post',
				'cursor'      => 0,
			),
			$manager->get_sweep_position( $key, $types )
		);
	}
}

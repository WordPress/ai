<?php
/**
 * Tests for the background scheduler.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Background
 */

namespace WordPress\AI\Tests\Integration\Includes\Background;

use WP_UnitTestCase;
use WordPress\AI\Background\Scheduler;

/**
 * Scheduler test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Background\Scheduler
 */
class SchedulerTest extends WP_UnitTestCase {

	/**
	 * Cron hook used by the tests.
	 *
	 * @var string
	 */
	private const HOOK = 'wpai_test_scheduler_run';

	/**
	 * Clears the test hook.
	 *
	 * @since x.x.x
	 */
	public function tear_down(): void {
		wp_unschedule_hook( self::HOOK );

		parent::tear_down();
	}

	/**
	 * Tests that the earlier of the scheduled run and the requested time wins.
	 *
	 * @since x.x.x
	 */
	public function test_earlier_run_wins(): void {
		$now = time();

		$this->assertTrue( Scheduler::schedule_at( self::HOOK, $now + 600 ) );
		$this->assertSame( $now + 600, wp_next_scheduled( self::HOOK ) );

		$this->assertTrue( Scheduler::schedule_at( self::HOOK, $now + 900 ), 'A later request is satisfied by the earlier run.' );
		$this->assertSame( $now + 600, wp_next_scheduled( self::HOOK ) );

		$this->assertTrue( Scheduler::schedule_at( self::HOOK, $now + 60 ) );
		$this->assertSame( $now + 60, wp_next_scheduled( self::HOOK ) );
		$this->assertCount( 1, $this->get_events(), 'Moving a run up must not leave the later one behind.' );
	}

	/**
	 * Tests that each argument set has its own pending run.
	 *
	 * @since x.x.x
	 */
	public function test_arguments_are_scheduled_separately(): void {
		$now = time();

		Scheduler::schedule_at( self::HOOK, $now + 600, array( 1 ) );
		Scheduler::schedule_at( self::HOOK, $now + 60, array( 2 ) );

		$this->assertSame( $now + 600, wp_next_scheduled( self::HOOK, array( 1 ) ) );
		$this->assertSame( $now + 60, wp_next_scheduled( self::HOOK, array( 2 ) ) );
		$this->assertCount( 2, $this->get_events() );
	}

	/**
	 * Returns the scheduled events for the test hook.
	 *
	 * @since x.x.x
	 *
	 * @return list<array<string, mixed>> The events.
	 */
	private function get_events(): array {
		$events = array();

		foreach ( _get_cron_array() as $hooks ) {
			foreach ( $hooks[ self::HOOK ] ?? array() as $event ) {
				$events[] = $event;
			}
		}

		return $events;
	}
}

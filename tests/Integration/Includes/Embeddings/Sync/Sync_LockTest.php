<?php
/**
 * Tests for the sync lock.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Embeddings\Sync
 */

namespace WordPress\AI\Tests\Integration\Includes\Embeddings\Sync;

use WP_UnitTestCase;
use WordPress\AI\Embeddings\Sync\Sync_Lock;

/**
 * Sync_Lock test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Embeddings\Sync\Sync_Lock
 */
class Sync_LockTest extends WP_UnitTestCase {

	/**
	 * Tests mutual exclusion and release.
	 *
	 * @since x.x.x
	 */
	public function test_only_one_runner_holds_the_lock(): void {
		$first  = new Sync_Lock();
		$second = new Sync_Lock();

		$this->assertTrue( $first->acquire() );
		$this->assertFalse( $second->acquire() );

		$first->release();

		$this->assertTrue( $second->acquire() );
		$second->release();
	}

	/**
	 * Tests that an abandoned lock is taken over.
	 *
	 * @since x.x.x
	 */
	public function test_stale_lock_is_taken_over(): void {
		$crashed = new Sync_Lock();
		$crashed->acquire( time() - Sync_Lock::TTL - 1 );

		$next = new Sync_Lock();

		$this->assertTrue( $next->acquire() );

		// The crashed runner's late release must not free the new holder's lock.
		$crashed->release();

		$this->assertFalse( ( new Sync_Lock() )->acquire() );
		$next->release();
	}

	/**
	 * Tests that refreshing keeps a long run from looking abandoned.
	 *
	 * @since x.x.x
	 */
	public function test_refresh_keeps_the_lock_fresh(): void {
		$lock = new Sync_Lock();
		$lock->acquire( time() - Sync_Lock::TTL + 5 );

		$this->assertTrue( $lock->refresh() );
		$this->assertFalse( ( new Sync_Lock() )->acquire( time() + 10 ) );

		$lock->release();
	}

	/**
	 * Tests that refreshing in the same second as acquiring keeps the lock.
	 *
	 * MySQL reports zero affected rows when an UPDATE writes an identical value.
	 *
	 * @since x.x.x
	 */
	public function test_refresh_in_same_second_keeps_the_lock(): void {
		$now  = time();
		$lock = new Sync_Lock();

		$this->assertTrue( $lock->acquire( $now ) );
		$this->assertTrue( $lock->refresh( $now ) );
		$this->assertTrue( $lock->is_held() );
		$this->assertFalse( ( new Sync_Lock() )->acquire( $now ) );

		$lock->release();

		$next = new Sync_Lock();

		$this->assertTrue( $next->acquire( $now ) );
		$next->release();
	}

	/**
	 * Tests that refreshing after another runner took the lock over fails.
	 *
	 * @since x.x.x
	 */
	public function test_refresh_after_takeover_fails(): void {
		$stale = new Sync_Lock();
		$stale->acquire( time() - Sync_Lock::TTL - 1 );

		$taker = new Sync_Lock();

		$this->assertTrue( $taker->acquire() );
		$this->assertFalse( $stale->refresh() );
		$this->assertFalse( $stale->is_held() );
		$this->assertTrue( $taker->is_held() );

		$taker->release();
	}
}

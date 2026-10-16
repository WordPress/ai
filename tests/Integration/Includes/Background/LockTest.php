<?php
/**
 * Tests for the background lock.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Background
 */

namespace WordPress\AI\Tests\Integration\Includes\Background;

use InvalidArgumentException;
use WP_UnitTestCase;
use WordPress\AI\Background\Lock;

/**
 * Lock test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Background\Lock
 */
class LockTest extends WP_UnitTestCase {

	/**
	 * Tests mutual exclusion and release.
	 *
	 * @since x.x.x
	 */
	public function test_only_one_runner_holds_the_lock(): void {
		$first  = new Lock( 'test' );
		$second = new Lock( 'test' );

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
		$crashed = new Lock( 'test' );
		$crashed->acquire( time() - Lock::DEFAULT_TTL - 1 );

		$next = new Lock( 'test' );

		$this->assertTrue( $next->acquire() );

		// The crashed runner's late release must not free the new holder's lock.
		$crashed->release();

		$this->assertFalse( ( new Lock( 'test' ) )->acquire() );
		$next->release();
	}

	/**
	 * Tests that refreshing keeps a long run from looking abandoned.
	 *
	 * @since x.x.x
	 */
	public function test_refresh_keeps_the_lock_fresh(): void {
		$lock = new Lock( 'test' );
		$lock->acquire( time() - Lock::DEFAULT_TTL + 5 );

		$this->assertTrue( $lock->refresh() );
		$this->assertFalse( ( new Lock( 'test' ) )->acquire( time() + 10 ) );

		$lock->release();
	}

	/**
	 * Tests that refreshing in the same second as acquiring keeps the lock.
	 *
	 * @since x.x.x
	 */
	public function test_refresh_in_same_second_keeps_the_lock(): void {
		$now  = time();
		$lock = new Lock( 'test' );

		$this->assertTrue( $lock->acquire( $now ) );
		$this->assertTrue( $lock->refresh( $now ) );
		$this->assertTrue( $lock->is_held() );
		$this->assertFalse( ( new Lock( 'test' ) )->acquire( $now ) );

		$lock->release();

		$next = new Lock( 'test' );

		$this->assertTrue( $next->acquire( $now ) );
		$next->release();
	}

	/**
	 * Tests that refreshing after another runner took the lock over fails.
	 *
	 * @since x.x.x
	 */
	public function test_refresh_after_takeover_fails(): void {
		$stale = new Lock( 'test' );
		$stale->acquire( time() - Lock::DEFAULT_TTL - 1 );

		$taker = new Lock( 'test' );

		$this->assertTrue( $taker->acquire() );
		$this->assertFalse( $stale->refresh() );
		$this->assertFalse( $stale->is_held() );
		$this->assertTrue( $taker->is_held() );

		$taker->release();
	}

	/**
	 * Tests that differently named locks do not block each other.
	 *
	 * @since x.x.x
	 */
	public function test_names_are_independent(): void {
		$first  = new Lock( 'test_job_1' );
		$second = new Lock( 'test_job_2' );

		$this->assertSame( 'wpai_test_job_1_lock', $first->get_option_name() );
		$this->assertTrue( $first->acquire() );
		$this->assertTrue( $second->acquire() );
		$this->assertFalse( ( new Lock( 'test_job_1' ) )->acquire() );

		$first->release();
		$second->release();
	}

	/**
	 * Tests that a custom TTL decides when a lock counts as abandoned.
	 *
	 * @since x.x.x
	 */
	public function test_custom_ttl(): void {
		$now  = time();
		$lock = new Lock( 'test', 30 );

		$this->assertSame( 30, $lock->get_ttl() );
		$this->assertTrue( $lock->acquire( $now - 31 ) );
		$this->assertFalse( ( new Lock( 'test', 60 ) )->acquire( $now ), 'A longer TTL must still see the lock as held.' );

		$next = new Lock( 'test', 30 );

		$this->assertTrue( $next->acquire( $now ), 'The same TTL must see the lock as abandoned.' );

		$next->release();
	}

	/**
	 * Tests that invalid names and TTLs are rejected.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_invalid_arguments
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  TTL.
	 */
	public function test_rejects_invalid_arguments( string $name, int $ttl ): void {
		$this->expectException( InvalidArgumentException::class );

		new Lock( $name, $ttl );
	}

	/**
	 * Data provider for invalid constructor arguments.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: int}> Name and TTL.
	 */
	public function data_invalid_arguments(): array {
		return array(
			'empty name'     => array( '', 300 ),
			'uppercase name' => array( 'Test', 300 ),
			'wildcard name'  => array( 'test%', 300 ),
			'long name'      => array( str_repeat( 'a', 171 ), 300 ),
			'zero TTL'       => array( 'test', 0 ),
		);
	}
}

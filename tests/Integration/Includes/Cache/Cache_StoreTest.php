<?php
/**
 * Tests for the cache store.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Cache
 */

namespace WordPress\AI\Tests\Integration\Includes\Cache;

use WP_UnitTestCase;
use WordPress\AI\Cache\Cache_Store;

/**
 * Cache_Store test case.
 *
 * @since 1.6.0
 *
 * @covers \WordPress\AI\Cache\Cache_Store
 */
class Cache_StoreTest extends WP_UnitTestCase {

	/**
	 * Values round-trip.
	 *
	 * @since 1.6.0
	 */
	public function test_set_and_get_round_trip(): void {
		$store = new Cache_Store();
		$value = array(
			'status'  => 200,
			'headers' => array( 'Content-Type' => array( 'application/json' ) ),
			'body'    => '{"ok":true}',
		);

		$this->assertTrue( $store->set( 'wpai_test_key', $value, 60 ) );
		$this->assertSame( $value, $store->get( 'wpai_test_key' ) );
	}

	/**
	 * Missing keys return the default.
	 *
	 * @since 1.6.0
	 */
	public function test_missing_key_returns_default(): void {
		$store = new Cache_Store();

		$this->assertNull( $store->get( 'wpai_missing_key' ) );
		$this->assertSame( 'fallback', $store->get( 'wpai_missing_key', 'fallback' ) );
	}

	/**
	 * Deleting removes the value.
	 *
	 * @since 1.6.0
	 */
	public function test_delete_removes_the_value(): void {
		$store = new Cache_Store();

		$store->set( 'wpai_test_delete', 'value', 60 );
		$this->assertSame( 'value', $store->get( 'wpai_test_delete' ) );

		$store->delete( 'wpai_test_delete' );

		$this->assertNull( $store->get( 'wpai_test_delete' ) );
	}
}

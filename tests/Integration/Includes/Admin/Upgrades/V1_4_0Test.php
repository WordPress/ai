<?php
/**
 * Integration tests for V1_4_0.
 *
 * @package WordPress\AI\Tests\Integration\Admin\Upgrades
 */

namespace WordPress\AI\Tests\Integration\Admin\Upgrades;

use WP_UnitTestCase;
use WordPress\AI\Admin\Upgrades\V1_4_0;

/**
 * V1_4_0 test case.
 *
 * @covers \WordPress\AI\Admin\Upgrades\V1_4_0
 *
 * @since x.x.x
 */
class V1_4_0Test extends WP_UnitTestCase {

	/**
	 * The historical transient key the migration clears.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const CACHE_KEY = 'wpai_active_seo_plugin';

	/**
	 * Tests that run() clears the legacy SEO plugin detection cache.
	 *
	 * @since x.x.x
	 */
	public function test_run_clears_seo_plugin_cache(): void {
		set_transient( self::CACHE_KEY, 'yoast-seo' );

		( new V1_4_0( '1.3.0' ) )->run();

		$this->assertFalse( get_transient( self::CACHE_KEY ), 'The legacy SEO plugin cache should be cleared.' );
	}

	/**
	 * Tests that run() returns true on success.
	 *
	 * @since x.x.x
	 */
	public function test_run_returns_success(): void {
		$this->assertTrue( ( new V1_4_0( '1.3.0' ) )->run() );
	}

	/**
	 * Tests that run() leaves the cache alone when the version is already current.
	 *
	 * @since x.x.x
	 */
	public function test_run_skips_when_version_already_current(): void {
		set_transient( self::CACHE_KEY, 'yoast-seo' );

		( new V1_4_0( '1.4.0' ) )->run();

		$this->assertSame( 'yoast-seo', get_transient( self::CACHE_KEY ), 'The cache should be untouched when the upgrade is skipped.' );
	}

	/**
	 * Tests that run() leaves the cache alone on a new install, where an empty
	 * database version means the plugin has never stored the legacy cache.
	 *
	 * @since x.x.x
	 */
	public function test_run_skips_on_a_new_install(): void {
		set_transient( self::CACHE_KEY, 'yoast-seo' );

		$this->assertTrue( ( new V1_4_0( '' ) )->run() );

		$this->assertSame( 'yoast-seo', get_transient( self::CACHE_KEY ), 'No transient should be deleted on a new install.' );
	}
}

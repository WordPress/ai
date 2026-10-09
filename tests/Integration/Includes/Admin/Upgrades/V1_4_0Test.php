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
 * @since 1.4.0
 */
class V1_4_0Test extends WP_UnitTestCase {

	/**
	 * The historical transient key the migration clears.
	 *
	 * @since 1.4.0
	 *
	 * @var string
	 */
	private const CACHE_KEY = 'wpai_active_seo_plugin';

	/**
	 * The historical global toggle option the migration removes.
	 *
	 * @since 1.4.0
	 *
	 * @var string
	 */
	private const GLOBAL_OPTION = 'wpai_features_enabled';

	/**
	 * A feature toggle used by the global toggle tests.
	 *
	 * @since 1.4.0
	 *
	 * @var string
	 */
	private const TITLE_OPTION = 'wpai_feature_title-generation_enabled';

	/**
	 * A second feature toggle used by the global toggle tests.
	 *
	 * @since 1.4.0
	 *
	 * @var string
	 */
	private const EXCERPT_OPTION = 'wpai_feature_excerpt-generation_enabled';

	/**
	 * Cleans up options written by the tests.
	 *
	 * @since 1.4.0
	 */
	public function tear_down(): void {
		delete_option( self::GLOBAL_OPTION );
		delete_option( 'wpai_global_toggle_removed' );
		delete_option( 'wpai_feature_title-generation_custom' );

		delete_option( self::TITLE_OPTION );
		delete_option( self::EXCERPT_OPTION );

		parent::tear_down();
	}

	/**
	 * Tests that run() clears the legacy SEO plugin detection cache.
	 *
	 * @since 1.4.0
	 */
	public function test_run_clears_seo_plugin_cache(): void {
		set_transient( self::CACHE_KEY, 'yoast-seo' );

		( new V1_4_0( '1.3.0' ) )->run();

		$this->assertFalse( get_transient( self::CACHE_KEY ), 'The legacy SEO plugin cache should be cleared.' );
	}

	/**
	 * Tests that run() returns true on success.
	 *
	 * @since 1.4.0
	 */
	public function test_run_returns_success(): void {
		$this->assertTrue( ( new V1_4_0( '1.3.0' ) )->run() );
	}

	/**
	 * Tests that run() leaves the cache alone when the version is already current.
	 *
	 * @since 1.4.0
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
	 * @since 1.4.0
	 */
	public function test_run_skips_on_a_new_install(): void {
		set_transient( self::CACHE_KEY, 'yoast-seo' );

		$this->assertTrue( ( new V1_4_0( '' ) )->run() );

		$this->assertSame( 'yoast-seo', get_transient( self::CACHE_KEY ), 'No transient should be deleted on a new install.' );
	}

	/**
	 * Tests that individually enabled features are turned off when AI was globally disabled.
	 *
	 * @since 1.4.0
	 */
	public function test_run_disables_features_when_globally_disabled(): void {
		update_option( self::GLOBAL_OPTION, false );
		update_option( self::TITLE_OPTION, true );
		update_option( self::EXCERPT_OPTION, true );

		( new V1_4_0( '1.3.0' ) )->run();

		$this->assertFalse( (bool) get_option( self::TITLE_OPTION ), 'Title Generation should be turned off.' );
		$this->assertFalse( (bool) get_option( self::EXCERPT_OPTION ), 'Excerpt Generation should be turned off.' );
		$this->assertFalse( get_option( self::GLOBAL_OPTION ), 'The global option should be deleted.' );
	}

	/**
	 * Tests that features are turned off when the global option was never saved,
	 * since the toggle defaulted to off.
	 *
	 * @since 1.4.0
	 */
	public function test_run_disables_features_when_global_option_missing(): void {
		delete_option( self::GLOBAL_OPTION );
		update_option( self::TITLE_OPTION, true );

		( new V1_4_0( '1.3.0' ) )->run();

		$this->assertFalse( (bool) get_option( self::TITLE_OPTION ), 'The feature should be turned off.' );
	}

	/**
	 * Tests that feature toggles are preserved when AI was globally enabled.
	 *
	 * @since 1.4.0
	 */
	public function test_run_preserves_features_when_globally_enabled(): void {
		update_option( self::GLOBAL_OPTION, true );
		update_option( self::TITLE_OPTION, true );
		update_option( self::EXCERPT_OPTION, false );

		( new V1_4_0( '1.3.0' ) )->run();

		$this->assertTrue( (bool) get_option( self::TITLE_OPTION ), 'Enabled features should stay enabled.' );
		$this->assertFalse( (bool) get_option( self::EXCERPT_OPTION ), 'Disabled features should stay disabled.' );
		$this->assertFalse( get_option( self::GLOBAL_OPTION ), 'The global option should be deleted.' );
	}

	/**
	 * Tests that options which are not feature toggles are left alone.
	 *
	 * @since 1.4.0
	 */
	public function test_run_ignores_non_toggle_feature_options(): void {
		update_option( self::GLOBAL_OPTION, false );
		update_option( 'wpai_feature_title-generation_custom', '1' );

		( new V1_4_0( '1.3.0' ) )->run();

		$this->assertSame( '1', get_option( 'wpai_feature_title-generation_custom' ), 'Non-toggle options should be untouched.' );
	}

	/**
	 * Tests that feature toggles are left alone on a new install.
	 *
	 * @since 1.4.0
	 */
	public function test_run_does_not_touch_features_on_a_new_install(): void {
		update_option( self::TITLE_OPTION, true );

		( new V1_4_0( '' ) )->run();

		$this->assertTrue( (bool) get_option( self::TITLE_OPTION ), 'Features should be untouched on a new install.' );
	}

	/**
	 * Tests that re-running the upgrade does not turn features off again.
	 *
	 * Pre-release builds keep a stored version below 1.4.0, so the routine can
	 * run on every admin request; once the global option is gone it must not
	 * be treated as "disabled" a second time.
	 *
	 * @since 1.4.0
	 */
	public function test_run_only_migrates_global_option_once(): void {
		update_option( self::GLOBAL_OPTION, true );

		( new V1_4_0( '1.3.0' ) )->run();

		// The user enables a feature after the migration.
		update_option( self::TITLE_OPTION, true );

		( new V1_4_0( '1.3.0' ) )->run();

		$this->assertTrue( (bool) get_option( self::TITLE_OPTION ), 'A second run should not turn features off.' );
	}
}

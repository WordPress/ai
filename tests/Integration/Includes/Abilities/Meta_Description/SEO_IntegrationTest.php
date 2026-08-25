<?php
/**
 * Integration tests for the SEO_Integration utility class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Meta_Description
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Meta_Description;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Meta_Description\SEO_Integration;

/**
 * SEO_Integration test case.
 *
 * @since 0.7.0
 */
class SEO_IntegrationTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();
		SEO_Integration::clear_cache();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.7.0
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_meta_description_seo_plugins' );
		remove_all_filters( 'wpai_meta_description_meta_key' );
		SEO_Integration::clear_cache();
		remove_action( 'activated_plugin', array( SEO_Integration::class, 'clear_cache' ) );
		remove_action( 'deactivated_plugin', array( SEO_Integration::class, 'clear_cache' ) );
		parent::tearDown();
	}

	/**
	 * Test that get_supported_plugins() returns the expected default plugins.
	 *
	 * @since 0.7.0
	 */
	public function test_get_supported_plugins_returns_defaults() {
		$plugins = SEO_Integration::get_supported_plugins();

		$this->assertIsArray( $plugins, 'Supported plugins should be an array' );
		$this->assertArrayHasKey( 'yoast-seo', $plugins, 'Should include Yoast SEO' );
		$this->assertArrayHasKey( 'rank-math', $plugins, 'Should include Rank Math' );
		$this->assertArrayHasKey( 'all-in-one-seo', $plugins, 'Should include All in One SEO' );
		$this->assertArrayHasKey( 'seopress', $plugins, 'Should include SEOPress' );

		// Verify each plugin has required keys.
		foreach ( $plugins as $slug => $info ) {
			$this->assertArrayHasKey( 'file', $info, "Plugin '{$slug}' should have a file key" );
			$this->assertArrayHasKey( 'meta_key', $info, "Plugin '{$slug}' should have a meta_key key" );
		}
	}

	/**
	 * Test that get_supported_plugins() can be filtered.
	 *
	 * @since 0.7.0
	 */
	public function test_get_supported_plugins_is_filterable() {
		add_filter(
			'wpai_meta_description_seo_plugins',
			static function ( $plugins ) {
				$plugins['custom-seo'] = array(
					'file'     => 'custom-seo/custom-seo.php',
					'meta_key' => '_custom_seo_description',
				);
				return $plugins;
			}
		);

		$plugins = SEO_Integration::get_supported_plugins();

		$this->assertArrayHasKey( 'custom-seo', $plugins, 'Custom SEO plugin should be registered' );
		$this->assertEquals( '_custom_seo_description', $plugins['custom-seo']['meta_key'], 'Custom meta key should match' );
	}

	/**
	 * Test that detect_active_plugin() returns null when no SEO plugin is active.
	 *
	 * @since 0.7.0
	 */
	public function test_detect_active_plugin_returns_null_when_none_active() {
		$result = SEO_Integration::detect_active_plugin();

		$this->assertNull( $result, 'Should return null when no SEO plugin is active' );
	}

	/**
	 * Test that get_meta_key() returns fallback when no SEO plugin is active.
	 *
	 * @since 0.7.0
	 */
	public function test_get_meta_key_returns_fallback_when_no_plugin_active() {
		$meta_key = SEO_Integration::get_meta_key();

		$this->assertEquals( SEO_Integration::FALLBACK_META_KEY, $meta_key, 'Should return fallback meta key' );
	}

	/**
	 * Test that get_meta_key() returns correct key for known plugin slug.
	 *
	 * @since 0.7.0
	 */
	public function test_get_meta_key_returns_correct_key_for_known_plugin() {
		$this->assertEquals( '_yoast_wpseo_metadesc', SEO_Integration::get_meta_key( 'yoast-seo' ), 'Should return Yoast meta key' );
		$this->assertEquals( 'rank_math_description', SEO_Integration::get_meta_key( 'rank-math' ), 'Should return Rank Math meta key' );
		$this->assertEquals( '_aioseo_description', SEO_Integration::get_meta_key( 'all-in-one-seo' ), 'Should return AIOSEO meta key' );
		$this->assertEquals( '_seopress_titles_desc', SEO_Integration::get_meta_key( 'seopress' ), 'Should return SEOPress meta key' );
	}

	/**
	 * Test that get_meta_key() returns fallback for unknown plugin slug.
	 *
	 * @since 0.7.0
	 */
	public function test_get_meta_key_returns_fallback_for_unknown_plugin() {
		$meta_key = SEO_Integration::get_meta_key( 'unknown-plugin' );

		$this->assertEquals( SEO_Integration::FALLBACK_META_KEY, $meta_key, 'Should return fallback for unknown plugin' );
	}

	/**
	 * Test that get_meta_key() can be filtered.
	 *
	 * @since 0.7.0
	 */
	public function test_get_meta_key_is_filterable() {
		add_filter(
			'wpai_meta_description_meta_key',
			static function () {
				return '_custom_override_key';
			}
		);

		$meta_key = SEO_Integration::get_meta_key();

		$this->assertEquals( '_custom_override_key', $meta_key, 'Meta key should be overridable via filter' );
	}

	/**
	 * Test that detect_active_plugin() returns the slug when a supported plugin is active.
	 *
	 * @since 0.7.0
	 */
	public function test_detect_active_plugin_returns_slug_when_plugin_active() {
		// Force a known plugin into the active plugins list.
		$active = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_merge( $active, array( 'wordpress-seo/wp-seo.php' ) ) );

		$result = SEO_Integration::detect_active_plugin();

		$this->assertEquals( 'yoast-seo', $result, 'Should return the slug of the active plugin' );

		// Restore.
		update_option( 'active_plugins', $active );
	}

	/**
	 * Test that get_meta_key() auto-detects active plugin when no slug provided.
	 *
	 * @since 0.7.0
	 */
	public function test_get_meta_key_auto_detects_active_plugin() {
		$active = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_merge( $active, array( 'wordpress-seo/wp-seo.php' ) ) );

		$meta_key = SEO_Integration::get_meta_key();

		$this->assertEquals( '_yoast_wpseo_metadesc', $meta_key, 'Should return the meta key of the detected active plugin' );

		// Restore.
		update_option( 'active_plugins', $active );
	}

	/**
	 * Test that detect_active_plugin() caches the detected slug.
	 *
	 * @since x.x.x
	 */
	public function test_detect_active_plugin_caches_detected_slug() {
		$active = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_merge( $active, array( 'wordpress-seo/wp-seo.php' ) ) );

		// First call scans and caches the detected slug.
		$this->assertEquals( 'yoast-seo', SEO_Integration::detect_active_plugin() );
		$this->assertEquals( 'yoast-seo', get_transient( SEO_Integration::CACHE_KEY ), 'The detected slug should be cached.' );

		// Deactivating without clearing the cache still returns the cached slug.
		update_option( 'active_plugins', $active );
		$this->assertEquals( 'yoast-seo', SEO_Integration::detect_active_plugin(), 'Should return the cached slug until the cache is cleared.' );
	}

	/**
	 * Test that detect_active_plugin() caches the "none active" result.
	 *
	 * @since x.x.x
	 */
	public function test_detect_active_plugin_caches_none_result() {
		// With no SEO plugin active, the "none" result is cached.
		$this->assertNull( SEO_Integration::detect_active_plugin() );
		$this->assertNotFalse( get_transient( SEO_Integration::CACHE_KEY ), 'The "none active" result should be cached.' );

		// Activating a plugin afterwards still returns null until the cache is cleared.
		$active = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_merge( $active, array( 'wordpress-seo/wp-seo.php' ) ) );
		$this->assertNull( SEO_Integration::detect_active_plugin(), 'Should return the cached "none" result until cleared.' );

		// Clearing the cache lets a fresh scan pick up the newly active plugin.
		SEO_Integration::clear_cache();
		$this->assertEquals( 'yoast-seo', SEO_Integration::detect_active_plugin() );

		update_option( 'active_plugins', $active );
	}

	/**
	 * Test that clear_cache() removes the cached value.
	 *
	 * @since x.x.x
	 */
	public function test_clear_cache_removes_cached_value() {
		set_transient( SEO_Integration::CACHE_KEY, 'yoast-seo', DAY_IN_SECONDS );

		SEO_Integration::clear_cache();

		$this->assertFalse( get_transient( SEO_Integration::CACHE_KEY ), 'clear_cache() should delete the cached value.' );
	}

	/**
	 * Test that register_cache_invalidation() hooks clear_cache onto plugin activation and deactivation.
	 *
	 * @since x.x.x
	 */
	public function test_register_cache_invalidation_hooks_actions() {
		SEO_Integration::register_cache_invalidation();

		$this->assertNotFalse(
			has_action( 'activated_plugin', array( SEO_Integration::class, 'clear_cache' ) ),
			'register_cache_invalidation() should hook clear_cache onto activated_plugin.'
		);
		$this->assertNotFalse(
			has_action( 'deactivated_plugin', array( SEO_Integration::class, 'clear_cache' ) ),
			'register_cache_invalidation() should hook clear_cache onto deactivated_plugin.'
		);
	}

	/**
	 * Test that the cache is cleared when a plugin is activated.
	 *
	 * @since x.x.x
	 */
	public function test_cache_is_cleared_on_plugin_activation() {
		SEO_Integration::register_cache_invalidation();
		set_transient( SEO_Integration::CACHE_KEY, 'yoast-seo', DAY_IN_SECONDS );

		do_action( 'activated_plugin', 'some-plugin/some-plugin.php', false );

		$this->assertFalse( get_transient( SEO_Integration::CACHE_KEY ), 'Activating a plugin should clear the cache.' );
	}

	/**
	 * Test that the cache is cleared when a plugin is deactivated.
	 *
	 * @since x.x.x
	 */
	public function test_cache_is_cleared_on_plugin_deactivation() {
		SEO_Integration::register_cache_invalidation();
		set_transient( SEO_Integration::CACHE_KEY, 'yoast-seo', DAY_IN_SECONDS );

		do_action( 'deactivated_plugin', 'some-plugin/some-plugin.php', false );

		$this->assertFalse( get_transient( SEO_Integration::CACHE_KEY ), 'Deactivating a plugin should clear the cache.' );
	}
}

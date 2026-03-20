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
 * @since 0.6.0
 */
class SEO_IntegrationTest extends WP_UnitTestCase {

	/**
	 * Tear down test case.
	 *
	 * @since 0.6.0
	 */
	public function tearDown(): void {
		remove_all_filters( 'wpai_meta_description_seo_plugins' );
		remove_all_filters( 'wpai_meta_description_meta_key' );
		parent::tearDown();
	}

	/**
	 * Test that get_supported_plugins() returns the expected default plugins.
	 *
	 * @since 0.6.0
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
	 * @since 0.6.0
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
	 * @since 0.6.0
	 */
	public function test_detect_active_plugin_returns_null_when_none_active() {
		$result = SEO_Integration::detect_active_plugin();

		$this->assertNull( $result, 'Should return null when no SEO plugin is active' );
	}

	/**
	 * Test that get_meta_key() returns fallback when no SEO plugin is active.
	 *
	 * @since 0.6.0
	 */
	public function test_get_meta_key_returns_fallback_when_no_plugin_active() {
		$meta_key = SEO_Integration::get_meta_key();

		$this->assertEquals( SEO_Integration::FALLBACK_META_KEY, $meta_key, 'Should return fallback meta key' );
	}

	/**
	 * Test that get_meta_key() returns correct key for known plugin slug.
	 *
	 * @since 0.6.0
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
	 * @since 0.6.0
	 */
	public function test_get_meta_key_returns_fallback_for_unknown_plugin() {
		$meta_key = SEO_Integration::get_meta_key( 'unknown-plugin' );

		$this->assertEquals( SEO_Integration::FALLBACK_META_KEY, $meta_key, 'Should return fallback for unknown plugin' );
	}

	/**
	 * Test that get_meta_key() can be filtered.
	 *
	 * @since 0.6.0
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
}

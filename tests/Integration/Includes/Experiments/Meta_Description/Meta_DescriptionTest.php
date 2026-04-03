<?php
/**
 * Integration tests for the Meta_Description experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Meta_Description
 */

namespace WordPress\AI\Tests\Integration\Experiments\Meta_Description;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Meta_Description\Meta_Description;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Meta_Description experiment test case.
 *
 * @since x.x.x
 */
class Meta_DescriptionTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_meta-description_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->register_features();

		$experiment = $registry->get_feature( 'meta-description' );
		$this->assertInstanceOf(
			Meta_Description::class,
			$experiment,
			'Meta description experiment should be registered in the registry.'
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		wp_cache_delete( 'wpai_active_seo_plugin', 'wpai' );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_meta-description_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Tests that the experiment reports correct metadata.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_registration(): void {
		$experiment = new Meta_Description();

		$this->assertEquals( 'meta-description', $experiment->get_id() );
		$this->assertEquals( 'Meta Description Generation', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Tests that the experiment can be disabled via the filter.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_can_be_disabled_via_filter(): void {
		add_filter( 'wpai_feature_meta-description_enabled', '__return_false' );

		$experiment = new Meta_Description();
		$this->assertFalse( $experiment->is_enabled() );

		remove_all_filters( 'wpai_feature_meta-description_enabled' );
	}

	/**
	 * Tests that register() hooks all required actions.
	 *
	 * @since x.x.x
	 */
	public function test_register_hooks_actions(): void {
		$experiment = new Meta_Description();
		$experiment->register();

		$this->assertNotFalse(
			has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ),
			'register_abilities should be hooked to wp_abilities_api_init'
		);
		$this->assertNotFalse(
			has_action( 'admin_enqueue_scripts', array( $experiment, 'enqueue_assets' ) ),
			'enqueue_assets should be hooked to admin_enqueue_scripts'
		);

		$meta = get_registered_meta_keys( 'post', 'post' );
		$this->assertArrayHasKey( 'wpai_meta_description', $meta, 'register_post_meta should be called during register()' );
	}

	/**
	 * Tests that register_abilities() hooks into the abilities API.
	 *
	 * @since x.x.x
	 */
	public function test_register_abilities(): void {
		$experiment = new Meta_Description();
		$experiment->register();

		$this->assertNotFalse(
			has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ),
			'register_abilities should be hooked to wp_abilities_api_init'
		);
	}

	/**
	 * Tests that register_post_meta() registers fallback meta when no SEO plugin is active.
	 *
	 * @since x.x.x
	 */
	public function test_register_post_meta_registers_fallback(): void {
		$experiment = new Meta_Description();
		$experiment->register_post_meta();

		$meta = get_registered_meta_keys( 'post', 'post' );
		$this->assertArrayHasKey( 'wpai_meta_description', $meta, 'Fallback meta key should be registered for post type' );
		$this->assertTrue( $meta['wpai_meta_description']['show_in_rest'], 'Meta key should be available in REST API' );
		$this->assertEquals( 'string', $meta['wpai_meta_description']['type'], 'Meta key type should be string' );
	}

	/**
	 * Tests that register_post_meta() skips attachment post type.
	 *
	 * @since x.x.x
	 */
	public function test_register_post_meta_skips_attachment(): void {
		$experiment = new Meta_Description();
		$experiment->register_post_meta();

		$meta = get_registered_meta_keys( 'post', 'attachment' );
		$this->assertArrayNotHasKey( 'wpai_meta_description', $meta, 'Meta key should not be registered for attachment post type' );
	}

	/**
	 * Tests that register_post_meta() does not register when SEO plugin is active.
	 *
	 * @since x.x.x
	 */
	public function test_register_post_meta_skips_when_seo_plugin_active(): void {
		// Clear the cache from setUp so detect_active_plugin() re-checks.
		wp_cache_delete( 'wpai_active_seo_plugin', 'wpai' );

		// Simulate an active SEO plugin via the active_plugins option.
		$active = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_merge( $active, array( 'wordpress-seo/wp-seo.php' ) ) );

		$experiment = new Meta_Description();
		$experiment->register_post_meta();

		$meta = get_registered_meta_keys( 'post', 'post' );
		$this->assertArrayNotHasKey( '_yoast_wpseo_metadesc', $meta, 'Should not register SEO plugin meta key' );
		$this->assertArrayNotHasKey( 'wpai_meta_description', $meta, 'Should not register fallback meta when SEO plugin is active' );

		// Restore.
		update_option( 'active_plugins', $active );
	}

	/**
	 * Tests that enqueue_assets() does not load on irrelevant screens.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_skips_irrelevant_screens(): void {
		$experiment = new Meta_Description();
		$experiment->register();

		$experiment->enqueue_assets( 'options-general.php' );
		$this->assertFalse( wp_script_is( 'ai_meta_description', 'enqueued' ), 'Script should not be enqueued on options-general.php' );
	}
}

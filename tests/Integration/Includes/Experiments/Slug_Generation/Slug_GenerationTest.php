<?php
/**
 * Integration tests for the Slug_Generation experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Slug_Generation;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Slug_Generation\Slug_Generation;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Slug_Generation test case.
 */
class Slug_GenerationTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_slug-generation_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'slug-generation' );
		$this->assertInstanceOf( Slug_Generation::class, $experiment, 'Slug generation experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		set_current_screen( 'front' );
		unset( $GLOBALS['current_screen'] );
		wp_dequeue_style( 'ai_slug_generation' );
		wp_deregister_style( 'ai_slug_generation' );
		wp_dequeue_script( 'ai_slug_generation' );
		wp_deregister_script( 'ai_slug_generation' );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_slug-generation_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 */
	public function test_experiment_registration() {
		$experiment = new Slug_Generation();

		$this->assertEquals( 'slug-generation', $experiment->get_id() );
		$this->assertEquals( 'Slug Generation', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that register() adds the expected hooks.
	 */
	public function test_register_adds_hooks() {
		$experiment = new Slug_Generation();
		$experiment->register();
		$this->assertIsInt( has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ), 'Should register abilities hook' );
		$this->assertIsInt( has_action( 'admin_enqueue_scripts', array( $experiment, 'enqueue_assets' ) ), 'Should register assets hook' );
	}

	/**
	 * Test that enqueue_assets() returns early for non-post screens.
	 */
	public function test_enqueue_assets_returns_early_for_non_post_screens() {
		$experiment = new Slug_Generation();

		// Should not enqueue for a non-post screen.
		$experiment->enqueue_assets( 'options-general.php' );

		$this->assertFalse( wp_script_is( 'ai_slug_generation', 'enqueued' ), 'Should not enqueue script on options page' );
		$this->assertFalse( wp_style_is( 'ai_slug_generation', 'enqueued' ), 'Should not enqueue style on options page' );
	}

	/**
	 * Test that the experiment is not enabled when globally disabled.
	 */
	public function test_experiment_not_enabled_when_globally_disabled() {
		update_option( 'wpai_features_enabled', false );

		$experiment = new Slug_Generation();

		$this->assertFalse( $experiment->is_enabled(), 'Should not be enabled when global toggle is off' );
	}

	/**
	 * Test that the experiment is not enabled when individually disabled.
	 */
	public function test_experiment_not_enabled_when_individually_disabled() {
		update_option( 'wpai_feature_slug-generation_enabled', false );

		$experiment = new Slug_Generation();

		$this->assertFalse( $experiment->is_enabled(), 'Should not be enabled when feature toggle is off' );
	}

	/**
	 * Tests that enqueue_assets() enqueues scripts, enqueues stylesheet and localizes settings.
	 */
	public function test_enqueue_assets_enqueues_and_localizes() {
		set_current_screen( 'post' );

		$experiment = new Slug_Generation();
		$experiment->enqueue_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'ai_slug_generation', 'enqueued' ), 'Script should be enqueued' );
		$this->assertTrue( wp_style_is( 'ai_slug_generation', 'enqueued' ), 'Style should be enqueued' );

		$localized_data = wp_scripts()->get_data( 'ai_slug_generation', 'data' );
		$this->assertStringContainsString( '"enabled":"1"', $localized_data );
		$this->assertStringContainsString( '"minContentLength":"250"', $localized_data );
		$this->assertStringContainsString( '"numberOfSuggestions":"3"', $localized_data );
	}

	/**
	 * Test that enqueue_assets() returns early for attachment post type screens.
	 */
	public function test_enqueue_assets_returns_early_for_attachment_post_type() {
		set_current_screen( 'attachment' );

		$experiment = new Slug_Generation();
		$experiment->enqueue_assets( 'post.php' );

		$this->assertFalse( wp_script_is( 'ai_slug_generation', 'enqueued' ), 'Should not enqueue script for attachment screen' );
		$this->assertFalse( wp_style_is( 'ai_slug_generation', 'enqueued' ), 'Should not enqueue style for attachment screen' );
	}

	/**
	 * Test that enqueue_assets() returns early for post types that do not support title.
	 */
	public function test_enqueue_assets_returns_early_for_post_type_without_title_support() {
		register_post_type(
			'no_title_cpt',
			array(
				'supports' => array( 'editor' ),
			)
		);
		set_current_screen( 'no_title_cpt' );

		$experiment = new Slug_Generation();
		$experiment->enqueue_assets( 'post.php' );

		$this->assertFalse( wp_script_is( 'ai_slug_generation', 'enqueued' ), 'Should not enqueue script for post type without title support' );
		$this->assertFalse( wp_style_is( 'ai_slug_generation', 'enqueued' ), 'Should not enqueue style for post type without title support' );

		unregister_post_type( 'no_title_cpt' );
	}

	/**
	 * Test that enqueue_assets() returns early when current screen is null.
	 */
	public function test_enqueue_assets_returns_early_when_no_screen() {
		unset( $GLOBALS['current_screen'] );

		$experiment = new Slug_Generation();
		$experiment->enqueue_assets( 'post.php' );

		$this->assertFalse( wp_script_is( 'ai_slug_generation', 'enqueued' ), 'Should not enqueue script when current screen is null' );
		$this->assertFalse( wp_style_is( 'ai_slug_generation', 'enqueued' ), 'Should not enqueue style when current screen is null' );
	}

	/**
	 * Test that enqueue_assets() validates and clamps values returned by wpai_slug_generation_number_of_suggestions filter.
	 */
	public function test_enqueue_assets_clamps_filtered_number_of_suggestions() {
		set_current_screen( 'post' );

		add_filter(
			'wpai_slug_generation_number_of_suggestions',
			static function () {
				return 999;
			}
		);

		$experiment = new Slug_Generation();
		$experiment->enqueue_assets( 'post.php' );

		$localized_data = wp_scripts()->get_data( 'ai_slug_generation', 'data' );
		$this->assertStringContainsString( '"numberOfSuggestions":"10"', $localized_data );

		remove_all_filters( 'wpai_slug_generation_number_of_suggestions' );
	}
}

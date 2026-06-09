<?php
/**
 * Integration tests for the Title_Generation class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Title_Generation;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Title_Generation\Title_Generation;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Title_Generation test case.
 *
 * @since 0.1.0
 */
class Title_GenerationTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_title-generation_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'title-generation' );
		$this->assertInstanceOf( Title_Generation::class, $experiment, 'Title generation experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.1.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_title-generation_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since 0.1.0
	 */
	public function test_experiment_registration() {
		$experiment = new Title_Generation();

		$this->assertEquals( 'title-generation', $experiment->get_id() );
		$this->assertEquals( 'Title Generation', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that register() adds the expected hooks.
	 *
	 * @since 0.7.0
	 */
	public function test_register_adds_hooks() {
		$experiment = new Title_Generation();
		$experiment->register();
		$this->assertIsInt( has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ), 'Should register abilities hook' );
		$this->assertIsInt( has_action( 'admin_enqueue_scripts', array( $experiment, 'enqueue_assets' ) ), 'Should register assets hook' );
	}

	/**
	 * Test that enqueue_assets() returns early for non-post screens.
	 *
	 * @since 0.7.0
	 */
	public function test_enqueue_assets_returns_early_for_non_post_screens() {
		$experiment = new Title_Generation();

		// Should not enqueue for a non-post screen.
		$experiment->enqueue_assets( 'options-general.php' );

		$this->assertFalse( wp_script_is( 'ai_title_generation', 'enqueued' ), 'Should not enqueue on options page' );
	}

	/**
	 * Test that the experiment is not enabled when globally disabled.
	 *
	 * @since 0.7.0
	 */
	public function test_experiment_not_enabled_when_globally_disabled() {
		update_option( 'wpai_features_enabled', false );

		$experiment = new Title_Generation();

		$this->assertFalse( $experiment->is_enabled(), 'Should not be enabled when global toggle is off' );
	}

	/**
	 * Test that the experiment is not enabled when individually disabled.
	 *
	 * @since 0.7.0
	 */
	public function test_experiment_not_enabled_when_individually_disabled() {
		update_option( 'wpai_feature_title-generation_enabled', false );

		$experiment = new Title_Generation();

		$this->assertFalse( $experiment->is_enabled(), 'Should not be enabled when feature toggle is off' );
	}

	/**
	 * Tests that enqueue_assets() localizes the default minimum content length.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_localizes_default_min_content_length() {
		$experiment = new Title_Generation();
		$experiment->enqueue_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'ai_title_generation', 'enqueued' ) );
		$this->assertStringContainsString(
			'"minContentLength":"100"',
			(string) wp_scripts()->get_data( 'ai_title_generation', 'data' )
		);
	}

	/**
	 * Tests that enqueue_assets() localizes the filtered minimum content length.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_localizes_filtered_min_content_length() {
		$filter = static function () {
			return 250;
		};

		add_filter( 'wpai_min_content_length', $filter );

		$experiment = new Title_Generation();
		$experiment->enqueue_assets( 'post.php' );

		remove_filter( 'wpai_min_content_length', $filter );

		$this->assertStringContainsString(
			'"minContentLength":"250"',
			(string) wp_scripts()->get_data( 'ai_title_generation', 'data' )
		);
	}
}

<?php
/**
 * Integration tests for the Content Resizing experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Content_Resizing;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Resizing\Content_Resizing;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Content Resizing experiment test case.
 *
 * @since 0.9.0
 */
class Content_ResizingTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.9.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_content-resizing_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'content-resizing' );
		$this->assertInstanceOf( Content_Resizing::class, $experiment, 'Content Resizing experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.9.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_content-resizing_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since 0.9.0
	 */
	public function test_experiment_registration() {
		$experiment = new Content_Resizing();

		$this->assertEquals( 'content-resizing', $experiment->get_id() );
		$this->assertEquals( 'Content Resizing', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that the experiment can be disabled via filter.
	 *
	 * @since 0.9.0
	 */
	public function test_experiment_can_be_disabled() {
		add_filter( 'wpai_feature_content-resizing_enabled', '__return_false' );

		$experiment = new Content_Resizing();

		$this->assertFalse( $experiment->is_enabled() );

		remove_filter( 'wpai_feature_content-resizing_enabled', '__return_false' );
	}

	/**
	 * Test that the experiment metadata is correct.
	 *
	 * @since 0.9.0
	 */
	public function test_experiment_metadata() {
		$experiment = new Content_Resizing();

		$this->assertEquals( 'content-resizing', $experiment->get_id() );
		$this->assertNotEmpty( $experiment->get_label(), 'Label should not be empty' );
		$this->assertNotEmpty( $experiment->get_description(), 'Description should not be empty' );
	}

	/**
	 * Test that register() hooks all required actions.
	 *
	 * @since 0.9.0
	 */
	public function test_register_hooks_actions() {
		$experiment = new Content_Resizing();
		$experiment->register();

		$this->assertNotFalse(
			has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ),
			'register_abilities should be hooked to wp_abilities_api_init'
		);
		$this->assertNotFalse(
			has_action( 'admin_enqueue_scripts', array( $experiment, 'enqueue_assets' ) ),
			'enqueue_assets should be hooked to admin_enqueue_scripts'
		);
	}

	/**
	 * Test that enqueue_assets() does not enqueue the assets on the wrong admin page.
	 *
	 * @since 0.9.0
	 */
	public function test_enqueue_assets_does_not_enqueue_on_wrong_admin_page() {
		$experiment = new Content_Resizing();
		$experiment->register();
		$experiment->enqueue_assets( 'edit.php' );

		$this->assertFalse( wp_script_is( 'ai_content_resizing', 'enqueued' ), 'Script should not be enqueued on edit.php' );
	}

	/**
	 * Test that the experiment is disabled when the global toggle is off.
	 *
	 * @since 0.9.0
	 */
	public function test_experiment_disabled_when_global_toggle_off() {
		update_option( 'wpai_features_enabled', false );

		$experiment = new Content_Resizing();

		$this->assertFalse( $experiment->is_enabled(), 'Experiment should be disabled when global toggle is off' );
	}

	/**
	 * Tests that enqueue_assets() localizes the default minimum content length.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_localizes_default_min_content_length() {
		$experiment = new Content_Resizing();
		$experiment->enqueue_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'ai_content_resizing', 'enqueued' ) );
		$this->assertStringContainsString(
			'"minContentLength":"100"',
			(string) wp_scripts()->get_data( 'ai_content_resizing', 'data' )
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

		$experiment = new Content_Resizing();
		$experiment->enqueue_assets( 'post.php' );

		remove_filter( 'wpai_min_content_length', $filter );

		$this->assertStringContainsString(
			'"minContentLength":"250"',
			(string) wp_scripts()->get_data( 'ai_content_resizing', 'data' )
		);
	}
}

<?php
/**
 * Integration tests for the Summarization class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Summarization;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Experiments;
use WordPress\AI\Experiments\Summarization\Summarization;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Summarization test case.
 *
 * @since 0.2.0
 */
class SummarizationTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.2.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_summarization_enabled', true );

		$experiments = new Experiments();
		$experiments->init();

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'summarization' );
		$this->assertInstanceOf( Summarization::class, $experiment, 'Summarization experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.2.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_summarization_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_filter( 'wpai_default_feature_classes', array( Experiments::class, 'register_default_experiment_classes' ), 9 );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since 0.2.0
	 */
	public function test_experiment_registration() {
		$experiment = new Summarization();

		$this->assertEquals( 'summarization', $experiment->get_id() );
		$this->assertEquals( 'Content Summarization', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Tests that the editor assets are registered with the block editor assets hook.
	 *
	 * @since 1.1.0
	 */
	public function test_register_uses_block_editor_assets_hook() {
		$experiment = new Summarization();

		try {
			$experiment->register();

			$this->assertNotFalse(
				has_action( 'enqueue_block_editor_assets', array( $experiment, 'enqueue_assets' ) ),
				'Summarization editor assets should load with other block editor controls.'
			);
			$this->assertFalse(
				has_action( 'admin_enqueue_scripts', array( $experiment, 'enqueue_assets' ) ),
				'Summarization editor assets should not load through the general admin assets hook.'
			);
		} finally {
			remove_action( 'enqueue_block_editor_assets', array( $experiment, 'enqueue_assets' ) );
			remove_action( 'admin_enqueue_scripts', array( $experiment, 'enqueue_assets' ) );
			remove_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) );
			remove_action( 'enqueue_block_assets', array( $experiment, 'enqueue_block_assets' ) );
		}
	}

	/**
	 * Tests that enqueue_assets() does not load outside the post editor.
	 *
	 * @since 1.1.0
	 */
	public function test_enqueue_assets_skips_non_post_screens() {
		$experiment = new Summarization();

		set_current_screen( 'dashboard' );

		try {
			$experiment->enqueue_assets();

			$this->assertFalse(
				wp_script_is( 'ai_summarization', 'enqueued' ),
				'Summarization assets should not load outside post editor screens.'
			);
		} finally {
			set_current_screen( 'front' );
		}
	}
}

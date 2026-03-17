<?php
/**
 * Integration tests for the Contextual_Tagging class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Contextual_Tagging;

use WP_UnitTestCase;
use WordPress\AI\Experiment_Category;
use WordPress\AI\Experiment_Loader;
use WordPress\AI\Experiment_Registry;
use WordPress\AI\Experiments\Contextual_Tagging\Contextual_Tagging;

/**
 * Contextual_Tagging test case.
 *
 * @since 0.6.0
 */
class Contextual_TaggingTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.6.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_contextual-tagging_enabled', true );

		$registry = new Experiment_Registry();
		$loader   = new Experiment_Loader( $registry );
		$loader->register_default_experiments();

		$experiment = $registry->get_experiment( 'contextual-tagging' );
		$this->assertInstanceOf( Contextual_Tagging::class, $experiment, 'Contextual tagging experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.6.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_contextual-tagging_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since 0.6.0
	 */
	public function test_experiment_registration() {
		$experiment = new Contextual_Tagging();

		$this->assertEquals( 'contextual-tagging', $experiment->get_id() );
		$this->assertEquals( 'Contextual Tagging', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that experiment settings are registered.
	 *
	 * @since 0.6.0
	 */
	public function test_experiment_settings_registration() {
		$experiment = new Contextual_Tagging();
		$experiment->register_settings();

		// Verify the settings are registered by checking they can be retrieved.
		$strategy = get_option( 'ai_experiment_contextual-tagging_field_strategy', 'existing_only' );
		$this->assertEquals( 'existing_only', $strategy );

		$max_suggestions = get_option( 'ai_experiment_contextual-tagging_field_max_suggestions', 5 );
		$this->assertEquals( 5, $max_suggestions );
	}

	/**
	 * Test that strategy sanitization works correctly.
	 *
	 * @since 0.6.0
	 */
	public function test_sanitize_strategy() {
		$experiment = new Contextual_Tagging();

		$this->assertEquals( 'existing_only', $experiment->sanitize_strategy( 'existing_only' ) );
		$this->assertEquals( 'allow_new', $experiment->sanitize_strategy( 'allow_new' ) );
		$this->assertEquals( 'existing_only', $experiment->sanitize_strategy( 'invalid_value' ) );
		$this->assertEquals( 'existing_only', $experiment->sanitize_strategy( '' ) );
	}

	/**
	 * Test that max suggestions sanitization works correctly.
	 *
	 * @since 0.6.0
	 */
	public function test_sanitize_max_suggestions() {
		$experiment = new Contextual_Tagging();

		$this->assertEquals( 5, $experiment->sanitize_max_suggestions( 5 ) );
		$this->assertEquals( 1, $experiment->sanitize_max_suggestions( 0 ) );
		$this->assertEquals( 1, $experiment->sanitize_max_suggestions( -1 ) );
		$this->assertEquals( 10, $experiment->sanitize_max_suggestions( 15 ) );
		$this->assertEquals( 7, $experiment->sanitize_max_suggestions( '7' ) );
	}
}

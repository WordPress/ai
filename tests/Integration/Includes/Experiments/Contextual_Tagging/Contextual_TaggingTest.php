<?php
/**
 * Integration tests for the Contextual_Tagging class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Contextual_Tagging;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Contextual_Tagging\Contextual_Tagging;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Contextual_Tagging test case.
 *
 * @since x.x.x
 */
class Contextual_TaggingTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_contextual-tagging_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->register_features();

		$experiment = $registry->get_feature( 'contextual-tagging' );
		$this->assertInstanceOf( Contextual_Tagging::class, $experiment, 'Contextual tagging experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_contextual-tagging_enabled' );
		delete_option( 'wpai_feature_contextual-tagging_field_strategy' );
		delete_option( 'wpai_feature_contextual-tagging_field_max_suggestions' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_all_filters( 'wpai_contextual_tagging_strategy' );
		remove_all_filters( 'wpai_contextual_tagging_max_suggestions' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	public function test_experiment_settings_registration() {
		$experiment = new Contextual_Tagging();
		$experiment->register_settings();

		// Verify the settings are registered by checking they can be retrieved.
		$strategy = get_option( 'wpai_feature_contextual-tagging_field_strategy', 'existing_only' );
		$this->assertEquals( 'existing_only', $strategy );

		$max_suggestions = get_option( 'wpai_feature_contextual-tagging_field_max_suggestions', 5 );
		$this->assertEquals( 5, $max_suggestions );
	}

	/**
	 * Test that strategy sanitization works correctly.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	public function test_sanitize_max_suggestions() {
		$experiment = new Contextual_Tagging();

		$this->assertEquals( 5, $experiment->sanitize_max_suggestions( 5 ) );
		$this->assertEquals( 1, $experiment->sanitize_max_suggestions( 0 ) );
		$this->assertEquals( 1, $experiment->sanitize_max_suggestions( -1 ) );
		$this->assertEquals( 10, $experiment->sanitize_max_suggestions( 15 ) );
		$this->assertEquals( 7, $experiment->sanitize_max_suggestions( '7' ) );
	}

	/**
	 * Test that get_strategy() returns the default value.
	 *
	 * @since x.x.x
	 */
	public function test_get_strategy_returns_default() {
		$experiment = new Contextual_Tagging();

		$this->assertEquals( 'existing_only', $experiment->get_strategy() );
	}

	/**
	 * Test that get_strategy() returns the saved option value.
	 *
	 * @since x.x.x
	 */
	public function test_get_strategy_returns_saved_option() {
		update_option( 'wpai_feature_contextual-tagging_field_strategy', 'allow_new' );
		$experiment = new Contextual_Tagging();

		$this->assertEquals( 'allow_new', $experiment->get_strategy() );
	}

	/**
	 * Test that get_strategy() is filterable.
	 *
	 * @since x.x.x
	 */
	public function test_get_strategy_is_filterable() {
		$experiment = new Contextual_Tagging();

		add_filter(
			'wpai_contextual_tagging_strategy',
			static function () {
				return 'allow_new';
			}
		);

		$this->assertEquals( 'allow_new', $experiment->get_strategy() );
	}

	/**
	 * Test that get_strategy() sanitizes filtered value.
	 *
	 * @since x.x.x
	 */
	public function test_get_strategy_sanitizes_filtered_value() {
		$experiment = new Contextual_Tagging();

		add_filter(
			'wpai_contextual_tagging_strategy',
			static function () {
				return 'malicious_value';
			}
		);

		$this->assertEquals( 'existing_only', $experiment->get_strategy(), 'Invalid filtered value should fall back to default' );
	}

	/**
	 * Test that get_max_suggestions() returns the default value.
	 *
	 * @since x.x.x
	 */
	public function test_get_max_suggestions_returns_default() {
		$experiment = new Contextual_Tagging();

		$this->assertEquals( 5, $experiment->get_max_suggestions() );
	}

	/**
	 * Test that get_max_suggestions() returns the saved option value.
	 *
	 * @since x.x.x
	 */
	public function test_get_max_suggestions_returns_saved_option() {
		update_option( 'wpai_feature_contextual-tagging_field_max_suggestions', 8 );
		$experiment = new Contextual_Tagging();

		$this->assertEquals( 8, $experiment->get_max_suggestions() );
	}

	/**
	 * Test that get_max_suggestions() is filterable.
	 *
	 * @since x.x.x
	 */
	public function test_get_max_suggestions_is_filterable() {
		$experiment = new Contextual_Tagging();

		add_filter(
			'wpai_contextual_tagging_max_suggestions',
			static function () {
				return 3;
			}
		);

		$this->assertEquals( 3, $experiment->get_max_suggestions() );
	}
}

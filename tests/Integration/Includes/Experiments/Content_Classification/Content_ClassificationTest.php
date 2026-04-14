<?php
/**
 * Integration tests for the Content_Classification class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Content_Classification;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Content_Classification\Content_Classification;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Content_Classification test case.
 *
 * @since 0.7.0
 */
class Content_ClassificationTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.7.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_content-classification_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'content-classification' );
		$this->assertInstanceOf( Content_Classification::class, $experiment, 'Content classification experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.7.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_content-classification_enabled' );
		delete_option( 'wpai_feature_content-classification_field_strategy' );
		delete_option( 'wpai_feature_content-classification_field_max_suggestions' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_all_filters( 'wpai_content_classification_strategy' );
		remove_all_filters( 'wpai_content_classification_max_suggestions' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since 0.7.0
	 */
	public function test_experiment_registration() {
		$experiment = new Content_Classification();

		$this->assertEquals( 'content-classification', $experiment->get_id() );
		$this->assertEquals( 'Content Classification', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that experiment settings are registered.
	 *
	 * @since 0.7.0
	 */
	public function test_experiment_settings_registration() {
		$experiment = new Content_Classification();
		$experiment->register_settings();

		// Verify the settings are registered by checking they can be retrieved.
		$strategy = get_option( 'wpai_feature_content-classification_field_strategy', 'existing_only' );
		$this->assertEquals( 'existing_only', $strategy );

		$max_suggestions = get_option( 'wpai_feature_content-classification_field_max_suggestions', 5 );
		$this->assertEquals( 5, $max_suggestions );
	}

	/**
	 * Test that strategy sanitization works correctly.
	 *
	 * @since 0.7.0
	 */
	public function test_sanitize_strategy() {
		$experiment = new Content_Classification();

		$this->assertEquals( 'existing_only', $experiment->sanitize_strategy( 'existing_only' ) );
		$this->assertEquals( 'allow_new', $experiment->sanitize_strategy( 'allow_new' ) );
		$this->assertEquals( 'existing_only', $experiment->sanitize_strategy( 'invalid_value' ) );
		$this->assertEquals( 'existing_only', $experiment->sanitize_strategy( '' ) );
	}

	/**
	 * Test that max suggestions sanitization works correctly.
	 *
	 * @since 0.7.0
	 */
	public function test_sanitize_max_suggestions() {
		$experiment = new Content_Classification();

		$this->assertEquals( 5, $experiment->sanitize_max_suggestions( 5 ) );
		$this->assertEquals( 1, $experiment->sanitize_max_suggestions( 0 ) );
		$this->assertEquals( 1, $experiment->sanitize_max_suggestions( -1 ) );
		$this->assertEquals( 10, $experiment->sanitize_max_suggestions( 15 ) );
		$this->assertEquals( 7, $experiment->sanitize_max_suggestions( '7' ) );
	}

	/**
	 * Test that get_strategy() returns the default value.
	 *
	 * @since 0.7.0
	 */
	public function test_get_strategy_returns_default() {
		$experiment = new Content_Classification();

		$this->assertEquals( 'existing_only', $experiment->get_strategy() );
	}

	/**
	 * Test that get_strategy() returns the saved option value.
	 *
	 * @since 0.7.0
	 */
	public function test_get_strategy_returns_saved_option() {
		update_option( 'wpai_feature_content-classification_field_strategy', 'allow_new' );
		$experiment = new Content_Classification();

		$this->assertEquals( 'allow_new', $experiment->get_strategy() );
	}

	/**
	 * Test that get_strategy() is filterable.
	 *
	 * @since 0.7.0
	 */
	public function test_get_strategy_is_filterable() {
		$experiment = new Content_Classification();

		add_filter(
			'wpai_content_classification_strategy',
			static function () {
				return 'allow_new';
			}
		);

		$this->assertEquals( 'allow_new', $experiment->get_strategy() );
	}

	/**
	 * Test that get_strategy() sanitizes filtered value.
	 *
	 * @since 0.7.0
	 */
	public function test_get_strategy_sanitizes_filtered_value() {
		$experiment = new Content_Classification();

		add_filter(
			'wpai_content_classification_strategy',
			static function () {
				return 'malicious_value';
			}
		);

		$this->assertEquals( 'existing_only', $experiment->get_strategy(), 'Invalid filtered value should fall back to default' );
	}

	/**
	 * Test that get_max_suggestions() returns the default value.
	 *
	 * @since 0.7.0
	 */
	public function test_get_max_suggestions_returns_default() {
		$experiment = new Content_Classification();

		$this->assertEquals( 5, $experiment->get_max_suggestions() );
	}

	/**
	 * Test that get_max_suggestions() returns the saved option value.
	 *
	 * @since 0.7.0
	 */
	public function test_get_max_suggestions_returns_saved_option() {
		update_option( 'wpai_feature_content-classification_field_max_suggestions', 8 );
		$experiment = new Content_Classification();

		$this->assertEquals( 8, $experiment->get_max_suggestions() );
	}

	/**
	 * Test that get_max_suggestions() is filterable.
	 *
	 * @since 0.7.0
	 */
	public function test_get_max_suggestions_is_filterable() {
		$experiment = new Content_Classification();

		add_filter(
			'wpai_content_classification_max_suggestions',
			static function () {
				return 3;
			}
		);

		$this->assertEquals( 3, $experiment->get_max_suggestions() );
	}

	/**
	 * Test that get_max_suggestions() sanitizes filtered value that exceeds maximum.
	 *
	 * @since 0.7.0
	 */
	public function test_get_max_suggestions_sanitizes_filtered_value_above_max() {
		$experiment = new Content_Classification();

		add_filter(
			'wpai_content_classification_max_suggestions',
			static function () {
				return 50;
			}
		);

		$this->assertEquals( 10, $experiment->get_max_suggestions(), 'Filtered value above 10 should be clamped to 10' );
	}

	/**
	 * Test that get_max_suggestions() sanitizes filtered value below minimum.
	 *
	 * @since 0.7.0
	 */
	public function test_get_max_suggestions_sanitizes_filtered_value_below_min() {
		$experiment = new Content_Classification();

		add_filter(
			'wpai_content_classification_max_suggestions',
			static function () {
				return 0;
			}
		);

		$this->assertEquals( 1, $experiment->get_max_suggestions(), 'Filtered value of 0 should be clamped to 1' );
	}

	/**
	 * Test that get_settings_fields() returns strategy and max_suggestions.
	 *
	 * @since 0.7.0
	 */
	public function test_get_settings_fields_returns_expected_fields() {
		$experiment = new Content_Classification();
		$fields     = $experiment->get_settings_fields();

		$this->assertCount( 2, $fields, 'Should declare two custom settings fields' );

		// Verify strategy field.
		$this->assertSame( 'strategy', $fields[0]['id'] );
		$this->assertSame( 'text', $fields[0]['type'] );
		$this->assertSame( 'existing_only', $fields[0]['default'] );
		$this->assertCount( 2, $fields[0]['elements'], 'Strategy field should have two element options' );
		$this->assertSame( 'existing_only', $fields[0]['elements'][0]['value'] );
		$this->assertSame( 'allow_new', $fields[0]['elements'][1]['value'] );

		// Verify max_suggestions field.
		$this->assertSame( 'max_suggestions', $fields[1]['id'] );
		$this->assertSame( 'integer', $fields[1]['type'] );
		$this->assertSame( 5, $fields[1]['default'] );
	}

	/**
	 * Test that get_settings_fields_metadata() resolves IDs to full option names.
	 *
	 * @since 0.7.0
	 */
	public function test_get_settings_fields_metadata_resolves_ids() {
		$experiment = new Content_Classification();
		$fields     = $experiment->get_settings_fields_metadata();

		$this->assertSame(
			'wpai_feature_content-classification_field_strategy',
			$fields[0]['id'],
			'Strategy field id should be resolved to full option name'
		);
		$this->assertSame(
			'wpai_feature_content-classification_field_max_suggestions',
			$fields[1]['id'],
			'Max suggestions field id should be resolved to full option name'
		);
	}

	/**
	 * Test that register_settings() registers options with show_in_rest.
	 *
	 * @since 0.7.0
	 */
	public function test_register_settings_has_show_in_rest() {
		$experiment = new Content_Classification();
		$experiment->register_settings();

		$registered = get_registered_settings();

		$strategy_key        = 'wpai_feature_content-classification_field_strategy';
		$max_suggestions_key = 'wpai_feature_content-classification_field_max_suggestions';

		$this->assertArrayHasKey( $strategy_key, $registered, 'Strategy setting should be registered' );
		$this->assertArrayHasKey( $max_suggestions_key, $registered, 'Max suggestions setting should be registered' );

		$this->assertNotEmpty( $registered[ $strategy_key ]['show_in_rest'], 'Strategy should have show_in_rest' );
		$this->assertNotEmpty( $registered[ $max_suggestions_key ]['show_in_rest'], 'Max suggestions should have show_in_rest' );
	}
}

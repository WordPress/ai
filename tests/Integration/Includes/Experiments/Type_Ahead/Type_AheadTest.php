<?php
/**
 * Integration tests for the Type_Ahead experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Type_Ahead
 */

namespace WordPress\AI\Tests\Integration\Experiments\Type_Ahead;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Type_Ahead\Type_Ahead;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Type_Ahead experiment test case.
 *
 * @since x.x.x
 */
class Type_AheadTest extends WP_UnitTestCase {
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
		update_option( 'wpai_feature_type-ahead_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'type-ahead' );
		$this->assertInstanceOf( Type_Ahead::class, $experiment );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_type-ahead_enabled' );
		delete_option( 'wpai_feature_type-ahead_field_mode' );
		delete_option( 'wpai_feature_type-ahead_field_delay' );
		delete_option( 'wpai_feature_type-ahead_field_confidence' );
		delete_option( 'wpai_feature_type-ahead_field_max_words' );
		delete_option( 'wpai_feature_type-ahead_field_headings' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Tests experiment metadata and registration.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_registration() {
		$experiment = new Type_Ahead();

		$this->assertEquals( 'type-ahead', $experiment->get_id() );
		$this->assertEquals( 'Type-ahead Text', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Tests experiment can be disabled via filter.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_can_be_disabled_via_filter() {
		add_filter( 'wpai_feature_type-ahead_enabled', '__return_false' );

		$experiment = new Type_Ahead();
		$this->assertFalse( $experiment->is_enabled() );

		remove_all_filters( 'wpai_feature_type-ahead_enabled' );
	}

	/**
	 * Tests register() hooks expected actions.
	 *
	 * @since x.x.x
	 */
	public function test_register_hooks_actions() {
		$experiment = new Type_Ahead();
		$experiment->register();

		$this->assertNotFalse(
			has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ),
			'register_abilities should be hooked to wp_abilities_api_init'
		);
		$this->assertNotFalse(
			has_action( 'enqueue_block_assets', array( $experiment, 'enqueue_assets' ) ),
			'enqueue_assets should be hooked to enqueue_block_assets'
		);
	}

	/**
	 * Tests register_settings() registers expected settings with REST exposure.
	 *
	 * @since x.x.x
	 */
	public function test_register_settings_has_show_in_rest() {
		$experiment = new Type_Ahead();
		$experiment->register_settings();

		$registered = get_registered_settings();
		$keys       = array(
			'wpai_feature_type-ahead_field_mode',
			'wpai_feature_type-ahead_field_delay',
			'wpai_feature_type-ahead_field_confidence',
			'wpai_feature_type-ahead_field_max_words',
			'wpai_feature_type-ahead_field_headings',
		);

		foreach ( $keys as $key ) {
			$this->assertArrayHasKey( $key, $registered, sprintf( '%s should be registered', $key ) );
			$this->assertNotEmpty( $registered[ $key ]['show_in_rest'], sprintf( '%s should have show_in_rest', $key ) );
		}
	}

	/**
	 * Tests setting sanitizers.
	 *
	 * @since x.x.x
	 */
	public function test_sanitizers() {
		$experiment = new Type_Ahead();

		$this->assertSame( 'word', $experiment->sanitize_mode( 'word' ) );
		$this->assertSame( 'smart', $experiment->sanitize_mode( 'invalid' ) );

		$this->assertSame( 200, $experiment->sanitize_delay( 100 ) );
		$this->assertSame( 2000, $experiment->sanitize_delay( 3000 ) );
		$this->assertSame( 450, $experiment->sanitize_delay( 450 ) );

		$this->assertSame( 0, $experiment->sanitize_confidence( -10 ) );
		$this->assertSame( 100, $experiment->sanitize_confidence( 110 ) );
		$this->assertSame( 75, $experiment->sanitize_confidence( 75 ) );

		$this->assertSame( 1, $experiment->sanitize_max_words( 0 ) );
		$this->assertSame( 50, $experiment->sanitize_max_words( 100 ) );
		$this->assertSame( 12, $experiment->sanitize_max_words( 12 ) );
	}

	/**
	 * Tests get_settings_fields() structure.
	 *
	 * @since x.x.x
	 */
	public function test_get_settings_fields_returns_expected_fields() {
		$experiment = new Type_Ahead();
		$fields     = $experiment->get_settings_fields();

		$this->assertCount( 5, $fields );
		$this->assertSame( 'mode', $fields[0]['id'] );
		$this->assertSame( 'delay', $fields[1]['id'] );
		$this->assertSame( 'confidence', $fields[2]['id'] );
		$this->assertSame( 'max_words', $fields[3]['id'] );
		$this->assertSame( 'headings', $fields[4]['id'] );
	}
}

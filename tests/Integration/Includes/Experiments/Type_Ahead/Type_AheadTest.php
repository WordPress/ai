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
 * @since 1.1.0
 */
class Type_AheadTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		wp_dequeue_style( 'ai_type_ahead' );
		wp_deregister_style( 'ai_type_ahead' );
		wp_dequeue_script( 'ai_type_ahead' );
		wp_deregister_script( 'ai_type_ahead' );
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
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
	 */
	public function test_register_hooks_actions() {
		$experiment = new Type_Ahead();
		$experiment->register();

		$this->assertNotFalse(
			has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ),
			'register_abilities should be hooked to wp_abilities_api_init'
		);
		$this->assertNotFalse(
			has_action( 'enqueue_block_editor_assets', array( $experiment, 'enqueue_assets' ) ),
			'enqueue_assets should be hooked to enqueue_block_editor_assets'
		);
		$this->assertNotFalse(
			has_action( 'enqueue_block_assets', array( $experiment, 'enqueue_block_assets' ) ),
			'enqueue_block_assets should be hooked to enqueue_block_assets'
		);
	}

	/**
	 * Tests enqueue_assets() enqueues scripts.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_enqueues_scripts() {
		$experiment = new Type_Ahead();
		$experiment->enqueue_assets();

		$this->assertTrue( wp_script_is( 'ai_type_ahead', 'enqueued' ) );
	}

	/**
	 * Tests enqueue_block_assets() enqueues styles in the admin, where the block
	 * editor iframe's stylesheet is assembled from.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_block_assets_enqueues_styles_in_admin() {
		set_current_screen( 'post' );

		$experiment = new Type_Ahead();
		$experiment->enqueue_block_assets();

		set_current_screen( 'front' );

		$this->assertTrue( wp_style_is( 'ai_type_ahead', 'enqueued' ) );
	}

	/**
	 * Tests enqueue_block_assets() skips styles on the front end, since type-ahead
	 * has no front-end output.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_block_assets_skips_styles_on_front_end() {
		set_current_screen( 'front' );

		$experiment = new Type_Ahead();
		$experiment->enqueue_block_assets();

		$this->assertFalse( wp_style_is( 'ai_type_ahead', 'enqueued' ) );
	}
}

<?php
/**
 * Integration tests for the Alt_Text_Generation experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Alt_Text_Generation
 */

namespace WordPress\AI\Tests\Integration\Experiments\Alt_Text_Generation;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Alt_Text_Generation\Alt_Text_Generation;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Alt_Text_Generation experiment test case.
 *
 * @since 0.3.0
 */
class Alt_Text_GenerationTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.3.0
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_alt-text-generation_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->register_features();

		$experiment = $registry->get_feature( 'alt-text-generation' );
		$this->assertInstanceOf(
			Alt_Text_Generation::class,
			$experiment,
			'Alt Text Generation experiment should be registered in the registry.'
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.3.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_alt-text-generation_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since 0.3.0
	 */
	public function test_experiment_registration() {
		$experiment = new Alt_Text_Generation();

		$this->assertEquals( 'alt-text-generation', $experiment->get_id() );
		$this->assertEquals( 'Alt Text Generation', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that the experiment can be disabled via filter.
	 *
	 * @since 0.3.0
	 */
	public function test_experiment_can_be_disabled_via_filter() {
		add_filter( 'wpai_feature_alt-text-generation_enabled', '__return_false' );

		$experiment = new Alt_Text_Generation();
		$this->assertFalse( $experiment->is_enabled() );

		remove_all_filters( 'wpai_feature_alt-text-generation_enabled' );
	}
}

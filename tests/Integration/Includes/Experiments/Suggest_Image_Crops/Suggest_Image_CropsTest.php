<?php
/**
 * Integration tests for the Suggest_Image_Crops experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Suggest_Image_Crops
 */

namespace WordPress\AI\Tests\Integration\Experiments\Suggest_Image_Crops;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Suggest_Image_Crops\Suggest_Image_Crops;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Suggest_Image_Crops experiment test case.
 *
 * @since x.x.x
 */
class Suggest_Image_CropsTest extends WP_UnitTestCase {
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
		update_option( 'wpai_feature_suggest-image-crops_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'suggest-image-crops' );
		$this->assertInstanceOf(
			Suggest_Image_Crops::class,
			$experiment,
			'Suggest Image Crops experiment should be registered in the registry.'
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_suggest-image-crops_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_all_filters( 'wpai_feature_suggest-image-crops_enabled' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment exposes the expected metadata.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_metadata(): void {
		$experiment = new Suggest_Image_Crops();

		$this->assertSame( 'suggest-image-crops', $experiment->get_id() );
		$this->assertSame( 'Image Crop Suggestions', $experiment->get_label() );
		$this->assertSame( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that the experiment can be disabled via the per-feature filter.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_can_be_disabled_via_filter(): void {
		add_filter( 'wpai_feature_suggest-image-crops_enabled', '__return_false' );

		$experiment = new Suggest_Image_Crops();
		$this->assertFalse( $experiment->is_enabled() );

		remove_all_filters( 'wpai_feature_suggest-image-crops_enabled' );
	}

	/**
	 * Test that register() attaches the abilities API init hook.
	 *
	 * @since x.x.x
	 */
	public function test_register_attaches_abilities_init_hook(): void {
		$experiment = new Suggest_Image_Crops();
		$experiment->register();

		$this->assertNotFalse(
			has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ),
			'register() should hook register_abilities into wp_abilities_api_init.'
		);
	}
}

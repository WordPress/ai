<?php
/**
 * Integration tests for the Meta_Description experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Meta_Description
 */

namespace WordPress\AI\Tests\Integration\Experiments\Meta_Description;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Meta_Description\Meta_Description;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Meta_Description experiment test case.
 *
 * @since 0.6.0
 */
class Meta_DescriptionTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since 0.6.0
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_meta-description_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->register_features();

		$experiment = $registry->get_feature( 'meta-description' );
		$this->assertInstanceOf(
			Meta_Description::class,
			$experiment,
			'Meta description experiment should be registered in the registry.'
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.6.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_meta-description_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Tests that the experiment reports correct metadata.
	 *
	 * @since 0.6.0
	 */
	public function test_experiment_registration(): void {
		$experiment = new Meta_Description();

		$this->assertEquals( 'meta-description', $experiment->get_id() );
		$this->assertEquals( 'Meta Description', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Tests that the experiment can be disabled via the filter.
	 *
	 * @since 0.6.0
	 */
	public function test_experiment_can_be_disabled_via_filter(): void {
		add_filter( 'wpai_feature_meta-description_enabled', '__return_false' );

		$experiment = new Meta_Description();
		$this->assertFalse( $experiment->is_enabled() );

		remove_all_filters( 'wpai_feature_meta-description_enabled' );
	}
}

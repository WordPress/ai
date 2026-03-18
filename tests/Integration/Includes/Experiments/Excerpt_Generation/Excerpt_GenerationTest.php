<?php
/**
 * Integration tests for the Excerpt_Generation experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Excerpt_Generation
 */

namespace WordPress\AI\Tests\Integration\Experiments\Excerpt_Generation;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Excerpt_Generation\Excerpt_Generation;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Excerpt_Generation experiment test case.
 *
 * @since 0.4.0
 */
class Excerpt_GenerationTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since 0.4.0
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_excerpt-generation_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->register_features();

		$experiment = $registry->get_feature( 'excerpt-generation' );
		$this->assertInstanceOf(
			Excerpt_Generation::class,
			$experiment,
			'Excerpt generation experiment should be registered in the registry.'
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.4.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_excerpt-generation_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Tests that the experiment reports correct metadata.
	 *
	 * @since 0.4.0
	 */
	public function test_experiment_registration(): void {
		$experiment = new Excerpt_Generation();

		$this->assertEquals( 'excerpt-generation', $experiment->get_id() );
		$this->assertEquals( 'Excerpt Generation', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Tests that the experiment can be disabled via the filter.
	 *
	 * @since 0.4.0
	 */
	public function test_experiment_can_be_disabled_via_filter(): void {
		add_filter( 'wpai_feature_excerpt-generation_enabled', '__return_false' );

		$experiment = new Excerpt_Generation();
		$this->assertFalse( $experiment->is_enabled() );

		remove_all_filters( 'wpai_feature_excerpt-generation_enabled' );
	}
}

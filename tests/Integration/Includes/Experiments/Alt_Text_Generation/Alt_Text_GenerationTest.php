<?php
/**
 * Integration tests for the Alt_Text_Generation experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Alt_Text_Generation
 */

namespace WordPress\AI\Tests\Integration\Experiments\Alt_Text_Generation;

use WP_UnitTestCase;
use WordPress\AI\Experiment_Loader;
use WordPress\AI\Experiment_Registry;
use WordPress\AI\Experiments\Alt_Text_Generation\Alt_Text_Generation;

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
		add_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );

		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_alt-text-generation_enabled', true );

		$registry = new Experiment_Registry();
		$loader   = new Experiment_Loader( $registry );
		$loader->register_default_experiments();

		$experiment = $registry->get_experiment( 'alt-text-generation' );
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
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_alt-text-generation_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );
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
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that the experiment can be disabled via filter.
	 *
	 * @since 0.3.0
	 */
	public function test_experiment_can_be_disabled_via_filter() {
		add_filter( 'ai_experiments_experiment_alt-text-generation_enabled', '__return_false' );

		$experiment = new Alt_Text_Generation();
		$this->assertFalse( $experiment->is_enabled() );

		remove_all_filters( 'ai_experiments_experiment_alt-text-generation_enabled' );
	}
}

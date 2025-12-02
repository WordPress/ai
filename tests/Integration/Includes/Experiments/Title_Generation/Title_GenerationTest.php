<?php
/**
 * Integration tests for the Title_Generation class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Title_Generation;

use WordPress\AI\Experiment_Registry;
use WordPress\AI\Experiment_Loader;
use WordPress\AI\Experiments\Title_Generation\Title_Generation;
use WP_UnitTestCase;

/**
 * Title_Generation test case.
 *
 * @since 0.1.0
 */
class Title_GenerationTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_title-generation_enabled', true );

		$registry = new Experiment_Registry();
		$loader   = new Experiment_Loader( $registry );
		$loader->register_default_experiments();

		$experiment = $registry->get_experiment( 'title-generation' );
		$this->assertInstanceOf( Title_Generation::class, $experiment, 'Title generation experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.1.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_title-generation_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'ai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since 0.1.0
	 */
	public function test_experiment_registration() {
		$experiment = new Title_Generation();

		$this->assertEquals( 'title-generation', $experiment->get_id() );
		$this->assertEquals( 'Title Generation', $experiment->get_label() );
		$this->assertTrue( $experiment->is_enabled() );
	}
}

<?php
/**
 * Integration tests for the Summarization class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Summarization;

use WordPress\AI\Experiment_Category;
use WordPress\AI\Experiment_Registry;
use WordPress\AI\Experiment_Loader;
use WordPress\AI\Experiments\Summarization\Summarization;
use WP_UnitTestCase;

/**
 * Summarization test case.
 *
 * @since 0.2.0
 */
class SummarizationTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since 0.2.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_summarization_enabled', true );

		$registry = new Experiment_Registry();
		$loader   = new Experiment_Loader( $registry );
		$loader->register_default_experiments();
		$loader->initialize_experiments();

		$experiment = $registry->get_experiment( 'summarization' );
		$this->assertInstanceOf( Summarization::class, $experiment, 'Summarization experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.2.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_summarization_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since 0.2.0
	 */
	public function test_experiment_registration() {
		$experiment = new Summarization();

		$this->assertEquals( 'summarization', $experiment->get_id() );
		$this->assertEquals( 'Content Summarization', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}
}


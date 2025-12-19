<?php
/**
 * Integration tests for the Markdown_Feeds class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Markdown_Feeds;

use WP_UnitTestCase;
use WordPress\AI\Experiment_Loader;
use WordPress\AI\Experiment_Registry;
use WordPress\AI\Experiments\Markdown_Feeds\Markdown_Feeds;

/**
 * Markdown_Feeds test case.
 *
 * @since x.x.x
 */
class Markdown_FeedsTest extends WP_UnitTestCase {
	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_markdown-feeds_enabled', true );

		$registry = new Experiment_Registry();
		$loader   = new Experiment_Loader( $registry );
		$loader->register_default_experiments();

		$experiment = $registry->get_experiment( 'markdown-feeds' );
		$this->assertInstanceOf( Markdown_Feeds::class, $experiment, 'Markdown feeds experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_markdown-feeds_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_registration() {
		$experiment = new Markdown_Feeds();

		$this->assertEquals( 'markdown-feeds', $experiment->get_id() );
		$this->assertEquals( 'Markdown', $experiment->get_label() );
		$this->assertTrue( $experiment->is_enabled() );
	}
}

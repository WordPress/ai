<?php
/**
 * Integration tests for the Content Resizing experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Content_Resizing;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Content_Resizing\Content_Resizing;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Content Resizing experiment test case.
 *
 * @since x.x.x
 */
class Content_ResizingTest extends WP_UnitTestCase {
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
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_content-resizing_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->register_features();
		$loader->initialize_features();

		$experiment = $registry->get_feature( 'content-resizing' );
		$this->assertInstanceOf( Content_Resizing::class, $experiment, 'Content Resizing experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_content-resizing_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_registration() {
		$experiment = new Content_Resizing();

		$this->assertEquals( 'content-resizing', $experiment->get_id() );
		$this->assertEquals( 'Content Resizing', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that the experiment can be disabled via filter.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_can_be_disabled() {
		add_filter( 'wpai_feature_content-resizing_enabled', '__return_false' );

		$experiment = new Content_Resizing();

		$this->assertFalse( $experiment->is_enabled() );

		remove_filter( 'wpai_feature_content-resizing_enabled', '__return_false' );
	}

	/**
	 * Test that the experiment metadata is correct.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_metadata() {
		$experiment = new Content_Resizing();

		$this->assertEquals( 'content-resizing', $experiment->get_id() );
		$this->assertNotEmpty( $experiment->get_label(), 'Label should not be empty' );
		$this->assertNotEmpty( $experiment->get_description(), 'Description should not be empty' );
	}
}

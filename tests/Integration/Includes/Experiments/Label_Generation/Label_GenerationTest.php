<?php
/**
 * Integration tests for the Label_Generation experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Label_Generation;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Experiments\Label_Generation\Label_Generation;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Label_Generation test case.
 *
 * @since x.x.x
 */
class Label_GenerationTest extends WP_UnitTestCase {
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

		// Enable the experiment.
		update_option( 'wpai_feature_label-generation_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'label-generation' );
		$this->assertInstanceOf( Label_Generation::class, $experiment, 'Label generation experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		set_current_screen( 'front' );
		unset( $GLOBALS['current_screen'] );
		wp_dequeue_style( 'ai_label-generation' );
		wp_deregister_style( 'ai_label-generation' );
		wp_dequeue_script( 'ai_label-generation' );
		wp_deregister_script( 'ai_label-generation' );
		delete_option( 'wpai_feature_label-generation_enabled' );
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
		$experiment = new Label_Generation();

		$this->assertEquals( 'label-generation', $experiment->get_id() );
		$this->assertEquals( 'Label Generation', $experiment->get_label() );
		$this->assertEquals( Experiment_Category::EDITOR, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that register_ability() registers the ability with the abilities API.
	 *
	 * @since x.x.x
	 */
	public function test_register_ability() {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		$experiment = new Label_Generation();
		$experiment->register();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Abilities API hook.
		do_action( 'wp_abilities_api_init' );

		$this->assertTrue( function_exists( 'wp_has_ability' ) && wp_has_ability( 'ai/label-generation' ), 'Ability ai/label-generation should be registered.' );
		$ability = wp_get_ability( 'ai/label-generation' );
		$this->assertNotNull( $ability, 'Ability should not be null.' );
		$this->assertEquals( 'Label Generation', $ability->get_label() );
	}

	/**
	 * Test that the experiment is not enabled when individually disabled.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_not_enabled_when_individually_disabled() {
		update_option( 'wpai_feature_label-generation_enabled', false );

		$experiment = new Label_Generation();

		$this->assertFalse( $experiment->is_enabled(), 'Should not be enabled when feature toggle is off' );
	}

	/**
	 * Tests that enqueue_assets() localizes the default minimum content length.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_localizes_default_min_content_length() {
		set_current_screen( 'post' );

		$experiment = new Label_Generation();
		$experiment->enqueue_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'ai_label-generation', 'enqueued' ) );
		$data = (string) wp_scripts()->get_data( 'ai_label-generation', 'data' );
		$this->assertStringContainsString( '"minContentLength":"25"', $data );
		$this->assertStringContainsString( '"enabled":"1"', $data );
	}

	/**
	 * Tests that enqueue_assets() localizes the filtered minimum content length.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_localizes_filtered_min_content_length() {
		set_current_screen( 'post' );

		$filter = static function () {
			return 75;
		};

		add_filter( 'wpai_min_content_length', $filter );

		$experiment = new Label_Generation();
		$experiment->enqueue_assets( 'post.php' );

		remove_filter( 'wpai_min_content_length', $filter );

		$data = (string) wp_scripts()->get_data( 'ai_label-generation', 'data' );
		$this->assertStringContainsString( '"minContentLength":"75"', $data );
	}
}

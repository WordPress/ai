<?php
/**
 * Integration tests for the Editorial_Updates experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Editorial_Updates;

use WordPress\AI\Experiments\Experiments;
use WordPress\AI\Experiments\Editorial_Updates\Editorial_Updates;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;
use WP_UnitTestCase;

/**
 * Editorial_Updates test case.
 *
 * @since 0.8.0
 */
class Editorial_UpdatesTest extends WP_UnitTestCase {

	/**
	 * The experiment instance under test.
	 *
	 * @since 0.8.0
	 *
	 * @var Editorial_Updates
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since 0.8.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable features globally and individually.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_editorial-updates_enabled', true );

		$experiments = new Experiments();
		$experiments->init();

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'editorial-updates' );
		$this->assertInstanceOf( Editorial_Updates::class, $experiment, 'Editorial_Updates experiment should be registered in the registry.' );

		$this->experiment = $experiment;
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.8.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_editorial-updates_enabled' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_all_filters( 'wpai_default_feature_classes' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Experiment registration
	// -------------------------------------------------------------------------

	/**
	 * Tests that the experiment is registered correctly.
	 *
	 * @since 0.8.0
	 */
	public function test_experiment_registration() {
		$this->assertEquals( 'editorial-updates', $this->experiment->get_id() );
		$this->assertEquals( 'Editorial Updates', $this->experiment->get_label() );
		$this->assertTrue( $this->experiment->is_enabled() );
	}

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	/**
	 * Tests that the required hooks are registered after the experiment initialises.
	 *
	 * @since 0.8.0
	 */
	public function test_hooks_are_registered() {
		$this->assertNotFalse(
			has_action( 'wp_abilities_api_init', array( $this->experiment, 'register_abilities' ) ),
			'wp_abilities_api_init action should be registered'
		);

		$this->assertNotFalse(
			has_action( 'enqueue_block_editor_assets', array( $this->experiment, 'enqueue_assets' ) ),
			'enqueue_block_editor_assets action should be registered'
		);
	}

	/**
	 * Tests that register_abilities() registers the ai/editorial-updates ability.
	 *
	 * @since x.x.x
	 */
	public function test_register_abilities_registers_editorial_updates_ability() {
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		do_action( 'wp_abilities_api_init' );

		$ability = wp_get_ability( 'ai/editorial-updates' );
		$this->assertNotNull( $ability, 'ai/editorial-updates ability should be registered' );
	}

	// -------------------------------------------------------------------------
	// enqueue_assets()
	// -------------------------------------------------------------------------

	/**
	 * Tests that enqueue_assets() runs without error and attempts to enqueue the script.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_runs_without_error() {
		$this->experiment->enqueue_assets();

		$this->assertTrue( true, 'enqueue_assets() should run without throwing an exception' );
	}
}

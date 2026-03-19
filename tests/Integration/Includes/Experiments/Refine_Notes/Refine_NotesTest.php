<?php
/**
 * Integration tests for the Refine_Notes experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Refine_Notes;

use WordPress\AI\Experiments\Experiments;
use WordPress\AI\Experiments\Refine_Notes\Refine_Notes;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;
use WP_UnitTestCase;

/**
 * Refine_Notes test case.
 *
 * @since x.x.x
 */
class Refine_NotesTest extends WP_UnitTestCase {

	/**
	 * The experiment instance under test.
	 *
	 * @since x.x.x
	 *
	 * @var Refine_Notes
	 */
	private $experiment;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable features globally and individually.
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_refine-notes_enabled', true );

		$experiments = new Experiments();
		$experiments->init();

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->register_features();
		$loader->initialize_features();

		$experiment = $registry->get_feature( 'refine-notes' );
		$this->assertInstanceOf( Refine_Notes::class, $experiment, 'Refine_Notes experiment should be registered in the registry.' );

		$this->experiment = $experiment;
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_refine-notes_enabled' );
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
	 * @since x.x.x
	 */
	public function test_experiment_registration() {
		$this->assertEquals( 'refine-notes', $this->experiment->get_id() );
		$this->assertEquals( 'Refine from Notes', $this->experiment->get_label() );
		$this->assertTrue( $this->experiment->is_enabled() );
	}

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	/**
	 * Tests that the required hooks are registered after the experiment initialises.
	 *
	 * @since x.x.x
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
}

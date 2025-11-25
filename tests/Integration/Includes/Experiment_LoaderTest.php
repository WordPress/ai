<?php
/**
 * Tests for the Experiment_Loader class.
 *
 * @package WordPress\AI\Tests\Integration\Includes
 */

namespace WordPress\AI\Tests\Integration\Includes;

use WordPress\AI\Experiment_Registry;
use WordPress\AI\Experiment_Loader;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WP_UnitTestCase;

/**
 * Test experiment for loader tests.
 *
 * @since 0.1.0
 */
class Mock_Experiment extends Abstract_Experiment {
	/**
	 * Tracks if register was called.
	 *
	 * @var bool
	 */
	public $register_called = false;

	/**
	 * Loads experiment metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'mock-experiment',
			'label'       => 'Mock Experiment',
			'description' => 'A mock experiment for testing',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		$this->register_called = true;
	}
}

/**
 * Experiment_Loader test case.
 *
 * @since 0.1.0
 */
class Experiment_LoaderTest extends WP_UnitTestCase {
	/**
	 * Experiment registry instance.
	 *
	 * @var Experiment_Registry
	 */
	private $registry;

	/**
	 * Experiment loader instance.
	 *
	 * @var Experiment_Loader
	 */
	private $loader;

	/**
	 * Setup test case.
	 *
	 * @since 0.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'ai_pre_has_valid_credentials_check', '__return_true' );

		$this->registry = new Experiment_Registry();
		$this->loader   = new Experiment_Loader( $this->registry );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.1.0
	 */
	public function tearDown(): void {
		remove_filter( 'ai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test register_default_experiments registers default experiments.
	 *
	 * @since 0.1.0
	 */
	public function test_register_default_experiments() {
		$this->loader->register_default_experiments();

		$this->assertTrue(
			$this->registry->has_experiment( 'example-experiment' ),
			'Example experiment should be registered'
		);

		$this->assertTrue(
			$this->registry->has_experiment( 'title-generation' ),
			'Title generation experiment should be registered'
		);

		$experiment = $this->registry->get_experiment( 'example-experiment' );
		$this->assertNotNull( $experiment, 'Example experiment should exist' );
		$this->assertEquals( 'example-experiment', $experiment->get_id() );

		$experiment = $this->registry->get_experiment( 'title-generation' );
		$this->assertNotNull( $experiment, 'Title generation experiment should exist' );
		$this->assertEquals( 'title-generation', $experiment->get_id() );
	}

	/**
	 * Test ai_register_experiments action hook fires.
	 *
	 * @since 0.1.0
	 */
	public function test_ai_register_experiments_hook_fires() {
		$hook_fired = false;
		$passed_registry = null;

		add_action(
			'ai_register_experiments',
			function ( $registry ) use ( &$hook_fired, &$passed_registry ) {
				$hook_fired = true;
				$passed_registry = $registry;
			}
		);

		$this->loader->register_default_experiments();

		$this->assertTrue( $hook_fired, 'ai_register_experiments hook should fire' );
		$this->assertSame(
			$this->registry,
			$passed_registry,
			'Registry should be passed to hook'
		);
	}

	/**
	 * Test third-party experiments can be registered via hook.
	 *
	 * @since 0.1.0
	 */
	public function test_third_party_experiment_registration() {
		add_action(
			'ai_register_experiments',
			function ( $registry ) {
				$custom_experiment = new Mock_Experiment();
				$registry->register_experiment( $custom_experiment );
			}
		);

		$this->loader->register_default_experiments();

		$this->assertTrue(
			$this->registry->has_experiment( 'mock-experiment' ),
			'Custom experiment should be registered via hook'
		);
	}

	/**
	 * Test initialize_experiments calls register on enabled experiments.
	 *
	 * @since 0.1.0
	 */
	public function test_initialize_experiments_calls_register() {
		// Enable experiments globally and individually.
		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_mock-experiment_enabled', true );

		$experiment = new Mock_Experiment();
		$this->registry->register_experiment( $experiment );

		$this->loader->initialize_experiments();

		$this->assertTrue(
			$experiment->register_called,
			'Experiment register() should be called'
		);

		// Cleanup.
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_mock-experiment_enabled' );
	}

	/**
	 * Test initialize_experiments doesn't initialize twice.
	 *
	 * @since 0.1.0
	 */
	public function test_initialize_experiments_prevents_double_initialization() {
		$experiment = new Mock_Experiment();
		$this->registry->register_experiment( $experiment );

		$this->loader->initialize_experiments();
		$this->assertTrue( $this->loader->is_initialized(), 'Should be initialized' );

		// Reset the flag to track second call.
		$experiment->register_called = false;

		// Try to initialize again.
		$this->loader->initialize_experiments();

		$this->assertFalse(
			$experiment->register_called,
			'Experiment register() should not be called twice'
		);
	}

	/**
	 * Test ai_experiments_initialized action fires.
	 *
	 * @since 0.1.0
	 */
	public function test_ai_experiments_initialized_hook_fires() {
		$hook_fired = false;

		add_action(
			'ai_experiments_initialized',
			function () use ( &$hook_fired ) {
				$hook_fired = true;
			}
		);

		$experiment = new Mock_Experiment();
		$this->registry->register_experiment( $experiment );

		$this->loader->initialize_experiments();

		$this->assertTrue( $hook_fired, 'ai_experiments_initialized hook should fire' );
	}

	/**
	 * Test ai_experiments_initialized fires before is_initialized is true.
	 *
	 * @since 0.1.0
	 */
	public function test_ai_experiments_initialized_fires_before_initialized_flag() {
		$initialized_during_hook = null;

		add_action(
			'ai_experiments_initialized',
			function () use ( &$initialized_during_hook ) {
				$initialized_during_hook = $this->loader->is_initialized();
			}
		);

		$this->loader->initialize_experiments();

		$this->assertFalse(
			$initialized_during_hook,
			'Loader should not be marked initialized during hook'
		);
		$this->assertTrue(
			$this->loader->is_initialized(),
			'Loader should be initialized after hook'
		);
	}

	/**
	 * Test disabled experiments are skipped during initialization.
	 *
	 * @since 0.1.0
	 */
	public function test_disabled_experiments_are_skipped() {
		$experiment = new Mock_Experiment();
		$this->registry->register_experiment( $experiment );

		// Disable the experiment.
		add_filter( 'ai_experiment_mock-experiment_enabled', '__return_false' );

		$this->loader->initialize_experiments();

		$this->assertFalse(
			$experiment->register_called,
			'Disabled experiment register() should not be called'
		);
	}
}

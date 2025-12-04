<?php
/**
 * Tests for the Experiment_Registry class.
 *
 * @package WordPress\AI\Tests\Includes
 */

namespace WordPress\AI\Tests\Integration\Includes;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Experiment_Loader;
use WordPress\AI\Experiment_Registry;

/**
 * Test experiment for registry tests.
 *
 * @since 0.1.0
 */
class Test_Experiment extends Abstract_Experiment {
	/**
	 * Loads experiment metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Experiment metadata.
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'test-experiment',
			'label'       => 'Test Experiment',
			'description' => 'A test experiment for unit testing',
		);
	}

	/**
	 * Registers the experiment.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		// No-op for testing.
	}
}

/**
 * Experiment_Registry test case.
 *
 * @since 0.1.0
 */
class Experiment_Registry_Test extends WP_UnitTestCase {
	/**
	 * Experiment registry instance.
	 *
	 * @var \WordPress\AI\Experiment_Registry
	 */
	private $registry;

	/**
	 * Setup test case.
	 *
	 * @since 0.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'ai_experiments_pre_has_valid_credentials_check', '__return_true' );

		$this->registry = new Experiment_Registry();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.1.0
	 */
	public function tearDown(): void {
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'ai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test registering an experiment.
	 *
	 * @since 0.1.0
	 */
	public function test_register_experiment() {
		$experiment = new Test_Experiment();
		$result     = $this->registry->register_experiment( $experiment );

		$this->assertTrue( $result, 'Experiment should register successfully' );
		$this->assertTrue( $this->registry->has_experiment( 'test-experiment' ), 'Experiment should exist in registry' );
	}

	/**
	 * Test registering duplicate experiment fails.
	 *
	 * @since 0.1.0
	 */
	public function test_register_duplicate_experiment_fails() {
		$experiment = new Test_Experiment();
		$this->registry->register_experiment( $experiment );
		$result = $this->registry->register_experiment( $experiment );

		$this->assertFalse( $result, 'Duplicate experiment registration should fail' );
	}

	/**
	 * Test getting a registered experiment.
	 *
	 * @since 0.1.0
	 */
	public function test_get_experiment() {
		$experiment = new Test_Experiment();
		$this->registry->register_experiment( $experiment );
		$retrieved = $this->registry->get_experiment( 'test-experiment' );

		$this->assertSame( $experiment, $retrieved, 'Should retrieve the same experiment instance' );
	}

	/**
	 * Test getting non-existent experiment returns null.
	 *
	 * @since 0.1.0
	 */
	public function test_get_nonexistent_experiment_returns_null() {
		$retrieved = $this->registry->get_experiment( 'nonexistent-experiment' );

		$this->assertNull( $retrieved, 'Non-existent experiment should return null' );
	}

	/**
	 * Test getting all experiments.
	 *
	 * @since 0.1.0
	 */
	public function test_get_all_experiments() {
		$experiment1 = new Test_Experiment();
		$this->registry->register_experiment( $experiment1 );

		$experiments = $this->registry->get_all_experiments();

		$this->assertIsArray( $experiments, 'get_all_experiments should return an array' );
		$this->assertCount( 1, $experiments, 'Should have one experiment' );
		$this->assertArrayHasKey( 'test-experiment', $experiments, 'Experiments array should contain registered experiment' );
		$this->assertSame( $experiment1, $experiments['test-experiment'], 'Should return same instance' );
	}

	/**
	 * Test has_experiment returns true for existing experiment.
	 *
	 * @since 0.1.0
	 */
	public function test_has_experiment_returns_true_for_existing_experiment() {
		$experiment = new Test_Experiment();
		$this->registry->register_experiment( $experiment );

		$this->assertTrue( $this->registry->has_experiment( 'test-experiment' ), 'Should find existing experiment' );
	}

	/**
	 * Test has_experiment returns false for non-existent experiment.
	 *
	 * @since 0.1.0
	 */
	public function test_has_experiment_returns_false_for_nonexistent_experiment() {
		$this->assertFalse( $this->registry->has_experiment( 'nonexistent-experiment' ), 'Should not find non-existent experiment' );
	}

	/**
	 * Test experiment initialization.
	 *
	 * @since 0.1.0
	 */
	public function test_initialize_experiments() {
		$experiment = new Test_Experiment();
		$this->registry->register_experiment( $experiment );

		$loader = new Experiment_Loader( $this->registry );
		$loader->initialize_experiments();

		$this->assertTrue( $loader->is_initialized(), 'Loader should be marked as initialized' );
	}

	/**
	 * Test that disabled experiments are not initialized.
	 *
	 * @since 0.1.0
	 */
	public function test_disabled_experiments_not_initialized() {
		add_filter( 'ai_experiments_experiment_test-experiment_enabled', '__return_false' );

		$experiment = new Test_Experiment();
		$this->registry->register_experiment( $experiment );

		$loader = new Experiment_Loader( $this->registry );
		$loader->initialize_experiments();

		$this->assertFalse( $experiment->is_enabled(), 'Experiment should be disabled' );
	}
}

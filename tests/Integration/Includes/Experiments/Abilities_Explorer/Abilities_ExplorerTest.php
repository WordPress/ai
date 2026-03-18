<?php
/**
 * Integration tests for the Abilities_Explorer experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer
 */

namespace WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer;

use WP_UnitTestCase;
use WordPress\AI\Experiment_Category;
use WordPress\AI\Experiment_Loader;
use WordPress\AI\Experiment_Registry;
use WordPress\AI\Experiments\Abilities_Explorer\Abilities_Explorer;

/**
 * Abilities_Explorer test case.
 *
 * @since 0.2.0
 */
class Abilities_ExplorerTest extends WP_UnitTestCase {
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
	 * Set up test case.
	 *
	 * @since 0.2.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable experiments globally and individually.
		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_abilities-explorer_enabled', true );

		$this->registry = new Experiment_Registry();
		$this->loader   = new Experiment_Loader( $this->registry );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.2.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_abilities-explorer_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment has correct metadata.
	 *
	 * @since 0.2.0
	 */
	public function test_experiment_metadata() {
		$experiment = new Abilities_Explorer();

		$this->assertEquals( 'abilities-explorer', $experiment->get_id() );
		$this->assertEquals( 'Abilities Explorer', $experiment->get_label() );
		$this->assertNotEmpty( $experiment->get_description() );
		$this->assertEquals( Experiment_Category::ADMIN, $experiment->get_category() );
	}

	/**
	 * Test that the experiment is enabled when option is set.
	 *
	 * @since 0.2.0
	 */
	public function test_experiment_is_enabled_when_option_set() {
		$experiment = new Abilities_Explorer();

		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that the experiment is disabled when option is not set.
	 *
	 * @since 0.2.0
	 */
	public function test_experiment_is_disabled_when_option_not_set() {
		delete_option( 'ai_experiment_abilities-explorer_enabled' );

		$experiment = new Abilities_Explorer();

		$this->assertFalse( $experiment->is_enabled() );
	}

	/**
	 * Test that assets are not enqueued on wrong admin page.
	 *
	 * @since 0.2.0
	 */
	public function test_assets_not_enqueued_on_wrong_page() {
		$experiment = new Abilities_Explorer();
		$experiment->register();

		// Simulate being on a different admin page.
		$experiment->enqueue_assets( 'edit.php' );

		$this->assertFalse( wp_script_is( 'ai-abilities_explorer', 'enqueued' ) );
	}

	/**
	 * Test that the experiment can be registered in the registry.
	 *
	 * @since 0.2.0
	 */
	public function test_experiment_registration_in_registry() {
		$experiment = new Abilities_Explorer();
		$this->registry->register_experiment( $experiment );

		$this->assertTrue( $this->registry->has_experiment( 'abilities-explorer' ) );

		$registered = $this->registry->get_experiment( 'abilities-explorer' );
		$this->assertInstanceOf( Abilities_Explorer::class, $registered );
	}
}

<?php
/**
 * Integration tests for the Command_Palette_AI_Search experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Command_Palette_AI_Search;

use WP_Scripts;
use WP_Styles;
use WP_UnitTestCase;
use WordPress\AI\Experiments\Command_Palette_AI_Search\Command_Palette_AI_Search;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * Command_Palette_AI_Search test case.
 */
class Command_Palette_AI_SearchTest extends WP_UnitTestCase {
	/**
	 * Scripts registry from before the test.
	 *
	 * @var \WP_Scripts|null
	 */
	private $old_wp_scripts;

	/**
	 * Styles registry from before the test.
	 *
	 * @var \WP_Styles|null
	 */
	private $old_wp_styles;

	/**
	 * Set up test case.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->old_wp_scripts  = $GLOBALS['wp_scripts'] ?? null;
		$this->old_wp_styles   = $GLOBALS['wp_styles'] ?? null;
		$GLOBALS['wp_scripts'] = new WP_Scripts(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh registries, since other tests leave scripts that depend on wp-commands enqueued.
		$GLOBALS['wp_styles']  = new WP_Styles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh registries, since other tests leave scripts that depend on wp-commands enqueued.

		// Set up mock AI credentials so has_ai_credentials() returns true.
		update_option( 'wp_ai_client_provider_credentials', array( 'openai' => 'test-api-key' ) );

		// Mock has_valid_ai_credentials to return true for tests.
		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		// Enable the experiment.
		update_option( 'wpai_feature_command-palette-ai-search_enabled', true );

		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		$experiment = $registry->get_feature( 'command-palette-ai-search' );
		$this->assertInstanceOf( Command_Palette_AI_Search::class, $experiment, 'Command palette AI search experiment should be registered in the registry.' );
	}

	/**
	 * Tear down test case.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		$GLOBALS['wp_scripts'] = $this->old_wp_scripts; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the registries replaced in setUp().
		$GLOBALS['wp_styles']  = $this->old_wp_styles; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the registries replaced in setUp().
		delete_option( 'wpai_feature_command-palette-ai-search_enabled' );
		delete_option( 'wp_ai_client_provider_credentials' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Test that the experiment is registered correctly.
	 */
	public function test_experiment_registration() {
		$experiment = new Command_Palette_AI_Search();

		$this->assertSame( 'command-palette-ai-search', $experiment->get_id() );
		$this->assertSame( 'Command Palette AI Search', $experiment->get_label() );
		$this->assertSame( Experiment_Category::ADMIN, $experiment->get_category() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Test that register() adds the expected hooks.
	 */
	public function test_register_adds_hooks() {
		$experiment = new Command_Palette_AI_Search();
		$experiment->register();

		$this->assertSame( 10, has_action( 'wp_abilities_api_init', array( $experiment, 'register_abilities' ) ), 'Should register abilities hook' );
		$this->assertSame( 20, has_action( 'admin_enqueue_scripts', array( $experiment, 'enqueue_assets' ) ), 'Should register assets hook after the command palette' );
	}

	/**
	 * Test that the experiment is not enabled when individually disabled.
	 */
	public function test_experiment_not_enabled_when_individually_disabled() {
		update_option( 'wpai_feature_command-palette-ai-search_enabled', false );

		$experiment = new Command_Palette_AI_Search();

		$this->assertFalse( $experiment->is_enabled(), 'Should not be enabled when feature toggle is off' );
	}

	/**
	 * Test that enqueue_assets() does nothing on screens without the command palette.
	 */
	public function test_enqueue_assets_returns_early_without_command_palette() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$experiment = new Command_Palette_AI_Search();
		$experiment->enqueue_assets();

		$this->assertFalse( wp_script_is( 'ai_command_palette_ai_search', 'enqueued' ), 'Should not enqueue script without the command palette' );
		$this->assertFalse( wp_style_is( 'ai_command_palette_ai_search', 'enqueued' ), 'Should not enqueue style without the command palette' );
	}

	/**
	 * Test that enqueue_assets() enqueues the script and style with the command palette.
	 */
	public function test_enqueue_assets_enqueues_with_command_palette() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		wp_enqueue_script( 'wp-commands' );

		$experiment = new Command_Palette_AI_Search();
		$experiment->enqueue_assets();

		$this->assertTrue( wp_script_is( 'ai_command_palette_ai_search', 'enqueued' ), 'Script should be enqueued' );
		$this->assertTrue( wp_style_is( 'ai_command_palette_ai_search', 'enqueued' ), 'Style should be enqueued' );
		$this->assertContains( 'wp-commands', wp_scripts()->registered['ai_command_palette_ai_search']->deps, 'Script should depend on the command palette' );
	}

	/**
	 * Test that enqueue_assets() does nothing for users without edit_posts.
	 */
	public function test_enqueue_assets_returns_early_for_subscriber() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		wp_enqueue_script( 'wp-commands' );

		$experiment = new Command_Palette_AI_Search();
		$experiment->enqueue_assets();

		$this->assertFalse( wp_script_is( 'ai_command_palette_ai_search', 'enqueued' ), 'Should not enqueue script for a subscriber' );
		$this->assertFalse( wp_style_is( 'ai_command_palette_ai_search', 'enqueued' ), 'Should not enqueue style for a subscriber' );
	}
}

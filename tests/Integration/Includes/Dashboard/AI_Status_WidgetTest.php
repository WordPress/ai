<?php
/**
 * Tests for the AI_Status_Widget class.
 *
 * @package WordPress\AI\Tests\Integration\Dashboard
 */

namespace WordPress\AI\Tests\Integration\Dashboard;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Dashboard\AI_Status_Widget;
use WordPress\AI\Experiment_Registry;
use WordPress\AI\Settings\Settings_Registration;

/**
 * Stub experiment A for status widget tests.
 *
 * @since x.x.x
 */
class Status_Test_Experiment_A extends Abstract_Experiment {
	/**
	 * {@inheritDoc}
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'test-exp-a',
			'label'       => 'First Experiment',
			'description' => 'A test experiment',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Stub experiment B for status widget tests.
 *
 * @since x.x.x
 */
class Status_Test_Experiment_B extends Abstract_Experiment {
	/**
	 * {@inheritDoc}
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'test-exp-b',
			'label'       => 'Second Experiment',
			'description' => 'Another test experiment',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * AI_Status_Widget test case.
 *
 * @since x.x.x
 */
class AI_Status_WidgetTest extends WP_UnitTestCase {

	/**
	 * Tear down after each test.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		delete_option( Settings_Registration::GLOBAL_OPTION );
		delete_option( 'ai_experiment_test-exp-a_enabled' );
		delete_option( 'ai_experiment_test-exp-b_enabled' );
		parent::tearDown();
	}

	/**
	 * Tests that getting-started mode is rendered when setup is incomplete.
	 *
	 * Without any credentials or enabled experiments, the widget should
	 * always show the getting-started checklist.
	 *
	 * @since x.x.x
	 */
	public function test_render_getting_started_mode() {
		$registry = new Experiment_Registry();
		$widget   = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'ai-dashboard-status__checklist',
			$output,
			'Should render the getting-started checklist'
		);
	}

	/**
	 * Tests that getting-started mode shows all four checklist steps.
	 *
	 * @since x.x.x
	 */
	public function test_getting_started_has_all_steps() {
		$registry = new Experiment_Registry();
		$widget   = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Configure an AI provider', $output );
		$this->assertStringContainsString( 'Globally enable AI Experiments', $output );
		$this->assertStringContainsString( 'Enable an individual experiment', $output );
		$this->assertStringContainsString( 'Try it out', $output );
	}

	/**
	 * Tests that incomplete steps show error icons.
	 *
	 * @since x.x.x
	 */
	public function test_getting_started_shows_error_icons_for_incomplete_steps() {
		$registry = new Experiment_Registry();
		$widget   = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'dashicons-dismiss',
			$output,
			'Should show error icons for incomplete steps'
		);
	}

	/**
	 * Tests that the global enabled step shows a success icon when enabled.
	 *
	 * @since x.x.x
	 */
	public function test_getting_started_shows_success_for_global_enabled() {
		update_option( Settings_Registration::GLOBAL_OPTION, true );

		$registry = new Experiment_Registry();
		$widget   = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'dashicons-yes-alt',
			$output,
			'Should show success icon for the enabled global toggle step'
		);
	}

	/**
	 * Tests that enabling an experiment shows its step as complete.
	 *
	 * @since x.x.x
	 */
	public function test_getting_started_shows_success_for_enabled_experiment() {
		update_option( Settings_Registration::GLOBAL_OPTION, true );
		update_option( 'ai_experiment_test-exp-a_enabled', true );

		$registry = new Experiment_Registry();
		$registry->register_experiment( new Status_Test_Experiment_A() );

		$widget = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		// Still in getting-started mode (no credentials), but experiment step is green.
		$this->assertStringContainsString( 'ai-dashboard-status__checklist', $output );

		// Count success icons — global enabled + experiment enabled = at least 2.
		$success_count = substr_count( $output, 'dashicons-yes-alt' );
		$this->assertGreaterThanOrEqual(
			2,
			$success_count,
			'Should have at least 2 success icons (global + experiment enabled)'
		);
	}

	/**
	 * Tests that the checklist links to the correct admin pages.
	 *
	 * @since x.x.x
	 */
	public function test_getting_started_links_to_admin_pages() {
		$registry = new Experiment_Registry();
		$widget   = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'options-connectors.php', $output, 'Should link to connectors page' );
		$this->assertStringContainsString( 'page=ai-experiments', $output, 'Should link to experiments settings' );
		$this->assertStringContainsString( 'post-new.php', $output, 'Should link to new post page' );
	}

	/**
	 * Tests that the widget renders without errors when the registry has experiments.
	 *
	 * @since x.x.x
	 */
	public function test_render_with_multiple_experiments_in_registry() {
		$registry = new Experiment_Registry();
		$registry->register_experiment( new Status_Test_Experiment_A() );
		$registry->register_experiment( new Status_Test_Experiment_B() );

		$widget = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'ai-dashboard-status',
			$output,
			'Should render without errors with multiple experiments'
		);
	}

	/**
	 * Tests that the widget renders without errors when the registry is empty.
	 *
	 * @since x.x.x
	 */
	public function test_render_with_empty_registry() {
		$registry = new Experiment_Registry();
		$widget   = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'ai-dashboard-status',
			$output,
			'Should render cleanly with empty registry'
		);
	}
}

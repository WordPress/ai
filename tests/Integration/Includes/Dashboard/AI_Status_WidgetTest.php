<?php
/**
 * Tests for the AI_Status_Widget class.
 *
 * @package WordPress\AI\Tests\Integration\Dashboard
 */

namespace WordPress\AI\Tests\Integration\Dashboard;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Admin\Dashboard\AI_Status_Widget;
use WordPress\AI\Features\Registry;
use WordPress\AI\Settings\Settings_Registration;

/**
 * Stub feature A for status widget tests.
 *
 * @since 0.8.0
 */
class Status_Test_Feature_A extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'test-feature-a';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'First Feature',
			'description' => 'A test feature',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Stub feature B for status widget tests.
 *
 * @since 0.8.0
 */
class Status_Test_Feature_B extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'test-feature-b';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Second Feature',
			'description' => 'Another test feature',
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
 * @since 0.8.0
 */
class AI_Status_WidgetTest extends WP_UnitTestCase {

	/**
	 * Tear down after each test.
	 *
	 * @since 0.8.0
	 */
	public function tearDown(): void {
		delete_option( Settings_Registration::GLOBAL_OPTION );
		delete_option( 'wpai_feature_test-feature-a_enabled' );
		delete_option( 'wpai_feature_test-feature-b_enabled' );
		remove_all_filters( 'wpai_feature_test-feature-a_enabled' );
		parent::tearDown();
	}

	/**
	 * Tests that getting-started mode is rendered when setup is incomplete.
	 *
	 * Without any credentials or enabled features, the widget should
	 * always show the getting-started checklist.
	 *
	 * @since 0.8.0
	 */
	public function test_render_getting_started_mode() {
		$registry = new Registry();
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
	 * @since 0.8.0
	 */
	public function test_getting_started_has_all_steps() {
		$registry = new Registry();
		$widget   = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Configure an AI provider', $output );
		$this->assertStringContainsString( 'Globally enable AI Features', $output );
		$this->assertStringContainsString( 'Enable a feature or experiment', $output );
	}

	/**
	 * Tests that incomplete steps show error icons.
	 *
	 * @since 0.8.0
	 */
	public function test_getting_started_shows_error_icons_for_incomplete_steps() {
		$registry = new Registry();
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
	 * @since 0.8.0
	 */
	public function test_getting_started_shows_success_for_global_enabled() {
		update_option( Settings_Registration::GLOBAL_OPTION, true );

		$registry = new Registry();
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
	 * Tests that enabling an feature shows its step as complete.
	 *
	 * @since 0.8.0
	 */
	public function test_getting_started_shows_success_for_enabled_feature() {
		update_option( Settings_Registration::GLOBAL_OPTION, true );
		update_option( 'wpai_feature_test-feature-a_enabled', true );

		$registry = new Registry();
		$registry->register_feature( new Status_Test_Feature_A() );

		$widget = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		// Still in getting-started mode (no credentials), but feature step is green.
		$this->assertStringContainsString( 'ai-dashboard-status__checklist', $output );

		// Count success icons — global enabled + feature enabled = at least 2.
		$success_count = substr_count( $output, 'dashicons-yes-alt' );
		$this->assertGreaterThanOrEqual(
			2,
			$success_count,
			'Should have at least 2 success icons (global + feature enabled)'
		);
	}

	/**
	 * Tests that the feature step uses the individual feature setting.
	 *
	 * @since 1.0.1
	 */
	public function test_getting_started_shows_success_for_enabled_feature_when_global_ai_is_disabled() {
		update_option( Settings_Registration::GLOBAL_OPTION, false );
		update_option( 'wpai_feature_test-feature-a_enabled', true );

		$registry = new Registry();
		$registry->register_feature( new Status_Test_Feature_A() );

		$widget = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ai-dashboard-status__checklist', $output );

		$this->assertMatchesRegularExpression(
			'/dashicons-yes-alt.*Enable a feature or experiment/s',
			$output,
			'Should show the feature step as complete when its individual setting is enabled'
		);
	}

	/**
	 * Tests that the feature step respects the individual feature enabled filter.
	 *
	 * @since 1.0.1
	 */
	public function test_getting_started_shows_success_for_filtered_enabled_feature() {
		update_option( Settings_Registration::GLOBAL_OPTION, false );
		update_option( 'wpai_feature_test-feature-a_enabled', false );
		add_filter( 'wpai_feature_test-feature-a_enabled', '__return_true' );

		$registry = new Registry();
		$registry->register_feature( new Status_Test_Feature_A() );

		$widget = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ai-dashboard-status__checklist', $output );

		$this->assertMatchesRegularExpression(
			'/dashicons-yes-alt.*Enable a feature or experiment/s',
			$output,
			'Should show the feature step as complete when the individual feature filter enables it'
		);
	}

	/**
	 * Tests that the checklist links to the correct admin pages.
	 *
	 * @since 0.8.0
	 */
	public function test_getting_started_links_to_admin_pages() {
		$registry = new Registry();
		$widget   = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'options-connectors.php', $output, 'Should link to connectors page' );
		$this->assertStringContainsString( 'page=ai-wp-admin', $output, 'Should link to features settings' );
	}

	/**
	 * Tests that the widget renders without errors when the registry has features.
	 *
	 * @since 0.8.0
	 */
	public function test_render_with_multiple_features_in_registry() {
		$registry = new Registry();
		$registry->register_feature( new Status_Test_Feature_A() );
		$registry->register_feature( new Status_Test_Feature_B() );

		$widget = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'ai-dashboard-status',
			$output,
			'Should render without errors with multiple features'
		);
	}

	/**
	 * Tests that the widget renders without errors when the registry is empty.
	 *
	 * @since 0.8.0
	 */
	public function test_render_with_empty_registry() {
		$registry = new Registry();
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

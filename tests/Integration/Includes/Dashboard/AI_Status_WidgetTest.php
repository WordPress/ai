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
 * Additional stub features for threshold tests.
 *
 * @since 1.4.0
 */
class Status_Test_Feature_C extends Status_Test_Feature_A {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'test-feature-c';
	}
}

/**
 * Additional stub features for threshold tests.
 *
 * @since 1.4.0
 */
class Status_Test_Feature_D extends Status_Test_Feature_A {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'test-feature-d';
	}
}

/**
 * Additional stub features for threshold tests.
 *
 * @since 1.4.0
 */
class Status_Test_Feature_E extends Status_Test_Feature_A {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'test-feature-e';
	}
}

/**
 * Additional stub features for threshold tests.
 *
 * @since 1.4.0
 */
class Status_Test_Feature_F extends Status_Test_Feature_A {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'test-feature-f';
	}
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
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_test-feature-a_enabled' );
		delete_option( 'wpai_feature_test-feature-b_enabled' );
		delete_option( 'wpai_feature_test-feature-c_enabled' );
		delete_option( 'wpai_feature_test-feature-d_enabled' );
		delete_option( 'wpai_feature_test-feature-e_enabled' );
		delete_option( 'wpai_feature_test-feature-f_enabled' );
		remove_all_filters( 'wpai_feature_test-feature-a_enabled' );
		remove_all_filters( 'wpai_has_ai_credentials' );
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
	 * Tests that getting-started mode shows both checklist steps and no
	 * longer includes the retired global toggle step.
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
		$this->assertStringContainsString( 'Enable a feature or experiment', $output );
		$this->assertStringNotContainsString( 'Globally enable AI Features', $output );
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
	 * Tests that enabling an feature shows its step as complete.
	 *
	 * @since 0.8.0
	 */
	public function test_getting_started_shows_success_for_enabled_feature() {
		update_option( 'wpai_feature_test-feature-a_enabled', true );

		$registry = new Registry();
		$registry->register_feature( new Status_Test_Feature_A() );

		$widget = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		// Still in getting-started mode (no credentials), but feature step is green.
		$this->assertStringContainsString( 'ai-dashboard-status__checklist', $output );

		$this->assertMatchesRegularExpression(
			'/dashicons-yes-alt[^<]*"><\/span>\s*<a [^>]*>\s*Enable a feature or experiment/',
			$output,
			'Should show the feature step as complete when its individual setting is enabled'
		);
	}

	/**
	 * Tests that the checklist stays visible when credentials exist but no
	 * feature is enabled.
	 *
	 * @since 1.4.0
	 */
	public function test_getting_started_when_credentials_but_no_enabled_feature() {
		add_filter( 'wpai_has_ai_credentials', '__return_true' );

		$registry = new Registry();
		$registry->register_feature( new Status_Test_Feature_A() );

		$widget = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ai-dashboard-status__checklist', $output );
		$this->assertStringNotContainsString( 'ai-dashboard-status__columns', $output );
		$this->assertMatchesRegularExpression(
			'/dashicons-yes-alt[^<]*"><\/span>\s*<a [^>]*>\s*Configure an AI provider/',
			$output,
			'Should show the provider step as complete'
		);
	}

	/**
	 * Tests that the status view renders once credentials exist and a
	 * feature is enabled, even if the legacy global option is false.
	 *
	 * @since 1.4.0
	 */
	public function test_status_view_ignores_legacy_global_option() {
		add_filter( 'wpai_has_ai_credentials', '__return_true' );
		update_option( 'wpai_features_enabled', false );
		update_option( 'wpai_feature_test-feature-a_enabled', true );

		$registry = new Registry();
		$registry->register_feature( new Status_Test_Feature_A() );

		$widget = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ai-dashboard-status__columns', $output );
		$this->assertStringNotContainsString( 'ai-dashboard-status__checklist', $output );
	}

	/**
	 * Tests that the feature step respects the individual feature enabled filter.
	 *
	 * @since 1.0.1
	 */
	public function test_getting_started_shows_success_for_filtered_enabled_feature() {
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
			'/dashicons-yes-alt[^<]*"><\/span>\s*<a [^>]*>\s*Enable a feature or experiment/',
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
	 * Renders the widget in full status mode.
	 *
	 * Enables credentials (via filter) and the individual setting for
	 * feature A, leaving feature B disabled.
	 *
	 * @since 1.0.2
	 *
	 * @return string The rendered widget output.
	 */
	private function render_status_view(): string {
		return $this->render_status_view_with_features(
			array( new Status_Test_Feature_A(), new Status_Test_Feature_B() ),
			array( 'test-feature-a' )
		);
	}

	/**
	 * Renders status mode with a configurable set of test experiments.
	 *
	 * @param array<\WordPress\AI\Contracts\Feature> $features       Test experiments to register.
	 * @param string[]                                      $enabled_ids IDs of enabled test experiments.
	 * @return string The rendered widget output.
	 */
	private function render_status_view_with_features( array $features, array $enabled_ids ): string {
		add_filter( 'wpai_has_ai_credentials', '__return_true' );

		$registry = new Registry();
		foreach ( $features as $feature ) {
			update_option( 'wpai_feature_' . $feature::get_id() . '_enabled', in_array( $feature::get_id(), $enabled_ids, true ) );
			$registry->register_feature( $feature );
		}

		$widget = new AI_Status_Widget( $registry );

		ob_start();
		$widget->render();

		return ob_get_clean();
	}

	/**
	 * Extracts the Experiments column from rendered widget markup.
	 *
	 * @param string $output Rendered widget markup.
	 * @return string Experiments column markup.
	 */
	private function get_experiments_section( string $output ): string {
		return substr( $output, (int) strpos( $output, 'Experiments' ) );
	}

	/**
	 * Tests that five enabled experiments are still listed individually.
	 *
	 * @since 1.4.0
	 */
	public function test_status_view_lists_five_enabled_experiments_individually() {
		$output              = $this->render_status_view_with_features(
			array(
				new Status_Test_Feature_A(),
				new Status_Test_Feature_B(),
				new Status_Test_Feature_C(),
				new Status_Test_Feature_D(),
				new Status_Test_Feature_E(),
			),
			array(
				'test-feature-a',
				'test-feature-b',
				'test-feature-c',
				'test-feature-d',
				'test-feature-e',
			)
		);
		$experiments_section = $this->get_experiments_section( $output );

		$this->assertSame( 5, substr_count( $experiments_section, 'dashicons-yes-alt' ) );
		$this->assertStringNotContainsString( '5 experiments enabled', $experiments_section );
	}

	/**
	 * Tests that more than five enabled experiments are summarized.
	 *
	 * @since 1.4.0
	 */
	public function test_status_view_summarizes_more_than_five_enabled_experiments() {
		$output              = $this->render_status_view_with_features(
			array(
				new Status_Test_Feature_A(),
				new Status_Test_Feature_B(),
				new Status_Test_Feature_C(),
				new Status_Test_Feature_D(),
				new Status_Test_Feature_E(),
				new Status_Test_Feature_F(),
			),
			array(
				'test-feature-a',
				'test-feature-b',
				'test-feature-c',
				'test-feature-d',
				'test-feature-e',
				'test-feature-f',
			)
		);
		$experiments_section = $this->get_experiments_section( $output );

		$this->assertStringContainsString( '6 experiments enabled', $experiments_section );
		$this->assertSame( 1, substr_count( $experiments_section, 'dashicons-yes-alt' ) );
	}

	/**
	 * Tests that multiple disabled experiments use the plural summary.
	 *
	 * @since 1.4.0
	 */
	public function test_status_view_summarizes_multiple_disabled_experiments() {
		$output              = $this->render_status_view_with_features(
			array(
				new Status_Test_Feature_A(),
				new Status_Test_Feature_B(),
				new Status_Test_Feature_C(),
			),
			array( 'test-feature-a' )
		);
		$experiments_section = $this->get_experiments_section( $output );

		$this->assertStringContainsString( '2 experiments not enabled', $experiments_section );
	}

	/**
	 * Tests that the status view renders the three-column layout.
	 *
	 * @since 1.0.2
	 */
	public function test_status_view_renders_columns() {
		$output = $this->render_status_view();

		$this->assertStringContainsString(
			'ai-dashboard-status__columns',
			$output,
			'Should render the status view when setup is complete'
		);
	}

	/**
	 * Tests that an enabled experiment shows a success icon.
	 *
	 * @since 1.0.2
	 */
	public function test_status_view_shows_success_icon_for_enabled_experiment() {
		$output = $this->render_status_view();

		$this->assertMatchesRegularExpression(
			'/dashicons-yes-alt.*First Feature/s',
			$output,
			'Enabled experiments should show a success icon'
		);
	}

	/**
	 * Tests that a disabled experiment shows a neutral icon, not an error icon.
	 *
	 * Disabled experiments are an expected state, not a problem, so they
	 * should not be rendered with the red error cross.
	 *
	 * @since 1.0.2
	 */
	public function test_status_view_shows_neutral_icon_for_disabled_experiment() {
		$output = $this->render_status_view();

		$this->assertMatchesRegularExpression(
			'/ai-dashboard-status__icon--neutral.*1 experiment not enabled/s',
			$output,
			'Disabled experiments should show a neutral icon'
		);

		// The Experiments column is rendered last, so everything after the
		// section title belongs to it. Disabled experiments must not use
		// the error icon there.
		$experiments_section = substr( $output, (int) strpos( $output, 'Experiments' ) );
		$this->assertStringNotContainsString(
			'ai-dashboard-status__icon--error',
			$experiments_section,
			'Disabled experiments should not show the error icon'
		);
		$this->assertStringNotContainsString(
			'dashicons-no',
			$experiments_section,
			'Disabled experiments should not use the dashicons-no icon'
		);
	}

	/**
	 * Tests that feature state is exposed to screen readers in the status view.
	 *
	 * @since 1.0.2
	 */
	public function test_status_view_exposes_state_to_screen_readers() {
		$output = $this->render_status_view();

		$this->assertMatchesRegularExpression(
			'/screen-reader-text">[^<]*Enabled:/s',
			$output,
			'Enabled state should be announced to screen readers'
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

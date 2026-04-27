<?php
/**
 * Tests for the AI_Capabilities_Widget class.
 *
 * @package WordPress\AI\Tests\Integration\Dashboard
 */

namespace WordPress\AI\Tests\Integration\Dashboard;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Admin\Dashboard\AI_Capabilities_Widget;
use WordPress\AI\Features\Registry;

/**
 * Stub feature for capabilities widget tests.
 *
 * @since 0.8.0
 */
class Capabilities_Test_Feature extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'abilities-explorer';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Abilities Explorer',
			'description' => 'Test abilities explorer',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * AI_Capabilities_Widget test case.
 *
 * @since 0.8.0
 */
class AI_Capabilities_WidgetTest extends WP_UnitTestCase {

	/**
	 * Tear down after each test.
	 *
	 * @since 0.8.0
	 */
	public function tearDown(): void {
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_abilities-explorer_enabled' );
		parent::tearDown();
	}

	/**
	 * Tests that the widget renders the wrapper div.
	 *
	 * @since 0.8.0
	 */
	public function test_render_outputs_wrapper() {
		$registry = new Registry();
		$widget   = new AI_Capabilities_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'ai-dashboard-capabilities',
			$output,
			'Should render the capabilities wrapper div'
		);
	}

	/**
	 * Tests that abilities summary renders stat cards when abilities API is available.
	 *
	 * @since 0.8.0
	 */
	public function test_render_abilities_summary_stat_cards() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$registry = new Registry();
		$widget   = new AI_Capabilities_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'ai-dashboard-capabilities__stat-card',
			$output,
			'Should render stat cards'
		);
		$this->assertStringContainsString( 'Total Abilities', $output );
		$this->assertStringContainsString( 'Core', $output );
		$this->assertStringContainsString( 'Plugins', $output );
		$this->assertStringContainsString( 'Theme', $output );
	}

	/**
	 * Tests that Abilities Explorer link shows when feature is enabled.
	 *
	 * @since 0.8.0
	 */
	public function test_abilities_explorer_link_shown_when_enabled() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_abilities-explorer_enabled', true );

		$registry = new Registry();
		$registry->register_feature( new Capabilities_Test_Feature() );

		$widget = new AI_Capabilities_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'ai-abilities-explorer',
			$output,
			'Should link to Abilities Explorer'
		);
	}

	/**
	 * Tests that Abilities Explorer link is hidden when feature is disabled.
	 *
	 * @since 0.8.0
	 */
	public function test_abilities_explorer_link_hidden_when_disabled() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$registry = new Registry();
		$registry->register_feature( new Capabilities_Test_Feature() );

		$widget = new AI_Capabilities_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString(
			'ai-abilities-explorer',
			$output,
			'Should not link to Abilities Explorer when disabled'
		);
	}

	/**
	 * Tests that provider capabilities section renders when AiClient is available.
	 *
	 * @since 0.8.0
	 */
	public function test_render_provider_capabilities() {
		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient not available.' );
		}

		$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
		$provider_ids = $registry->getRegisteredProviderIds();

		if ( empty( $provider_ids ) ) {
			$this->markTestSkipped( 'No AI providers registered.' );
		}

		$exp_registry = new Registry();
		$widget       = new AI_Capabilities_Widget( $exp_registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'Provider Capabilities',
			$output,
			'Should render the Provider Capabilities heading'
		);
		$this->assertStringContainsString(
			'ai-dashboard-capabilities__cap-tag',
			$output,
			'Should render capability tags'
		);
	}

	/**
	 * Tests that capability labels are human-readable.
	 *
	 * @since 0.8.0
	 */
	public function test_capability_labels_are_human_readable() {
		if ( ! class_exists( \WordPress\AiClient\AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient not available.' );
		}

		$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
		$provider_ids = $registry->getRegisteredProviderIds();

		if ( empty( $provider_ids ) ) {
			$this->markTestSkipped( 'No AI providers registered.' );
		}

		$registry = new Registry();
		$widget   = new AI_Capabilities_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		// Should contain human-readable labels, not raw enum values.
		$this->assertStringNotContainsString(
			'text_generation',
			$output,
			'Should not show raw enum values'
		);
	}
}

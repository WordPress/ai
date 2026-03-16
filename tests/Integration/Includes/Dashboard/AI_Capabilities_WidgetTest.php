<?php
/**
 * Tests for the AI_Capabilities_Widget class.
 *
 * @package WordPress\AI\Tests\Integration\Dashboard
 */

namespace WordPress\AI\Tests\Integration\Dashboard;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Experiment;
use WordPress\AI\Dashboard\AI_Capabilities_Widget;
use WordPress\AI\Experiment_Registry;

/**
 * Stub experiment for capabilities widget tests.
 *
 * @since x.x.x
 */
class Capabilities_Test_Experiment extends Abstract_Experiment {
	/**
	 * {@inheritDoc}
	 */
	protected function load_experiment_metadata(): array {
		return array(
			'id'          => 'abilities-explorer',
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
 * @since x.x.x
 */
class AI_Capabilities_WidgetTest extends WP_UnitTestCase {

	/**
	 * Tear down after each test.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_abilities-explorer_enabled' );
		parent::tearDown();
	}

	/**
	 * Tests that the widget renders the wrapper div.
	 *
	 * @since x.x.x
	 */
	public function test_render_outputs_wrapper() {
		$registry = new Experiment_Registry();
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
	 * @since x.x.x
	 */
	public function test_render_abilities_summary_stat_cards() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$registry = new Experiment_Registry();
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
	 * Tests that Abilities Explorer link shows when experiment is enabled.
	 *
	 * @since x.x.x
	 */
	public function test_abilities_explorer_link_shown_when_enabled() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_abilities-explorer_enabled', true );

		$registry = new Experiment_Registry();
		$registry->register_experiment( new Capabilities_Test_Experiment() );

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
	 * Tests that Abilities Explorer link is hidden when experiment is disabled.
	 *
	 * @since x.x.x
	 */
	public function test_abilities_explorer_link_hidden_when_disabled() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$registry = new Experiment_Registry();
		$registry->register_experiment( new Capabilities_Test_Experiment() );

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
	 * @since x.x.x
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

		$exp_registry = new Experiment_Registry();
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
	 * @since x.x.x
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

		$exp_registry = new Experiment_Registry();
		$widget       = new AI_Capabilities_Widget( $exp_registry );

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

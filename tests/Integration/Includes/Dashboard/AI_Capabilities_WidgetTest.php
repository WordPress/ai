<?php
/**
 * Tests for the AI_Capabilities_Widget class.
 *
 * @package WordPress\AI\Tests\Integration\Dashboard
 */

namespace WordPress\AI\Tests\Integration\Dashboard;

use BadMethodCallException;
use ReflectionProperty;
use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Admin\Dashboard\AI_Capabilities_Widget;
use WordPress\AI\Features\Registry;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;

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
 * Stub provider with unavailable model metadata for capabilities widget tests.
 *
 * @since n.e.x.t
 */
final class Capabilities_Unavailable_Test_Provider {

	/**
	 * Returns stub provider metadata.
	 *
	 * @since n.e.x.t
	 */
	public static function metadata(): ProviderMetadata {
		return new ProviderMetadata(
			'test-provider',
			'Test Provider',
			ProviderTypeEnum::cloud(),
			'https://example.com'
		);
	}

	/**
	 * Simulates a provider whose model metadata is unavailable.
	 *
	 * @since n.e.x.t
	 *
	 * @throws \BadMethodCallException Always thrown for this stub.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client provider API.
	public static function modelMetadataDirectory(): void {
		throw new BadMethodCallException( 'Model metadata unavailable.' );
	}
}

/**
 * AI_Capabilities_Widget test case.
 *
 * @since 0.8.0
 */
class AI_Capabilities_WidgetTest extends WP_UnitTestCase {

	/**
	 * Stub provider ID.
	 *
	 * @since n.e.x.t
	 *
	 * @var string
	 */
	private const TEST_PROVIDER_ID = 'wpai_capabilities_test_provider';

	/**
	 * Original AI client provider registry map.
	 *
	 * @since n.e.x.t
	 *
	 * @var array<string, string>|null
	 */
	private ?array $original_registered_providers = null;

	/**
	 * Tear down after each test.
	 *
	 * @since 0.8.0
	 */
	public function tearDown(): void {
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_abilities-explorer_enabled' );
		$this->restore_registered_providers();
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
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient not available.' );
		}

		$registry     = AiClient::defaultRegistry();
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
	 * Tests that provider capabilities section is hidden when no provider row can render.
	 *
	 * @since n.e.x.t
	 */
	public function test_provider_capabilities_section_hidden_when_no_provider_rows_render() {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient not available.' );
		}

		$this->replace_registered_providers(
			array(
				self::TEST_PROVIDER_ID => Capabilities_Unavailable_Test_Provider::class,
			)
		);

		$registry = new Registry();
		$widget   = new AI_Capabilities_Widget( $registry );

		ob_start();
		$widget->render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString(
			'Provider Capabilities',
			$output,
			'Should not render an empty Provider Capabilities section.'
		);
		$this->assertStringNotContainsString(
			'ai-dashboard-capabilities__providers',
			$output,
			'Should not render an empty providers container.'
		);
	}

	/**
	 * Tests that capability labels are human-readable.
	 *
	 * @since 0.8.0
	 */
	public function test_capability_labels_are_human_readable() {
		if ( ! class_exists( AiClient::class ) ) {
			$this->markTestSkipped( 'AiClient not available.' );
		}

		$registry     = AiClient::defaultRegistry();
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

	/**
	 * Replaces registered providers in the AI client registry for a test.
	 *
	 * @since n.e.x.t
	 *
	 * @param array<string, string> $providers Registered provider IDs to class names.
	 */
	private function replace_registered_providers( array $providers ): void {
		$property = $this->get_registered_providers_property();

		if ( null === $this->original_registered_providers ) {
			$this->original_registered_providers = (array) $property->getValue( AiClient::defaultRegistry() );
		}

		$property->setValue( AiClient::defaultRegistry(), $providers );
	}

	/**
	 * Restores registered providers in the AI client registry.
	 *
	 * @since n.e.x.t
	 */
	private function restore_registered_providers(): void {
		if ( null === $this->original_registered_providers || ! class_exists( AiClient::class ) ) {
			return;
		}

		$this->get_registered_providers_property()->setValue(
			AiClient::defaultRegistry(),
			$this->original_registered_providers
		);
		$this->original_registered_providers = null;
	}

	/**
	 * Returns the registered providers registry property.
	 *
	 * @since n.e.x.t
	 */
	private function get_registered_providers_property(): ReflectionProperty {
		$property = new ReflectionProperty( AiClient::defaultRegistry(), 'registeredIdsToClassNames' );
		$property->setAccessible( true );
		return $property;
	}
}

<?php
/**
 * Extended Providers experiment tests.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\Extended_Providers
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Extended_Providers;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Extended_Providers\Extended_Providers;
use WordPress\AI\Settings\Settings_Registration;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\ProviderImplementations\OpenAi\OpenAiProvider;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;

/**
 * Dummy provider used to verify registration wiring.
 */
class DummyProvider extends OpenAiProvider {
	/**
	 * {@inheritDoc}
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			'dummy-provider',
			'Dummy Provider',
			ProviderTypeEnum::cloud()
		);
	}
}

/**
 * @group experiments
 */
class Extended_ProvidersTest extends WP_UnitTestCase {
	/**
	 * {@inheritDoc}
	 */
	public function setUp(): void {
		parent::setUp();
		add_filter( 'ai_pre_has_valid_credentials_check', '__return_true' );
		update_option( Settings_Registration::GLOBAL_OPTION, true );
		update_option( 'ai_experiment_extended-providers_enabled', true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		remove_filter( 'ai_pre_has_valid_credentials_check', '__return_true' );
		update_option( 'ai_experiment_extended-providers_enabled', false );
		parent::tearDown();
	}

	public function test_registers_custom_provider_classes(): void {
		add_filter(
			'ai_extended_provider_classes',
			static function ( $providers ) {
				$providers[] = DummyProvider::class;
				return $providers;
			}
		);

		$experiment = new Extended_Providers();
		$experiment->register_providers();

		$registry = AiClient::defaultRegistry();
		$this->assertTrue(
			$registry->hasProvider( DummyProvider::class ),
			'Dummy provider should be registered when experiment is enabled.'
		);

		remove_all_filters( 'ai_extended_provider_classes' );
	}
}

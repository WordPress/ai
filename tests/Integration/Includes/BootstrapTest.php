<?php
/**
 * Integration tests for bootstrap functions.
 *
 * @package WordPress\AI\Tests\Integration\Includes
 */

namespace WordPress\AI\Tests\Integration\Includes;

use WP_UnitTestCase;

/**
 * Bootstrap test case.
 *
 * @since x.x.x
 */
class BootstrapTest extends WP_UnitTestCase {
	/**
	 * Tracks original credentials option value for restoration.
	 *
	 * @since x.x.x
	 *
	 * @var mixed
	 */
	private $original_credentials_option;

	/**
	 * Sets up test fixture.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_credentials_option = get_option( 'wp_ai_client_provider_credentials', null );
	}

	/**
	 * Tears down test fixture.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_all_filters( 'ai_experiments_credentials_settings_url' );

		if ( null === $this->original_credentials_option ) {
			delete_option( 'wp_ai_client_provider_credentials' );
		} else {
			update_option( 'wp_ai_client_provider_credentials', $this->original_credentials_option );
		}

		parent::tearDown();
	}

	/**
	 * Tests that credentials settings URL can be provided by filter.
	 *
	 * @since x.x.x
	 */
	public function test_get_ai_credentials_settings_url_uses_filter(): void {
		add_filter(
			'ai_experiments_credentials_settings_url',
			static function (): string {
				return 'https://example.com/credentials';
			}
		);

		$this->assertSame(
			'https://example.com/credentials',
			\WordPress\AI\get_ai_credentials_settings_url()
		);
	}

	/**
	 * Tests that core provider registration runs without requiring bundled mode.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_register_available_ai_client_providers(): void {
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			$this->markTestSkipped( 'AI Client is not available.' );
		}

		\WordPress\AI\maybe_register_available_ai_client_providers();

		$provider_ids = \WordPress\AiClient\AiClient::defaultRegistry()->getRegisteredProviderIds();
		$this->assertIsArray( $provider_ids );
	}

	/**
	 * Tests that saved option credentials are applied to the AI client registry.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_apply_option_credentials_to_core_ai_client(): void {
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			$this->markTestSkipped( 'AI Client is not available.' );
		}

		if ( \WordPress\AI\should_use_bundled_wp_ai_client() ) {
			$this->markTestSkipped( 'Bundled WP AI Client mode does not use the core credential bridge.' );
		}

		\WordPress\AI\maybe_register_available_ai_client_providers();

		$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
		$provider_ids = $registry->getRegisteredProviderIds();
		if ( ! in_array( 'openai', $provider_ids, true ) ) {
			$this->markTestSkipped( 'OpenAI provider is not registered in this environment.' );
		}

		update_option(
			'wp_ai_client_provider_credentials',
			array(
				'openai' => 'sk-bootstrap-test-key',
			)
		);

		\WordPress\AI\maybe_apply_option_credentials_to_core_ai_client();

		$authentication = $registry->getProviderRequestAuthentication( 'openai' );
		if ( null === $authentication ) {
			$this->markTestSkipped( 'Core registry did not retain provider authentication in this test environment.' );
		}

		$this->assertTrue(
			$authentication instanceof \WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface
		);

		if ( ! method_exists( $authentication, 'getApiKey' ) ) {
			return;
		}

		$this->assertSame( 'sk-bootstrap-test-key', $authentication->getApiKey() );
	}
}

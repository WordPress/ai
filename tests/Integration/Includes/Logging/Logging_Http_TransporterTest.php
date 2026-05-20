<?php
/**
 * Integration tests for the request-source attribution in Logging_Http_Transporter.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use ReflectionMethod;
use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\Logging_Http_Transporter;

/**
 * Logging_Http_Transporter source attribution test case.
 *
 * @since 1.0.0
 *
 * @covers \WordPress\AI\Logging\Logging_Http_Transporter
 */
class Logging_Http_TransporterTest extends WP_UnitTestCase {

	/**
	 * Transporter instance under test, with no upstream HTTP transporter.
	 *
	 * @var \WordPress\AI\Logging\Logging_Http_Transporter
	 */
	private Logging_Http_Transporter $transporter;

	/**
	 * Set up test case.
	 */
	protected function setUp(): void {
		parent::setUp();

		$upstream = $this->createMock( \WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface::class );
		$manager  = new AI_Request_Log_Manager();

		$this->transporter = new Logging_Http_Transporter( $upstream, $manager );
	}

	/**
	 * Invokes the private is_infrastructure_file() helper via reflection.
	 *
	 * @param string $file File path to check.
	 * @return bool
	 */
	private function call_is_infrastructure_file( string $file ): bool {
		$method = new ReflectionMethod( $this->transporter, 'is_infrastructure_file' );
		$method->setAccessible( true );
		return (bool) $method->invoke( $this->transporter, $file );
	}

	/**
	 * Tests that frames inside the logging directory are skipped.
	 *
	 * @since 1.0.0
	 */
	public function test_skips_logging_dir(): void {
		$file = wp_normalize_path( WP_PLUGIN_DIR . '/ai/includes/Logging/Logging_Http_Transporter.php' );

		$this->assertTrue( $this->call_is_infrastructure_file( $file ) );
	}

	/**
	 * Tests that any /vendor/ frame is skipped.
	 *
	 * @since 1.0.0
	 */
	public function test_skips_vendor(): void {
		$file = wp_normalize_path( WP_PLUGIN_DIR . '/some-plugin/vendor/whatever/Library.php' );

		$this->assertTrue( $this->call_is_infrastructure_file( $file ) );
	}

	/**
	 * Tests that frames inside the AI Client SDK shipped with core are skipped.
	 *
	 * @since 1.0.0
	 */
	public function test_skips_php_ai_client_sdk(): void {
		$file = wp_normalize_path( ABSPATH . 'wp-includes/php-ai-client/src/Providers/Anthropic.php' );

		$this->assertTrue( $this->call_is_infrastructure_file( $file ) );
	}

	/**
	 * Tests that frames inside core's WP_AI_Client_* wrapper are skipped.
	 *
	 * @since 1.0.0
	 */
	public function test_skips_core_ai_client_wrapper(): void {
		$file = wp_normalize_path( ABSPATH . 'wp-includes/ai-client/class-wp-ai-client-prompt-builder.php' );

		$this->assertTrue( $this->call_is_infrastructure_file( $file ) );
	}

	/**
	 * Tests that frames inside a registered AI provider plugin are skipped.
	 *
	 * Without this skip, an AI request initiated by the AI plugin would be
	 * attributed to the provider plugin since its class sits between the
	 * caller and the transporter on the call stack.
	 *
	 * @since 1.0.0
	 */
	public function test_skips_registered_ai_provider_plugin(): void {
		$registered_slug = $this->first_ai_provider_plugin_slug();

		if ( null === $registered_slug ) {
			$this->markTestSkipped( 'No AI provider connectors registered in this environment.' );
		}

		$file = wp_normalize_path( WP_PLUGIN_DIR . '/' . $registered_slug . '/src/Provider/Anything.php' );

		$this->assertTrue( $this->call_is_infrastructure_file( $file ) );
	}

	/**
	 * Tests that frames in the AI plugin (the originator) are not skipped.
	 *
	 * @since 1.0.0
	 */
	public function test_does_not_skip_ai_plugin_originator(): void {
		$file = wp_normalize_path( WP_PLUGIN_DIR . '/ai/includes/Abilities/Title_Generation/Title_Generation.php' );

		$this->assertFalse( $this->call_is_infrastructure_file( $file ) );
	}

	/**
	 * Tests that frames in arbitrary plugins are not skipped.
	 *
	 * @since 1.0.0
	 */
	public function test_does_not_skip_arbitrary_plugin(): void {
		$file = wp_normalize_path( WP_PLUGIN_DIR . '/my-custom-plugin/src/Caller.php' );

		$this->assertFalse( $this->call_is_infrastructure_file( $file ) );
	}

	/**
	 * Returns the slug of the first registered AI provider connector whose
	 * plugin directory can be resolved, or null if none is available.
	 */
	private function first_ai_provider_plugin_slug(): ?string {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return null;
		}

		$resolver = new ReflectionMethod( $this->transporter, 'resolve_connector_plugin_slug' );
		$resolver->setAccessible( true );

		foreach ( wp_get_connectors() as $connector_data ) {
			if ( ! is_array( $connector_data ) || 'ai_provider' !== ( $connector_data['type'] ?? '' ) ) {
				continue;
			}

			$slug = (string) $resolver->invoke( $this->transporter, $connector_data );
			if ( '' !== $slug ) {
				return $slug;
			}
		}

		return null;
	}
}

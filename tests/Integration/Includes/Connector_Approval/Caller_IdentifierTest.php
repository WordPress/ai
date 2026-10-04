<?php
/**
 * Integration tests for the Caller_Identifier class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Connector_Approval
 */

namespace WordPress\AI\Tests\Integration\Includes\Connector_Approval;

use ReflectionMethod;
use WP_UnitTestCase;
use WordPress\AI\Connector_Approval\Caller_Identifier;

/**
 * Caller_Identifier test case.
 *
 * @since 1.0.1
 */
class Caller_IdentifierTest extends WP_UnitTestCase {
	/**
	 * Resolves a synthetic stack with the private Caller_Identifier resolver.
	 *
	 * @since 1.0.1
	 *
	 * @param array<int, array<string, mixed>> $frames         Synthetic stack frames.
	 * @param list<string>                     $infrastructure Extension keys to treat as infrastructure.
	 * @return array{type: string, basename: string, name: string}|null
	 */
	private function resolve_frames( array $frames, array $infrastructure = array() ): ?array {
		$identifier = new Caller_Identifier();
		$resolve    = new ReflectionMethod( Caller_Identifier::class, 'resolve' );
		$resolve->setAccessible( true );

		$result = $resolve->invoke( $identifier, $frames, $infrastructure );

		return is_array( $result ) ? $result : null;
	}

	/**
	 * Test that request logging frames are treated as infrastructure.
	 *
	 * @since 1.0.1
	 */
	public function test_skips_request_logging_frames_when_identifying_origin(): void {
		$result = $this->resolve_frames(
			array(
				array(
					'file' => WP_PLUGIN_DIR . '/ai/includes/Logging/Logging_Http_Transporter.php',
					'line' => 85,
				),
				array(
					'file' => ABSPATH . 'wp-includes/http.php',
					'line' => 612,
				),
				array(
					'file' => ABSPATH . 'wp-admin/options-connectors.php',
					'line' => 42,
				),
			)
		);

		$this->assertNull( $result );
	}

	/**
	 * Test that the deepest extension frame is treated as the origin.
	 *
	 * @since 1.0.1
	 */
	public function test_identifies_deepest_extension_frame_as_origin(): void {
		$result = $this->resolve_frames(
			array(
				array(
					'file' => WP_PLUGIN_DIR . '/ai/includes/Logging/Logging_Http_Transporter.php',
					'line' => 85,
				),
				array(
					'file' => WP_PLUGIN_DIR . '/ai/includes/Experiments/Title_Generation/Title_Generation.php',
					'line' => 262,
				),
				array(
					'file' => ABSPATH . 'wp-includes/class-wp-hook.php',
					'line' => 324,
				),
				array(
					'file' => WP_PLUGIN_DIR . '/consumer-plugin/includes/request-ai.php',
					'line' => 38,
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( Caller_Identifier::TYPE_PLUGIN, $result['type'] );
		$this->assertSame( 'consumer-plugin', $result['basename'] );
	}

	/**
	 * Test that another plugin's matching internal directory is not skipped.
	 *
	 * @since 1.0.1
	 */
	public function test_does_not_skip_matching_directory_names_in_other_plugins(): void {
		$result = $this->resolve_frames(
			array(
				array(
					'file' => WP_PLUGIN_DIR . '/another-plugin/includes/Settings/Options.php',
					'line' => 21,
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( Caller_Identifier::TYPE_PLUGIN, $result['type'] );
		$this->assertSame( 'another-plugin', $result['basename'] );
	}

	/**
	 * Test that core validating a connector key is not attributed to the
	 * connector's own provider plugin.
	 *
	 * @since x.x.x
	 */
	public function test_skips_infrastructure_plugin_frames_when_core_validates_a_key(): void {
		$result = $this->resolve_frames(
			array(
				array(
					'file' => WP_PLUGIN_DIR . '/ai-provider-for-test/src/Metadata/ModelMetadataDirectory.php',
					'line' => 69,
				),
				array(
					'file' => ABSPATH . 'wp-includes/php-ai-client/src/Providers/ProviderRegistry.php',
					'line' => 191,
				),
				array(
					'file' => ABSPATH . 'wp-includes/connectors.php',
					'line' => 613,
				),
			),
			array( 'plugin:ai-provider-for-test' )
		);

		$this->assertNull( $result );
	}

	/**
	 * Test that a plugin calling through the provider is still identified.
	 *
	 * @since x.x.x
	 */
	public function test_identifies_consumer_calling_through_an_infrastructure_plugin(): void {
		$result = $this->resolve_frames(
			array(
				array(
					'file' => WP_PLUGIN_DIR . '/ai-provider-for-test/src/Models/TextGenerationModel.php',
					'line' => 42,
				),
				array(
					'file' => WP_PLUGIN_DIR . '/consumer-plugin/includes/request-ai.php',
					'line' => 38,
				),
			),
			array( 'plugin:ai-provider-for-test' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'consumer-plugin', $result['basename'] );
	}

	/**
	 * Test that Gutenberg's polyfill of core's connectors.php is treated as core.
	 *
	 * @since x.x.x
	 */
	public function test_skips_gutenberg_connectors_polyfill(): void {
		$result = $this->resolve_frames(
			array(
				array(
					'file' => WP_PLUGIN_DIR . '/gutenberg/lib/compat/wordpress-7.0/default-connectors.php',
					'line' => 411,
				),
			)
		);

		$this->assertNull( $result );
	}

	/**
	 * Test that an infrastructure mu-plugin is skipped when core validates a key.
	 *
	 * @since x.x.x
	 */
	public function test_skips_infrastructure_mu_plugin_frames() {
		$result = $this->resolve_frames(
			array(
				array(
					'file' => WPMU_PLUGIN_DIR . '/acme-provider.php',
					'line' => 27,
				),
				array(
					'file' => ABSPATH . 'wp-includes/connectors.php',
					'line' => 613,
				),
			),
			array( 'mu-plugin:acme-provider.php' )
		);

		$this->assertNull( $result );
	}

	/**
	 * Test that a plugin key doesn't exempt a same-named mu-plugin.
	 *
	 * @since x.x.x
	 */
	public function test_plugin_key_does_not_exempt_mu_plugin() {
		$result = $this->resolve_frames(
			array(
				array(
					'file' => WPMU_PLUGIN_DIR . '/acme-provider/acme-provider.php',
					'line' => 27,
				),
			),
			array( 'plugin:acme-provider' )
		);

		$this->assertIsArray( $result );
		$this->assertSame( Caller_Identifier::TYPE_MU_PLUGIN, $result['type'] );
	}

	/**
	 * Test that extension keys use the plugin's directory slug, not its basename.
	 *
	 * @since x.x.x
	 */
	public function test_extension_key_uses_plugin_directory_slug() {
		$this->assertSame(
			'plugin:ai-provider-for-test',
			Caller_Identifier::extension_key(
				array(
					'type'     => Caller_Identifier::TYPE_PLUGIN,
					'basename' => 'ai-provider-for-test/plugin.php',
					'name'     => 'AI Provider for Test',
				)
			)
		);
		$this->assertSame(
			'theme:acme-theme',
			Caller_Identifier::extension_key(
				array(
					'type'     => Caller_Identifier::TYPE_THEME,
					'basename' => 'acme-theme',
					'name'     => 'Acme',
				)
			)
		);
	}
}

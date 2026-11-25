<?php
/**
 * Integration tests for the AI Workspace function-calling gate.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\AI_Workspace;

use ReflectionProperty;
use WP_Connector_Registry;
use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Function_Calling_Support;
use WordPress\AiClient\AiClient;

/**
 * Stub provider that counts model-list calls.
 *
 * @since x.x.x
 */
final class Function_Calling_Test_Provider {

	/**
	 * Number of model-list calls.
	 *
	 * @since x.x.x
	 *
	 * @var int
	 */
	public static int $list_calls = 0;

	/**
	 * Returns a stub model metadata directory with one function-calling model.
	 *
	 * @since x.x.x
	 *
	 * @return object Stub directory.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client provider API.
	public static function modelMetadataDirectory(): object {
		return new class() {
			// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client API.
			public function listModelMetadata(): array {
				++Function_Calling_Test_Provider::$list_calls;

				$name = new class() {
					// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client API.
					public function isFunctionDeclarations(): bool {
						return true;
					}
				};

				$option = new class( $name ) {
					private object $name;

					public function __construct( object $name ) {
						$this->name = $name;
					}

					// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client API.
					public function getName(): object {
						return $this->name;
					}
				};

				$model = new class( $option ) {
					private object $option;

					public function __construct( object $option ) {
						$this->option = $option;
					}

					// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Matches the AI client API.
					public function getSupportedOptions(): array {
						return array( $this->option );
					}
				};

				return array( $model );
			}
		};
	}
}

/**
 * Function_Calling_Support test case.
 *
 * @since x.x.x
 *
 * @covers \WordPress\AI\Experiments\AI_Workspace\Function_Calling_Support
 */
class Function_Calling_SupportTest extends WP_UnitTestCase {

	private const PROVIDER_ID = 'wpai_function_calling_test_provider';

	private const SETTING_NAME = 'connectors_ai_provider_wpai_function_calling_test_provider_api_key';

	/**
	 * Registers the stub provider and resets the call counter.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( AiClient::class ) || null === WP_Connector_Registry::get_instance() ) {
			$this->markTestSkipped( 'AiClient or the connector registry is not available.' );
		}

		Function_Calling_Test_Provider::$list_calls = 0;
		$this->set_registry_entry( true );
	}

	/**
	 * Removes the stub provider, connector and key.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		if ( class_exists( AiClient::class ) ) {
			$this->set_registry_entry( false );
		}

		$registry = WP_Connector_Registry::get_instance();
		if ( null !== $registry && $registry->is_registered( self::PROVIDER_ID ) ) {
			$registry->unregister( self::PROVIDER_ID );
		}

		delete_option( self::SETTING_NAME );

		parent::tearDown();
	}

	/**
	 * An unauthenticated connector reports no support and its model list is never read.
	 *
	 * @since x.x.x
	 */
	public function test_unauthenticated_connector_reports_no_support_without_listing_models(): void {
		$this->register_connector();

		$this->assertFalse( Function_Calling_Support::is_available() );
		$this->assertSame( 0, Function_Calling_Test_Provider::$list_calls );
	}

	/**
	 * An authenticated connector with a function-calling model reports support.
	 *
	 * @since x.x.x
	 */
	public function test_authenticated_connector_with_function_calling_model_reports_support(): void {
		$this->register_connector();
		update_option( self::SETTING_NAME, 'test-api-key' );

		$this->assertTrue( Function_Calling_Support::is_available() );
		$this->assertSame( 1, Function_Calling_Test_Provider::$list_calls );
	}

	/**
	 * The filter can override the detected support.
	 *
	 * @since x.x.x
	 */
	public function test_filter_overrides_detected_support(): void {
		add_filter( 'wpai_has_function_calling_support', '__return_true' );

		$this->assertTrue( Function_Calling_Support::is_available() );
	}

	/**
	 * Registers the stub connector with API key authentication.
	 *
	 * @since x.x.x
	 */
	private function register_connector(): void {
		WP_Connector_Registry::get_instance()->register(
			self::PROVIDER_ID,
			array(
				'name'           => 'Function Calling Test Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'api_key',
				),
			)
		);
	}

	/**
	 * Adds or removes the stub provider in the AI client registry.
	 *
	 * @since x.x.x
	 *
	 * @param bool $add True to add, false to remove.
	 */
	private function set_registry_entry( bool $add ): void {
		$registry = AiClient::defaultRegistry();
		$maps     = array(
			'registeredIdsToClassNames' => array( self::PROVIDER_ID, Function_Calling_Test_Provider::class ),
			'registeredClassNamesToIds' => array( Function_Calling_Test_Provider::class, self::PROVIDER_ID ),
		);

		foreach ( $maps as $property_name => [ $key, $value ] ) {
			$property = new ReflectionProperty( $registry, $property_name );
			$property->setAccessible( true );
			$map = (array) $property->getValue( $registry );

			if ( $add ) {
				$map[ $key ] = $value;
			} else {
				unset( $map[ $key ] );
			}

			$property->setValue( $registry, $map );
		}
	}
}

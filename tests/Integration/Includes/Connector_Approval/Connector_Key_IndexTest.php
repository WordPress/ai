<?php
/**
 * Integration tests for the Connector_Key_Index class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Connector_Approval
 */

namespace WordPress\AI\Tests\Integration\Includes\Connector_Approval;

use BadMethodCallException;
use ReflectionProperty;
use WP_Connector_Registry;
use WP_UnitTestCase;
use WordPress\AI\Connector_Approval\Connector_Key_Index;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Stub API-based provider used to exercise URL-fallback attribution.
 *
 * Only `baseUrl()` is ever called by `Connector_Key_Index`; the remaining
 * abstract methods are satisfied with throwing stubs so the class is
 * instantiable but those code paths fail loudly if reached.
 *
 * @since 1.0.0
 */
// phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
final class Stub_Api_Provider extends AbstractApiProvider {
	/**
	 * Base URL returned by the stub provider.
	 *
	 * Settable per test so multiple URL scenarios can share the same class.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public static string $stub_base_url = 'https://stub.example/v1';

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function baseUrl(): string {
		return self::$stub_base_url;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		throw new BadMethodCallException( 'Stub_Api_Provider::createModel() should not be called in these tests.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		throw new BadMethodCallException( 'Stub_Api_Provider::createProviderMetadata() should not be called in these tests.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		throw new BadMethodCallException( 'Stub_Api_Provider::createProviderAvailability() should not be called in these tests.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		throw new BadMethodCallException( 'Stub_Api_Provider::createModelMetadataDirectory() should not be called in these tests.' );
	}
}

/**
 * Connector_Key_Index test case.
 *
 * Exercises the two-stage attribution: credential scan first, URL fallback
 * second. Credential matching is covered end-to-end in `Http_GuardTest`, so
 * this suite focuses on the URL fallback that keyless providers (e.g. Ollama)
 * depend on. A stub provider is wired directly into the AiClient registry via
 * reflection, since `Connector_Key_Index` only consults `hasProvider()` and
 * `getProviderClassName()` during index construction.
 *
 * @since 1.0.0
 */
class Connector_Key_IndexTest extends WP_UnitTestCase {
	/**
	 * Test connector ID used by URL-fallback tests.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const TEST_CONNECTOR_ID = 'wpai_url_fallback_provider';

	/**
	 * Index under test.
	 *
	 * @since 1.0.0
	 *
	 * @var \WordPress\AI\Connector_Approval\Connector_Key_Index
	 */
	private Connector_Key_Index $index;

	/**
	 * Set up test case.
	 *
	 * @since 1.0.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->index = new Connector_Key_Index();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.0.0
	 */
	public function tearDown(): void {
		$this->unregister_stub_provider();
		$this->unregister_stub_connector();

		Stub_Api_Provider::$stub_base_url = 'https://stub.example/v1';

		parent::tearDown();
	}

	/**
	 * Test that an empty URL with no headers returns null.
	 *
	 * @since 1.0.0
	 */
	public function test_returns_null_for_empty_inputs() {
		$this->assertNull( $this->index->lookup( array(), '' ) );
	}

	/**
	 * Test that a request to an unrelated host is not attributed to any connector.
	 *
	 * @since 1.0.0
	 */
	public function test_returns_null_for_unrelated_host() {
		$this->assertNull(
			$this->index->lookup( array(), 'https://wordpress.org/news/feed/' )
		);
	}

	/**
	 * Test that a keyless request to a registered provider's base URL is
	 * attributed via the URL-fallback path.
	 *
	 * @since 1.0.0
	 */
	public function test_url_fallback_attributes_keyless_request() {
		Stub_Api_Provider::$stub_base_url = 'https://stub-ai.example/v1';
		$this->register_stub_connector();
		$this->register_stub_provider();

		$this->assertSame(
			self::TEST_CONNECTOR_ID,
			$this->index->lookup( array(), 'https://stub-ai.example/v1/chat/completions' )
		);
	}

	/**
	 * Test that a non-standard port is honored so Ollama-style local providers
	 * don't false-match against other local services.
	 *
	 * @since 1.0.0
	 */
	public function test_url_fallback_matches_host_with_port() {
		Stub_Api_Provider::$stub_base_url = 'http://localhost:11434';
		$this->register_stub_connector();
		$this->register_stub_provider();

		$this->assertSame(
			self::TEST_CONNECTOR_ID,
			$this->index->lookup( array(), 'http://localhost:11434/api/generate' )
		);

		$this->assertNull(
			$this->index->lookup( array(), 'http://localhost:8080/api/generate' ),
			'A different port on the same host must not match.'
		);
	}

	/**
	 * Test that a lookalike domain does not false-positive via URL fallback.
	 *
	 * @since 1.0.0
	 */
	public function test_url_fallback_rejects_lookalike_host() {
		Stub_Api_Provider::$stub_base_url = 'https://api.stub.example/v1';
		$this->register_stub_connector();
		$this->register_stub_provider();

		$this->assertNull(
			$this->index->lookup( array(), 'https://api.stub.example.evil.test/v1/chat' )
		);
	}

	/**
	 * Registers the stub connector in the WP connector registry with no
	 * authentication metadata, so only the URL-fallback path can attribute it.
	 *
	 * @since 1.0.0
	 */
	private function register_stub_connector(): void {
		$registry = WP_Connector_Registry::get_instance();
		if ( null === $registry || $registry->is_registered( self::TEST_CONNECTOR_ID ) ) {
			return;
		}

		$registry->register(
			self::TEST_CONNECTOR_ID,
			array(
				'name'           => 'Stub URL-fallback Provider',
				'description'    => 'Test provider used to exercise URL-based attribution.',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'none',
				),
			)
		);
	}

	/**
	 * Removes the stub connector from the WP connector registry.
	 *
	 * @since 1.0.0
	 */
	private function unregister_stub_connector(): void {
		$registry = WP_Connector_Registry::get_instance();
		if ( null === $registry || ! $registry->is_registered( self::TEST_CONNECTOR_ID ) ) {
			return;
		}

		$registry->unregister( self::TEST_CONNECTOR_ID );
	}

	/**
	 * Directly injects the stub provider into the AiClient registry's
	 * `registeredIdsToClassNames` map.
	 *
	 * Going through `registerProvider()` would pull in HTTP transporter and
	 * authentication scaffolding that these tests don't exercise, so we
	 * manipulate the map directly via reflection.
	 *
	 * @since 1.0.0
	 */
	private function register_stub_provider(): void {
		$registry = AiClient::defaultRegistry();
		$property = new ReflectionProperty( $registry, 'registeredIdsToClassNames' );
		$property->setAccessible( true );

		$map                            = (array) $property->getValue( $registry );
		$map[ self::TEST_CONNECTOR_ID ] = Stub_Api_Provider::class;
		$property->setValue( $registry, $map );
	}

	/**
	 * Removes the stub provider from the AiClient registry.
	 *
	 * @since 1.0.0
	 */
	private function unregister_stub_provider(): void {
		$registry = AiClient::defaultRegistry();
		$property = new ReflectionProperty( $registry, 'registeredIdsToClassNames' );
		$property->setAccessible( true );

		$map = (array) $property->getValue( $registry );
		unset( $map[ self::TEST_CONNECTOR_ID ] );
		$property->setValue( $registry, $map );
	}
}

<?php
/**
 * Integration tests for the Http_Guard class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Connector_Approval
 */

namespace WordPress\AI\Tests\Integration\Includes\Connector_Approval;

use ReflectionMethod;
use ReflectionProperty;
use WP_Connector_Registry;
use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Connector_Approval\Approvals_Store;
use WordPress\AI\Connector_Approval\Caller_Identifier;
use WordPress\AI\Connector_Approval\Connector_Key_Index;
use WordPress\AI\Connector_Approval\Http_Guard;
use WordPress\AiClient\AiClient;

/**
 * Http_Guard test case.
 *
 * Exercises the enforcement decision tree end-to-end with real collaborators.
 * The test file itself lives under the `ai` plugin, so the real
 * `Caller_Identifier` naturally resolves the calling plugin as `ai/...` for
 * the approved/unapproved cases.
 *
 * @since 1.0.0
 */
class Http_GuardTest extends WP_UnitTestCase {
	/**
	 * Test connector ID registered during setUp.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const TEST_CONNECTOR_ID = 'wpai_test_provider';

	/**
	 * Setting name holding the test connector's credential.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const TEST_SETTING = 'wpai_test_provider_key';

	/**
	 * Credential long enough to clear the index's minimum-length filter.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const TEST_CREDENTIAL = 'test-credential-value-1234567890';

	/**
	 * Approvals store used by each test.
	 *
	 * @since 1.0.0
	 *
	 * @var \WordPress\AI\Connector_Approval\Approvals_Store
	 */
	private Approvals_Store $store;

	/**
	 * Caller identifier used by each test.
	 *
	 * @since 1.0.0
	 *
	 * @var \WordPress\AI\Connector_Approval\Caller_Identifier
	 */
	private Caller_Identifier $identifier;

	/**
	 * Key index used by each test.
	 *
	 * @since 1.0.0
	 *
	 * @var \WordPress\AI\Connector_Approval\Connector_Key_Index
	 */
	private Connector_Key_Index $key_index;

	/**
	 * Set up test case.
	 *
	 * @since 1.0.0
	 */
	public function setUp(): void {
		parent::setUp();

		$registry = WP_Connector_Registry::get_instance();
		if ( null !== $registry && ! $registry->is_registered( self::TEST_CONNECTOR_ID ) ) {
			$registry->register(
				self::TEST_CONNECTOR_ID,
				array(
					'name'           => 'Test Provider',
					'description'    => 'Test provider for Http_Guard tests.',
					'type'           => 'ai_provider',
					'authentication' => array(
						'method'       => 'api_key',
						'setting_name' => self::TEST_SETTING,
					),
				)
			);
		}

		update_option( self::TEST_SETTING, self::TEST_CREDENTIAL );

		$this->store      = new Approvals_Store();
		$this->identifier = new Caller_Identifier();
		$this->key_index  = new Connector_Key_Index();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.0.0
	 */
	public function tearDown(): void {
		$registry = WP_Connector_Registry::get_instance();
		if ( null !== $registry && $registry->is_registered( self::TEST_CONNECTOR_ID ) ) {
			$registry->unregister( self::TEST_CONNECTOR_ID );
		}

		$this->set_test_provider_class( null );

		delete_option( self::TEST_SETTING );
		delete_option( Approvals_Store::OPTION_APPROVALS );
		delete_option( Approvals_Store::OPTION_PENDING );

		parent::tearDown();
	}

	/**
	 * Returns a guard wired with the current collaborators.
	 *
	 * @since 1.0.0
	 *
	 * @return \WordPress\AI\Connector_Approval\Http_Guard
	 */
	private function guard(): Http_Guard {
		return new Http_Guard( $this->identifier, $this->store, $this->key_index );
	}

	/**
	 * Returns the `ai` plugin basename as resolved by the real identifier.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function ai_plugin_basename(): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array_keys( get_plugins() ) as $basename ) {
			if ( str_starts_with( (string) $basename, 'ai/' ) ) {
				return (string) $basename;
			}
		}

		return 'ai';
	}

	/**
	 * Adds the tests directory to the identifier's skip list so the
	 * caller-identification step returns null for this test file.
	 *
	 * @since 1.0.0
	 */
	private function force_unidentifiable_caller(): void {
		$property = new ReflectionProperty( Caller_Identifier::class, 'skip_prefixes' );
		$property->setAccessible( true );

		$current   = (array) $property->getValue( $this->identifier );
		$current[] = wp_normalize_path( WP_PLUGIN_DIR . '/ai/' );
		$property->setValue( $this->identifier, $current );
	}

	/**
	 * Points the test connector ID at a class in the AI Client registry.
	 *
	 * Writes the registry's ID map directly: registerProvider() would demand
	 * full provider metadata, and only the class's file matters here.
	 *
	 * @since x.x.x
	 *
	 * @param string|null $class_name Class to register, or null to remove the entry.
	 */
	private function set_test_provider_class( ?string $class_name ): void {
		$registry = AiClient::defaultRegistry();
		$property = new ReflectionProperty( $registry, 'registeredIdsToClassNames' );
		$property->setAccessible( true );

		$map = (array) $property->getValue( $registry );
		if ( null === $class_name ) {
			unset( $map[ self::TEST_CONNECTOR_ID ] );
		} else {
			$map[ self::TEST_CONNECTOR_ID ] = $class_name;
		}
		$property->setValue( $registry, $map );
	}

	/**
	 * Re-registers the test connector with a declared owning plugin file.
	 *
	 * @since x.x.x
	 *
	 * @param string $plugin_file Plugin basename, e.g. `my-plugin/my-plugin.php`.
	 */
	private function declare_test_connector_plugin( string $plugin_file ): void {
		$registry = WP_Connector_Registry::get_instance();
		if ( null === $registry ) {
			$this->markTestSkipped( 'The connector registry is not available.' );
		}

		$connector = $registry->unregister( self::TEST_CONNECTOR_ID );
		$this->assertIsArray( $connector );

		$connector['plugin']['file'] = $plugin_file;
		$registry->register( self::TEST_CONNECTOR_ID, $connector );
	}

	/**
	 * Calls one of the guard's private methods.
	 *
	 * @since x.x.x
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments to pass.
	 * @return mixed The method's return value.
	 */
	private function invoke_guard( string $method, ...$args ) {
		$reflection = new ReflectionMethod( Http_Guard::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( $this->guard(), ...$args );
	}

	/**
	 * Test that a non-false preempt value is returned unchanged.
	 *
	 * @since 1.0.0
	 */
	public function test_returns_preempt_when_already_short_circuited() {
		$existing = array( 'response' => array( 'code' => 200 ) );

		$this->assertSame(
			$existing,
			$this->guard()->maybe_block_request( $existing, array(), 'https://example.com' )
		);
	}

	/**
	 * Test that requests without a matched credential are passed through.
	 *
	 * @since 1.0.0
	 */
	public function test_passes_through_when_no_connector_credential_matches() {
		$this->assertFalse(
			$this->guard()->maybe_block_request( false, array(), 'https://example.com/nothing-here' )
		);
	}

	/**
	 * Test that requests without an identifiable caller are allowed through.
	 *
	 * @since 1.0.0
	 */
	public function test_passes_through_when_caller_cannot_be_identified() {
		$this->force_unidentifiable_caller();

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . self::TEST_CREDENTIAL,
			),
		);

		$this->assertFalse(
			$this->guard()->maybe_block_request( false, $args, 'https://api.example.com/v1/chat' )
		);
		$this->assertSame( array(), $this->store->get_pending() );
	}

	/**
	 * Test that an approved caller is allowed through without creating a pending entry.
	 *
	 * @since 1.0.0
	 */
	public function test_allows_approved_caller_without_recording_pending() {
		$basename = $this->ai_plugin_basename();
		$this->store->set_approval( $basename, self::TEST_CONNECTOR_ID, true );

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . self::TEST_CREDENTIAL,
			),
		);

		$this->assertFalse(
			$this->guard()->maybe_block_request( false, $args, 'https://api.example.com/v1/chat' )
		);
		$this->assertSame( array(), $this->store->get_pending() );
	}

	/**
	 * Test that an unapproved caller is blocked and a pending entry is recorded.
	 *
	 * @since 1.0.0
	 */
	public function test_blocks_unapproved_caller_and_records_pending() {
		$basename = $this->ai_plugin_basename();

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . self::TEST_CREDENTIAL,
			),
		);

		$result = $this->guard()->maybe_block_request( false, $args, 'https://api.example.com/v1/chat' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wpai_connector_not_approved', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status'] );
		$this->assertSame( self::TEST_CONNECTOR_ID, $data['connector_id'] );
		$this->assertSame( $basename, $data['caller']['basename'] );

		$pending = $this->store->get_pending();
		$this->assertArrayHasKey(
			$this->store->pending_key( $basename, self::TEST_CONNECTOR_ID ),
			$pending
		);
	}

	/**
	 * Test that a connector's declared plugin is exempted instead of wherever
	 * its provider class happened to be loaded from.
	 *
	 * @since x.x.x
	 */
	public function test_exempts_declared_plugin_over_provider_class_location() {
		// The class file lives in the `ai` plugin, standing in for a copy of the
		// provider package bundled by some other plugin.
		$this->set_test_provider_class( self::class );
		$this->declare_test_connector_plugin( 'ai-provider-for-test/plugin.php' );

		$this->assertSame(
			array( 'plugin:ai-provider-for-test' ),
			$this->invoke_guard( 'provider_extension_keys', self::TEST_CONNECTOR_ID )
		);
	}

	/**
	 * Test that the provider class's plugin is exempted when no plugin is declared.
	 *
	 * @since x.x.x
	 */
	public function test_exempts_provider_class_plugin_when_none_declared() {
		$this->set_test_provider_class( self::class );

		$this->assertSame(
			array( 'plugin:ai' ),
			$this->invoke_guard( 'provider_extension_keys', self::TEST_CONNECTOR_ID )
		);
	}

	/**
	 * Test that nothing is exempted for a connector without a registered provider.
	 *
	 * @since x.x.x
	 */
	public function test_exempts_nothing_without_a_registered_provider() {
		$this->assertSame(
			array(),
			$this->invoke_guard( 'provider_extension_keys', self::TEST_CONNECTOR_ID )
		);
	}

	/**
	 * Test that a provider class bundled in a plugin's vendor directory
	 * exempts nothing, so the bundling plugin can't bypass approval.
	 *
	 * @since x.x.x
	 */
	public function test_exempts_nothing_for_provider_class_in_vendor_directory() {
		$this->assertSame(
			array(),
			$this->invoke_guard(
				'provider_keys_for_file',
				WP_PLUGIN_DIR . '/seo-plugin/vendor/acme/ai-provider/src/Provider.php'
			)
		);
	}

	/**
	 * Test that a provider class in an mu-plugin exempts that mu-plugin.
	 *
	 * @since x.x.x
	 */
	public function test_exempts_mu_plugin_provider() {
		$this->assertSame(
			array( 'mu-plugin:acme-provider.php' ),
			$this->invoke_guard( 'provider_keys_for_file', WPMU_PLUGIN_DIR . '/acme-provider.php' )
		);
	}

	/**
	 * Test that a provider class in a theme exempts that theme.
	 *
	 * @since x.x.x
	 */
	public function test_exempts_theme_provider() {
		$this->assertSame(
			array( 'theme:acme-theme' ),
			$this->invoke_guard(
				'provider_keys_for_file',
				get_theme_root() . '/acme-theme/inc/class-acme-provider.php'
			)
		);
	}
}

<?php
/**
 * Integration tests for the Http_Guard class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Connector_Approval
 */

namespace WordPress\AI\Tests\Integration\Includes\Connector_Approval;

use ReflectionProperty;
use WP_Connector_Registry;
use WP_Error;
use WP_UnitTestCase;
use WordPress\AI\Connector_Approval\Approvals_Store;
use WordPress\AI\Connector_Approval\Caller_Identifier;
use WordPress\AI\Connector_Approval\Connector_Key_Index;
use WordPress\AI\Connector_Approval\Http_Guard;

/**
 * Http_Guard test case.
 *
 * Exercises the enforcement decision tree end-to-end with real collaborators.
 * The test file itself lives under the `ai` plugin, so the real
 * `Caller_Identifier` naturally resolves the calling plugin as `ai/...` for
 * the approved/unapproved cases.
 *
 * @since x.x.x
 */
class Http_GuardTest extends WP_UnitTestCase {
	/**
	 * Test connector ID registered during setUp.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const TEST_CONNECTOR_ID = 'wpai_test_provider';

	/**
	 * Setting name holding the test connector's credential.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const TEST_SETTING = 'wpai_test_provider_key';

	/**
	 * Credential long enough to clear the index's minimum-length filter.
	 *
	 * @since x.x.x
	 *
	 * @var string
	 */
	private const TEST_CREDENTIAL = 'test-credential-value-1234567890';

	/**
	 * Approvals store used by each test.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Connector_Approval\Approvals_Store
	 */
	private Approvals_Store $store;

	/**
	 * Caller identifier used by each test.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Connector_Approval\Caller_Identifier
	 */
	private Caller_Identifier $identifier;

	/**
	 * Key index used by each test.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Connector_Approval\Connector_Key_Index
	 */
	private Connector_Key_Index $key_index;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	public function tearDown(): void {
		$registry = WP_Connector_Registry::get_instance();
		if ( null !== $registry && $registry->is_registered( self::TEST_CONNECTOR_ID ) ) {
			$registry->unregister( self::TEST_CONNECTOR_ID );
		}

		delete_option( self::TEST_SETTING );
		delete_option( Approvals_Store::OPTION_APPROVALS );
		delete_option( Approvals_Store::OPTION_PENDING );

		parent::tearDown();
	}

	/**
	 * Returns a guard wired with the current collaborators.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Connector_Approval\Http_Guard
	 */
	private function guard(): Http_Guard {
		return new Http_Guard( $this->identifier, $this->store, $this->key_index );
	}

	/**
	 * Returns the `ai` plugin basename as resolved by the real identifier.
	 *
	 * @since x.x.x
	 *
	 * @return string
	 */
	private function ai_plugin_basename(): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( array_keys( get_plugins() ) as $basename ) {
			if ( 0 === strpos( (string) $basename, 'ai/' ) ) {
				return (string) $basename;
			}
		}

		return 'ai';
	}

	/**
	 * Adds the tests directory to the identifier's skip list so the
	 * caller-identification step returns null for this test file.
	 *
	 * @since x.x.x
	 */
	private function force_unidentifiable_caller(): void {
		$property = new ReflectionProperty( Caller_Identifier::class, 'skip_substrings' );
		$property->setAccessible( true );

		$current   = (array) $property->getValue( $this->identifier );
		$current[] = wp_normalize_path( WP_PLUGIN_DIR . '/ai/' );
		$property->setValue( $this->identifier, $current );
	}

	/**
	 * Test that a non-false preempt value is returned unchanged.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	public function test_passes_through_when_no_connector_credential_matches() {
		$this->assertFalse(
			$this->guard()->maybe_block_request( false, array(), 'https://example.com/nothing-here' )
		);
	}

	/**
	 * Test that requests without an identifiable caller are allowed through.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
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
}

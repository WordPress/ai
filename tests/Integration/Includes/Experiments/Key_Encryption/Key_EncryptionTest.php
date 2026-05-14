<?php
/**
 * Integration tests for the Key_Encryption experiment.
 *
 * Defines global stand-ins for the displace-secrets-manager API (`set_secret`, `get_secret`,
 * `delete_secret`) so the experiment can be exercised in environments where the secrets manager
 * plugin is not installed. The real Secrets_Bridge invokes these globals via `function_exists`
 * and will transparently pick them up.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace {
	if ( ! function_exists( 'set_secret' ) ) {
		/**
		 * In-memory stand-in for displace-secrets-manager.
		 *
		 * @param string $key   Secret key.
		 * @param string $value Plaintext value.
		 */
		function set_secret( string $key, string $value ): bool {
			$GLOBALS['wpai_test_secret_store'][ $key ] = $value;
			return true;
		}

		/**
		 * In-memory stand-in for displace-secrets-manager.
		 *
		 * @param string $key Secret key.
		 */
		function get_secret( string $key ): ?string {
			return $GLOBALS['wpai_test_secret_store'][ $key ] ?? null;
		}

		/**
		 * In-memory stand-in for displace-secrets-manager.
		 *
		 * @param string $key Secret key.
		 */
		function delete_secret( string $key ): bool {
			if ( ! isset( $GLOBALS['wpai_test_secret_store'][ $key ] ) ) {
				return false;
			}
			unset( $GLOBALS['wpai_test_secret_store'][ $key ] );
			return true;
		}
	}
}

namespace WordPress\AI\Tests\Integration\Experiments\Key_Encryption {

	use WP_UnitTestCase;
	use WordPress\AI\Admin\Deactivation;
	use WordPress\AI\Experiments\Key_Encryption\Key_Encryption;

	/**
	 * Test case for the Key_Encryption experiment.
	 *
	 * @since x.x.x
	 */
	class Key_EncryptionTest extends WP_UnitTestCase {

		private const CONNECTOR_ID = 'testprovider';
		private const SETTING_NAME = 'connectors_ai_testprovider_api_key';
		private const SECRET_KEY   = 'ai/testprovider_api_key';
		private const TOGGLE       = 'wpai_feature_key-encryption_enabled';

		/**
		 * @var Key_Encryption
		 */
		private Key_Encryption $experiment;

		/**
		 * @since x.x.x
		 */
		public function setUp(): void {
			parent::setUp();

			$GLOBALS['wpai_test_secret_store'] = array();

			$this->register_test_connector();

			// Defang the WP 7.0 connector sanitize/mask filters so we can write/read raw values.
			remove_all_filters( 'sanitize_option_' . self::SETTING_NAME );
			remove_filter( 'option_' . self::SETTING_NAME, '_wp_connectors_mask_api_key' );

			delete_option( self::SETTING_NAME );
			delete_option( self::TOGGLE );

			update_option( 'wpai_features_enabled', true );

			// The plugin's normal boot flow has already instantiated the experiment and wired its
			// toggle hooks via Settings_Registration. We just need a reference for read-bypass
			// helpers; the singleton bridge accessor returns the same bridge those hooks use.
			$this->experiment = new Key_Encryption();
			$this->experiment->register_settings();
		}

		/**
		 * @since x.x.x
		 */
		public function tearDown(): void {
			remove_all_actions( "update_option_{$this->toggle()}" );
			remove_all_actions( "add_option_{$this->toggle()}" );
			delete_option( 'wpai_features_enabled' );
			delete_option( self::TOGGLE );
			delete_option( self::SETTING_NAME );
			$GLOBALS['wpai_test_secret_store'] = array();
			parent::tearDown();
		}

		/**
		 * @since x.x.x
		 */
		private function toggle(): string {
			return self::TOGGLE;
		}

		/**
		 * @since x.x.x
		 */
		public function test_round_trip_when_enabled() {
			update_option( self::TOGGLE, true );

			update_option( self::SETTING_NAME, 'sk-secret-value' );

			$this->assertSame( '', $this->raw_option( self::SETTING_NAME ) );
			$this->assertSame( 'sk-secret-value', get_option( self::SETTING_NAME ) );
			$this->assertSame( 'sk-secret-value', $GLOBALS['wpai_test_secret_store'][ self::SECRET_KEY ] ?? null );
		}

		/**
		 * @since x.x.x
		 */
		public function test_opt_in_encrypts_existing_plaintext_keys() {
			update_option( self::SETTING_NAME, 'sk-plaintext' );
			$this->assertSame( 'sk-plaintext', $this->raw_option( self::SETTING_NAME ) );

			update_option( self::TOGGLE, true );

			$this->assertSame( '', $this->raw_option( self::SETTING_NAME ) );
			$this->assertSame( 'sk-plaintext', $GLOBALS['wpai_test_secret_store'][ self::SECRET_KEY ] ?? null );
			$this->assertSame( 'sk-plaintext', get_option( self::SETTING_NAME ) );
		}

		/**
		 * @since x.x.x
		 */
		public function test_opt_out_restores_plaintext() {
			update_option( self::TOGGLE, true );
			update_option( self::SETTING_NAME, 'sk-restored' );

			update_option( self::TOGGLE, false );

			$this->assertSame( 'sk-restored', $this->raw_option( self::SETTING_NAME ) );
			$this->assertArrayNotHasKey( self::SECRET_KEY, $GLOBALS['wpai_test_secret_store'] );
		}

		/**
		 * @since x.x.x
		 */
		public function test_deactivation_restores_plaintext() {
			update_option( self::TOGGLE, true );
			update_option( self::SETTING_NAME, 'sk-deactivate' );

			Deactivation::deactivation_callback();

			$this->assertSame( 'sk-deactivate', $this->raw_option( self::SETTING_NAME ) );
			$this->assertArrayNotHasKey( self::SECRET_KEY, $GLOBALS['wpai_test_secret_store'] );
		}

		/**
		 * @since x.x.x
		 */
		public function test_deactivation_with_experiment_disabled_is_noop() {
			update_option( self::SETTING_NAME, 'sk-plaintext' );

			Deactivation::deactivation_callback();

			$this->assertSame( 'sk-plaintext', $this->raw_option( self::SETTING_NAME ) );
		}

		/**
		 * @since x.x.x
		 */
		public function test_write_with_empty_string_clears_secret() {
			update_option( self::TOGGLE, true );
			update_option( self::SETTING_NAME, 'sk-temp' );
			$this->assertArrayHasKey( self::SECRET_KEY, $GLOBALS['wpai_test_secret_store'] );

			update_option( self::SETTING_NAME, '' );

			$this->assertArrayNotHasKey( self::SECRET_KEY, $GLOBALS['wpai_test_secret_store'] );
			$this->assertSame( '', $this->raw_option( self::SETTING_NAME ) );
		}

		/**
		 * @since x.x.x
		 */
		public function test_read_passthrough_when_no_secret_stored() {
			// Experiment enabled but no migration ever ran for this key — the read filter should
			// transparently fall through to the stored plaintext.
			update_option( self::SETTING_NAME, 'sk-untouched' );
			update_option( self::TOGGLE, true );

			// After opt-in, the value was migrated to the secret store. Now wipe just the secret
			// store entry to simulate a key that exists only as plaintext (e.g., partial state).
			unset( $GLOBALS['wpai_test_secret_store'][ self::SECRET_KEY ] );
			update_option( self::SETTING_NAME, '' ); // Re-clear the wp_options row to ensure clean state.

			$this->set_raw_option( self::SETTING_NAME, 'sk-fallback' );
			$this->assertSame( 'sk-fallback', get_option( self::SETTING_NAME ) );
		}

		/**
		 * Reads a wp_option directly without our read filter intercepting.
		 *
		 * @since x.x.x
		 */
		private function raw_option( string $option ): string {
			$bridge = Key_Encryption::get_bridge();
			remove_filter( "option_{$option}", array( $bridge, 'on_read' ), 10 );
			$value = get_option( $option, '' );
			add_filter( "option_{$option}", array( $bridge, 'on_read' ), 10, 2 );
			return is_string( $value ) ? $value : '';
		}

		/**
		 * Writes a raw value to wp_options bypassing our write filter.
		 *
		 * @since x.x.x
		 */
		private function set_raw_option( string $option, string $value ): void {
			$bridge = Key_Encryption::get_bridge();
			remove_filter( "pre_update_option_{$option}", array( $bridge, 'on_write' ), 10 );
			update_option( $option, $value );
			add_filter( "pre_update_option_{$option}", array( $bridge, 'on_write' ), 10, 1 );
		}

		/**
		 * Registers a fake AI connector in the WP 7.0 connector registry.
		 *
		 * @since x.x.x
		 */
		private function register_test_connector(): void {
			$registry = \WP_Connector_Registry::get_instance();
			if ( null === $registry ) {
				$this->markTestSkipped( 'WordPress Connectors API is unavailable.' );
			}

			if ( ! $registry->is_registered( self::CONNECTOR_ID ) ) {
				$registry->register(
					self::CONNECTOR_ID,
					array(
						'name'           => 'Test Provider',
						'description'    => 'Fake provider for Key_Encryption tests.',
						'type'           => 'ai_provider',
						'authentication' => array(
							'method'       => 'api_key',
							'setting_name' => self::SETTING_NAME,
						),
					)
				);
			}
		}
	}
}

<?php
/**
 * Integration tests for the Key_Encryption experiment.
 *
 * Exercises the experiment end-to-end against the bundled secrets backend (the libsodium-based
 * encrypted-options provider vendored under WordPress\AI\Vendor\Secrets). No global secret
 * functions are stubbed — the real provider encrypts to wp_options and the assertions read back
 * through the {@see Secrets} facade, so these tests prove the encryption round-trip rather than a
 * fake store.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Experiments\Key_Encryption;

use WP_UnitTestCase;
use WordPress\AI\Admin\Deactivation;
use WordPress\AI\Experiments\Key_Encryption\Key_Decryption_Exception;
use WordPress\AI\Experiments\Key_Encryption\Key_Encryption;
use WordPress\AI\Vendor\Secrets\Secrets;
use WordPress\AI\Vendor\Secrets\Secrets_Manager;

/**
 * Test case for the Key_Encryption experiment.
 *
 * @since 1.1.0
 */
class Key_EncryptionTest extends WP_UnitTestCase {

	private const CONNECTOR_ID  = 'testprovider';
	private const SETTING_NAME  = 'connectors_ai_testprovider_api_key';
	private const SECRET_KEY    = 'ai/testprovider_api_key';
	private const TOGGLE        = 'wpai_feature_key-encryption_enabled';
	private const LEGACY_OPTION = 'wpai_features_enabled';

	/**
	 * Caller context mirroring the bridge: explicit self-namespace so reads/writes are allowed
	 * regardless of the (absent) current user in the test runner.
	 */
	private const SECRET_CONTEXT = array( 'plugin' => 'ai' );

	/**
	 * @var Key_Encryption
	 */
	private Key_Encryption $experiment;

	/**
	 * @since 1.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Drop any provider/master-key state cached on the process-wide singleton so each test
		// starts from a clean keyring against its own (transaction-scoped) options.
		Secrets_Manager::reset();

		$this->register_test_connector();

		// Defang the WP 7.0 connector sanitize/mask filters so we can write/read raw values.
		remove_all_filters( 'sanitize_option_' . self::SETTING_NAME );
		remove_filter( 'option_' . self::SETTING_NAME, '_wp_connectors_mask_api_key' );

		delete_option( self::SETTING_NAME );
		delete_option( self::TOGGLE );
		delete_option( self::LEGACY_OPTION );

		// The plugin's normal boot flow has already instantiated the experiment and wired its
		// toggle hooks via Settings_Registration. Re-running register_settings on a fresh
		// instance is safe — the inner has_action checks make it idempotent.
		$this->experiment = new Key_Encryption();
		$this->experiment->register_settings();
	}

	/**
	 * @since 1.1.0
	 */
	public function tearDown(): void {
		delete_option( self::LEGACY_OPTION );
		delete_option( self::TOGGLE );
		delete_option( self::SETTING_NAME );
		delete_option( Key_Encryption::RESUME_MIGRATION_OPTION );
		Secrets_Manager::reset();
		parent::tearDown();
	}

	/**
	 * @since 1.1.0
	 */
	public function test_round_trip_when_enabled() {
		update_option( self::TOGGLE, true );

		update_option( self::SETTING_NAME, 'sk-secret-value' );

		$this->assertSame( '', $this->raw_option( self::SETTING_NAME ) );
		$this->assertSame( 'sk-secret-value', get_option( self::SETTING_NAME ) );
		$this->assertSame( 'sk-secret-value', $this->secret_value() );

		// The stored secret must be ciphertext at rest, never the plaintext key.
		$stored = get_option( '_secret_' . self::SECRET_KEY );
		$this->assertNotFalse( $stored );
		$this->assertNotSame( 'sk-secret-value', $stored );
	}

	/**
	 * @since 1.1.0
	 */
	public function test_opt_in_encrypts_existing_plaintext_keys() {
		update_option( self::SETTING_NAME, 'sk-plaintext' );
		$this->assertSame( 'sk-plaintext', $this->raw_option( self::SETTING_NAME ) );

		update_option( self::TOGGLE, true );

		$this->assertSame( '', $this->raw_option( self::SETTING_NAME ) );
		$this->assertSame( 'sk-plaintext', $this->secret_value() );
		$this->assertSame( 'sk-plaintext', get_option( self::SETTING_NAME ) );
	}

	/**
	 * @since 1.1.0
	 */
	public function test_opt_out_restores_plaintext() {
		update_option( self::TOGGLE, true );
		update_option( self::SETTING_NAME, 'sk-restored' );

		update_option( self::TOGGLE, false );

		$this->assertSame( 'sk-restored', $this->raw_option( self::SETTING_NAME ) );
		$this->assertFalse( $this->secret_stored() );
	}

	/**
	 * @since 1.1.0
	 */
	public function test_deactivation_restores_plaintext() {
		update_option( self::TOGGLE, true );
		update_option( self::SETTING_NAME, 'sk-deactivate' );

		Deactivation::deactivation_callback();

		$this->assertSame( 'sk-deactivate', $this->raw_option( self::SETTING_NAME ) );
		$this->assertFalse( $this->secret_stored() );
	}

	/**
	 * @since 1.1.0
	 */
	public function test_deactivation_with_experiment_disabled_is_noop() {
		update_option( self::SETTING_NAME, 'sk-plaintext' );

		Deactivation::deactivation_callback();

		$this->assertSame( 'sk-plaintext', $this->raw_option( self::SETTING_NAME ) );
	}

	/**
	 * @since 1.1.0
	 */
	public function test_write_with_empty_string_clears_secret() {
		update_option( self::TOGGLE, true );
		update_option( self::SETTING_NAME, 'sk-temp' );
		$this->assertTrue( $this->secret_stored() );

		update_option( self::SETTING_NAME, '' );

		$this->assertFalse( $this->secret_stored() );
		$this->assertSame( '', $this->raw_option( self::SETTING_NAME ) );
	}

	/**
	 * The retired global toggle no longer affects Key Encryption. Writing the legacy option
	 * (e.g. an old site or a stray import) must not decrypt keys while the experiment is on.
	 *
	 * @since 1.4.0
	 */
	public function test_legacy_global_option_does_not_decrypt_keys() {
		update_option( self::TOGGLE, true );
		update_option( self::SETTING_NAME, 'sk-legacy-off' );
		$this->assertTrue( $this->secret_stored() );

		update_option( self::LEGACY_OPTION, false );

		$this->assertSame( '', $this->raw_option( self::SETTING_NAME ) );
		$this->assertSame( 'sk-legacy-off', $this->secret_value() );
		$this->assertTrue( Key_Encryption::is_effectively_enabled() );
	}

	/**
	 * Tests that for_each_site() runs the callback once on the current site by default.
	 *
	 * @since x.x.x
	 */
	public function test_for_each_site_runs_on_current_site_only() {
		$visited = array();

		Key_Encryption::for_each_site(
			false,
			static function () use ( &$visited ): void {
				$visited[] = get_current_blog_id();
			}
		);

		$this->assertSame( array( get_current_blog_id() ), $visited );
	}

	/**
	 * Tests that for_each_site() returns what a callback threw instead of letting it escape.
	 *
	 * @since x.x.x
	 */
	public function test_for_each_site_returns_callback_errors() {
		$blog_id = get_current_blog_id();
		$error   = new \RuntimeException( 'Cannot decrypt.' );

		$errors = Key_Encryption::for_each_site(
			false,
			static function () use ( $error ): void {
				throw $error;
			}
		);

		$this->assertSame( array( $blog_id => $error ), $errors );
		$this->assertSame( $blog_id, get_current_blog_id() );
	}

	/**
	 * Tests that for_each_site() returns no errors when the callback succeeds.
	 *
	 * @since x.x.x
	 */
	public function test_for_each_site_returns_no_errors_on_success() {
		$this->assertSame( array(), Key_Encryption::for_each_site( false, '__return_true' ) );
	}

	/**
	 * Tests that decrypt_all() restores the other keys when one cannot be decrypted, then reports it.
	 *
	 * @since x.x.x
	 */
	public function test_decrypt_all_restores_remaining_keys_when_one_fails() {
		$second_setting = 'connectors_ai_testprovider2_api_key';
		$this->register_second_test_connector( $second_setting );

		update_option( self::TOGGLE, true );
		update_option( self::SETTING_NAME, 'sk-unreadable' );
		update_option( $second_setting, 'sk-healthy' );

		// Damage the first connector's stored secret.
		update_option( '_secret_' . self::SECRET_KEY, 'not valid ciphertext' );

		$failed = null;
		try {
			Key_Encryption::get_bridge()->decrypt_all();
		} catch ( Key_Decryption_Exception $e ) {
			$failed = $e->get_connector_ids();
		}

		$this->assertSame( array( self::CONNECTOR_ID ), $failed, 'The unreadable key should be reported.' );
		$this->assertSame( 'sk-healthy', $this->raw_option( $second_setting ), 'The healthy key should still be restored.' );
		$this->assertSame( 'not valid ciphertext', get_option( '_secret_' . self::SECRET_KEY ), 'The unreadable secret should be left in place.' );

		delete_option( $second_setting );
		delete_option( '_secret_' . self::SECRET_KEY );
	}

	/**
	 * Tests that for_each_site() runs the callback in the context of every site of the network.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_for_each_site_runs_on_every_site_when_network_wide() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$main_blog_id   = get_current_blog_id();
		$second_blog_id = self::factory()->blog->create();
		$visited        = array();

		Key_Encryption::for_each_site(
			true,
			static function () use ( &$visited ): void {
				$visited[] = get_current_blog_id();
			}
		);

		$this->assertContains( $main_blog_id, $visited );
		$this->assertContains( $second_blog_id, $visited );
		$this->assertSame( $main_blog_id, get_current_blog_id(), 'The original site should be restored.' );

		wp_delete_site( get_site( $second_blog_id ) );
	}

	/**
	 * Plugin lifecycle: deactivate decrypts; reactivate (via the deferred resume flag) re-encrypts.
	 *
	 * @since 1.1.0
	 */
	public function test_reactivation_re_encrypts_plaintext_keys() {
		update_option( self::TOGGLE, true );
		update_option( self::SETTING_NAME, 'sk-roundtrip' );
		$this->assertTrue( $this->secret_stored() );

		// Simulate deactivation: keys decrypted, secret cleared.
		Deactivation::deactivation_callback();
		$this->assertSame( 'sk-roundtrip', $this->raw_option( self::SETTING_NAME ) );
		$this->assertFalse( $this->secret_stored() );

		// Simulate reactivation: activation hook sets the deferred flag.
		Key_Encryption::flag_resume_migration();
		$this->assertSame( '1', get_option( Key_Encryption::RESUME_MIGRATION_OPTION ) );

		// On the next request, `register()` runs first (because the feature is effectively
		// enabled) and wires the option filters BEFORE init+16 fires the deferred migration.
		// Simulate that ordering — `encrypt_all` must defang those filters during its own
		// run, otherwise its `update_option( $setting, '' )` call gets intercepted by the
		// write filter and the just-stored secret is deleted right back out.
		Key_Encryption::get_bridge()->register_option_filters();

		// Simulate init+16.
		Key_Encryption::maybe_resume_migration();

		$this->assertSame( '', $this->raw_option( self::SETTING_NAME ) );
		$this->assertSame( 'sk-roundtrip', $this->secret_value() );
		$this->assertFalse( get_option( Key_Encryption::RESUME_MIGRATION_OPTION, false ) );

		// And the read filter still works after the migration.
		$this->assertSame( 'sk-roundtrip', get_option( self::SETTING_NAME ) );
	}

	/**
	 * Tests the resume flag is listed for loading with the feature toggles.
	 *
	 * @since x.x.x
	 */
	public function test_preloaded_options_list_the_resume_flag() {
		$this->assertSame( array( Key_Encryption::RESUME_MIGRATION_OPTION ), $this->experiment->get_preloaded_options() );
	}

	/**
	 * Fresh activation with the experiment never enabled is a no-op: the flag is consumed but
	 * no migration runs.
	 *
	 * @since 1.1.0
	 */
	public function test_resume_migration_noop_when_not_effectively_enabled() {
		update_option( self::SETTING_NAME, 'sk-plaintext' );
		Key_Encryption::flag_resume_migration();

		Key_Encryption::maybe_resume_migration();

		$this->assertSame( 'sk-plaintext', $this->raw_option( self::SETTING_NAME ) );
		$this->assertFalse( $this->secret_stored() );
		$this->assertFalse( get_option( Key_Encryption::RESUME_MIGRATION_OPTION, false ) );
	}

	/**
	 * Reproduces the production fresh-site path: the connector setting is registered with a
	 * default of '' (as core's `_wp_register_default_connector_settings()` does). Because a
	 * brand-new site has no `wp_options` row for the key, the first encrypted write makes
	 * `update_option()` short-circuit (filtered value '' equals the default ''), so the row is
	 * never created — and `option_{$name}` (the read filter) only fires for options that exist.
	 * The decrypted key must still be readable on the very first save.
	 *
	 * @since 1.1.0
	 */
	public function test_first_write_surfaces_key_when_setting_has_empty_default() {
		// Mirror core: register the setting with a '' default so a missing option reads as ''.
		register_setting(
			'connectors',
			self::SETTING_NAME,
			array(
				'type'    => 'string',
				'default' => '',
			)
		);

		try {
			update_option( self::TOGGLE, true );

			// Brand-new site: there is no wp_options row for this key yet.
			update_option( self::SETTING_NAME, 'sk-fresh-key' );

			// The decrypted key must be readable even though no plaintext row was created.
			$this->assertSame( 'sk-fresh-key', get_option( self::SETTING_NAME ) );
			$this->assertSame( 'sk-fresh-key', $this->secret_value() );
		} finally {
			unregister_setting( 'connectors', self::SETTING_NAME );
		}
	}

	/**
	 * @since 1.1.0
	 */
	public function test_read_passthrough_when_no_secret_stored() {
		// Experiment enabled but no migration ever ran for this key — the read filter should
		// transparently fall through to the stored plaintext.
		update_option( self::SETTING_NAME, 'sk-untouched' );
		update_option( self::TOGGLE, true );

		// After opt-in, the value was migrated to the secret store. Now delete just the secret
		// to simulate a key that exists only as plaintext (e.g., partial state).
		Secrets::delete( self::SECRET_KEY, self::SECRET_CONTEXT );
		update_option( self::SETTING_NAME, '' ); // Re-clear the wp_options row to ensure clean state.

		$this->set_raw_option( self::SETTING_NAME, 'sk-fallback' );
		$this->assertSame( 'sk-fallback', get_option( self::SETTING_NAME ) );
	}

	/**
	 * Returns the decrypted secret value for the test connector, or null if none is stored.
	 *
	 * @since 1.1.0
	 */
	private function secret_value(): ?string {
		return Secrets::get( self::SECRET_KEY, self::SECRET_CONTEXT );
	}

	/**
	 * Returns whether an encrypted secret is stored for the test connector.
	 *
	 * @since 1.1.0
	 */
	private function secret_stored(): bool {
		return Secrets::exists( self::SECRET_KEY, self::SECRET_CONTEXT );
	}

	/**
	 * Reads a wp_option directly without our read filter intercepting.
	 *
	 * @since 1.1.0
	 */
	private function raw_option( string $option ): string {
		$bridge = Key_Encryption::get_bridge();
		remove_filter( "option_{$option}", array( $bridge, 'on_read' ), 10 );
		remove_filter( "default_option_{$option}", array( $bridge, 'on_read_default' ), 11 );
		$value = get_option( $option, '' );
		add_filter( "option_{$option}", array( $bridge, 'on_read' ), 10, 2 );
		add_filter( "default_option_{$option}", array( $bridge, 'on_read_default' ), 11, 2 );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Writes a raw value to wp_options bypassing our write filter.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
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

	/**
	 * Registers a second connector, listed after the first one.
	 *
	 * @since x.x.x
	 *
	 * @param string $setting_name Option the connector stores its API key in.
	 */
	private function register_second_test_connector( string $setting_name ): void {
		$registry = \WP_Connector_Registry::get_instance();

		if ( ! $registry->is_registered( 'testprovider2' ) ) {
			$registry->register(
				'testprovider2',
				array(
					'name'           => 'Second Test Provider',
					'description'    => 'Second fake provider for Key_Encryption tests.',
					'type'           => 'ai_provider',
					'authentication' => array(
						'method'       => 'api_key',
						'setting_name' => $setting_name,
					),
				)
			);
		}

		remove_all_filters( 'sanitize_option_' . $setting_name );
		remove_filter( 'option_' . $setting_name, '_wp_connectors_mask_api_key' );
		delete_option( $setting_name );

		// Pick up the new connector's option filters.
		Key_Encryption::get_bridge()->register_option_filters();
	}
}

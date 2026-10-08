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
	 * @var \WordPress\AI\Experiments\Key_Encryption\Key_Encryption
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
		$stored = get_option( '_wp_secret_' . self::SECRET_KEY ) ?: get_option( '_secret_' . self::SECRET_KEY );
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
	 * Tests Secrets_Bridge get_secret, set_secret, and delete_secret methods.
	 *
	 * @since 1.5.0
	 */
	public function test_bridge_get_set_delete() {
		$bridge = Key_Encryption::get_bridge();

		$this->assertTrue( $bridge->set_secret( self::SECRET_KEY, 'sk-bridge-test' ) );
		$this->assertSame( 'sk-bridge-test', $bridge->get_secret( self::SECRET_KEY ) );

		$this->assertTrue( $bridge->delete_secret( self::SECRET_KEY ) );
		$this->assertNull( $bridge->get_secret( self::SECRET_KEY ) );
	}

	/**
	 * Tests maybe_migrate_legacy_secrets safely handles legacy rows.
	 *
	 * @since 1.5.0
	 */
	public function test_bridge_maybe_migrate_legacy_secrets() {
		$bridge = Key_Encryption::get_bridge();

		// Setting a legacy secret.
		$bridge->is_legacy_provider_available();
		$bridge->is_secrets_manager_available();
		$this->assertTrue( Secrets::set( self::SECRET_KEY, 'sk-legacy-to-migrate', self::SECRET_CONTEXT ) );
		$this->assertSame( 'sk-legacy-to-migrate', $bridge->get_secret( self::SECRET_KEY ) );

		// Run migration routine.
		$migrated = $bridge->maybe_migrate_legacy_secrets();
		$this->assertGreaterThanOrEqual( 0, $migrated );

		// Value is still readable.
		$this->assertSame( 'sk-legacy-to-migrate', $bridge->get_secret( self::SECRET_KEY ) );
	}

	/**
	 * Tests Secrets_Bridge handles absent secrets properly.
	 *
	 * @since 1.5.0
	 */
	public function test_bridge_get_secret_returns_null_when_absent(): void {
		$bridge = Key_Encryption::get_bridge();
		$this->assertNull( $bridge->get_secret( 'ai/non_existent_key_12345' ) );
	}

	/**
	 * Tests Secrets_Bridge transparent read-time promotion from legacy option to Secrets API.
	 *
	 * @since 1.5.0
	 */
	public function test_bridge_transparent_read_promotion(): void {
		$bridge = Key_Encryption::get_bridge();
		$legacy_key = 'ai/transparent_promo_key';
		$legacy_row = '_secret_' . $legacy_key;

		$bridge->is_legacy_provider_available();
		$bridge->is_secrets_manager_available();
		$this->assertTrue( Secrets::set( $legacy_key, 'sk-transparent-val', self::SECRET_CONTEXT ) );
		$this->assertNotFalse( get_option( $legacy_row ) );

		// Read via bridge: must transparently promote and clean up legacy row.
		$this->assertSame( 'sk-transparent-val', $bridge->get_secret( $legacy_key ) );
		$this->assertFalse( get_option( $legacy_row, false ) );

		// Subsequent reads come from the new Secrets API.
		$secret = wp_get_secret( $legacy_key );
		$revealed = $secret instanceof \WP_Secret ? $secret->reveal() : $secret;
		$this->assertSame( 'sk-transparent-val', $revealed );

		$bridge->delete_secret( $legacy_key );
	}

	/**
	 * Tests Secrets_Bridge set_secret clears any existing legacy option.
	 *
	 * @since 1.5.0
	 */
	public function test_bridge_set_secret_cleans_legacy_row(): void {
		$bridge = Key_Encryption::get_bridge();
		$key = 'ai/overwrite_legacy_key';
		$legacy_row = '_secret_' . $key;

		$bridge->is_legacy_provider_available();
		Secrets::set( $key, 'sk-old-val', self::SECRET_CONTEXT );
		$this->assertNotFalse( get_option( $legacy_row ) );

		// Write new value via set_secret:
		$this->assertTrue( $bridge->set_secret( $key, 'sk-new-val' ) );
		$this->assertFalse( get_option( $legacy_row, false ) );
		$this->assertSame( 'sk-new-val', $bridge->get_secret( $key ) );

		$bridge->delete_secret( $key );
	}

	/**
	 * Tests Secrets_Bridge delete_secret removes both new and legacy rows.
	 *
	 * @since 1.5.0
	 */
	public function test_bridge_delete_secret_cleans_all(): void {
		$bridge = Key_Encryption::get_bridge();
		$key = 'ai/delete_all_key';
		$legacy_row = '_secret_' . $key;

		$bridge->is_legacy_provider_available();
		Secrets::set( $key, 'sk-val', self::SECRET_CONTEXT );
		$bridge->set_secret( $key, 'sk-val' );

		$this->assertTrue( $bridge->delete_secret( $key ) );
		$this->assertNull( $bridge->get_secret( $key ) );
		$this->assertFalse( get_option( $legacy_row, false ) );
	}

	/**
	 * Tests maybe_migrate_legacy_secrets returns 0 when no legacy rows exist.
	 *
	 * @since 1.5.0
	 */
	public function test_bridge_maybe_migrate_legacy_secrets_empty(): void {
		$bridge = Key_Encryption::get_bridge();
		$this->assertSame( 0, $bridge->maybe_migrate_legacy_secrets() );
	}

	/**
	 * Tests Key_Encryption preloaded options and register method.
	 *
	 * @since 1.5.0
	 */
	public function test_experiment_preloaded_options_and_register(): void {
		$this->assertSame( array( Key_Encryption::RESUME_MIGRATION_OPTION ), $this->experiment->get_preloaded_options() );
		$this->experiment->register();
		$this->assertTrue( Key_Encryption::get_bridge()->is_secrets_manager_available() );
	}

	/**
	 * Tests deleting a secret that only exists in the legacy option store.
	 *
	 * @since 1.5.0
	 */
	public function test_bridge_delete_secret_legacy_only(): void {
		$bridge = Key_Encryption::get_bridge();
		$key = 'ai/legacy_only_delete_key';
		$legacy_row = '_secret_' . $key;

		$bridge->is_legacy_provider_available();
		Secrets::set( $key, 'sk-legacy-only', self::SECRET_CONTEXT );
		$this->assertNotFalse( get_option( $legacy_row ) );

		$this->assertTrue( $bridge->delete_secret( $key ) );
		$this->assertFalse( get_option( $legacy_row, false ) );
		$this->assertNull( $bridge->get_secret( $key ) );
	}

	/**
	 * Tests maybe_migrate_legacy_secrets skips empty or malformed legacy rows.
	 *
	 * @since 1.5.0
	 */
	public function test_bridge_maybe_migrate_legacy_secrets_skips_empty(): void {
		$bridge = Key_Encryption::get_bridge();
		$empty_key = '_secret_ai/empty_legacy_key';
		update_option( $empty_key, '' );

		$migrated = $bridge->maybe_migrate_legacy_secrets();
		$this->assertSame( 0, $migrated );

		delete_option( $empty_key );
	}

	/**
	 * Tests ensure_secrets_api is safely callable multiple times.
	 *
	 * @since 1.5.0
	 */
	public function test_bridge_ensure_secrets_api_idempotent(): void {
		$bridge = Key_Encryption::get_bridge();
		$bridge->ensure_secrets_api();
		$bridge->ensure_secrets_api();
		$this->assertTrue( function_exists( 'wp_get_secret' ) );
	}

	/**
	 * Returns the decrypted secret value for the test connector, or null if none is stored.
	 *
	 * @since 1.1.0
	 */
	private function secret_value(): ?string {
		return Key_Encryption::get_bridge()->get_secret( self::SECRET_KEY );
	}

	/**
	 * Returns whether an encrypted secret is stored for the test connector.
	 *
	 * @since 1.1.0
	 */
	private function secret_stored(): bool {
		return null !== Key_Encryption::get_bridge()->get_secret( self::SECRET_KEY );
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

		if ( $registry->is_registered( self::CONNECTOR_ID ) ) {
			return;
		}

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

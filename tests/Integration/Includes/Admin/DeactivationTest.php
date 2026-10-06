<?php
/**
 * Integration tests for Deactivation.
 *
 * @package WordPress\AI\Tests\Integration\Admin
 */

namespace WordPress\AI\Tests\Integration\Admin;

use WP_UnitTestCase;
use WordPress\AI\Admin\Deactivation;
use WordPress\AI\Experiments\Key_Encryption\Key_Encryption;
use WordPress\AI\Vendor\Secrets\Secrets;
use WordPress\AI\Vendor\Secrets\Secrets_Manager;

/**
 * Deactivation test case.
 *
 * @covers \WordPress\AI\Admin\Deactivation
 * @since 1.4.0
 */
class DeactivationTest extends WP_UnitTestCase {

	private const CONNECTOR_ID   = 'testprovider';
	private const SETTING_NAME   = 'connectors_ai_testprovider_api_key';
	private const SECRET_KEY     = 'ai/testprovider_api_key';
	private const TOGGLE         = 'wpai_feature_key-encryption_enabled';
	private const SECRET_CONTEXT = array( 'plugin' => 'ai' );

	/**
	 * Cleans up options before each test.
	 *
	 * @since 1.4.0
	 */
	public function setUp(): void {
		parent::setUp();

		Secrets_Manager::reset();

		$this->register_test_connector();

		remove_all_filters( 'sanitize_option_' . self::SETTING_NAME );
		remove_filter( 'option_' . self::SETTING_NAME, '_wp_connectors_mask_api_key' );

		delete_option( self::SETTING_NAME );
		delete_option( self::TOGGLE );
	}

	/**
	 * Cleans up options after each test.
	 *
	 * @since 1.4.0
	 */
	public function tearDown(): void {
		delete_option( self::TOGGLE );
		delete_option( self::SETTING_NAME );
		delete_option( Key_Encryption::RESUME_MIGRATION_OPTION );
		Secrets_Manager::reset();

		parent::tearDown();
	}

	/**
	 * Tests that deactivation_callback() is a no-op when Key Encryption is disabled.
	 *
	 * @since 1.4.0
	 */
	public function test_deactivation_callback_with_experiment_disabled_is_noop(): void {
		delete_option( self::TOGGLE );
		update_option( self::SETTING_NAME, 'sk-plaintext-key' );

		Deactivation::deactivation_callback();

		$this->assertSame( 'sk-plaintext-key', get_option( self::SETTING_NAME ) );
	}

	/**
	 * Tests that deactivation_callback() restores plaintext keys when Key Encryption is enabled.
	 *
	 * @since 1.4.0
	 */
	public function test_deactivation_callback_restores_plaintext_when_enabled(): void {
		$experiment = new Key_Encryption();
		$experiment->register_settings();

		update_option( self::TOGGLE, true );
		update_option( self::SETTING_NAME, 'sk-deactivate-secret' );

		Deactivation::deactivation_callback();

		$this->assertSame( 'sk-deactivate-secret', $this->raw_option( self::SETTING_NAME ) );
		$this->assertFalse( Secrets::exists( self::SECRET_KEY, self::SECRET_CONTEXT ) );
	}

	/**
	 * Tests that deactivation_callback() accepts the null WordPress can pass for `$network_wide`.
	 *
	 * @since x.x.x
	 */
	public function test_deactivation_callback_accepts_null_network_wide(): void {
		$experiment = new Key_Encryption();
		$experiment->register_settings();

		update_option( self::TOGGLE, true );
		update_option( self::SETTING_NAME, 'sk-deactivate-secret' );

		Deactivation::deactivation_callback( null );

		$this->assertSame( 'sk-deactivate-secret', $this->raw_option( self::SETTING_NAME ) );
	}

	/**
	 * Tests that a network-wide deactivation restores plaintext keys on every site.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_network_wide_deactivation_restores_plaintext_on_every_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$experiment = new Key_Encryption();
		$experiment->register_settings();

		$second_blog_id = self::factory()->blog->create();

		update_option( self::TOGGLE, true );
		update_option( self::SETTING_NAME, 'sk-main-site' );

		// Each site has its own master key, so the cached one is dropped on every switch.
		switch_to_blog( $second_blog_id );
		Secrets_Manager::reset();
		update_option( self::TOGGLE, true );
		update_option( self::SETTING_NAME, 'sk-second-site' );
		$second_raw_before = $this->raw_option( self::SETTING_NAME );
		restore_current_blog();
		Secrets_Manager::reset();

		$this->assertSame( '', $second_raw_before, 'Second site key should be encrypted before deactivation.' );

		Deactivation::deactivation_callback( true );

		$this->assertSame( 'sk-main-site', $this->raw_option( self::SETTING_NAME ), 'Main site key should be restored.' );
		$this->assertFalse( Secrets::exists( self::SECRET_KEY, self::SECRET_CONTEXT ), 'Main site secret should be removed.' );

		switch_to_blog( $second_blog_id );
		$second_raw_after    = $this->raw_option( self::SETTING_NAME );
		$second_secret_after = get_option( '_secret_' . self::SECRET_KEY );
		restore_current_blog();

		$this->assertSame( 'sk-second-site', $second_raw_after, 'Second site key should be restored.' );
		$this->assertFalse( $second_secret_after, 'Second site secret should be removed.' );

		wp_delete_site( get_site( $second_blog_id ) );
	}

	/**
	 * Tests that a single-site deactivation on multisite leaves the other sites encrypted.
	 *
	 * @group ms-required
	 *
	 * @since x.x.x
	 */
	public function test_single_site_deactivation_leaves_other_sites_encrypted(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires a multisite installation.' );
		}

		$experiment = new Key_Encryption();
		$experiment->register_settings();

		$second_blog_id = self::factory()->blog->create();

		switch_to_blog( $second_blog_id );
		Secrets_Manager::reset();
		update_option( self::TOGGLE, true );
		update_option( self::SETTING_NAME, 'sk-second-site' );
		restore_current_blog();
		Secrets_Manager::reset();

		Deactivation::deactivation_callback( false );

		switch_to_blog( $second_blog_id );
		$second_raw_after    = $this->raw_option( self::SETTING_NAME );
		$second_secret_after = get_option( '_secret_' . self::SECRET_KEY );
		restore_current_blog();

		$this->assertSame( '', $second_raw_after, 'Second site key should stay encrypted.' );
		$this->assertNotFalse( $second_secret_after, 'Second site secret should be kept.' );

		wp_delete_site( get_site( $second_blog_id ) );
	}

	/**
	 * Reads a wp_option directly without read filters intercepting.
	 *
	 * @since 1.4.0
	 *
	 * @param string $option Option name.
	 * @return string
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
	 * Registers a test connector in the WP 7.0 connector registry.
	 *
	 * @since 1.4.0
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
					'description'    => 'Fake provider for Deactivation tests.',
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

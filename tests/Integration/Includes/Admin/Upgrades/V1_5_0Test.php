<?php
/**
 * Integration tests for V1_5_0.
 *
 * @package WordPress\AI\Tests\Integration\Admin\Upgrades
 */

namespace WordPress\AI\Tests\Integration\Admin\Upgrades;

use WP_UnitTestCase;
use WordPress\AI\Admin\Upgrades\V1_5_0;
use WordPress\AI\Experiments\Key_Encryption\Key_Encryption;
use WordPress\AI\Vendor\Secrets\Secrets;
use WordPress\AI\Vendor\Secrets\Secrets_Manager;

/**
 * V1_5_0 test case.
 *
 * @covers \WordPress\AI\Admin\Upgrades\V1_5_0
 *
 * @since 1.5.0
 */
class V1_5_0Test extends WP_UnitTestCase {

	private const CONNECTOR_ID = 'testupgrade';
	private const SECRET_KEY   = 'ai/testupgrade_api_key';
	private const LEGACY_ROW   = '_secret_ai/testupgrade_api_key';

	/**
	 * Cleans up options written by the tests.
	 *
	 * @since 1.5.0
	 */
	public function tearDown(): void {
		delete_option( self::LEGACY_ROW );
		delete_option( '_wp_secret_' . self::SECRET_KEY );
		if ( function_exists( 'wp_delete_secret' ) ) {
			wp_delete_secret( self::SECRET_KEY );
		}
		Secrets_Manager::reset();
		parent::tearDown();
	}

	/**
	 * Tests that run() returns true on success.
	 *
	 * @since 1.5.0
	 */
	public function test_run_returns_success(): void {
		$this->assertTrue( ( new V1_5_0( '1.4.0' ) )->run() );
	}

	/**
	 * Tests that upgrade() skips when db_version is empty (new install).
	 *
	 * @since 1.5.0
	 */
	public function test_upgrade_skips_when_db_version_is_empty(): void {
		$this->assertTrue( ( new V1_5_0( '' ) )->run() );
	}

	/**
	 * Tests that run() skips upgrade when the db version is already current.
	 *
	 * @since 1.5.0
	 */
	public function test_run_skips_when_already_upgraded(): void {
		$bridge = Key_Encryption::get_bridge();
		$bridge->is_legacy_provider_available();
		$bridge->is_secrets_manager_available();
		Secrets::set( self::SECRET_KEY, 'sk-legacy-value', array( 'plugin' => 'ai' ) );

		$this->assertTrue( ( new V1_5_0( '1.5.0' ) )->run() );

		// Legacy secret should remain untouched because upgrade was skipped.
		$this->assertNotFalse( get_option( self::LEGACY_ROW ) );
	}

	/**
	 * Tests that upgrade() migrates legacy secrets when the global Secrets API is available.
	 *
	 * @since 1.5.0
	 */
	public function test_upgrade_migrates_legacy_secret(): void {
		$bridge = Key_Encryption::get_bridge();

		// Populate a legacy secret directly via internal provider.
		$bridge->is_legacy_provider_available();
		$bridge->is_secrets_manager_available();
		Secrets::set( self::SECRET_KEY, 'sk-legacy-value', array( 'plugin' => 'ai' ) );

		$this->assertNotFalse( get_option( self::LEGACY_ROW ) );

		// Run upgrade.
		$result = ( new V1_5_0( '1.4.0' ) )->run();
		$this->assertTrue( $result );

		// Verify migration to the bundled Secrets API.
		$secret = wp_get_secret( self::SECRET_KEY );
		$revealed = $secret instanceof \WP_Secret ? $secret->reveal() : $secret;
		$this->assertSame( 'sk-legacy-value', $revealed );
		$this->assertFalse( get_option( self::LEGACY_ROW, false ) );
	}
}

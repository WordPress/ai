<?php
/**
 * Integration tests for the MCP Adapter Plugin_Installer class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\MCP_Adapter
 */

namespace WordPress\AI\Tests\Integration\Experiments\MCP_Adapter;

use WP_UnitTestCase;
use WordPress\AI\Experiments\MCP_Adapter\Plugin_Installer;

/**
 * Plugin_Installer test case.
 */
class Plugin_InstallerTest extends WP_UnitTestCase {
	/**
	 * Creates a user allowed to install and activate plugins.
	 *
	 * On multisite those capabilities belong to super admins, not site
	 * administrators.
	 *
	 * @return int The user id.
	 */
	private function create_installer_user(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( $user_id );
		}

		return $user_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		delete_transient( Plugin_Installer::LOCK_TRANSIENT );
		remove_all_filters( 'wpai_mcp_adapter_plugin_slug' );
		remove_all_filters( 'wpai_pre_mcp_adapter_autoinstall' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Tests that the plugin state reports a missing plugin.
	 */
	public function test_get_state_reports_missing_plugin() {
		$state = Plugin_Installer::get_state();

		$this->assertSame( 'mcp-adapter', $state['slug'] );
		$this->assertSame( 'missing', $state['status'] );
		$this->assertNull( $state['file'] );
	}

	/**
	 * Tests that users without install capability never trigger an install.
	 */
	public function test_no_attempt_without_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$attempted = false;
		add_filter(
			'wpai_pre_mcp_adapter_autoinstall',
			static function () use ( &$attempted ) {
				$attempted = true;
				return true;
			}
		);

		( new Plugin_Installer() )->maybe_install_and_activate();

		$this->assertFalse( $attempted, 'Users without install_plugins must not trigger an install.' );
	}

	/**
	 * Tests that an attempt is made for capable users and no attempt repeats while locked.
	 */
	public function test_attempt_runs_once_and_locks_on_failure() {
		wp_set_current_user( $this->create_installer_user() );

		$attempts = 0;
		add_filter(
			'wpai_pre_mcp_adapter_autoinstall',
			static function () use ( &$attempts ) {
				++$attempts;
				return new \WP_Error( 'install_failed', 'Simulated failure.' );
			}
		);

		$installer = new Plugin_Installer();
		$installer->maybe_install_and_activate();
		$installer->maybe_install_and_activate();

		$this->assertSame( 1, $attempts, 'A failed attempt must set the lock and not retry immediately.' );
		$this->assertNotFalse( get_transient( Plugin_Installer::LOCK_TRANSIENT ) );
	}

	/**
	 * Tests that a successful attempt does not set the failure lock.
	 */
	public function test_successful_attempt_does_not_lock() {
		wp_set_current_user( $this->create_installer_user() );

		add_filter( 'wpai_pre_mcp_adapter_autoinstall', '__return_true' );

		( new Plugin_Installer() )->maybe_install_and_activate();

		$this->assertFalse( get_transient( Plugin_Installer::LOCK_TRANSIENT ) );
	}
}

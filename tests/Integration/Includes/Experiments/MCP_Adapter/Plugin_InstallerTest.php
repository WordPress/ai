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
		delete_option( Plugin_Installer::HANDLED_OPTION );
		remove_all_filters( 'wpai_mcp_adapter_plugin_slug' );
		remove_all_filters( 'wpai_pre_mcp_adapter_autoinstall' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Counts attempts by short-circuiting the actual install.
	 *
	 * @param mixed $result The result each attempt should report.
	 * @return callable(): int Callback returning the attempt count.
	 */
	private function count_attempts( $result ): callable {
		$attempts = 0;
		add_filter(
			'wpai_pre_mcp_adapter_autoinstall',
			static function () use ( &$attempts, $result ) {
				++$attempts;
				return $result;
			}
		);

		return static function () use ( &$attempts ): int {
			return $attempts;
		};
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
	 * Tests that a single-file plugin matching the slug is detected.
	 */
	public function test_get_state_matches_single_file_plugin() {
		if ( ! file_exists( WP_PLUGIN_DIR . '/hello.php' ) ) {
			$this->markTestSkipped( 'Requires the bundled hello.php single-file plugin.' );
		}

		add_filter(
			'wpai_mcp_adapter_plugin_slug',
			static function (): string {
				return 'hello';
			}
		);

		$state = Plugin_Installer::get_state();

		$this->assertSame( 'hello.php', $state['file'], 'A main file named after the slug should match even outside a slug directory.' );
		$this->assertNotSame( 'missing', $state['status'] );
	}

	/**
	 * Tests that users without install capability never trigger or consume an attempt.
	 */
	public function test_no_attempt_without_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$attempts = $this->count_attempts( true );

		( new Plugin_Installer() )->maybe_install_and_activate();

		$this->assertSame( 0, $attempts(), 'Users without install_plugins must not trigger an install.' );
		$this->assertFalse( get_option( Plugin_Installer::HANDLED_OPTION ), 'An incapable visit must not consume the enable-cycle attempt.' );

		// A capable user afterwards still gets the attempt.
		wp_set_current_user( $this->create_installer_user() );
		( new Plugin_Installer() )->maybe_install_and_activate();
		$this->assertSame( 1, $attempts() );
	}

	/**
	 * Tests that only one attempt happens per enable cycle, even after failure.
	 */
	public function test_single_attempt_per_enable_cycle() {
		wp_set_current_user( $this->create_installer_user() );
		$attempts = $this->count_attempts( new \WP_Error( 'install_failed', 'Simulated failure.' ) );

		$installer = new Plugin_Installer();
		$installer->maybe_install_and_activate();
		$installer->maybe_install_and_activate();

		$this->assertSame( 1, $attempts(), 'The attempt must run once per enable cycle, not per admin_init.' );
		$this->assertSame( 'Simulated failure.', get_option( Plugin_Installer::HANDLED_OPTION ) );
		$this->assertSame( 'Simulated failure.', Plugin_Installer::get_state()['autoinstall_error'] );
	}

	/**
	 * Tests that a successful attempt marks the cycle handled with no error.
	 */
	public function test_successful_attempt_marks_handled() {
		wp_set_current_user( $this->create_installer_user() );
		$attempts = $this->count_attempts( true );

		$installer = new Plugin_Installer();
		$installer->maybe_install_and_activate();
		$installer->maybe_install_and_activate();

		$this->assertSame( 1, $attempts() );
		$this->assertSame( '1', get_option( Plugin_Installer::HANDLED_OPTION ) );
		$this->assertNull( Plugin_Installer::get_state()['autoinstall_error'] );
	}

	/**
	 * Tests that a stale in-flight claim does not block the attempt forever.
	 */
	public function test_stale_running_claim_is_retried() {
		wp_set_current_user( $this->create_installer_user() );
		update_option( Plugin_Installer::HANDLED_OPTION, 'running:' . ( time() - HOUR_IN_SECONDS ), false );
		$attempts = $this->count_attempts( true );

		( new Plugin_Installer() )->maybe_install_and_activate();

		$this->assertSame( 1, $attempts(), 'A claim left behind by a crashed attempt must not disable the installer forever.' );
		$this->assertSame( '1', get_option( Plugin_Installer::HANDLED_OPTION ) );
	}

	/**
	 * Tests that a fresh in-flight claim blocks concurrent attempts.
	 */
	public function test_fresh_running_claim_blocks() {
		wp_set_current_user( $this->create_installer_user() );
		update_option( Plugin_Installer::HANDLED_OPTION, 'running:' . time(), false );
		$attempts = $this->count_attempts( true );

		( new Plugin_Installer() )->maybe_install_and_activate();

		$this->assertSame( 0, $attempts(), 'A fresh claim means another request is installing right now.' );
	}

	/**
	 * Tests that a filter blocking the install is recorded as a failure, not success.
	 */
	public function test_filter_false_records_failure() {
		wp_set_current_user( $this->create_installer_user() );
		add_filter( 'wpai_pre_mcp_adapter_autoinstall', '__return_false' );

		( new Plugin_Installer() )->maybe_install_and_activate();

		$state = Plugin_Installer::get_state();
		$this->assertTrue( $state['autoinstall_handled'] );
		$this->assertNotNull( $state['autoinstall_error'], 'A blocked install must not be presented as success.' );
	}

	/**
	 * Tests that no attempt runs during AJAX requests.
	 */
	public function test_no_attempt_during_ajax() {
		wp_set_current_user( $this->create_installer_user() );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$attempts = $this->count_attempts( true );

		( new Plugin_Installer() )->maybe_install_and_activate();

		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertSame( 0, $attempts(), 'Blocking installs must not run inside AJAX/heartbeat requests.' );
		$this->assertFalse( get_option( Plugin_Installer::HANDLED_OPTION ), 'An AJAX request must not consume the attempt.' );
	}

	/**
	 * Tests that a failure without a message still reads as a failure.
	 */
	public function test_empty_error_message_still_reports_failure() {
		wp_set_current_user( $this->create_installer_user() );
		$this->count_attempts( new \WP_Error( 'install_failed' ) );

		( new Plugin_Installer() )->maybe_install_and_activate();

		$state = Plugin_Installer::get_state();
		$this->assertTrue( $state['autoinstall_handled'] );
		$this->assertNotNull( $state['autoinstall_error'], 'A failure with an empty message must not be presented as success.' );
	}

	/**
	 * Tests that disabling the experiment re-arms the attempt.
	 */
	public function test_disabling_experiment_resets_handled() {
		wp_set_current_user( $this->create_installer_user() );
		$attempts = $this->count_attempts( true );

		$installer = new Plugin_Installer();
		$installer->init();
		$installer->maybe_install_and_activate();

		do_action( 'update_option_wpai_feature_mcp-adapter_enabled', '1', '', 'wpai_feature_mcp-adapter_enabled' );
		$installer->maybe_install_and_activate();

		$this->assertSame( 2, $attempts(), 'Toggling the experiment off must re-arm the attempt for the next enable.' );
	}

	/**
	 * Tests that a truthy re-save of the experiment option keeps the cycle handled.
	 */
	public function test_enabled_resave_keeps_handled() {
		wp_set_current_user( $this->create_installer_user() );
		$attempts = $this->count_attempts( true );

		$installer = new Plugin_Installer();
		$installer->init();
		$installer->maybe_install_and_activate();

		do_action( 'update_option_wpai_feature_mcp-adapter_enabled', '1', '1', 'wpai_feature_mcp-adapter_enabled' );
		$installer->maybe_install_and_activate();

		$this->assertSame( 1, $attempts(), 'A truthy re-save is not a disable and must not re-arm the attempt.' );
	}
}

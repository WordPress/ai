<?php
/**
 * Integration tests for the Admin_Notice class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Connector_Approval
 */

namespace WordPress\AI\Tests\Integration\Includes\Connector_Approval;

use WP_UnitTestCase;
use WordPress\AI\Connector_Approval\Admin_Notice;
use WordPress\AI\Connector_Approval\Approvals_Store;

/**
 * Admin_Notice test case.
 *
 * @since 1.0.0
 */
class Admin_NoticeTest extends WP_UnitTestCase {
	/**
	 * Store instance under test.
	 *
	 * @since 1.0.0
	 *
	 * @var \WordPress\AI\Connector_Approval\Approvals_Store
	 */
	private Approvals_Store $store;

	/**
	 * Set up test case.
	 *
	 * @since 1.0.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->store = new Approvals_Store();
		delete_option( 'wpai_connector_approval_checked_connectors' );
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.0.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( Approvals_Store::OPTION_APPROVALS );
		delete_option( Approvals_Store::OPTION_PENDING );
		delete_option( 'wpai_connector_approval_checked_connectors' );
		parent::tearDown();
	}

	/**
	 * Tests that the notice is not rendered on the Connector Approvals screen.
	 *
	 * @since 1.0.0
	 */
	public function test_render_skips_connector_approvals_screen(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->store->record_pending(
			array(
				'type'     => 'plugin',
				'basename' => 'example/example.php',
				'name'     => 'Example',
			),
			'openai'
		);

		set_current_screen( 'tools_page_ai-connector-approval' );

		$notice = new Admin_Notice(
			$this->store,
			static function (): string {
				return admin_url( 'tools.php?page=ai-connector-approval' );
			}
		);

		ob_start();
		$notice->render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Tests that the notice does not include the inline class on other pages.
	 *
	 * @since 1.0.1
	 */
	public function test_render_does_not_use_inline_class_by_default(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->store->record_pending(
			array(
				'type'     => 'plugin',
				'basename' => 'example/example.php',
				'name'     => 'Example',
			),
			'openai'
		);

		set_current_screen( 'dashboard' );

		$notice = new Admin_Notice(
			$this->store,
			static function (): string {
				return admin_url( 'tools.php?page=ai-connector-approval' );
			}
		);

		ob_start();
		$notice->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="notice notice-warning ai-connector-approval-notice"', $output );
		$this->assertStringNotContainsString( 'inline', $output );
	}

	/**
	 * Tests that the notice includes the inline class on the Request Logs screen.
	 *
	 * @since 1.0.1
	 */
	public function test_render_uses_inline_class_on_request_logs_screen(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->store->record_pending(
			array(
				'type'     => 'plugin',
				'basename' => 'example/example.php',
				'name'     => 'Example',
			),
			'openai'
		);

		set_current_screen( 'tools_page_ai-request-logs' );

		$notice = new Admin_Notice(
			$this->store,
			static function (): string {
				return admin_url( 'tools.php?page=ai-connector-approval' );
			}
		);

		ob_start();
		$notice->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'class="notice notice-warning ai-connector-approval-notice inline"', $output );
	}

	/**
	 * Tests that flag_any_active_connectors flags unapproved active connectors.
	 *
	 * @since x.x.x
	 */
	public function test_flag_any_active_connectors(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Register a dummy connector.
		$registry = \WP_Connector_Registry::get_instance();
		$registry->register(
			'wpai_test_notice_provider',
			array(
				'name'           => 'Test Notice Provider',
				'description'    => 'Test provider.',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method'       => 'api_key',
					'setting_name' => 'wpai_test_notice_key',
				),
			)
		);
		update_option( 'wpai_test_notice_key', 'some-credential' );

		$ai_basename = plugin_basename( WPAI_PLUGIN_FILE );

		$notice = new Admin_Notice(
			$this->store,
			static function (): string {
				return admin_url( 'tools.php?page=ai-connector-approval' );
			}
		);
		$notice->flag_any_active_connectors();

		$pending     = $this->store->get_pending();
		$key         = $this->store->pending_key( $ai_basename, 'wpai_test_notice_provider' );

		$this->assertArrayHasKey( $key, $pending );
		$this->assertSame( 'wpai_test_notice_provider', $pending[ $key ]['connector_id'] );

		// Clean up connector registry.
		$registry->unregister( 'wpai_test_notice_provider' );
		delete_option( 'wpai_test_notice_key' );
	}
}

<?php
/**
 * Integration tests for the Admin_Notice class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Connector_Approval
 */

namespace WordPress\AI\Tests\Integration\Includes\Connector_Approval;

use WPDieException;
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
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.0.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $_GET['wpai_ca_notice_dismiss'], $_GET['_wpnonce'] );
		remove_all_filters( 'wp_redirect' );
		delete_option( Approvals_Store::OPTION_APPROVALS );
		delete_option( Approvals_Store::OPTION_PENDING );
		parent::tearDown();
	}

	/**
	 * Tests that register() attaches the admin_init and admin_notices hooks.
	 *
	 * @since x.x.x
	 */
	public function test_register_adds_admin_hooks(): void {
		$notice = new Admin_Notice(
			$this->store,
			static function (): string {
				return admin_url( 'tools.php?page=ai-connector-approval' );
			}
		);

		$notice->register();

		$this->assertSame( 10, has_action( 'admin_init', array( $notice, 'maybe_handle_dismiss' ) ) );
		$this->assertSame( 10, has_action( 'admin_notices', array( $notice, 'render' ) ) );

		remove_action( 'admin_init', array( $notice, 'maybe_handle_dismiss' ) );
		remove_action( 'admin_notices', array( $notice, 'render' ) );
	}

	/**
	 * Tests that the notice is not rendered for users lacking manage_options.
	 *
	 * @since x.x.x
	 */
	public function test_render_skips_users_without_manage_options(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

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

		$this->assertSame( '', $output );
	}

	/**
	 * Tests that the notice is not rendered when the pending queue is empty.
	 *
	 * @since x.x.x
	 */
	public function test_render_skips_when_pending_queue_is_empty(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

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

		$this->assertSame( '', $output );
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
	 * Tests that render() outputs singular and plural messages along with review and dismiss links.
	 *
	 * @since x.x.x
	 */
	public function test_render_outputs_singular_and_plural_messages_with_links(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		set_current_screen( 'dashboard' );

		$this->store->record_pending(
			array(
				'type'     => 'plugin',
				'basename' => 'example-one/example-one.php',
				'name'     => 'Example One',
			),
			'openai'
		);

		$notice = new Admin_Notice(
			$this->store,
			static function (): string {
				return admin_url( 'tools.php?page=ai-connector-approval' );
			}
		);

		ob_start();
		$notice->render();
		$singular_output = (string) ob_get_clean();

		$this->assertStringContainsString( '1 plugin or theme is requesting access to an AI connector.', $singular_output );
		$this->assertStringContainsString( 'tools.php?page=ai-connector-approval', $singular_output );
		$this->assertStringContainsString( 'wpai_ca_notice_dismiss=1', $singular_output );

		$this->store->record_pending(
			array(
				'type'     => 'plugin',
				'basename' => 'example-two/example-two.php',
				'name'     => 'Example Two',
			),
			'google'
		);

		ob_start();
		$notice->render();
		$plural_output = (string) ob_get_clean();

		$this->assertStringContainsString( '2 plugins or themes are requesting access to AI connectors.', $plural_output );
	}

	/**
	 * Tests that maybe_handle_dismiss() bails when the dismiss query arg is absent.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_handle_dismiss_bails_without_query_arg(): void {
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

		$notice = new Admin_Notice(
			$this->store,
			static function (): string {
				return admin_url( 'tools.php?page=ai-connector-approval' );
			}
		);

		$notice->maybe_handle_dismiss();

		$this->assertSame( '', get_user_meta( $admin_id, 'wpai_connector_approval_notice_dismissed', true ) );
	}

	/**
	 * Tests that maybe_handle_dismiss() bails when the user lacks manage_options.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_handle_dismiss_bails_without_manage_options(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$_GET['wpai_ca_notice_dismiss'] = '1';
		$_GET['_wpnonce']               = wp_create_nonce( 'wpai_ca_notice' );

		$notice = new Admin_Notice(
			$this->store,
			static function (): string {
				return admin_url( 'tools.php?page=ai-connector-approval' );
			}
		);

		$notice->maybe_handle_dismiss();

		$this->assertSame( '', get_user_meta( $subscriber_id, 'wpai_connector_approval_notice_dismissed', true ) );
	}

	/**
	 * Tests that maybe_handle_dismiss() bails when the nonce is invalid.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_handle_dismiss_bails_with_invalid_nonce(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_GET['wpai_ca_notice_dismiss'] = '1';
		$_GET['_wpnonce']               = 'invalid-nonce';

		$notice = new Admin_Notice(
			$this->store,
			static function (): string {
				return admin_url( 'tools.php?page=ai-connector-approval' );
			}
		);

		$notice->maybe_handle_dismiss();

		$this->assertSame( '', get_user_meta( $admin_id, 'wpai_connector_approval_notice_dismissed', true ) );
	}

	/**
	 * Tests that maybe_handle_dismiss() stores the pending signature and silences the notice until the queue changes.
	 *
	 * @since x.x.x
	 */
	public function test_maybe_handle_dismiss_stores_signature_and_silences_notice_until_queue_changes(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		set_current_screen( 'dashboard' );

		$this->store->record_pending(
			array(
				'type'     => 'plugin',
				'basename' => 'example-one/example-one.php',
				'name'     => 'Example One',
			),
			'openai'
		);

		$_GET['wpai_ca_notice_dismiss'] = '1';
		$_GET['_wpnonce']               = wp_create_nonce( 'wpai_ca_notice' );

		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new WPDieException( esc_html( $location ) );
			}
		);

		$notice = new Admin_Notice(
			$this->store,
			static function (): string {
				return admin_url( 'tools.php?page=ai-connector-approval' );
			}
		);

		try {
			$notice->maybe_handle_dismiss();
			$this->fail( 'Expected wp_safe_redirect to be intercepted.' );
		} catch ( WPDieException $e ) {
			$this->assertStringNotContainsString( 'wpai_ca_notice_dismiss', $e->getMessage() );
		}

		$dismissed_signature = (string) get_user_meta( $admin_id, 'wpai_connector_approval_notice_dismissed', true );
		$this->assertNotSame( '', $dismissed_signature );

		ob_start();
		$notice->render();
		$output_after_dismiss = (string) ob_get_clean();

		$this->assertSame( '', $output_after_dismiss );

		// Recording a new pending item changes the signature and causes the notice to reappear.
		$this->store->record_pending(
			array(
				'type'     => 'plugin',
				'basename' => 'example-two/example-two.php',
				'name'     => 'Example Two',
			),
			'google'
		);

		ob_start();
		$notice->render();
		$output_after_new_pending = (string) ob_get_clean();

		$this->assertStringContainsString( '2 plugins or themes are requesting access to AI connectors.', $output_after_new_pending );
	}
}

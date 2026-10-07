<?php
/**
 * Integration tests for the Connector_Approval Admin_Page class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Connector_Approval
 */

declare( strict_types=1 );

namespace WordPress\AI\Tests\Integration\Experiments\Connector_Approval;

use WPDieException;
use WP_UnitTestCase;
use WordPress\AI\Experiments\Connector_Approval\Admin_Page;

/**
 * Connector_Approval Admin_Page test case.
 *
 * @covers \WordPress\AI\Experiments\Connector_Approval\Admin_Page
 *
 * @since x.x.x
 */
class Admin_PageTest extends WP_UnitTestCase {

	/**
	 * Admin page instance under test.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Experiments\Connector_Approval\Admin_Page
	 */
	private Admin_Page $admin_page;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_page = new Admin_Page();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_action( 'admin_menu', array( $this->admin_page, 'add_submenu' ) );
		remove_action( 'admin_enqueue_scripts', array( $this->admin_page, 'enqueue_assets' ) );
		wp_deregister_script( 'ai_connector_approval' );
		wp_dequeue_script( 'ai_connector_approval' );
		wp_deregister_style( 'ai_connector_approval' );
		wp_dequeue_style( 'ai_connector_approval' );

		parent::tearDown();
	}

	/**
	 * Tests that url() returns the expected Tools submenu URL.
	 *
	 * @since x.x.x
	 */
	public function test_url_returns_expected_admin_url(): void {
		$this->assertSame(
			admin_url( 'tools.php?page=' . Admin_Page::PAGE_SLUG ),
			Admin_Page::url()
		);
	}

	/**
	 * Tests that register() hooks menu and asset callbacks.
	 *
	 * @since x.x.x
	 */
	public function test_register_adds_admin_hooks(): void {
		$this->admin_page->register();

		$this->assertSame( 10, has_action( 'admin_menu', array( $this->admin_page, 'add_submenu' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $this->admin_page, 'enqueue_assets' ) ) );
	}

	/**
	 * Tests that add_submenu() registers the Connector Approvals page under Tools.
	 *
	 * @since x.x.x
	 */
	public function test_add_submenu_registers_tools_submenu_for_administrator(): void {
		global $submenu;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->admin_page->add_submenu();

		$tools_submenus = $submenu['tools.php'] ?? array();
		$found          = false;

		foreach ( $tools_submenus as $item ) {
			if ( Admin_Page::PAGE_SLUG !== $item[2] ) {
				continue;
			}

			$found = true;
			$this->assertSame( 'Connector Approvals', $item[0] );
			$this->assertSame( 'manage_options', $item[1] );
			$this->assertSame( 'Connector Approvals', $item[3] );
			break;
		}

		$this->assertTrue( $found, 'Submenu item ai-connector-approval should be registered under tools.php.' );
	}

	/**
	 * Tests that enqueue_assets() bails when the hook suffix does not match.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_bails_on_non_matching_hook_suffix(): void {
		$this->admin_page->enqueue_assets( 'index.php' );

		$this->assertFalse( wp_script_is( 'ai_connector_approval', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'ai_connector_approval', 'enqueued' ) );
	}

	/**
	 * Tests that enqueue_assets() enqueues script, style, and localizes REST config on matching hook.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_enqueues_script_style_and_localizes_data_on_matching_hook(): void {
		$this->admin_page->enqueue_assets( 'tools_page_ai-connector-approval' );

		$this->assertTrue( wp_script_is( 'ai_connector_approval', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'ai_connector_approval', 'enqueued' ) );

		$data = wp_scripts()->get_data( 'ai_connector_approval', 'data' );

		$this->assertIsString( $data );
		$this->assertStringContainsString( 'aiConnectorApproval', $data );
		$this->assertStringContainsString( 'ai/v1/connector-approvals', $data );
		$this->assertStringContainsString( 'nonce', $data );
	}

	/**
	 * Tests that render() terminates with wp_die() when the user lacks manage_options.
	 *
	 * @since x.x.x
	 */
	public function test_render_requires_manage_options_capability(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->expectException( WPDieException::class );

		$this->admin_page->render();
	}

	/**
	 * Tests that render() outputs the page wrapper and mount root for administrators.
	 *
	 * @since x.x.x
	 */
	public function test_render_outputs_page_container_for_administrator(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		$this->admin_page->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="wrap">', $output );
		$this->assertStringContainsString( '<h1>Connector Approvals</h1>', $output );
		$this->assertStringContainsString( '<div id="ai-connector-approval-root"></div>', $output );
	}
}

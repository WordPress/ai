<?php
/**
 * Integration tests for the Admin_Page class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer
 */

namespace WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer;

use WP_UnitTestCase;
use WordPress\AI\Experiments\Abilities_Explorer\Admin_Page;

/**
 * Admin_Page test case.
 *
 * @since 0.2.0
 */
class Admin_PageTest extends WP_UnitTestCase {
	/**
	 * Admin page instance.
	 *
	 * @var \WordPress\AI\Experiments\Abilities_Explorer\Admin_Page
	 */
	private $admin_page;

	/**
	 * Set up test case.
	 *
	 * @since 0.2.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_page = new Admin_Page();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.2.0
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that admin menu is registered under Tools.
	 *
	 * @since 0.2.0
	 */
	public function test_admin_menu_is_registered() {
		global $submenu;

		// Log in as admin.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->admin_page->init();
		do_action( 'admin_menu' );

		// Abilities Explorer is a submenu under Tools; submenu slugs are at index 2.
		$tools_submenus = $submenu['tools.php'] ?? array();
		$submenu_slugs  = array_column( $tools_submenus, 2 );
		$this->assertContains( 'ai-abilities-explorer', $submenu_slugs );
	}

	/**
	 * Test that AJAX action is registered.
	 *
	 * @since 0.2.0
	 */
	public function test_ajax_action_is_registered() {
		$this->admin_page->init();

		$this->assertTrue( has_action( 'wp_ajax_ai_ability_explorer_invoke' ) !== false );
	}

	/**
	 * Test that page render requires manage_options capability.
	 *
	 * @since 0.2.0
	 */
	public function test_render_page_requires_manage_options() {
		// Log in as subscriber (no manage_options).
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->expectException( \WPDieException::class );

		$this->admin_page->render_page();
	}

	/**
	 * Test that page renders for admin user.
	 *
	 * @since 0.2.0
	 */
	public function test_render_page_works_for_admin() {
		// Log in as admin.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		$this->admin_page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ability-explorer-wrap', $output );
		$this->assertStringContainsString( 'Abilities Explorer', $output );
	}
}

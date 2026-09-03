<?php
/**
 * Integration tests for the AI_Workspace experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\AI_Workspace
 */

namespace WordPress\AI\Tests\Integration\Experiments\AI_Workspace;

use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\AI_Workspace;
use WordPress\AI\Experiments\AI_Workspace\Admin_Page;
use WordPress\AI\Experiments\Experiment_Category;
use WordPress\AI\Features\Loader;
use WordPress\AI\Features\Registry;

/**
 * AI_Workspace experiment test case.
 *
 * @since x.x.x
 */
class AI_WorkspaceTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );

		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_ai-workspace_enabled', true );

		// Ensure a clean Tools submenu for reachability assertions.
		unset( $GLOBALS['submenu']['tools.php'] );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $GLOBALS['submenu']['tools.php'] );
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_ai-workspace_enabled' );
		remove_filter( 'wpai_pre_has_valid_credentials_check', '__return_true' );
		remove_all_filters( 'wpai_feature_ai-workspace_enabled' );
		parent::tearDown();
	}

	/**
	 * Boots the feature loader and returns the registry it populated.
	 *
	 * @since x.x.x
	 *
	 * @return \WordPress\AI\Features\Registry The populated registry.
	 */
	private function boot_loader(): Registry {
		$registry = new Registry();
		$loader   = new Loader( $registry );
		$loader->init();

		return $registry;
	}

	/**
	 * Returns the registered Tools submenu slugs.
	 *
	 * @since x.x.x
	 *
	 * @return array<int, string> The submenu slugs registered under Tools.
	 */
	private function get_tools_submenu_slugs(): array {
		$tools_submenus = $GLOBALS['submenu']['tools.php'] ?? array();

		return array_map( 'strval', array_column( $tools_submenus, 2 ) );
	}

	/**
	 * Test that the experiment exposes the expected metadata.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_metadata() {
		$experiment = new AI_Workspace();

		$this->assertSame( 'ai-workspace', $experiment->get_id() );
		$this->assertSame( 'AI Workspace', $experiment->get_label() );
		$this->assertNotEmpty( $experiment->get_description() );
		$this->assertSame( Experiment_Category::ADMIN, $experiment->get_category() );
		$this->assertSame( 'text_generation', $experiment->get_capability() );
	}

	/**
	 * Test that an enabled experiment registers the menu entry and renders the mount node.
	 *
	 * @since x.x.x
	 */
	public function test_enabled_experiment_registers_menu_and_renders_mount_node_for_administrator() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$registry   = $this->boot_loader();
		$experiment = $registry->get_feature( 'ai-workspace' );

		$this->assertInstanceOf( AI_Workspace::class, $experiment );
		$this->assertTrue( $experiment->is_enabled() );

		do_action( 'admin_menu' );

		$this->assertContains( Admin_Page::PAGE_SLUG, $this->get_tools_submenu_slugs() );

		ob_start();
		( new Admin_Page() )->render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="ai-workspace-root"', $output );
	}

	/**
	 * The DataViews stylesheet is enqueued for the transcript's results table.
	 *
	 * The bundled copy is used only where WordPress registers no `wp-dataviews`
	 * style of its own. It is enqueued directly rather than through
	 * {@see \WordPress\AI\Asset_Loader::enqueue_style()}, so no RTL sibling is
	 * ever looked for; this test would fail on the `_doing_it_wrong()` that path
	 * raises.
	 *
	 * @since x.x.x
	 */
	public function test_dataviews_style_is_enqueued_for_the_transcript_table() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$expected = ! wp_styles()->query( 'wp-dataviews' )
			&& file_exists( WPAI_PLUGIN_DIR . 'build/admin/dataviews.css' );

		( new Admin_Page() )->enqueue_assets();

		$this->assertSame( $expected, wp_style_is( 'ai-dataviews', 'enqueued' ) );
		$this->assertFalse(
			wp_styles()->get_data( 'ai-dataviews', 'rtl' ),
			'The style must not ask WordPress for an RTL sibling it does not ship.'
		);

		// The enqueue globals outlive a test, so this one leaves them as it found them.
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the enqueue registries between tests.
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting the enqueue registries between tests.
	}

	/**
	 * Test that a disabled experiment registers no menu entry.
	 *
	 * @since x.x.x
	 */
	public function test_disabled_experiment_registers_no_menu_entry() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		delete_option( 'wpai_feature_ai-workspace_enabled' );

		$registry   = $this->boot_loader();
		$experiment = $registry->get_feature( 'ai-workspace' );

		$this->assertInstanceOf( AI_Workspace::class, $experiment );
		$this->assertFalse( $experiment->is_enabled() );

		do_action( 'admin_menu' );

		$this->assertNotContains( Admin_Page::PAGE_SLUG, $this->get_tools_submenu_slugs() );
	}

	/**
	 * Test that the global experiments toggle suppresses registration.
	 *
	 * @since x.x.x
	 */
	public function test_global_toggle_off_prevents_registration() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		delete_option( 'wpai_features_enabled' );

		$registry   = $this->boot_loader();
		$experiment = $registry->get_feature( 'ai-workspace' );

		$this->assertInstanceOf( AI_Workspace::class, $experiment );
		$this->assertFalse( $experiment->is_enabled() );

		do_action( 'admin_menu' );

		$this->assertNotContains( Admin_Page::PAGE_SLUG, $this->get_tools_submenu_slugs() );
	}

	/**
	 * Test that a subscriber requesting the page slug gets a capability failure.
	 *
	 * @since x.x.x
	 */
	public function test_subscriber_receives_capability_failure() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );

		( new Admin_Page() )->render();
	}

	/**
	 * Test that a logged-out request emits neither the app shell nor localized data.
	 *
	 * @since x.x.x
	 */
	public function test_logged_out_request_emits_no_app_shell_or_localized_data() {
		wp_set_current_user( 0 );

		$page = new Admin_Page();

		$died = false;

		ob_start();
		try {
			$page->render();
		} catch ( \WPDieException $e ) {
			$died = true;
		}
		$output = (string) ob_get_clean();

		$this->assertTrue( $died, 'Rendering the workspace while logged out should have called wp_die().' );
		$this->assertStringNotContainsString( 'ai-workspace-root', $output );

		// Assets must not be enqueued for a user without the capability.
		$page->enqueue_assets();

		$this->assertFalse( wp_script_is( 'ai_workspace', 'enqueued' ) );
	}

	/**
	 * Test that the menu entry is not registered for a user without the capability.
	 *
	 * @since x.x.x
	 */
	public function test_menu_entry_is_not_registered_for_subscriber() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$registry   = $this->boot_loader();
		$experiment = $registry->get_feature( 'ai-workspace' );

		$this->assertInstanceOf( AI_Workspace::class, $experiment );
		$this->assertTrue( $experiment->is_enabled() );

		do_action( 'admin_menu' );

		$this->assertNotContains( Admin_Page::PAGE_SLUG, $this->get_tools_submenu_slugs() );
	}

	/**
	 * Test that the full-screen body class is applied only via this screen's load hook.
	 *
	 * @since x.x.x
	 */
	public function test_full_screen_body_class_is_applied() {
		$page = new Admin_Page();

		$this->assertFalse( has_filter( 'admin_body_class', array( $page, 'add_body_class' ) ) );

		$page->on_load();

		$this->assertNotFalse( has_filter( 'admin_body_class', array( $page, 'add_body_class' ) ) );
		$this->assertStringContainsString( 'is-fullscreen-mode', $page->add_body_class( 'wp-admin ' ) );

		remove_filter( 'admin_body_class', array( $page, 'add_body_class' ) );
		remove_action( 'admin_enqueue_scripts', array( $page, 'enqueue_assets' ) );
	}
}

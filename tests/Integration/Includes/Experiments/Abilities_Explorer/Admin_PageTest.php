<?php
/**
 * Integration tests for the Admin_Page class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer
 */

namespace WordPress\AI\Tests\Integration\Experiments\Abilities_Explorer;

use WP_UnitTestCase;
use WordPress\AI\Experiments\AI_Workspace\Tool_Policy;
use WordPress\AI\Experiments\Abilities_Explorer\Admin_Page;
use WordPress\AI\Experiments\Abilities_Explorer\REST\Abilities_Controller;

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
		wp_dequeue_script( 'ai_abilities_explorer' );
		wp_deregister_script( 'ai_abilities_explorer' );
		wp_dequeue_style( 'ai_abilities_explorer' );
		wp_deregister_style( 'ai_abilities_explorer' );
		wp_dequeue_style( 'ai-dataviews' );
		wp_deregister_style( 'ai-dataviews' );
		wp_deregister_style( 'wp-dataviews' );
		remove_all_actions( 'admin_enqueue_scripts' );

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

		$this->log_in_as( 'administrator' );

		$this->admin_page->init();
		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

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
	 * The screen's load hook registers the help tabs and the asset loader.
	 *
	 * @since x.x.x
	 */
	public function test_load_hook_registers_help_tabs_and_assets() {
		$this->log_in_as( 'administrator' );

		$this->admin_page->add_admin_menu();

		$hook = get_plugin_page_hookname( Admin_Page::PAGE_SLUG, 'tools.php' );

		$this->assertSame( 10, has_action( "load-{$hook}", array( $this->admin_page, 'add_help_tabs' ) ) );
		$this->assertSame( 10, has_action( "load-{$hook}", array( $this->admin_page, 'on_load' ) ) );
	}

	/**
	 * Nothing is enqueued globally: assets are hooked only once the Explorer screen loads.
	 *
	 * @since x.x.x
	 */
	public function test_assets_are_hooked_only_when_the_screen_loads() {
		$this->log_in_as( 'administrator' );

		$this->admin_page->init();
		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$this->assertFalse( has_action( 'admin_enqueue_scripts', array( $this->admin_page, 'enqueue_assets' ) ) );

		$this->admin_page->on_load();

		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $this->admin_page, 'enqueue_assets' ) ) );
	}

	/**
	 * The bundle, its stylesheet and the DataViews stylesheet fallback enqueue for an administrator.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_enqueues_bundle_and_dataviews_fallback() {
		$this->log_in_as( 'administrator' );

		$this->admin_page->enqueue_assets();

		$this->assertTrue( wp_script_is( 'ai_abilities_explorer', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'ai_abilities_explorer', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'ai-dataviews', 'enqueued' ) );
	}

	/**
	 * The bundled DataViews stylesheet is skipped when WordPress registers its own.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_skips_dataviews_fallback_when_core_registers_it() {
		$this->log_in_as( 'administrator' );
		wp_register_style( 'wp-dataviews', 'https://example.com/dataviews.css', array(), '1.0.0' );

		$this->admin_page->enqueue_assets();

		$this->assertTrue( wp_script_is( 'ai_abilities_explorer', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'ai-dataviews', 'enqueued' ) );
	}

	/**
	 * Nothing is enqueued for a user without `manage_options`.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_does_nothing_for_an_editor() {
		$this->log_in_as( 'editor' );

		$this->admin_page->enqueue_assets();

		$this->assertFalse( wp_script_is( 'ai_abilities_explorer', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'ai-dataviews', 'enqueued' ) );
	}

	/**
	 * The localized route map carries exactly the controller's four route constants.
	 *
	 * @since x.x.x
	 */
	public function test_localized_settings_route_map_matches_controller_constants() {
		$this->log_in_as( 'administrator' );

		$this->admin_page->enqueue_assets();

		$settings = $this->localized_settings();

		$this->assertSame(
			array(
				'abilities' => Abilities_Controller::ABILITIES_ROUTE,
				'item'      => Abilities_Controller::ITEM_ROUTE,
				'invoke'    => Abilities_Controller::INVOKE_ROUTE,
				'surface'   => Abilities_Controller::SURFACE_ROUTE,
			),
			$settings['rest']['routes']
		);
		$this->assertSame( wp_create_nonce( 'wp_rest' ), $settings['rest']['nonce'] );
		$this->assertSame( esc_url_raw( rest_url() ), $settings['rest']['root'] );
		$this->assertSame( Admin_Page::PAGE_SLUG, $settings['pageSlug'] );
	}

	/**
	 * The localized settings carry pre-translated reason and provider labels.
	 *
	 * @since x.x.x
	 */
	public function test_localized_settings_carry_reason_and_provider_labels() {
		$this->log_in_as( 'administrator' );

		$this->admin_page->enqueue_assets();

		$settings = $this->localized_settings();

		$this->assertSame(
			'You removed this ability from the assistant.',
			$settings['surfaceReasonLabels'][ Tool_Policy::REASON_OWNER_EXCLUDED ]
		);
		$this->assertCount( 8, $settings['surfaceReasonLabels'] );
		$this->assertSame(
			array(
				'Core'   => 'Core',
				'Plugin' => 'Plugin',
				'Theme'  => 'Theme',
			),
			$settings['providerLabels']
		);
	}

	/**
	 * The page renders only the React root for an administrator, with no heading of its own.
	 *
	 * @since 0.2.0
	 */
	public function test_render_page_works_for_admin() {
		$this->log_in_as( 'administrator' );

		$output = $this->render();

		$this->assertStringContainsString( '<div id="ai-abilities-explorer-root"></div>', $output );
		$this->assertStringContainsString( 'ability-explorer-wrap', $output );
		// The admin-ui Page component provides the single h1.
		$this->assertStringNotContainsString( '<h1', $output );
		// The legacy PHP views no longer render.
		$this->assertStringNotContainsString( 'ability-explorer-stats', $output );
		$this->assertStringNotContainsString( '<table', $output );
	}

	/**
	 * A deep link to a view still renders only the React root.
	 *
	 * @since x.x.x
	 */
	public function test_render_page_ignores_action_for_server_render() {
		$this->log_in_as( 'administrator' );

		$_GET['action']  = 'test';
		$_GET['ability'] = 'core/get-site-info';

		try {
			$output = $this->render();
		} finally {
			unset( $_GET['action'], $_GET['ability'] );
		}

		$this->assertSame( '<div class="wrap ability-explorer-wrap"><div id="ai-abilities-explorer-root"></div></div>', trim( $output ) );
	}

	/**
	 * The page renders nothing for an editor.
	 *
	 * @since x.x.x
	 */
	public function test_render_page_outputs_nothing_for_editor() {
		$this->log_in_as( 'editor' );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * The page renders nothing for a logged-out visitor.
	 *
	 * @since x.x.x
	 */
	public function test_render_page_outputs_nothing_when_logged_out() {
		wp_set_current_user( 0 );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * The Help tabs still register on the screen.
	 *
	 * @since x.x.x
	 */
	public function test_help_tabs_register_on_screen() {
		$this->log_in_as( 'administrator' );

		set_current_screen( 'tools_page_ai-abilities-explorer' );

		try {
			$this->admin_page->add_help_tabs();

			$screen = get_current_screen();
			$this->assertNotNull( $screen );

			$tabs = $screen->get_help_tabs();
			$this->assertSame(
				array( 'abilities-overview', 'abilities-providers', 'abilities-testing' ),
				array_keys( $tabs )
			);
			$this->assertStringContainsString( 'Abilities API Documentation', $screen->get_help_sidebar() );
		} finally {
			set_current_screen( 'front' );
		}
	}

	/**
	 * Logs in a new user with the given role.
	 *
	 * @param string $role The role.
	 */
	private function log_in_as( string $role ): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => $role ) ) );
	}

	/**
	 * Renders the page and returns its output.
	 *
	 * @return string The output.
	 */
	private function render(): string {
		ob_start();
		$this->admin_page->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * Decodes the settings localized onto the Explorer script.
	 *
	 * @return array<string, mixed> The settings.
	 */
	private function localized_settings(): array {
		$data = wp_scripts()->get_data( 'ai_abilities_explorer', 'data' );

		$this->assertIsString( $data );
		$this->assertSame( 1, preg_match( '/var aiAbilitiesExplorer = (\{.*\});/s', $data, $matches ) );

		$settings = json_decode( $matches[1], true );
		$this->assertIsArray( $settings );

		return $settings;
	}
}

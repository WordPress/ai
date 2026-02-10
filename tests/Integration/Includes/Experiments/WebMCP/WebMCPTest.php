<?php
/**
 * Integration tests for the WebMCP experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments\WebMCP
 */

namespace WordPress\AI\Tests\Integration\Experiments\WebMCP;

use WP_UnitTestCase;
use WordPress\AI\Experiment_Registry;
use WordPress\AI\Experiments\WebMCP\WebMCP;

/**
 * WebMCP test case.
 *
 * @since 0.4.0
 */
class WebMCPTest extends WP_UnitTestCase {
	/**
	 * Experiment registry instance.
	 *
	 * @var \WordPress\AI\Experiment_Registry
	 */
	private $registry;

	/**
	 * Set up test case.
	 *
	 * @since 0.4.0
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'ai_experiments_enabled', true );
		update_option( 'ai_experiment_webmcp-adapter_enabled', true );

		$this->registry = new Experiment_Registry();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 0.4.0
	 */
	public function tearDown(): void {
		delete_option( 'ai_experiments_enabled' );
		delete_option( 'ai_experiment_webmcp-adapter_enabled' );
		delete_option( 'ai_experiment_webmcp-adapter_field_debug_panel_enabled' );
		remove_all_filters( 'ai_webmcp_adapter_allowed_hooks' );
		remove_all_filters( 'ai_webmcp_adapter_tool_names' );

		wp_dequeue_script( 'ai_webmcp_adapter' );
		wp_deregister_script( 'wp-hooks' );
		wp_deregister_script( 'wp-abilities' );
		wp_deregister_script( 'wp-core-abilities' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Tests experiment metadata.
	 *
	 * @since 0.4.0
	 */
	public function test_experiment_metadata() {
		$experiment = new WebMCP();

		$this->assertEquals( 'webmcp-adapter', $experiment->get_id() );
		$this->assertEquals( 'WebMCP Adapter', $experiment->get_label() );
		$this->assertNotEmpty( $experiment->get_description() );
	}

	/**
	 * Tests assets are not enqueued on unsupported screens.
	 *
	 * @since 0.4.0
	 */
	public function test_assets_not_enqueued_on_unsupported_screen() {
		$experiment = new WebMCP();

		wp_register_script( 'wp-hooks', '', array(), '1.0.0', true );
		wp_register_script( 'wp-abilities', '', array(), '1.0.0', true );
		wp_register_script( 'wp-core-abilities', '', array(), '1.0.0', true );

		$experiment->enqueue_assets( 'plugins.php' );

		$this->assertFalse( wp_script_is( 'ai_webmcp_adapter', 'enqueued' ) );
	}

	/**
	 * Tests assets are enqueued even when abilities handles are missing.
	 *
	 * @since 0.4.0
	 */
	public function test_assets_enqueued_when_dependencies_missing() {
		$experiment = new WebMCP();

		$experiment->enqueue_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'ai_webmcp_adapter', 'enqueued' ) );

		$script = wp_scripts()->registered['ai_webmcp_adapter'] ?? null;

		$this->assertNotNull( $script );
		$this->assertIsArray( $script->deps );
		$this->assertNotContains( 'wp-abilities', $script->deps );
		$this->assertNotContains( 'wp-core-abilities', $script->deps );
	}

	/**
	 * Tests assets are enqueued on Gutenberg compatibility hooks.
	 *
	 * @since 0.4.0
	 */
	public function test_assets_enqueued_on_gutenberg_compat_hook() {
		$experiment = new WebMCP();

		$experiment->enqueue_assets( 'appearance_page_gutenberg-edit-site' );

		$this->assertTrue( wp_script_is( 'ai_webmcp_adapter', 'enqueued' ) );
	}

	/**
	 * Tests assets are enqueued and localized when dependencies are available.
	 *
	 * @since 0.4.0
	 */
	public function test_assets_enqueued_with_localized_context() {
		$experiment = new WebMCP();
		update_option( 'ai_experiment_webmcp-adapter_field_debug_panel_enabled', true );

		wp_register_script( 'wp-hooks', '', array(), '1.0.0', true );
		wp_register_script( 'wp-abilities', '', array(), '1.0.0', true );
		wp_register_script( 'wp-core-abilities', '', array(), '1.0.0', true );

		set_current_screen( 'post' );
		$experiment->enqueue_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'ai_webmcp_adapter', 'enqueued' ) );

		$script_data = wp_scripts()->get_data( 'ai_webmcp_adapter', 'data' );

		$this->assertIsString( $script_data );
		$this->assertStringContainsString( 'aiWebMCPAdapterData', $script_data );
		$this->assertStringContainsString(
			'"debugPanelEnabled":"1"',
			$script_data
		);
	}

	/**
	 * Tests custom debug panel setting registration.
	 *
	 * @since 0.4.0
	 */
	public function test_register_settings_registers_debug_panel_setting() {
		$experiment = new WebMCP();
		$experiment->register_settings();

		global $wp_registered_settings;

		$this->assertArrayHasKey(
			'ai_experiment_webmcp-adapter_field_debug_panel_enabled',
			$wp_registered_settings
		);
	}

	/**
	 * Tests custom debug panel setting field rendering.
	 *
	 * @since 0.4.0
	 */
	public function test_render_settings_fields_outputs_debug_panel_toggle() {
		$experiment = new WebMCP();

		ob_start();
		$experiment->render_settings_fields();
		$output = ob_get_clean();

		$this->assertIsString( $output );
		$this->assertStringContainsString(
			'Enable WebMCP debug panel',
			$output
		);
		$this->assertStringContainsString(
			'ai_experiment_webmcp-adapter_field_debug_panel_enabled',
			$output
		);
	}

	/**
	 * Tests the experiment exposes settings UI in the admin page.
	 *
	 * @since 0.4.0
	 */
	public function test_has_settings_returns_true() {
		$experiment = new WebMCP();

		$this->assertTrue( $experiment->has_settings() );
	}

	/**
	 * Tests allowed hooks filter can prevent enqueuing.
	 *
	 * @since 0.4.0
	 */
	public function test_allowed_hooks_filter_can_prevent_enqueue() {
		$experiment = new WebMCP();
		$callback   = static function () {
			return array();
		};

		add_filter(
			'ai_webmcp_adapter_allowed_hooks',
			$callback
		);

		$experiment->enqueue_assets( 'post.php' );
		$this->assertFalse( wp_script_is( 'ai_webmcp_adapter', 'enqueued' ) );

		remove_filter( 'ai_webmcp_adapter_allowed_hooks', $callback );
	}

	/**
	 * Tests tool name filter applies custom names and preserves defaults on invalid values.
	 *
	 * @since 0.4.0
	 */
	public function test_tool_name_filter_applies_with_fallbacks() {
		$experiment = new WebMCP();
		$callback   = static function () {
			return array(
				'discover' => 'custom-discover',
				'info'     => '',
				'execute'  => 'custom-execute',
			);
		};

		add_filter(
			'ai_webmcp_adapter_tool_names',
			$callback
		);

		$experiment->enqueue_assets( 'post.php' );

		$script_data = wp_scripts()->get_data( 'ai_webmcp_adapter', 'data' );
		$this->assertIsString( $script_data );
		$this->assertStringContainsString( '"discover":"custom-discover"', $script_data );
		$this->assertStringContainsString( '"info":"wp-get-ability-info"', $script_data );
		$this->assertStringContainsString( '"execute":"custom-execute"', $script_data );

		remove_filter( 'ai_webmcp_adapter_tool_names', $callback );
	}

	/**
	 * Tests experiment can be registered in registry.
	 *
	 * @since 0.4.0
	 */
	public function test_experiment_registration_in_registry() {
		$experiment = new WebMCP();
		$this->registry->register_experiment( $experiment );

		$this->assertTrue( $this->registry->has_experiment( 'webmcp-adapter' ) );

		$registered = $this->registry->get_experiment( 'webmcp-adapter' );
		$this->assertInstanceOf( WebMCP::class, $registered );
	}
}

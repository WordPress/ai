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
use WordPress\AI\Settings\Settings_Registration;

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
		remove_all_filters( 'ai_webmcp_adapter_is_available' );
		remove_all_filters( 'ai_experiments_pre_has_valid_credentials_check' );
		remove_all_filters( 'sanitize_option_ai_experiment_webmcp-adapter_enabled' );

		wp_dequeue_script( 'ai_webmcp_adapter' );
		wp_deregister_script( 'wp-hooks' );
		wp_deregister_script( 'wp-abilities' );
		wp_deregister_script( 'wp-core-abilities' );
		wp_set_current_user( 0 );
		unset( $_GET['page'] );

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
	 * Tests script dependencies omit unregistered ability handles.
	 *
	 * @since 0.4.0
	 */
	public function test_assets_enqueued_when_dependencies_missing() {
		$experiment = new WebMCP();
		$reflection = new \ReflectionClass( $experiment );
		$method     = $reflection->getMethod( 'get_script_dependencies' );
		$method->setAccessible( true );

		$dependencies = $method->invoke( $experiment );

		$this->assertIsArray( $dependencies );
		$this->assertNotContains( 'wp-abilities', $dependencies );
		$this->assertNotContains( 'wp-core-abilities', $dependencies );
	}

	/**
	 * Tests Gutenberg compatibility hook is allowed for enqueue decisions.
	 *
	 * @since 0.4.0
	 */
	public function test_assets_enqueued_on_gutenberg_compat_hook() {
		$experiment = new WebMCP();
		$reflection = new \ReflectionClass( $experiment );
		$method     = $reflection->getMethod( 'should_enqueue_for_hook' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $experiment, 'appearance_page_gutenberg-edit-site' ) );
	}

	/**
	 * Tests WordPress context payload includes expected keys and value types.
	 *
	 * @since 0.4.0
	 */
	public function test_assets_enqueued_with_localized_context() {
		$experiment = new WebMCP();
		$reflection = new \ReflectionClass( $experiment );
		$method     = $reflection->getMethod( 'get_wp_context' );
		$method->setAccessible( true );

		set_current_screen( 'post' );
		$context = $method->invoke( $experiment );

		$this->assertIsArray( $context );
		$this->assertArrayHasKey( 'screen', $context );
		$this->assertArrayHasKey( 'adminPage', $context );
		$this->assertArrayHasKey( 'postType', $context );
		$this->assertArrayHasKey( 'query', $context );
		$this->assertIsString( $context['screen'] );
		$this->assertIsString( $context['adminPage'] );
		$this->assertIsString( $context['postType'] );
		$this->assertIsArray( $context['query'] );
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
	 * Tests experiment cannot be enabled when dependencies are unavailable.
	 *
	 * @since 0.4.0
	 */
	public function test_experiment_is_disabled_when_unavailable() {
		$callback = static function () {
			return false;
		};
		add_filter( 'ai_webmcp_adapter_is_available', $callback );

		$experiment = new WebMCP();
		$this->assertFalse( $experiment->is_enabled() );

		remove_filter( 'ai_webmcp_adapter_is_available', $callback );
	}

	/**
	 * Tests enabled setting cannot be saved when dependencies are unavailable.
	 *
	 * @since 0.4.0
	 */
	public function test_enabled_setting_forced_off_when_unavailable() {
		$experiment = new WebMCP();
		$experiment->register_settings();

		$callback = static function () {
			return false;
		};
		add_filter( 'ai_webmcp_adapter_is_available', $callback );

		// phpcs:disable WordPress.NamingConventions.ValidHookName.UseUnderscores -- Option name contains a required hyphen from experiment ID.
		$sanitized_value = apply_filters(
			'sanitize_option_ai_experiment_webmcp-adapter_enabled',
			true,
			'ai_experiment_webmcp-adapter_enabled',
			true
		);
		// phpcs:enable WordPress.NamingConventions.ValidHookName.UseUnderscores

		$this->assertFalse( $sanitized_value );
		$errors = get_settings_errors( Settings_Registration::OPTION_GROUP );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'Abilities API', $errors[0]['message'] );

		remove_filter( 'ai_webmcp_adapter_is_available', $callback );
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
		$reflection = new \ReflectionClass( $experiment );
		$method     = $reflection->getMethod( 'get_tool_names' );
		$method->setAccessible( true );
		$callback = static function () {
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

		$tool_names = $method->invoke( $experiment );
		$this->assertIsArray( $tool_names );
		$this->assertSame( 'custom-discover', $tool_names['discover'] );
		$this->assertSame( 'wp-get-ability-info', $tool_names['info'] );
		$this->assertSame( 'custom-execute', $tool_names['execute'] );

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

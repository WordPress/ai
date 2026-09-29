<?php
/**
 * Integration tests for AI_Request_Log_Page.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Logging
 */

namespace WordPress\AI\Tests\Integration\Includes\Logging;

use ReflectionMethod;
use WP_Connector_Registry;
use WP_UnitTestCase;
use WordPress\AI\Logging\AI_Request_Log_Manager;
use WordPress\AI\Logging\AI_Request_Log_Page;

/**
 * AI_Request_Log_Page test case.
 *
 * @covers \WordPress\AI\Logging\AI_Request_Log_Page
 *
 * @since x.x.x
 */
class AI_Request_Log_PageTest extends WP_UnitTestCase {

	/**
	 * Log manager instance.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Manager
	 */
	private AI_Request_Log_Manager $manager;

	/**
	 * Page instance under test.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Logging\AI_Request_Log_Page
	 */
	private AI_Request_Log_Page $page;

	/**
	 * Registered connector IDs for cleanup.
	 *
	 * @since x.x.x
	 *
	 * @var string[]
	 */
	private array $test_connector_ids = array();

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	protected function setUp(): void {
		parent::setUp();

		delete_option( 'wpai_request_logs_schema_version' );

		$this->manager = new AI_Request_Log_Manager();
		$this->manager->init();
		$this->page = new AI_Request_Log_Page( $this->manager );
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		$registry = WP_Connector_Registry::get_instance();
		if ( null !== $registry ) {
			foreach ( $this->test_connector_ids as $connector_id ) {
				if ( ! $registry->is_registered( $connector_id ) ) {
					continue;
				}

				$registry->unregister( $connector_id );
			}
		}

		wp_deregister_script( 'ai_ai_request_logs' );
		wp_dequeue_script( 'ai_ai_request_logs' );
		wp_deregister_style( 'ai_ai_request_logs' );
		wp_dequeue_style( 'ai_ai_request_logs' );
		wp_deregister_style( 'ai-dataviews' );
		wp_dequeue_style( 'ai-dataviews' );
		wp_deregister_style( 'wp-dataviews' );

		parent::tearDown();
	}

	/**
	 * Tests that constructor creates an AI_Request_Log_Page instance.
	 *
	 * @since x.x.x
	 */
	public function test_constructor_initializes_instance(): void {
		$page = new AI_Request_Log_Page( $this->manager );

		$this->assertInstanceOf( AI_Request_Log_Page::class, $page );
	}

	/**
	 * Tests that register_menu() adds the Tools submenu page and load hook for authorized user.
	 *
	 * @since x.x.x
	 */
	public function test_register_menu_adds_submenu_page_for_authorized_user(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->page->register_menu();

		global $submenu;
		$this->assertArrayHasKey( 'tools.php', $submenu );

		$found = false;
		foreach ( $submenu['tools.php'] as $item ) {
			if ( 'ai-request-logs' === $item[2] ) {
				$found = true;
				$this->assertSame( 'AI Request Logs', $item[0] );
				$this->assertSame( 'manage_options', $item[1] );
				$this->assertSame( 'AI Request Logs', $item[3] );
				break;
			}
		}

		$this->assertTrue( $found, 'Submenu item ai-request-logs should be registered under tools.php.' );

		$hook = get_plugin_page_hookname( 'ai-request-logs', 'tools.php' );
		$this->assertSame( 10, has_action( "load-{$hook}", array( $this->page, 'on_load' ) ) );
	}

	/**
	 * Tests that register_menu() does not hook on_load when the current user lacks manage_options capability.
	 *
	 * @since x.x.x
	 */
	public function test_register_menu_does_not_hook_on_load_when_user_lacks_capability(): void {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->page->register_menu();

		$hook = get_plugin_page_hookname( 'ai-request-logs', 'tools.php' );
		$this->assertFalse( has_action( "load-{$hook}", array( $this->page, 'on_load' ) ) );
	}

	/**
	 * Tests that on_load() hooks enqueue_assets to admin_enqueue_scripts.
	 *
	 * @since x.x.x
	 */
	public function test_on_load_hooks_enqueue_assets_action(): void {
		$this->page->on_load();

		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $this->page, 'enqueue_assets' ) ) );
	}

	/**
	 * Tests that enqueue_assets() enqueues the script, styles, and dataviews fallback style.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_enqueues_scripts_and_styles(): void {
		$this->page->enqueue_assets();

		$this->assertTrue( wp_script_is( 'ai_ai_request_logs', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'ai_ai_request_logs', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'ai-dataviews', 'enqueued' ) );
	}

	/**
	 * Tests that enqueue_assets() does not enqueue bundled dataviews style if wp-dataviews is already registered.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_does_not_enqueue_dataviews_fallback_if_wp_dataviews_registered(): void {
		wp_register_style( 'wp-dataviews', 'https://example.com/dataviews.css', array(), '1.0.0' );

		$this->page->enqueue_assets();

		$this->assertFalse( wp_style_is( 'ai-dataviews', 'enqueued' ) );
	}

	/**
	 * Tests that enqueue_assets() localizes settings and REST routes for the script.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_assets_localizes_settings_data(): void {
		$this->page->enqueue_assets();

		$scripts = wp_scripts();
		$data    = $scripts->get_data( 'ai_ai_request_logs', 'data' );

		$this->assertIsString( $data );
		$this->assertStringContainsString( 'aiRequestLogsSettings', $data );
		$this->assertStringContainsString( 'ai/v1/logs', $data );
		$this->assertStringContainsString( 'ai/v1/logs/summary', $data );
		$this->assertStringContainsString( 'ai/v1/logs/filters', $data );
		$this->assertStringContainsString( 'options-connectors.php', $data );
		$this->assertStringContainsString( 'rest', $data );
		$this->assertStringContainsString( 'initialState', $data );
	}

	/**
	 * Tests that render_page() renders the root container for authorized users.
	 *
	 * @since x.x.x
	 */
	public function test_render_page_renders_container_for_authorized_user(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<div class="wrap ai-request-logs">', $output );
		$this->assertStringContainsString( '<div id="ai-request-logs-root"></div>', $output );
	}

	/**
	 * Tests that render_page() outputs nothing for unauthorized users.
	 *
	 * @since x.x.x
	 */
	public function test_render_page_outputs_nothing_for_unauthorized_user(): void {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Tests that render_page() outputs nothing when no user is logged in.
	 *
	 * @since x.x.x
	 */
	public function test_render_page_outputs_nothing_when_logged_out(): void {
		wp_set_current_user( 0 );

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Tests that get_provider_metadata() filters for AI provider connectors and formats metadata correctly.
	 *
	 * @since x.x.x
	 */
	public function test_get_provider_metadata_filters_and_formats_connectors(): void {
		$registry = WP_Connector_Registry::get_instance();
		if ( null === $registry ) {
			$this->markTestSkipped( 'WP_Connector_Registry is not available.' );
		}

		$this->register_test_connector(
			'test_cloud_provider',
			array(
				'name'           => 'Cloud AI Provider',
				'type'           => 'ai_provider',
				'logo_url'       => 'https://example.com/logo.png',
				'authentication' => array(
					'method'          => 'api_key',
					'credentials_url' => 'https://example.com/keys',
				),
			)
		);

		$this->register_test_connector(
			'test_client_provider',
			array(
				'name'           => 'Client AI Provider',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method' => 'none',
				),
			)
		);

		$this->register_test_connector(
			'test_storage_connector',
			array(
				'name'           => 'Storage Connector',
				'type'           => 'storage',
				'authentication' => array(
					'method' => 'none',
				),
			)
		);

		$reflection = new ReflectionMethod( AI_Request_Log_Page::class, 'get_provider_metadata' );
		$reflection->setAccessible( true );

		/**
		 * Metadata array returned by the reflection invocation.
		 *
		 * @var array<string, array<string, mixed>> $metadata
		 */
		$metadata = $reflection->invoke( $this->page );

		$this->assertArrayHasKey( 'test_cloud_provider', $metadata );
		$this->assertSame( 'test_cloud_provider', $metadata['test_cloud_provider']['id'] );
		$this->assertSame( 'Cloud AI Provider', $metadata['test_cloud_provider']['name'] );
		$this->assertSame( 'cloud', $metadata['test_cloud_provider']['type'] );
		$this->assertSame( 'https://example.com/logo.png', $metadata['test_cloud_provider']['logo'] );
		$this->assertSame( 'https://example.com/keys', $metadata['test_cloud_provider']['url'] );

		$this->assertArrayHasKey( 'test_client_provider', $metadata );
		$this->assertSame( 'test_client_provider', $metadata['test_client_provider']['id'] );
		$this->assertSame( 'Client AI Provider', $metadata['test_client_provider']['name'] );
		$this->assertSame( 'client', $metadata['test_client_provider']['type'] );
		$this->assertArrayNotHasKey( 'logo', $metadata['test_client_provider'] );
		$this->assertArrayNotHasKey( 'url', $metadata['test_client_provider'] );

		$this->assertArrayNotHasKey( 'test_storage_connector', $metadata );
	}

	/**
	 * Helper to register a test connector in the WP connector registry.
	 *
	 * @since x.x.x
	 *
	 * @param string               $connector_id Unique connector identifier.
	 * @param array<string, mixed> $data         Connector configuration data.
	 */
	private function register_test_connector( string $connector_id, array $data ): void {
		$registry = WP_Connector_Registry::get_instance();
		if ( null === $registry ) {
			return;
		}

		if ( $registry->is_registered( $connector_id ) ) {
			$registry->unregister( $connector_id );
		}

		$registry->register( $connector_id, $data );
		$this->test_connector_ids[] = $connector_id;
	}
}

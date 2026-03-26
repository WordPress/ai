<?php
/**
 * Tests for the AI_Service class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Services
 */

namespace WordPress\AI\Tests\Integration\Includes\Services;

use WP_UnitTestCase;
use WordPress\AI\Permissions\Permissions_Manager;
use WordPress\AI\Services\AI_Service;
use WordPress\AI\Settings\Settings_Registration;

use function WordPress\AI\get_ai_service;

/**
 * AI_Service test case.
 *
 * @since 0.2.1
 */
class AI_Service_Test extends WP_UnitTestCase {

	/**
	 * AI service instance.
	 *
	 * @var \WordPress\AI\Services\AI_Service
	 */
	private AI_Service $service;

	/**
	 * Setup test case.
	 *
	 * @since 0.2.1
	 */
	public function setUp(): void {
		parent::setUp();
		$this->service = AI_Service::get_instance();
	}

	/**
	 * Resets permission state and cleans up options after each test.
	 *
	 * @since 0.2.1
	 */
	public function tearDown(): void {
		// Reset the Permissions_Manager singleton so permission state does not bleed between tests.
		$reflection = new \ReflectionClass( Permissions_Manager::class );
		$prop       = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		delete_option( Settings_Registration::GLOBAL_OPTION );

		parent::tearDown();
	}

	/**
	 * Test singleton instance.
	 *
	 * @since 0.2.1
	 */
	public function test_get_instance_returns_singleton(): void {
		$instance1 = AI_Service::get_instance();
		$instance2 = AI_Service::get_instance();

		$this->assertSame( $instance1, $instance2, 'Should return the same instance' );
	}

	/**
	 * Test helper function returns service instance.
	 *
	 * @since 0.2.1
	 */
	public function test_get_ai_service_helper_returns_instance(): void {
		$service = get_ai_service();

		$this->assertInstanceOf( AI_Service::class, $service, 'Helper should return AI_Service instance' );
		$this->assertSame( $this->service, $service, 'Helper should return singleton instance' );
	}

	/**
	 * Test create_textgen_prompt returns prompt builder.
	 *
	 * @since 0.2.1
	 */
	public function test_create_textgen_prompt_returns_builder(): void {
		$builder = $this->service->create_textgen_prompt( 'Test prompt' );

		$this->assertInstanceOf(
			\WP_AI_Client_Prompt_Builder::class,
			$builder,
			'Should return WP_AI_Client_Prompt_Builder instance'
		);
	}

	/**
	 * Test create_textgen_prompt with options applies configuration.
	 *
	 * @since 0.2.1
	 */
	public function test_create_textgen_prompt_with_options(): void {
		$builder = $this->service->create_textgen_prompt(
			'Test prompt',
			array(
				'system_instruction' => 'You are helpful.',
				'temperature'        => 0.5,
				'max_tokens'         => 100,
			)
		);

		$this->assertInstanceOf(
			\WP_AI_Client_Prompt_Builder::class,
			$builder,
			'Should return WP_AI_Client_Prompt_Builder instance with options applied'
		);
	}

	// -------------------------------------------------------------------------
	// create_textgen_prompt_for_plugin
	// -------------------------------------------------------------------------

	/**
	 * Tests that create_textgen_prompt_for_plugin returns null when the plugin is not registered.
	 *
	 * @since 1.0.0
	 */
	public function test_create_textgen_prompt_for_plugin_returns_null_for_unregistered_plugin(): void {
		update_option( Settings_Registration::GLOBAL_OPTION, true );

		$builder = $this->service->create_textgen_prompt_for_plugin( 'unregistered-plugin', 'Hello' );

		$this->assertNull( $builder );
	}

	/**
	 * Tests that create_textgen_prompt_for_plugin returns null when global AI is disabled.
	 *
	 * @since 1.0.0
	 */
	public function test_create_textgen_prompt_for_plugin_returns_null_when_global_disabled(): void {
		update_option( Settings_Registration::GLOBAL_OPTION, false );

		$manager  = Permissions_Manager::get_instance();
		$registry = $manager->get_plugin_registry();
		$registry->register_plugin( 'my-plugin' );

		$plugin_key = $manager->sanitize_option_key( 'my-plugin' );
		update_option( Permissions_Manager::PLUGIN_ACCESS_OPTION_PREFIX . $plugin_key, true );

		$builder = $this->service->create_textgen_prompt_for_plugin( 'my-plugin', 'Hello' );

		$this->assertNull( $builder );
	}

	/**
	 * Tests that create_textgen_prompt_for_plugin returns null when the plugin has not been granted access.
	 *
	 * @since 1.0.0
	 */
	public function test_create_textgen_prompt_for_plugin_returns_null_when_access_not_granted(): void {
		update_option( Settings_Registration::GLOBAL_OPTION, true );

		$manager  = Permissions_Manager::get_instance();
		$registry = $manager->get_plugin_registry();
		$registry->register_plugin( 'my-plugin' );

		// Access option deliberately NOT set (defaults to false).
		$builder = $this->service->create_textgen_prompt_for_plugin( 'my-plugin', 'Hello' );

		$this->assertNull( $builder );
	}

	/**
	 * Tests that create_textgen_prompt_for_plugin returns a prompt builder when the plugin has been granted access.
	 *
	 * @since 1.0.0
	 */
	public function test_create_textgen_prompt_for_plugin_returns_builder_when_access_granted(): void {
		update_option( Settings_Registration::GLOBAL_OPTION, true );

		$manager  = Permissions_Manager::get_instance();
		$registry = $manager->get_plugin_registry();
		$registry->register_plugin( 'my-plugin' );

		$plugin_key = $manager->sanitize_option_key( 'my-plugin' );
		update_option( Permissions_Manager::PLUGIN_ACCESS_OPTION_PREFIX . $plugin_key, true );

		$builder = $this->service->create_textgen_prompt_for_plugin( 'my-plugin', 'Hello' );

		$this->assertInstanceOf( \WP_AI_Client_Prompt_Builder::class, $builder );
	}

	/**
	 * Tests that create_textgen_prompt_for_plugin accepts a null prompt argument.
	 *
	 * @since 1.0.0
	 */
	public function test_create_textgen_prompt_for_plugin_accepts_null_prompt(): void {
		update_option( Settings_Registration::GLOBAL_OPTION, true );

		$manager  = Permissions_Manager::get_instance();
		$registry = $manager->get_plugin_registry();
		$registry->register_plugin( 'my-plugin' );

		$plugin_key = $manager->sanitize_option_key( 'my-plugin' );
		update_option( Permissions_Manager::PLUGIN_ACCESS_OPTION_PREFIX . $plugin_key, true );

		$builder = $this->service->create_textgen_prompt_for_plugin( 'my-plugin' );

		$this->assertInstanceOf( \WP_AI_Client_Prompt_Builder::class, $builder );
	}

	/**
	 * Test ai_experiments_service_initialized action can be hooked.
	 *
	 * @since 0.2.1
	 */
	public function test_init_action_is_hookable(): void {
		$callback = static function () {};

		add_action( 'ai_experiments_service_initialized', $callback );

		// Verify callback was registered.
		$this->assertNotFalse(
			has_action( 'ai_experiments_service_initialized', $callback ),
			'Action should accept callbacks'
		);

		// Cleanup.
		remove_action( 'ai_experiments_service_initialized', $callback );
	}
}

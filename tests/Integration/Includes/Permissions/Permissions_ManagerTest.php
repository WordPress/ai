<?php
/**
 * Tests for the Permissions_Manager class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Permissions
 */

namespace WordPress\AI\Tests\Integration\Includes\Permissions;

use WP_UnitTestCase;
use WordPress\AI\Permissions\Permissions_Manager;
use WordPress\AI\Settings\Settings_Registration;

/**
 * Permissions_Manager test case.
 *
 * @covers \WordPress\AI\Permissions\Permissions_Manager
 * @since 1.0.0
 */
class Permissions_ManagerTest extends WP_UnitTestCase {

	/**
	 * Permissions manager instance under test.
	 *
	 * @since 1.0.0
	 * @var \WordPress\AI\Permissions\Permissions_Manager
	 */
	private Permissions_Manager $manager;

	/**
	 * Sets up a fresh manager instance before each test.
	 *
	 * @since 1.0.0
	 */
	public function setUp(): void {
		parent::setUp();
		$this->manager = Permissions_Manager::get_instance();
	}

	/**
	 * Resets singleton state and cleans up options after each test.
	 *
	 * @since 1.0.0
	 */
	public function tearDown(): void {
		$reflection = new \ReflectionClass( Permissions_Manager::class );
		$prop       = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		delete_option( Settings_Registration::GLOBAL_OPTION );

		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	/**
	 * Tests that get_instance returns the same object on repeated calls.
	 *
	 * @since 1.0.0
	 */
	public function test_get_instance_returns_singleton(): void {
		$a = Permissions_Manager::get_instance();
		$b = Permissions_Manager::get_instance();

		$this->assertSame( $a, $b );
	}

	// -------------------------------------------------------------------------
	// initialize / wpai_register_plugins action
	// -------------------------------------------------------------------------

	/**
	 * Tests that initialize() fires the wpai_register_plugins action with the registry.
	 *
	 * @since 1.0.0
	 */
	public function test_initialize_fires_register_plugins_action(): void {
		$received = null;
		$callback = function ( $registry ) use ( &$received ): void {
			$received = $registry;
		};

		add_action( 'wpai_register_plugins', $callback );
		$this->manager->initialize();
		remove_action( 'wpai_register_plugins', $callback );

		$this->assertInstanceOf(
			\WordPress\AI\Permissions\Plugin_Registry::class,
			$received,
			'wpai_register_plugins must receive a Plugin_Registry instance.'
		);
	}

	/**
	 * Tests that calling initialize() a second time does not fire the action again.
	 *
	 * @since 1.0.0
	 */
	public function test_initialize_is_idempotent(): void {
		$call_count = 0;
		$callback   = function () use ( &$call_count ): void {
			$call_count++;
		};

		add_action( 'wpai_register_plugins', $callback );
		$this->manager->initialize();
		$this->manager->initialize();
		remove_action( 'wpai_register_plugins', $callback );

		$this->assertSame( 1, $call_count );
	}

	// -------------------------------------------------------------------------
	// plugin_has_access — global toggle
	// -------------------------------------------------------------------------

	/**
	 * Tests that plugin_has_access returns false when the global toggle is off.
	 *
	 * @since 1.0.0
	 */
	public function test_plugin_has_access_returns_false_when_global_disabled(): void {
		update_option( Settings_Registration::GLOBAL_OPTION, false );

		$registry = $this->manager->get_plugin_registry();
		$registry->register_plugin( 'test-plugin' );

		$plugin_key = $this->manager->sanitize_option_key( 'test-plugin' );
		update_option( Permissions_Manager::PLUGIN_ACCESS_OPTION_PREFIX . $plugin_key, true );

		$this->assertFalse( $this->manager->plugin_has_access( 'test-plugin' ) );
	}

	/**
	 * Tests that plugin_has_access returns false for an unregistered plugin.
	 *
	 * @since 1.0.0
	 */
	public function test_plugin_has_access_returns_false_for_unregistered_plugin(): void {
		update_option( Settings_Registration::GLOBAL_OPTION, true );

		$this->assertFalse( $this->manager->plugin_has_access( 'unregistered-plugin' ) );
	}

	/**
	 * Tests that plugin_has_access defaults to false when the plugin has not been explicitly granted access.
	 *
	 * @since 1.0.0
	 */
	public function test_plugin_has_access_returns_false_when_not_granted(): void {
		update_option( Settings_Registration::GLOBAL_OPTION, true );

		$registry = $this->manager->get_plugin_registry();
		$registry->register_plugin( 'test-plugin' );

		$this->assertFalse( $this->manager->plugin_has_access( 'test-plugin' ) );
	}

	/**
	 * Tests that plugin_has_access returns true when global is on and the plugin option is granted.
	 *
	 * @since 1.0.0
	 */
	public function test_plugin_has_access_returns_true_when_granted(): void {
		update_option( Settings_Registration::GLOBAL_OPTION, true );

		$registry = $this->manager->get_plugin_registry();
		$registry->register_plugin( 'test-plugin' );

		$plugin_key = $this->manager->sanitize_option_key( 'test-plugin' );
		update_option( Permissions_Manager::PLUGIN_ACCESS_OPTION_PREFIX . $plugin_key, true );

		$this->assertTrue( $this->manager->plugin_has_access( 'test-plugin' ) );
	}

	/**
	 * Tests that the wpai_plugin_ai_access filter can override the access decision.
	 *
	 * @since 1.0.0
	 */
	public function test_plugin_has_access_respects_filter_override(): void {
		update_option( Settings_Registration::GLOBAL_OPTION, true );

		$registry = $this->manager->get_plugin_registry();
		$registry->register_plugin( 'test-plugin' );

		add_filter( 'wpai_plugin_ai_access', '__return_true' );
		$result = $this->manager->plugin_has_access( 'test-plugin' );
		remove_filter( 'wpai_plugin_ai_access', '__return_true' );

		$this->assertTrue( $result );
	}

	/**
	 * Tests that the wpai_plugin_ai_access filter receives the plugin_id as the second argument.
	 *
	 * @since 1.0.0
	 */
	public function test_plugin_has_access_filter_receives_plugin_id(): void {
		update_option( Settings_Registration::GLOBAL_OPTION, true );

		$registry = $this->manager->get_plugin_registry();
		$registry->register_plugin( 'test-plugin' );

		$received_id = null;
		$callback    = static function ( bool $access, string $plugin_id ) use ( &$received_id ): bool {
			$received_id = $plugin_id;
			return $access;
		};

		add_filter( 'wpai_plugin_ai_access', $callback, 10, 2 );
		$this->manager->plugin_has_access( 'test-plugin' );
		remove_filter( 'wpai_plugin_ai_access', $callback, 10 );

		$this->assertSame( 'test-plugin', $received_id );
	}

	// -------------------------------------------------------------------------
	// get_plugin_provider_preferences
	// -------------------------------------------------------------------------

	/**
	 * Tests that get_plugin_provider_preferences returns an empty array when no preference is stored.
	 *
	 * @since 1.0.0
	 */
	public function test_get_plugin_provider_preferences_returns_empty_by_default(): void {
		$registry = $this->manager->get_plugin_registry();
		$registry->register_plugin( 'test-plugin' );

		$this->assertSame( array(), $this->manager->get_plugin_provider_preferences( 'test-plugin' ) );
	}

	/**
	 * Tests that get_plugin_provider_preferences returns stored provider slugs as an ordered list.
	 *
	 * @since 1.0.0
	 */
	public function test_get_plugin_provider_preferences_returns_stored_slugs(): void {
		$plugin_key = $this->manager->sanitize_option_key( 'test-plugin' );
		update_option( Permissions_Manager::PLUGIN_PROVIDER_OPTION_PREFIX . $plugin_key, 'anthropic,google' );

		$prefs = $this->manager->get_plugin_provider_preferences( 'test-plugin' );

		$this->assertSame( array( 'anthropic', 'google' ), $prefs );
	}

	/**
	 * Tests that slugs with surrounding whitespace are trimmed.
	 *
	 * @since 1.0.0
	 */
	public function test_get_plugin_provider_preferences_trims_whitespace(): void {
		$plugin_key = $this->manager->sanitize_option_key( 'test-plugin' );
		update_option( Permissions_Manager::PLUGIN_PROVIDER_OPTION_PREFIX . $plugin_key, ' anthropic , google ' );

		$prefs = $this->manager->get_plugin_provider_preferences( 'test-plugin' );

		$this->assertSame( array( 'anthropic', 'google' ), $prefs );
	}

	/**
	 * Tests that empty comma-separated segments are discarded.
	 *
	 * @since 1.0.0
	 */
	public function test_get_plugin_provider_preferences_discards_empty_segments(): void {
		$plugin_key = $this->manager->sanitize_option_key( 'test-plugin' );
		update_option( Permissions_Manager::PLUGIN_PROVIDER_OPTION_PREFIX . $plugin_key, 'anthropic,,google' );

		$prefs = $this->manager->get_plugin_provider_preferences( 'test-plugin' );

		$this->assertSame( array( 'anthropic', 'google' ), $prefs );
	}

	/**
	 * Tests that the wpai_plugin_provider_preferences filter can override the preferences.
	 *
	 * @since 1.0.0
	 */
	public function test_get_plugin_provider_preferences_respects_filter(): void {
		$callback = static function ( array $prefs, string $plugin_id ): array {
			return array( 'openai' );
		};
		add_filter( 'wpai_plugin_provider_preferences', $callback, 10, 2 );

		$prefs = $this->manager->get_plugin_provider_preferences( 'test-plugin' );

		remove_filter( 'wpai_plugin_provider_preferences', $callback, 10 );

		$this->assertSame( array( 'openai' ), $prefs );
	}

	// -------------------------------------------------------------------------
	// sanitize_option_key
	// -------------------------------------------------------------------------

	/**
	 * Tests that sanitize_option_key lowercases the input string.
	 *
	 * @since 1.0.0
	 */
	public function test_sanitize_option_key_lowercases(): void {
		$this->assertSame( 'my_plugin', $this->manager->sanitize_option_key( 'My_Plugin' ) );
	}

	/**
	 * Tests that sanitize_option_key replaces hyphens and non-alphanumeric characters with underscores.
	 *
	 * @since 1.0.0
	 */
	public function test_sanitize_option_key_replaces_special_chars(): void {
		$this->assertSame( 'my_plugin_v2', $this->manager->sanitize_option_key( 'my-plugin/v2' ) );
	}

	/**
	 * Tests that sanitize_option_key preserves alphanumeric characters and underscores.
	 *
	 * @since 1.0.0
	 */
	public function test_sanitize_option_key_preserves_valid_chars(): void {
		$this->assertSame( 'my_plugin_123', $this->manager->sanitize_option_key( 'my_plugin_123' ) );
	}
}

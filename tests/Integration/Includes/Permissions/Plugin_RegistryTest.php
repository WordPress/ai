<?php
/**
 * Tests for the Plugin_Registry class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Permissions
 */

namespace WordPress\AI\Tests\Integration\Includes\Permissions;

use WP_UnitTestCase;
use WordPress\AI\Permissions\Plugin_Registry;

/**
 * Plugin_Registry test case.
 *
 * @covers \WordPress\AI\Permissions\Plugin_Registry
 * @since 1.0.0
 */
class Plugin_RegistryTest extends WP_UnitTestCase {

	/**
	 * Registry instance under test.
	 *
	 * @since 1.0.0
	 * @var \WordPress\AI\Permissions\Plugin_Registry
	 */
	private Plugin_Registry $registry;

	/**
	 * Sets up a fresh registry before each test.
	 *
	 * @since 1.0.0
	 */
	public function setUp(): void {
		parent::setUp();
		$this->registry = new Plugin_Registry();
	}

	// -------------------------------------------------------------------------
	// register_plugin
	// -------------------------------------------------------------------------

	/**
	 * Tests that a freshly registered plugin is retrievable by ID.
	 *
	 * @since 1.0.0
	 */
	public function test_register_plugin_stores_plugin(): void {
		$this->registry->register_plugin( 'my-plugin', array( 'name' => 'My Plugin' ) );

		$this->assertTrue( $this->registry->has_plugin( 'my-plugin' ) );
	}

	/**
	 * Tests that registered plugin data contains the correct id, name, and description.
	 *
	 * @since 1.0.0
	 */
	public function test_register_plugin_stores_correct_data(): void {
		$this->registry->register_plugin(
			'my-plugin',
			array(
				'name'        => 'My Plugin',
				'description' => 'Uses AI for content.',
			)
		);

		$plugin = $this->registry->get_plugin( 'my-plugin' );

		$this->assertSame( 'my-plugin', $plugin['id'] );
		$this->assertSame( 'My Plugin', $plugin['name'] );
		$this->assertSame( 'Uses AI for content.', $plugin['description'] );
	}

	/**
	 * Tests that the plugin ID is used as the name when no name is provided.
	 *
	 * @since 1.0.0
	 */
	public function test_register_plugin_defaults_name_to_id(): void {
		$this->registry->register_plugin( 'my-plugin' );

		$plugin = $this->registry->get_plugin( 'my-plugin' );

		$this->assertSame( 'my-plugin', $plugin['name'] );
	}

	/**
	 * Tests that the description defaults to an empty string when not provided.
	 *
	 * @since 1.0.0
	 */
	public function test_register_plugin_defaults_description_to_empty_string(): void {
		$this->registry->register_plugin( 'my-plugin' );

		$plugin = $this->registry->get_plugin( 'my-plugin' );

		$this->assertSame( '', $plugin['description'] );
	}

	/**
	 * Tests that registering a duplicate plugin ID is a no-op and the first registration wins.
	 *
	 * @since 1.0.0
	 */
	public function test_register_plugin_duplicate_id_is_noop(): void {
		$this->registry->register_plugin( 'my-plugin', array( 'name' => 'Original' ) );
		$this->registry->register_plugin( 'my-plugin', array( 'name' => 'Duplicate' ) );

		$plugin = $this->registry->get_plugin( 'my-plugin' );

		$this->assertSame( 'Original', $plugin['name'] );
	}

	/**
	 * Tests that the plugin name is sanitized via sanitize_text_field.
	 *
	 * @since 1.0.0
	 */
	public function test_register_plugin_sanitizes_name(): void {
		$this->registry->register_plugin( 'my-plugin', array( 'name' => '<script>alert(1)</script>My Plugin' ) );

		$plugin = $this->registry->get_plugin( 'my-plugin' );

		$this->assertStringNotContainsString( '<script>', $plugin['name'] );
	}

	// -------------------------------------------------------------------------
	// get_all_plugins
	// -------------------------------------------------------------------------

	/**
	 * Tests that get_all_plugins returns an empty array when nothing is registered.
	 *
	 * @since 1.0.0
	 */
	public function test_get_all_plugins_empty_by_default(): void {
		$this->assertSame( array(), $this->registry->get_all_plugins() );
	}

	/**
	 * Tests that get_all_plugins returns all registered plugins keyed by ID.
	 *
	 * @since 1.0.0
	 */
	public function test_get_all_plugins_returns_all(): void {
		$this->registry->register_plugin( 'plugin-a', array( 'name' => 'Plugin A' ) );
		$this->registry->register_plugin( 'plugin-b', array( 'name' => 'Plugin B' ) );

		$all = $this->registry->get_all_plugins();

		$this->assertCount( 2, $all );
		$this->assertArrayHasKey( 'plugin-a', $all );
		$this->assertArrayHasKey( 'plugin-b', $all );
	}

	// -------------------------------------------------------------------------
	// get_plugin
	// -------------------------------------------------------------------------

	/**
	 * Tests that get_plugin returns null for an unknown plugin ID.
	 *
	 * @since 1.0.0
	 */
	public function test_get_plugin_returns_null_for_unknown_id(): void {
		$this->assertNull( $this->registry->get_plugin( 'unknown' ) );
	}

	// -------------------------------------------------------------------------
	// has_plugin
	// -------------------------------------------------------------------------

	/**
	 * Tests that has_plugin returns false before a plugin is registered.
	 *
	 * @since 1.0.0
	 */
	public function test_has_plugin_returns_false_before_registration(): void {
		$this->assertFalse( $this->registry->has_plugin( 'my-plugin' ) );
	}

	/**
	 * Tests that has_plugin returns true after a plugin is registered.
	 *
	 * @since 1.0.0
	 */
	public function test_has_plugin_returns_true_after_registration(): void {
		$this->registry->register_plugin( 'my-plugin' );

		$this->assertTrue( $this->registry->has_plugin( 'my-plugin' ) );
	}
}

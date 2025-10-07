<?php
/**
 * Tests for the Plugin class.
 *
 * @package WordPress\AI\Tests
 */

namespace WordPress\AI\Tests;

use WordPress\AI\Plugin;
use WordPress\AI\Feature_Registry;
use WP_UnitTestCase;

/**
 * Plugin test case.
 *
 * @since 0.1.0
 */
class PluginTest extends WP_UnitTestCase {
	/**
	 * Test that Plugin returns a singleton instance.
	 *
	 * @since 0.1.0
	 */
	public function test_instance_returns_singleton() {
		$instance1 = Plugin::instance();
		$instance2 = Plugin::instance();

		$this->assertSame( $instance1, $instance2, 'Plugin should return the same singleton instance' );
	}

	/**
	 * Test that plugin initializes without errors.
	 *
	 * @since 0.1.0
	 */
	public function test_plugin_initializes() {
		$plugin = Plugin::instance();
		$plugin->init();

		$this->assertTrue( true, 'Plugin should initialize without errors' );
	}

	/**
	 * Test that feature registry is accessible.
	 *
	 * @since 0.1.0
	 */
	public function test_get_feature_registry() {
		$plugin   = Plugin::instance();
		$registry = $plugin->get_feature_registry();

		$this->assertInstanceOf( Feature_Registry::class, $registry, 'Plugin should return Feature_Registry instance' );
	}

}

<?php
/**
 * Integration tests for Activation.
 *
 * @package WordPress\AI\Tests\Integration\Admin
 */

namespace WordPress\AI\Tests\Integration\Admin;

use WP_UnitTestCase;
use WordPress\AI\Admin\Activation;

/**
 * Activation test case.
 *
 * @covers \WordPress\AI\Admin\Activation
 * @since 0.6.0
 */
class ActivationTest extends WP_UnitTestCase {

	/**
	 * Cleans up options before each test.
	 *
	 * @since 0.6.0
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( 'wpai_version' );
	}

	/**
	 * Cleans up options after each test.
	 *
	 * @since 0.6.0
	 */
	public function tearDown(): void {
		delete_option( 'wpai_version' );

		parent::tearDown();
	}

	/**
	 * Tests that activation_callback() triggers upgrades.
	 *
	 * @since 0.6.0
	 */
	public function test_activation_callback_triggers_upgrades() {
		Activation::activation_callback();

		$this->assertEquals( WPAI_VERSION, get_option( 'wpai_version' ) );
	}
}

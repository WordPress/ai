<?php
/**
 * Tests for the Settings_Toggle service.
 *
 * @package WordPress\AI\Tests\Admin\Settings
 */

namespace WordPress\AI\Tests\Integration\Admin\Settings;

use WordPress\AI\Admin\Settings\Settings_Toggle;
use WP_UnitTestCase;

/**
 * Settings_Toggle test case.
 *
 * @since 0.1.0
 *
 * @covers \WordPress\AI\Admin\Settings\Settings_Toggle
 */
class Settings_Toggle_Test extends WP_UnitTestCase {
	/**
	 * Toggle service instance.
	 *
	 * @var Settings_Toggle
	 */
	private $toggle;

	/**
	 * Sets up the test case.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->toggle = new Settings_Toggle();
		delete_option( Settings_Toggle::OPTION_KEY );
	}

	/**
	 * Tests that sanitize casts truthy and falsey strings correctly.
	 */
	public function test_sanitize_casts_strings_to_boolean(): void {
		$this->assertTrue( $this->toggle->sanitize( 'true' ) );
		$this->assertTrue( $this->toggle->sanitize( 'ON' ) );
		$this->assertFalse( $this->toggle->sanitize( 'false' ) );
		$this->assertFalse( $this->toggle->sanitize( 'no' ) );
	}

	/**
	 * Tests that the filter respects the stored option value.
	 */
	public function test_filter_features_enabled_respects_option(): void {
		$this->toggle->register();

		$this->toggle->update( false );
		$this->assertFalse( $this->toggle->filter_features_enabled( true ) );

		$this->toggle->update( true );
		$this->assertTrue( $this->toggle->filter_features_enabled( true ) );
	}

	/**
	 * Tests that the serialized payload reflects the current state.
	 */
	public function test_to_array_reflects_current_state(): void {
		$this->toggle->register();
		update_option( Settings_Toggle::OPTION_KEY, true );

		$data = $this->toggle->to_array();

		$this->assertSame( Settings_Toggle::OPTION_KEY, $data['optionKey'] );
		$this->assertArrayHasKey( 'restField', $data );
		$this->assertTrue( $data['enabled'] );
		$this->assertSame( 'ai_experiments', $data['group'] );
	}
}

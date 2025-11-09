<?php
/**
 * Tests for the Settings_Payload_Builder class.
 *
 * @package WordPress\AI\Tests\Integration\Admin\Settings
 */

namespace WordPress\AI\Tests\Integration\Admin\Settings;

use WordPress\AI\Admin\Settings_Payload_Builder;
use WordPress\AI\Admin\Settings\Settings_Section;
use WP_UnitTestCase;

/**
 * @since 0.1.0
 *
 * @covers \WordPress\AI\Admin\Settings\Settings_Payload_Builder
 * @covers \WordPress\AI\Admin\Settings\Settings_Section
 */
class SettingsPayloadBuilderTest extends WP_UnitTestCase {
	use Settings_Test_Helper_Trait;

	/**
	 * Sets up shared settings infrastructure.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->setup_settings_infrastructure();
	}

	/**
	 * Tears down shared infrastructure.
	 */
	protected function tearDown(): void {
		$this->teardown_settings_infrastructure();

		parent::tearDown();
	}

	/**
	 * Ensures section payloads expose the default enabled flag.
	 */
	public function test_sections_include_default_enabled_flag(): void {
		$section = new Settings_Section(
			'custom-section',
			'Custom',
			'Custom description',
			static function (): void {
			},
			10,
			'custom-feature',
			array(),
			false
		);
		$this->registry->register_section( $section );

		$payload_builder = new Settings_Payload_Builder( $this->toggle, $this->feature_toggles, $this->registry );
		$payload         = $payload_builder->build();

		$this->assertFalse( $payload['sections'][0]['defaultEnabled'], 'Default enabled flag should be exposed.' );
		$this->assertFalse( $payload['sections'][0]['enabled'], 'Enabled flag should respect default when no toggle exists.' );
	}

	/**
	 * Ensures persisted feature toggles override default enabled state.
	 */
	public function test_sections_use_persisted_toggle_value(): void {
		$this->feature_toggles->set_feature_enabled( 'custom-feature', true );

		$section = new Settings_Section(
			'custom-section',
			'Custom',
			'Custom description',
			static function (): void {
			},
			10,
			'custom-feature',
			array(),
			false
		);
		$this->registry->register_section( $section );

		$payload_builder = new Settings_Payload_Builder( $this->toggle, $this->feature_toggles, $this->registry );
		$payload         = $payload_builder->build();

		$this->assertFalse( $payload['sections'][0]['defaultEnabled'], 'Default flag remains the same.' );
		$this->assertTrue( $payload['sections'][0]['enabled'], 'Persisted toggle should override the default.' );
	}
}

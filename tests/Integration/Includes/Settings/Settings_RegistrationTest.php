<?php
/**
 * Integration tests for the Settings_Registration class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Settings
 */

namespace WordPress\AI\Tests\Integration\Includes\Settings;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Features\Registry;
use WordPress\AI\Settings\Settings_Registration;

/**
 * Stub feature for settings registration tests.
 */
class Settings_Registration_Test_Feature extends Abstract_Feature {

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'settings-test-feature';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Settings Test Feature',
			'description' => 'Feature used for settings registration tests.',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Stub feature that exposes custom settings registration.
 */
class Settings_Registration_Custom_Settings_Feature extends Abstract_Feature {

	/**
	 * Tracks whether register_settings() was called.
	 *
	 * @var bool
	 */
	public static bool $custom_settings_registered = false;

	/**
	 * {@inheritDoc}
	 */
	public static function get_id(): string {
		return 'settings-custom-feature';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Settings Custom Feature',
			'description' => 'Feature with custom settings registration for tests.',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}

	/**
	 * {@inheritDoc}
	 */
	public function register_settings(): void {
		self::$custom_settings_registered = true;
	}
}

/**
 * Tests for Settings_Registration.
 */
class Settings_RegistrationTest extends WP_UnitTestCase {

	/**
	 * Cleans up registered settings and meta after each test.
	 */
	public function tearDown(): void {
		Settings_Registration_Custom_Settings_Feature::$custom_settings_registered = false;

		unregister_setting( Settings_Registration::OPTION_GROUP, Settings_Registration::GLOBAL_OPTION );
		unregister_setting( Settings_Registration::OPTION_GROUP, 'wpai_feature_settings-test-feature_enabled' );
		unregister_setting( Settings_Registration::OPTION_GROUP, 'wpai_feature_settings-custom-feature_enabled' );

		if ( function_exists( 'unregister_meta_key' ) ) {
			unregister_meta_key( 'user', 'wpai_settings_guide_dismissed' );
		}

		parent::tearDown();
	}

	/**
	 * Tests that register_settings() registers the global setting with expected schema.
	 */
	public function test_register_settings_registers_global_toggle() {
		$registration = new Settings_Registration( new Registry() );
		$registration->register_settings();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( Settings_Registration::GLOBAL_OPTION, $registered );
		$this->assertSame( 'boolean', $registered[ Settings_Registration::GLOBAL_OPTION ]['type'] );
		$this->assertFalse( $registered[ Settings_Registration::GLOBAL_OPTION ]['default'] );
		$this->assertSame( 'rest_sanitize_boolean', $registered[ Settings_Registration::GLOBAL_OPTION ]['sanitize_callback'] );
		$this->assertTrue( $registered[ Settings_Registration::GLOBAL_OPTION ]['show_in_rest'] );
	}

	/**
	 * Tests that feature-level toggle settings are registered for all registered features.
	 */
	public function test_register_settings_registers_feature_toggles() {
		$registry = new Registry();
		$registry->register_feature( new Settings_Registration_Test_Feature() );
		$registry->register_feature( new Settings_Registration_Custom_Settings_Feature() );

		$registration = new Settings_Registration( $registry );
		$registration->register_settings();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( 'wpai_feature_settings-test-feature_enabled', $registered );
		$this->assertArrayHasKey( 'wpai_feature_settings-custom-feature_enabled', $registered );
		$this->assertTrue( $registered['wpai_feature_settings-test-feature_enabled']['show_in_rest'] );
		$this->assertTrue( $registered['wpai_feature_settings-custom-feature_enabled']['show_in_rest'] );
	}

	/**
	 * Tests that custom feature settings registration is triggered.
	 */
	public function test_register_settings_calls_feature_register_settings() {
		$registry = new Registry();
		$registry->register_feature( new Settings_Registration_Custom_Settings_Feature() );

		$registration = new Settings_Registration( $registry );
		$registration->register_settings();

		$this->assertTrue( Settings_Registration_Custom_Settings_Feature::$custom_settings_registered );
	}

	/**
	 * Tests that init() registers onboarding dismissal user meta.
	 */
	public function test_init_registers_onboarding_dismissal_user_meta() {
		$registration = new Settings_Registration( new Registry() );
		$registration->init();

		$meta = get_registered_meta_keys( 'user' );

		$this->assertArrayHasKey( 'wpai_settings_guide_dismissed', $meta );
		$this->assertSame( 'boolean', $meta['wpai_settings_guide_dismissed']['type'] );
		$this->assertTrue( $meta['wpai_settings_guide_dismissed']['single'] );
		$this->assertTrue( $meta['wpai_settings_guide_dismissed']['show_in_rest'] );
	}
}

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
		return 'settings-registration-test';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => 'Settings Registration Test',
			'description' => 'A test feature for settings registration.',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Settings_Registration test case.
 *
 * @since 0.9.0
 */
class Settings_RegistrationTest extends WP_UnitTestCase {
	/**
	 * {@inheritDoc}
	 */
	public function tearDown(): void {
		unregister_setting( Settings_Registration::OPTION_GROUP, Settings_Registration::GLOBAL_OPTION );
		unregister_setting( Settings_Registration::OPTION_GROUP, 'wpai_feature_settings-registration-test_enabled' );
		unregister_setting( Settings_Registration::OPTION_GROUP, 'wpai_feature_settings-registration-test_field_developer' );
		delete_option( 'wpai_feature_settings-registration-test_field_developer' );
		parent::tearDown();
	}

	/**
	 * Test that register_settings() registers developer model settings for each feature.
	 *
	 * @since 0.9.0
	 */
	public function test_register_settings_registers_developer_model_setting(): void {
		global $wp_registered_settings;

		$registry = new Registry();
		$registry->register_feature( new Settings_Registration_Test_Feature() );

		$registration = new Settings_Registration( $registry );
		$registration->register_settings();

		$setting_name = 'wpai_feature_settings-registration-test_field_developer';

		$this->assertArrayHasKey( $setting_name, $wp_registered_settings );
		$this->assertSame( 'object', $wp_registered_settings[ $setting_name ]['type'] );
		$this->assertSame( array(), $wp_registered_settings[ $setting_name ]['default'] );
		$this->assertSame(
			array( 'provider', 'model' ),
			array_keys( $wp_registered_settings[ $setting_name ]['show_in_rest']['schema']['properties'] )
		);
	}

	/**
	 * Test that init() registers the provider discovery REST route hook.
	 *
	 * @since 0.9.0
	 */
	public function test_init_registers_provider_discovery_rest_hook(): void {
		global $wp_filter;

		$registry     = new Registry();
		$registration = new Settings_Registration( $registry );
		$before       = isset( $wp_filter['rest_api_init'] ) ? count( $wp_filter['rest_api_init']->callbacks, COUNT_RECURSIVE ) : 0;

		$registration->init();

		$after = isset( $wp_filter['rest_api_init'] ) ? count( $wp_filter['rest_api_init']->callbacks, COUNT_RECURSIVE ) : 0;

		$this->assertGreaterThan( $before, $after );
	}

	/**
	 * Tests that register_settings() registers settings for active connectors.
	 *
	 * @since 1.0.2
	 */
	public function test_register_settings_registers_active_connector_settings(): void {
		global $wp_registered_settings;

		// Mock/register a connector
		$connector_id = 'wpai_settings_test_connector';
		$registry_wp = \WP_Connector_Registry::get_instance();
		if ( null !== $registry_wp ) {
			$registry_wp->register(
				$connector_id,
				array(
					'name'           => 'Settings Test Connector',
					'type'           => 'ai_provider',
					'authentication' => array(
						'method' => 'none',
					),
				)
			);
		}

		// Mock/register in AiClient registry
		$registry_ai = \WordPress\AiClient\AiClient::defaultRegistry();
		$ids_to_classes = new \ReflectionProperty( $registry_ai, 'registeredIdsToClassNames' );
		$ids_to_classes->setAccessible( true );
		$id_map                  = (array) $ids_to_classes->getValue( $registry_ai );
		$id_map[ $connector_id ] = \WordPress\AI\Tests\Integration\Includes\Helper_Test_Provider::class;
		$ids_to_classes->setValue( $registry_ai, $id_map );

		$registry = new Registry();
		$registration = new Settings_Registration( $registry );

		try {
			$registration->register_settings();
			$setting_name = "wpai_connector_{$connector_id}_enabled";

			$this->assertArrayHasKey( $setting_name, $wp_registered_settings );
			$this->assertSame( 'boolean', $wp_registered_settings[ $setting_name ]['type'] );
			$this->assertTrue( $wp_registered_settings[ $setting_name ]['default'] );
			$this->assertTrue( $wp_registered_settings[ $setting_name ]['show_in_rest'] );
		} finally {
			if ( null !== $registry_wp ) {
				$registry_wp->unregister( $connector_id );
			}
			$id_map = (array) $ids_to_classes->getValue( $registry_ai );
			unset( $id_map[ $connector_id ] );
			$ids_to_classes->setValue( $registry_ai, $id_map );
			unregister_setting( Settings_Registration::OPTION_GROUP, "wpai_connector_{$connector_id}_enabled" );
		}
	}
}

<?php
/**
 * Trait for settings test setup.
 *
 * @package WordPress\AI\Tests\Integration\Admin\Settings
 */

namespace WordPress\AI\Tests\Integration\Admin\Settings;

use WordPress\AI\Admin\Admin_Settings_Page;
use WordPress\AI\Admin\Settings\Feature_Toggles;
use WordPress\AI\Admin\Settings\Settings_Registry;
use WordPress\AI\Admin\Settings\Settings_Renderer;
use WordPress\AI\Admin\Settings\Settings_Service;
use WordPress\AI\Admin\Settings\Settings_Toggle;
use WordPress\AI\Admin\Settings_Page_Assets;
use WordPress\AI\Admin\Settings_Payload_Builder;

/**
 * Provides common setup for settings-related tests.
 *
 * @since 0.1.0
 */
trait Settings_Test_Helper_Trait {
	/**
	 * Settings toggle.
	 *
	 * @var Settings_Toggle
	 */
	protected $toggle;

	/**
	 * Feature toggles service.
	 *
	 * @var Feature_Toggles
	 */
	protected $feature_toggles;

	/**
	 * Settings registry.
	 *
	 * @var Settings_Registry
	 */
	protected $registry;

	/**
	 * Admin settings page.
	 *
	 * @var Admin_Settings_Page
	 */
	protected $page;

	/**
	 * Settings service.
	 *
	 * @var Settings_Service
	 */
	protected $service;

	/**
	 * Sets up common settings infrastructure for tests.
	 *
	 * @since 0.1.0
	 */
	protected function setup_settings_infrastructure(): void {
		$this->toggle          = new Settings_Toggle();
		$this->feature_toggles = new Feature_Toggles();
		$this->registry        = new Settings_Registry();

		// Create dependencies for Admin_Settings_Page.
		$payload_builder = new Settings_Payload_Builder( $this->toggle, $this->feature_toggles, $this->registry );
		$assets          = new Settings_Page_Assets( $payload_builder );
		$renderer        = new Settings_Renderer();

		$this->page    = new Admin_Settings_Page( $this->toggle, $this->registry, $assets, $payload_builder );
		$this->service = new Settings_Service( $this->toggle, $this->feature_toggles, $this->registry, $this->page, $renderer );
	}

	/**
	 * Cleans up settings infrastructure after tests.
	 *
	 * @since 0.1.0
	 */
	protected function teardown_settings_infrastructure(): void {
		delete_option( Settings_Toggle::OPTION_KEY );
		delete_option( Feature_Toggles::OPTION_KEY );
	}
}
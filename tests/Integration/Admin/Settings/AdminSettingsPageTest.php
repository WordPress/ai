<?php
/**
 * Tests for the Admin_Settings_Page controller.
 *
 * @package WordPress\AI\Tests\Admin\Settings
 */

namespace WordPress\AI\Tests\Integration\Admin\Settings;

use WordPress\AI\Admin\Admin_Settings_Page;
use WordPress\AI\Admin\Settings\Feature_Toggles;
use WordPress\AI\Admin\Settings\Settings_Registry;
use WordPress\AI\Admin\Settings\Settings_Toggle;
use WP_UnitTestCase;

/**
 * Admin_Settings_Page test case.
 *
 * @since 0.1.0
 *
 * @covers \WordPress\AI\Admin\Admin_Settings_Page
 * @covers \WordPress\AI\Admin\Admin_Settings_Page::register_default_sections
 * @covers \WordPress\AI\Admin\Admin_Settings_Page::render_toggle_section
 */
class Admin_Settings_Page_Test extends WP_UnitTestCase {
	/**
	 * @var Admin_Settings_Page
	 */
	private $page;

	/**
	 * @var Settings_Toggle
	 */
	private $toggle;

	/**
	 * @var Feature_Toggles
	 */
	private $feature_toggles;

	/**
	 * @var Settings_Registry
	 */
	private $registry;

	/**
	 * Sets up the test case.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->toggle          = new Settings_Toggle();
		$this->feature_toggles = new Feature_Toggles();
		$this->registry        = new Settings_Registry();
		$this->page            = new Admin_Settings_Page( $this->toggle, $this->feature_toggles, $this->registry );

		$this->toggle->register();
		$this->page->register_default_sections( $this->registry );
	}

	/**
	 * Cleans up after the test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( Settings_Toggle::OPTION_KEY );
		delete_option( Feature_Toggles::OPTION_KEY );

		parent::tearDown();
	}

	/**
	 * Tests that the fallback markup renders the default toggle section.
	 */
	public function test_render_outputs_toggle_section(): void {
		$this->set_current_user_as_admin();

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Enable Experimental Features', $output );
		$this->assertStringContainsString( 'ai-experiments-section-ai-experiments-toggle', $output );

		$this->assertMatchesRegularExpression(
			'/data-settings="[^"]+"/',
			$output,
			'Expected data-settings payload to be present.'
		);
	}

	/**
	 * Sets the current user to an administrator for rendering tests.
	 */
	private function set_current_user_as_admin(): void {
		$user_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $user_id );
	}
}

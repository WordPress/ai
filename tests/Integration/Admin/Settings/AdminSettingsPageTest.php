<?php
/**
 * Tests for the Admin_Settings_Page controller.
 *
 * @package WordPress\AI\Tests\Admin\Settings
 */

namespace WordPress\AI\Tests\Integration\Admin\Settings;

use WordPress\AI\Admin\Admin_Settings_Page;
use WordPress\AI\Admin\Settings\Settings_Registry;
use WordPress\AI\Admin\Settings\Settings_Service;
use WordPress\AI\Admin\Settings\Settings_Toggle;
use WP_UnitTestCase;

/**
 * Admin_Settings_Page test case.
 *
 * @since 0.1.0
 *
 * @covers \WordPress\AI\Admin\Admin_Settings_Page
 * @covers \WordPress\AI\Admin\Settings\Settings_Service
 */
class Admin_Settings_Page_Test extends WP_UnitTestCase {
	use Settings_Test_Helper_Trait;

	/**
	 * Sets up the test case.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->setup_settings_infrastructure();
		$this->toggle->register();
		$this->service->register_default_sections();
	}

	/**
	 * Cleans up after the test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		$this->teardown_settings_infrastructure();

		parent::tearDown();
	}

	/**
	 * Tests that the fallback markup renders the default toggle section.
	 */
	public function test_render_outputs_toggle_section(): void {
		$user_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

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
	 * Tests that the hydration payload matches the registered sections.
	 */
	public function test_render_outputs_payload_with_toggle_metadata(): void {
		$user_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		update_option( Settings_Toggle::OPTION_KEY, true );

		ob_start();
		$this->page->render();
		$output = ob_get_clean();

		preg_match( '/data-settings="([^"]+)"/', $output, $matches );

		$this->assertNotEmpty( $matches, 'Expected data-settings attribute to exist.' );

		$payload_json = html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' );
		$payload      = json_decode( $payload_json, true );

		$this->assertIsArray( $payload );
		$this->assertTrue( $payload['toggle']['enabled'] );
		$this->assertSame( Settings_Toggle::OPTION_KEY, $payload['toggle']['optionKey'] );
		$this->assertSame( 'ai-experiments-toggle', $payload['sections'][0]['id'] );
	}
}

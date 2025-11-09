<?php
/**
 * Tests for the Provides_Settings_Section trait.
 *
 * @package WordPress\AI\Tests\Admin\Settings
 */

namespace WordPress\AI\Tests\Integration\Admin\Settings;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Admin\Admin_Settings_Page;
use WordPress\AI\Admin\Settings\Feature_Toggles;
use WordPress\AI\Admin\Settings\Settings_Registry;
use WordPress\AI\Admin\Settings\Settings_Section;
use WordPress\AI\Admin\Settings\Settings_Service;
use WordPress\AI\Admin\Settings\Settings_Toggle;
use WordPress\AI\Features\Traits\Provides_Settings_Section;
use WP_UnitTestCase;

/**
 * @since 0.1.0
 *
 * @covers \WordPress\AI\Features\Traits\Provides_Settings_Section
 */
class ProvidesSettingsSectionTraitTest extends WP_UnitTestCase {
	use Settings_Test_Helper_Trait;

	/**
	 * Sets up the test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->setup_settings_infrastructure();
	}

	/**
	 * Cleans up after tests.
	 */
	protected function tearDown(): void {
		$this->teardown_settings_infrastructure();

		parent::tearDown();
	}

	/**
	 * Tests that the trait registers sections with default supports.
	 */
	public function test_registers_section_with_default_supports(): void {
		$section = new Settings_Section(
			'test-section',
			'Test Section',
			'Section description',
			static function (): void {
				echo '<p>Test content</p>';
			},
			10,
			'trait-test',
			array(
				'badges' => array(
					array(
						'label'   => 'Experimental',
						'context' => 'status',
					),
				),
				'assets' => array(
					'scripts' => array(),
					'styles'  => array(),
				),
			),
			true
		);

		$this->registry->register_section( $section );

		$retrieved = $this->registry->get_section( 'test-section' );
		$this->assertInstanceOf( Settings_Section::class, $retrieved );
		$this->assertSame( 'trait-test', $retrieved->get_feature_id() );

		$supports = $retrieved->get_supports();

		$this->assertArrayHasKey( 'badges', $supports );
		$this->assertNotEmpty( $supports['badges'] );
		$this->assertSame( 'Experimental', $supports['badges'][0]['label'] );
		$this->assertSame( 'status', $supports['badges'][0]['context'] );

		$this->assertArrayHasKey( 'assets', $supports );
		$this->assertSame(
			array(
				'scripts' => array(),
				'styles'  => array(),
			),
			$supports['assets']
		);
	}
}

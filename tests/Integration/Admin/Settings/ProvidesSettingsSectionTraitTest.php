<?php
/**
 * Tests for the Provides_Settings_Section trait.
 *
 * @package WordPress\AI\Tests\Admin\Settings
 */

namespace WordPress\AI\Tests\Integration\Admin\Settings;

use WordPress\AI\Admin\Settings\Settings_Registry;
use WordPress\AI\Features\Traits\Provides_Settings_Section;
use WP_UnitTestCase;

/**
 * @covers \WordPress\AI\Features\Traits\Provides_Settings_Section
 */
class ProvidesSettingsSectionTraitTest extends WP_UnitTestCase {
	/**
	 * Ensures a feature using the trait gets normalized supports metadata.
	 */
	public function test_registers_section_with_default_badge(): void {
		$registry = new Settings_Registry();

		$feature = new class() {
			use Provides_Settings_Section;

			public function get_id(): string {
				return 'trait-test';
			}

			public function is_enabled_by_default(): bool {
				return true;
			}

			public function register( Settings_Registry $registry ): bool {
				return $this->register_feature_settings_section(
					$registry,
					'trait-section',
					'Trait Section',
					static function (): void {
					},
					array(
						'supports' => array(
							'badges' => array(),
						),
					)
				);
			}
		};

		$this->assertTrue( $feature->register( $registry ) );

		$section = $registry->get_section( 'trait-section' );
		$this->assertNotNull( $section );
		$this->assertSame( 'trait-test', $section->get_feature_id() );

		$supports = $section->get_supports();
		$this->assertNotEmpty( $supports['badges'] );
		$this->assertSame( 'Experimental', $supports['badges'][0]['label'] );
	}
}

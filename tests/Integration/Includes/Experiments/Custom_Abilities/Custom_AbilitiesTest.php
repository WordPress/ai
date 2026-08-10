<?php
/**
 * Integration tests for the Custom_Abilities experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Custom_Abilities;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Gated\Post_Utilities;
use WordPress\AI\Experiments\Custom_Abilities\Custom_Abilities;
use WordPress\AI\Experiments\Experiment_Category;

/**
 * Custom_Abilities experiment test case.
 *
 * @covers \WordPress\AI\Experiments\Custom_Abilities\Custom_Abilities
 *
 * @since x.x.x
 */
class Custom_AbilitiesTest extends WP_UnitTestCase {

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		delete_option( 'wpai_features_enabled' );
		delete_option( 'wpai_feature_custom-abilities_enabled' );
		parent::tearDown();
	}

	/**
	 * Counts the callbacks currently attached to a hook.
	 *
	 * @since x.x.x
	 *
	 * @param string $hook The hook name.
	 * @return int The total number of attached callbacks.
	 */
	private function count_hook_callbacks( string $hook ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $wp_filter[ $hook ]->callbacks as $priority_callbacks ) {
			$total += count( $priority_callbacks );
		}

		return $total;
	}

	/**
	 * Tests the experiment id, metadata, and enabled state.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_registration(): void {
		update_option( 'wpai_features_enabled', true );
		update_option( 'wpai_feature_custom-abilities_enabled', true );

		$experiment = new Custom_Abilities();

		$this->assertSame( 'custom-abilities', $experiment->get_id() );
		$this->assertSame( 'Custom Abilities', $experiment->get_label() );
		$this->assertSame( Experiment_Category::ADMIN, $experiment->get_category() );
		$this->assertNotEmpty( $experiment->get_description() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Tests that the experiment is disabled when the global toggle is off.
	 *
	 * @since x.x.x
	 */
	public function test_experiment_disabled_when_global_toggle_off(): void {
		update_option( 'wpai_features_enabled', false );
		update_option( 'wpai_feature_custom-abilities_enabled', true );

		$this->assertFalse( ( new Custom_Abilities() )->is_enabled() );
	}

	/**
	 * Tests that register() hooks every gated ability and exposes core objects
	 * when an enabled ability requires it.
	 *
	 * @since x.x.x
	 */
	public function test_register_hooks_all_gated_abilities(): void {
		$before = $this->count_hook_callbacks( 'wp_abilities_api_init' );

		( new Custom_Abilities() )->register();

		$this->assertGreaterThan(
			$before,
			$this->count_hook_callbacks( 'wp_abilities_api_init' ),
			'register() should hook the gated ability registrations onto wp_abilities_api_init.'
		);
		$this->assertNotFalse(
			has_filter( 'register_setting_args' ),
			'Show_In_Abilities should run because read-settings/read-content require core-object exposure.'
		);
	}

	/**
	 * Tests that register() skips core-object exposure when no enabled ability
	 * requires it.
	 *
	 * @since x.x.x
	 */
	public function test_register_without_exposure_only_hooks_the_ability(): void {
		$callback = static function (): array {
			return array( Post_Utilities::class );
		};

		$before = $this->count_hook_callbacks( 'wp_abilities_api_init' );

		add_filter( 'wpai_gated_abilities', $callback );
		( new Custom_Abilities() )->register();
		remove_filter( 'wpai_gated_abilities', $callback );

		$this->assertGreaterThan(
			$before,
			$this->count_hook_callbacks( 'wp_abilities_api_init' ),
			'register() should still hook the post utility ability when no exposure is required.'
		);
	}
}

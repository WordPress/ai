<?php
/**
 * Integration tests for the Custom_Abilities experiment class.
 *
 * @package WordPress\AI\Tests\Integration\Experiments
 */

namespace WordPress\AI\Tests\Integration\Includes\Experiments\Custom_Abilities;

use ReflectionProperty;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Gated\Post_Utilities;
use WordPress\AI\Experiments\Custom_Abilities\Custom_Abilities;
use WordPress\AI\Experiments\Experiment_Category;

/**
 * Custom_Abilities experiment test case.
 *
 * @since 1.3.0
 */
class Custom_AbilitiesTest extends WP_UnitTestCase {

	/**
	 * Tear down test case.
	 *
	 * @since 1.3.0
	 */
	public function tearDown(): void {
		delete_option( 'wpai_feature_custom-abilities_enabled' );
		parent::tearDown();
	}

	/**
	 * Resets the Registrar's package-registration bookkeeping.
	 *
	 * The Registrar's statics plus the fired-action counters are process-wide;
	 * queued registrations from one test must not bleed into the next, and a
	 * fresh queue needs a fresh window. Only test code may reopen a window.
	 *
	 * @since x.x.x
	 */
	private function open_fresh_registration_state(): void {
		foreach ( array( 'hooked', 'categories_hooked', 'preload_hooked' ) as $flag ) {
			$property = new ReflectionProperty( \WordPress\Abilities\Registrar::class, $flag );
			$property->setValue( null, false );
		}

		foreach ( array( 'registered', 'deferred', 'preloads' ) as $map ) {
			$property = new ReflectionProperty( \WordPress\Abilities\Registrar::class, $map );
			$property->setValue( null, array() );
		}

		$GLOBALS['wp_actions']['wp_abilities_api_init']            = 0;
		$GLOBALS['wp_actions']['wp_abilities_api_categories_init'] = 0;
	}

	/**
	 * Counts the callbacks currently attached to a hook.
	 *
	 * @since 1.3.0
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
	 * @since 1.3.0
	 */
	public function test_experiment_registration(): void {
		update_option( 'wpai_feature_custom-abilities_enabled', true );

		$experiment = new Custom_Abilities();

		$this->assertSame( 'custom-abilities', $experiment->get_id() );
		$this->assertSame( 'Custom Abilities', $experiment->get_label() );
		$this->assertSame( Experiment_Category::ADMIN, $experiment->get_category() );
		$this->assertNotEmpty( $experiment->get_description() );
		$this->assertTrue( $experiment->is_enabled() );
	}

	/**
	 * Tests that the experiment is disabled when its own toggle is off.
	 *
	 * @since 1.4.0
	 */
	public function test_experiment_disabled_when_individual_toggle_off(): void {
		update_option( 'wpai_feature_custom-abilities_enabled', false );

		$this->assertFalse( ( new Custom_Abilities() )->is_enabled() );
	}

	/**
	 * Tests that register() hooks every gated ability and queues the package
	 * registration.
	 *
	 * The gated wrappers hook the abilities init action, and the vendored
	 * package's Registrar queues the same registrations there (plus its
	 * category declarations) — the production `init:15` shape.
	 *
	 * @since 1.3.0
	 */
	public function test_register_hooks_all_gated_abilities(): void {
		$this->open_fresh_registration_state();
		$before     = $this->count_hook_callbacks( 'wp_abilities_api_init' );
		$before_cat = $this->count_hook_callbacks( 'wp_abilities_api_categories_init' );

		( new Custom_Abilities() )->register();

		$this->assertGreaterThan(
			$before,
			$this->count_hook_callbacks( 'wp_abilities_api_init' ),
			'register() should hook the gated ability registrations onto wp_abilities_api_init.'
		);
		$this->assertGreaterThan(
			$before_cat,
			$this->count_hook_callbacks( 'wp_abilities_api_categories_init' ),
			'The package registration must queue its category declarations.'
		);
	}

	/**
	 * Tests that register() skips core-object exposure when no enabled ability
	 * requires it.
	 *
	 * @since 1.3.0
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

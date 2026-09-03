<?php
/**
 * Integration tests for the Abstract_Gated_Ability base class.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abstracts
 */

namespace WordPress\AI\Tests\Integration\Includes\Abstracts;

use WP_UnitTestCase;
use WordPress\AI\Abstracts\Abstract_Gated_Ability;

/**
 * Abstract_Gated_Ability test case.
 *
 * @since 1.3.0
 */
class Abstract_Gated_AbilityTest extends WP_UnitTestCase {

	/**
	 * Tests that a gated ability defaults to not requiring core-object exposure.
	 *
	 * Uses a minimal concrete subclass that does not override the method, so the
	 * abstract's default implementation is exercised directly.
	 *
	 * @since 1.3.0
	 */
	public function test_requires_core_object_exposure_defaults_to_false(): void {
		$ability = new class() extends Abstract_Gated_Ability {
			/**
			 * {@inheritDoc}
			 */
			public function register(): void {}
		};

		$this->assertFalse( $ability->requires_core_object_exposure() );
	}
}

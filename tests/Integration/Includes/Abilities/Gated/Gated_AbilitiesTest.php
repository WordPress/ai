<?php
/**
 * Integration tests for the gated abilities registry.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Gated
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Gated;

use RuntimeException;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Gated\Edit_Content;
use WordPress\AI\Abilities\Gated\Gated_Abilities;
use WordPress\AI\Abilities\Gated\Post_Utilities;
use WordPress\AI\Abilities\Gated\Read_Content;
use WordPress\AI\Abilities\Gated\Read_Settings;
use WordPress\AI\Abilities\Gated\Read_Users;
use WordPress\AI\Abstracts\Abstract_Gated_Ability;

/**
 * A valid gated ability used to exercise the registry filter.
 *
 * @since x.x.x
 */
final class Test_Valid_Gated_Ability extends Abstract_Gated_Ability {
	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * A gated ability whose constructor throws, used to exercise the registry's
 * instantiation guard.
 *
 * @since x.x.x
 */
final class Test_Throwing_Gated_Ability extends Abstract_Gated_Ability {
	/**
	 * Throws on construction.
	 *
	 * @throws \RuntimeException Always.
	 */
	public function __construct() {
		throw new RuntimeException( 'Cannot instantiate.' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {}
}

/**
 * Gated_Abilities registry test case.
 *
 * @since x.x.x
 */
class Gated_AbilitiesTest extends WP_UnitTestCase {

	/**
	 * Tests that get_all() returns the default gated ability instances.
	 *
	 * @since x.x.x
	 */
	public function test_get_all_returns_default_gated_abilities(): void {
		$abilities = Gated_Abilities::get_all();

		$this->assertCount( 5, $abilities );

		foreach ( $abilities as $ability ) {
			$this->assertInstanceOf( Abstract_Gated_Ability::class, $ability );
		}

		$classes = array_map( 'get_class', $abilities );
		$this->assertContains( Post_Utilities::class, $classes );
		$this->assertContains( Read_Settings::class, $classes );
		$this->assertContains( Read_Users::class, $classes );
		$this->assertContains( Read_Content::class, $classes );
		$this->assertContains( Edit_Content::class, $classes );
	}

	/**
	 * Tests that each gated ability reports the expected core-object-exposure need.
	 *
	 * @since x.x.x
	 */
	public function test_gated_abilities_report_expected_core_object_exposure(): void {
		$exposure = array();
		foreach ( Gated_Abilities::get_all() as $ability ) {
			$exposure[ get_class( $ability ) ] = $ability->requires_core_object_exposure();
		}

		$this->assertTrue( $exposure[ Read_Settings::class ], 'read-settings depends on core-object exposure.' );
		$this->assertTrue( $exposure[ Read_Content::class ], 'read-content depends on core-object exposure.' );
		$this->assertTrue( $exposure[ Edit_Content::class ], 'edit-content depends on core-object exposure.' );
		$this->assertFalse( $exposure[ Post_Utilities::class ], 'post utilities do not depend on core-object exposure.' );
		$this->assertFalse( $exposure[ Read_Users::class ], 'read-users does not depend on core-object exposure.' );
	}

	/**
	 * Tests that the wpai_gated_abilities filter can add a gated ability.
	 *
	 * @since x.x.x
	 */
	public function test_filter_can_add_a_gated_ability(): void {
		$callback = static function ( array $classes ): array {
			$classes[] = Test_Valid_Gated_Ability::class;
			return $classes;
		};

		add_filter( 'wpai_gated_abilities', $callback );
		$classes = array_map( 'get_class', Gated_Abilities::get_all() );
		remove_filter( 'wpai_gated_abilities', $callback );

		$this->assertContains( Test_Valid_Gated_Ability::class, $classes );
		$this->assertCount( 6, $classes );
	}

	/**
	 * Tests that the wpai_gated_abilities filter can remove a gated ability.
	 *
	 * @since x.x.x
	 */
	public function test_filter_can_remove_a_gated_ability(): void {
		$callback = static function ( array $classes ): array {
			return array_values(
				array_filter(
					$classes,
					static fn( string $class ): bool => Read_Users::class !== $class
				)
			);
		};

		add_filter( 'wpai_gated_abilities', $callback );
		$classes = array_map( 'get_class', Gated_Abilities::get_all() );
		remove_filter( 'wpai_gated_abilities', $callback );

		$this->assertNotContains( Read_Users::class, $classes );
		$this->assertCount( 4, $classes );
	}

	/**
	 * Tests that duplicate classes are only instantiated once.
	 *
	 * @since x.x.x
	 */
	public function test_get_all_dedupes_classes(): void {
		$callback = static function ( array $classes ): array {
			$classes[] = Post_Utilities::class;
			return $classes;
		};

		add_filter( 'wpai_gated_abilities', $callback );
		$classes = array_map( 'get_class', Gated_Abilities::get_all() );
		remove_filter( 'wpai_gated_abilities', $callback );

		$this->assertCount( 5, $classes );
		$this->assertCount( 1, array_keys( $classes, Post_Utilities::class, true ) );
	}

	/**
	 * Tests that non-string entries are skipped.
	 *
	 * @since x.x.x
	 */
	public function test_get_all_skips_non_string_entries(): void {
		$this->setExpectedIncorrectUsage( 'WordPress\AI\Abilities\Gated\Gated_Abilities::get_all' );

		$callback = static function (): array {
			return array( 123 );
		};

		add_filter( 'wpai_gated_abilities', $callback );
		$abilities = Gated_Abilities::get_all();
		remove_filter( 'wpai_gated_abilities', $callback );

		$this->assertSame( array(), $abilities );
	}

	/**
	 * Tests that classes not extending Abstract_Gated_Ability are skipped.
	 *
	 * @since x.x.x
	 */
	public function test_get_all_skips_classes_that_are_not_gated_abilities(): void {
		$this->setExpectedIncorrectUsage( 'WordPress\AI\Abilities\Gated\Gated_Abilities::get_all' );

		$callback = static function (): array {
			return array( \stdClass::class );
		};

		add_filter( 'wpai_gated_abilities', $callback );
		$abilities = Gated_Abilities::get_all();
		remove_filter( 'wpai_gated_abilities', $callback );

		$this->assertSame( array(), $abilities );
	}

	/**
	 * Tests that abilities which fail to instantiate are skipped.
	 *
	 * @since x.x.x
	 */
	public function test_get_all_skips_uninstantiable_abilities(): void {
		$this->setExpectedIncorrectUsage( 'WordPress\AI\Abilities\Gated\Gated_Abilities::get_all' );

		$callback = static function (): array {
			return array( Test_Throwing_Gated_Ability::class );
		};

		add_filter( 'wpai_gated_abilities', $callback );
		$abilities = Gated_Abilities::get_all();
		remove_filter( 'wpai_gated_abilities', $callback );

		$this->assertSame( array(), $abilities );
	}
}

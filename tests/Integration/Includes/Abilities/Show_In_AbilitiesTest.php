<?php
/**
 * Integration tests for the Show_In_Abilities exposure component.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities;

use WP_UnitTestCase;
use WordPress\AI\Abilities\Show_In_Abilities;

/**
 * Show_In_Abilities test case.
 *
 * @since 1.1.0
 */
class Show_In_AbilitiesTest extends WP_UnitTestCase {

	/**
	 * Set up test case.
	 *
	 * @since 1.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		Show_In_Abilities::register();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.1.0
	 */
	public function tearDown(): void {
		remove_filter( 'register_post_type_args', array( Show_In_Abilities::class, 'mark_post_type' ), 10 );

		// Restore the curated post types to their unmarked state.
		foreach ( array( 'post', 'page' ) as $post_type ) {
			$object = get_post_type_object( $post_type );
			if ( ! $object ) {
				continue;
			}

			unset( $object->show_in_abilities );
		}

		parent::tearDown();
	}

	/**
	 * Curated core post types are marked directly, since they register before the filter.
	 *
	 * @since 1.1.0
	 */
	public function test_marks_curated_registered_post_types(): void {
		// Show_In_Abilities::register() ran in setUp and patches existing post types.
		$this->assertNotEmpty( get_post_type_object( 'post' )->show_in_abilities );
		$this->assertNotEmpty( get_post_type_object( 'page' )->show_in_abilities );
	}

	/**
	 * The post type args filter marks a curated post type when it is registered.
	 *
	 * @since 1.1.0
	 */
	public function test_filter_marks_curated_post_type(): void {
		$args = Show_In_Abilities::mark_post_type( array(), 'page' );

		$this->assertTrue( $args['show_in_abilities'] );
	}

	/**
	 * The post type args filter leaves uncurated post types untouched.
	 *
	 * @since 1.1.0
	 */
	public function test_filter_skips_uncurated_post_type(): void {
		$args = Show_In_Abilities::mark_post_type( array(), 'wpai_not_curated_cpt' );

		$this->assertTrue( empty( $args['show_in_abilities'] ) );
	}

	/**
	 * An explicit `show_in_abilities` value already on the post type is preserved.
	 *
	 * @since 1.1.0
	 */
	public function test_filter_respects_existing_post_type_value(): void {
		$args = Show_In_Abilities::mark_post_type(
			array( 'show_in_abilities' => array( 'custom' => true ) ),
			'post'
		);

		$this->assertSame( array( 'custom' => true ), $args['show_in_abilities'] );
	}
}

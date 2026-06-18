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
 * @since x.x.x
 */
class Show_In_AbilitiesTest extends WP_UnitTestCase {

	/**
	 * Option names registered during a test, cleaned up on tear down.
	 *
	 * @since x.x.x
	 *
	 * @var array<string>
	 */
	private $registered_options = array();

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		Show_In_Abilities::register();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_filter( 'register_setting_args', array( Show_In_Abilities::class, 'mark_setting' ), 10 );
		remove_filter( 'register_post_type_args', array( Show_In_Abilities::class, 'mark_post_type' ), 10 );

		foreach ( $this->registered_options as $option ) {
			unregister_setting( 'group', $option );
		}
		$this->registered_options = array();

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
	 * Registers a setting and tracks it for cleanup.
	 *
	 * @since x.x.x
	 *
	 * @param string               $group  The settings group.
	 * @param string               $option The option name.
	 * @param array<string, mixed> $args   The registration arguments.
	 */
	private function register_setting( string $group, string $option, array $args ): void {
		$this->registered_options[] = $option;
		register_setting( $group, $option, $args );
	}

	/**
	 * A curated setting is flagged with `show_in_abilities => true`.
	 *
	 * @since x.x.x
	 */
	public function test_marks_curated_boolean_setting(): void {
		$this->register_setting( 'general', 'blogname', array( 'type' => 'string' ) );

		$settings = get_registered_settings();

		$this->assertTrue( $settings['blogname']['show_in_abilities'] );
	}

	/**
	 * A curated setting that maps to an array value receives that array verbatim.
	 *
	 * @since x.x.x
	 */
	public function test_marks_curated_array_setting(): void {
		$this->register_setting( 'discussion', 'default_comment_status', array( 'type' => 'string' ) );

		$settings = get_registered_settings();

		$this->assertSame(
			array( 'schema' => array( 'enum' => array( 'open', 'closed' ) ) ),
			$settings['default_comment_status']['show_in_abilities']
		);
	}

	/**
	 * A setting that is not in the curated map is left untouched.
	 *
	 * @since x.x.x
	 */
	public function test_does_not_mark_uncurated_setting(): void {
		$this->register_setting( 'general', 'wpai_not_curated_option', array( 'type' => 'string' ) );

		$settings = get_registered_settings();

		$this->assertTrue( empty( $settings['wpai_not_curated_option']['show_in_abilities'] ) );
	}

	/**
	 * An explicit `show_in_abilities` value already on the setting is preserved.
	 *
	 * @since x.x.x
	 */
	public function test_respects_existing_value(): void {
		$this->register_setting(
			'general',
			'blogname',
			array(
				'type'              => 'string',
				'show_in_abilities' => array( 'name' => 'custom_title' ),
			)
		);

		$settings = get_registered_settings();

		$this->assertSame( array( 'name' => 'custom_title' ), $settings['blogname']['show_in_abilities'] );
	}

	/**
	 * Curated core post types are marked directly, since they register before the filter.
	 *
	 * @since x.x.x
	 */
	public function test_marks_curated_registered_post_types(): void {
		// Show_In_Abilities::register() ran in setUp and patches existing post types.
		$this->assertNotEmpty( get_post_type_object( 'post' )->show_in_abilities );
		$this->assertNotEmpty( get_post_type_object( 'page' )->show_in_abilities );
	}

	/**
	 * The post type args filter marks a curated post type when it is registered.
	 *
	 * @since x.x.x
	 */
	public function test_filter_marks_curated_post_type(): void {
		$args = Show_In_Abilities::mark_post_type( array(), 'page' );

		$this->assertTrue( $args['show_in_abilities'] );
	}

	/**
	 * The post type args filter leaves uncurated post types untouched.
	 *
	 * @since x.x.x
	 */
	public function test_filter_skips_uncurated_post_type(): void {
		$args = Show_In_Abilities::mark_post_type( array(), 'wpai_not_curated_cpt' );

		$this->assertTrue( empty( $args['show_in_abilities'] ) );
	}

	/**
	 * An explicit `show_in_abilities` value already on the post type is preserved.
	 *
	 * @since x.x.x
	 */
	public function test_filter_respects_existing_post_type_value(): void {
		$args = Show_In_Abilities::mark_post_type(
			array( 'show_in_abilities' => array( 'custom' => true ) ),
			'post'
		);

		$this->assertSame( array( 'custom' => true ), $args['show_in_abilities'] );
	}
}

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
	 * The component under test. Held so the same instance can detach its filter on tear down.
	 *
	 * @since x.x.x
	 *
	 * @var \WordPress\AI\Abilities\Show_In_Abilities
	 */
	private $show_in_abilities;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->show_in_abilities = new Show_In_Abilities();
		$this->show_in_abilities->register();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		remove_filter( 'register_setting_args', array( $this->show_in_abilities, 'mark_setting' ), 10 );

		foreach ( $this->registered_options as $option ) {
			unregister_setting( 'group', $option );
		}
		$this->registered_options = array();

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
	 * Non-array arguments are returned untouched rather than fataling.
	 *
	 * Core applies the `register_setting_args` filter before `wp_parse_args()` normalizes the
	 * arguments, so a plugin that calls `register_setting()` with a non-array (e.g. WPBakery
	 * Page Builder passing `null`) reaches the filter unchanged. The callback must hand it back
	 * as-is for core to normalize, not blow up on a strict array type.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_non_array_args
	 *
	 * @param mixed $args A non-array value passed through the filter.
	 */
	public function test_passes_through_non_array_args( $args ): void {
		$filtered = $this->show_in_abilities->mark_setting( $args, array(), 'general', 'blogname' );

		$this->assertSame( $args, $filtered );
	}

	/**
	 * Non-array argument values for the pass-through test.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: mixed}> Data sets keyed by description.
	 */
	public function data_non_array_args(): array {
		return array(
			'null'   => array( null ),
			'string' => array( 'sanitize_me' ),
			'false'  => array( false ),
		);
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
}

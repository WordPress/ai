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
	 * Option names registered during a test, cleaned up on tear down.
	 *
	 * @since 1.1.0
	 *
	 * @var array<string>
	 */
	private $registered_options = array();

	/**
	 * The component under test. Held so the same instance can detach its filters on tear down.
	 *
	 * @since 1.1.0
	 *
	 * @var \WordPress\AI\Abilities\Show_In_Abilities
	 */
	private $show_in_abilities;

	/**
	 * Set up test case.
	 *
	 * @since 1.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		$this->show_in_abilities = new Show_In_Abilities();
		$this->show_in_abilities->register();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Also resets post type flags.
	 */
	public function tearDown(): void {
		remove_filter( 'register_setting_args', array( $this->show_in_abilities, 'mark_setting' ), 10 );
		remove_filter( 'register_post_type_args', array( $this->show_in_abilities, 'mark_post_type' ), 10 );
		remove_action( 'registered_post_type', array( $this->show_in_abilities, 'normalize_post_type' ), 0 );

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

			if ( property_exists( \WP_Post_Type::class, 'show_in_abilities' ) ) {
				$object->show_in_abilities = true;
			} else {
				unset( $object->show_in_abilities );
			}
		}

		parent::tearDown();
	}

	/**
	 * Registers a setting and tracks it for cleanup.
	 *
	 * @since 1.1.0
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
	 * Checks whether WordPress core declares the setting flag natively.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when core declares show_in_abilities in register_setting defaults.
	 */
	private function core_declares_setting_flag(): bool {
		static $declared = null;

		if ( null !== $declared ) {
			return $declared;
		}

		$captured = null;
		$spy      = static function ( $args, $defaults ) use ( &$captured ) {
			$captured = $defaults;
			return $args;
		};

		add_filter( 'register_setting_args', $spy, 1, 2 );
		try {
			register_setting( 'wpai_probe_group', 'wpai_probe_setting', array( 'type' => 'string' ) );
		} finally {
			remove_filter( 'register_setting_args', $spy, 1 );
			unregister_setting( 'wpai_probe_group', 'wpai_probe_setting' );
		}

		$declared = is_array( $captured ) && array_key_exists( 'show_in_abilities', $captured );

		return $declared;
	}

	/**
	 * Checks whether WordPress core declares the post type flag natively.
	 *
	 * @since x.x.x
	 *
	 * @return bool True when core declares show_in_abilities on WP_Post_Type.
	 */
	private function core_declares_post_type_flag(): bool {
		return property_exists( \WP_Post_Type::class, 'show_in_abilities' );
	}

	/**
	 * A curated setting is flagged with `show_in_abilities => true`.
	 *
	 * @since 1.1.0
	 */
	public function test_marks_curated_boolean_setting(): void {
		if ( $this->core_declares_setting_flag() ) {
			$this->markTestSkipped( 'Core declares show_in_abilities natively; the polyfill stands down.' );
		}

		$this->register_setting( 'general', 'blogname', array( 'type' => 'string' ) );

		$settings = get_registered_settings();

		$this->assertTrue( $settings['blogname']['show_in_abilities'] );
	}

	/**
	 * A curated setting with a REST API schema is flagged with `true`, so it reuses that schema.
	 *
	 * @since 1.1.0
	 * @since x.x.x The setting is flagged with `true` instead of a copy of its REST API schema.
	 */
	public function test_marks_curated_setting_with_rest_api_schema(): void {
		if ( $this->core_declares_setting_flag() ) {
			$this->markTestSkipped( 'Core declares show_in_abilities natively; the polyfill stands down.' );
		}

		$this->register_setting(
			'discussion',
			'default_comment_status',
			array(
				'type'         => 'string',
				'show_in_rest' => array( 'schema' => array( 'enum' => array( 'open', 'closed' ) ) ),
			)
		);

		$settings = get_registered_settings();

		$this->assertTrue( $settings['default_comment_status']['show_in_abilities'] );
	}

	/**
	 * A setting that is not in the curated map is left untouched.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * @since 1.1.0
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
	 * An explicit `show_in_abilities => false` opt-out is preserved.
	 *
	 * A falsy value is still a value. The polyfill fills the flag in only when the key is
	 * absent, so a site that deliberately opts a curated setting out keeps that choice.
	 *
	 * @since 1.2.0
	 */
	public function test_respects_explicit_false_setting_value(): void {
		$args = $this->show_in_abilities->mark_setting(
			array( 'show_in_abilities' => false ),
			array(),
			'general',
			'blogname'
		);

		$this->assertFalse(
			$args['show_in_abilities'],
			'An explicit opt-out must not be treated as an absent value.'
		);
	}

	/**
	 * The polyfill stands down once core declares the flag among its defaults.
	 *
	 * `register_setting()` filters the caller's arguments before merging them over its
	 * defaults, so the defaults array says which arguments core understands. Once
	 * `show_in_abilities` is one of them, core picks the default and each setting opts in,
	 * and the polyfill must not force a curated setting back on.
	 *
	 * @since 1.2.0
	 */
	public function test_stands_down_once_core_declares_the_flag(): void {
		$args = $this->show_in_abilities->mark_setting(
			array( 'type' => 'string' ),
			array( 'show_in_abilities' => false ),
			'general',
			'blogname'
		);

		$this->assertArrayNotHasKey(
			'show_in_abilities',
			$args,
			'Once core declares the flag it owns the default; the polyfill must not fill it in.'
		);
	}

	/**
	 * Core does not declare the setting flag yet, so the polyfill is still needed.
	 *
	 * A tripwire. It reads the defaults core actually passes to the `register_setting_args`
	 * filter, and skips when core starts shipping the argument natively.
	 *
	 * @since 1.2.0
	 */
	public function test_core_does_not_yet_declare_the_setting_flag(): void {
		if ( $this->core_declares_setting_flag() ) {
			$this->markTestSkipped( 'Core now declares show_in_abilities natively; the polyfill has stood down.' );
		}

		$captured = null;
		$spy      = static function ( $args, $defaults ) use ( &$captured ) {
			$captured = $defaults;
			return $args;
		};

		add_filter( 'register_setting_args', $spy, 1, 2 );
		try {
			register_setting( 'wpai_probe_group', 'wpai_probe_setting', array( 'type' => 'string' ) );
		} finally {
			remove_filter( 'register_setting_args', $spy, 1 );
			unregister_setting( 'wpai_probe_group', 'wpai_probe_setting' );
		}

		$this->assertIsArray( $captured, 'Precondition: the filter should receive the defaults from core.' );
		$this->assertArrayNotHasKey(
			'show_in_abilities',
			$captured,
			'Core now declares show_in_abilities as a setting argument; the polyfill must step aside.'
		);
	}

	/**
	 * Curated core post types are marked directly, since they register before the filter.
	 *
	 * @since 1.2.0
	 */
	public function test_marks_curated_registered_post_types(): void {
		if ( $this->core_declares_post_type_flag() ) {
			$this->markTestSkipped( 'Core declares show_in_abilities natively; the polyfill stands down.' );
		}

		// $this->show_in_abilities->register() ran in setUp and patches existing post types.
		$this->assertNotEmpty( get_post_type_object( 'post' )->show_in_abilities );
		$this->assertNotEmpty( get_post_type_object( 'page' )->show_in_abilities );
	}

	/**
	 * The post type args filter marks a curated post type when it is registered.
	 *
	 * @since 1.2.0
	 */
	public function test_filter_marks_curated_post_type(): void {
		if ( $this->core_declares_post_type_flag() ) {
			$this->markTestSkipped( 'Core declares show_in_abilities natively; the polyfill stands down.' );
		}

		$args = $this->show_in_abilities->mark_post_type( array(), 'page' );

		$this->assertTrue( $args['show_in_abilities'] );
	}

	/**
	 * The post type args filter leaves uncurated post types untouched.
	 *
	 * @since 1.2.0
	 */
	public function test_filter_skips_uncurated_post_type(): void {
		$args = $this->show_in_abilities->mark_post_type( array(), 'wpai_not_curated_cpt' );

		$this->assertTrue( empty( $args['show_in_abilities'] ) );
	}

	/**
	 * Argument filters retain the original value until registration finishes.
	 *
	 * @since x.x.x
	 */
	public function test_filter_preserves_value_for_later_filters(): void {
		$args = $this->show_in_abilities->mark_post_type(
			array( 'show_in_abilities' => array( 'custom' => true ) ),
			'post'
		);

		$this->assertSame( array( 'custom' => true ), $args['show_in_abilities'] );
	}

	/**
	 * Both late global filters and post-type-specific filters are normalized.
	 *
	 * @since x.x.x
	 * @dataProvider data_post_type_exposure
	 *
	 * @param string $hook     Registration argument filter.
	 * @param mixed  $value    Final filter value.
	 * @param bool   $expected Expected exposure.
	 */
	public function test_normalizes_final_registration_arguments( string $hook, $value, bool $expected ): void {
		$filter = static function ( array $args, string $post_type ) use ( $value ): array {
			if ( 'ai_exposure_probe' === $post_type ) {
				$args['show_in_abilities'] = $value;
			}
			return $args;
		};

		add_filter( $hook, $filter, 99, 2 );
		try {
			$object = register_post_type( 'ai_exposure_probe', array( 'public' => true ) );

			$this->assertSame( $expected, $object->show_in_abilities );
			$this->assertSame( $expected, in_array( 'ai_exposure_probe', get_post_types( array( 'show_in_abilities' => true ) ), true ) );
		} finally {
			remove_filter( $hook, $filter, 99 );
			unregister_post_type( 'ai_exposure_probe' );
		}
	}

	/**
	 * Registration filters and exposure values.
	 *
	 * @since x.x.x
	 * @return array<string, array<mixed>> Test cases.
	 */
	public static function data_post_type_exposure(): array {
		$cases = array();
		foreach ( array( 'register_post_type_args', 'register_ai_exposure_probe_post_type_args' ) as $hook ) {
			foreach ( array( true, false, 1, 'true', array( 'query' => true ), null ) as $index => $value ) {
				$cases[ $hook . '_' . $index ] = array( $hook, $value, true === $value );
			}
		}
		return $cases;
	}

	/**
	 * Types registered before the polyfill also receive strict normalization.
	 *
	 * @since x.x.x
	 */
	public function test_normalizes_previously_registered_post_types(): void {
		if ( $this->core_declares_post_type_flag() ) {
			$this->markTestSkipped( 'Core normalizes the flag during registration.' );
		}

		remove_action( 'registered_post_type', array( $this->show_in_abilities, 'normalize_post_type' ), 0 );
		try {
			$object = register_post_type( 'ai_exposure_probe', array( 'show_in_abilities' => array( 'query' => true ) ) );
			$this->assertIsArray( $object->show_in_abilities );

			$this->show_in_abilities->mark_registered_post_types();

			$this->assertFalse( $object->show_in_abilities );
		} finally {
			unregister_post_type( 'ai_exposure_probe' );
			add_action( 'registered_post_type', array( $this->show_in_abilities, 'normalize_post_type' ), 0, 2 );
		}
	}

	/**
	 * An explicit `show_in_abilities => false` opt-out passed to the filter is preserved.
	 *
	 * @since 1.2.0
	 */
	public function test_filter_respects_explicit_false_post_type_value(): void {
		$args = $this->show_in_abilities->mark_post_type(
			array( 'show_in_abilities' => false ),
			'page'
		);

		$this->assertFalse( $args['show_in_abilities'] );
	}

	/**
	 * An explicit `show_in_abilities => false` opt-out on a registered post type object is preserved.
	 *
	 * @since 1.2.0
	 */
	public function test_direct_patch_respects_explicit_false(): void {
		if ( $this->core_declares_post_type_flag() ) {
			$this->markTestSkipped( 'Core declares show_in_abilities natively; the polyfill stands down.' );
		}

		get_post_type_object( 'page' )->show_in_abilities = false;

		$this->show_in_abilities->mark_registered_post_types();

		$this->assertFalse( get_post_type_object( 'page' )->show_in_abilities );
	}

	/**
	 * Core does not declare the post type flag yet, so the polyfill is still needed.
	 *
	 * This is a tripwire. When core declares `show_in_abilities` on `WP_Post_Type`, both
	 * polyfill paths stand down and core owns the flag. If that lands, the curated post
	 * types are only exposed when core exposes them, so review `Show_In_Abilities` and the
	 * `core/content-query` registration before deleting this test.
	 *
	 * @since 1.2.0
	 */
	public function test_core_does_not_yet_declare_the_post_type_flag(): void {
		if ( $this->core_declares_post_type_flag() ) {
			$this->markTestSkipped( 'Core now declares show_in_abilities natively; the polyfill has stood down.' );
		}

		$this->assertFalse(
			property_exists( \WP_Post_Type::class, 'show_in_abilities' ),
			'Core now declares show_in_abilities on WP_Post_Type; the polyfill must step aside.'
		);
	}
}

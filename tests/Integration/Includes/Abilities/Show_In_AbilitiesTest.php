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
	 * @since x.x.x Also resets taxonomy flags.
	 */
	public function tearDown(): void {
		remove_filter( 'register_setting_args', array( $this->show_in_abilities, 'mark_setting' ), 10 );
		remove_filter( 'register_post_type_args', array( $this->show_in_abilities, 'mark_post_type' ), 10 );
		remove_action( 'registered_post_type', array( $this->show_in_abilities, 'normalize_post_type' ), 0 );
		remove_filter( 'register_taxonomy_args', array( $this->show_in_abilities, 'mark_taxonomy' ), 10 );
		remove_action( 'registered_taxonomy', array( $this->show_in_abilities, 'normalize_taxonomy' ), 0 );

		foreach ( $this->registered_options as $option ) {
			unregister_setting( 'group', $option );
		}
		$this->registered_options = array();

		// Restore the curated post types and taxonomies: marked once core declares the flag, unmarked before.
		foreach ( $this->data_object_kinds() as [ $kind ] ) {
			foreach ( $kind['curated'] as $name ) {
				$object = $kind['get']( $name );
				if ( ! $object ) {
					continue;
				}

				if ( $this->core_declares_object_flag( $kind ) ) {
					$object->show_in_abilities = true;
				} else {
					unset( $object->show_in_abilities );
				}
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
	 * @since 1.5.0
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
	 * Checks whether WordPress core declares the flag natively for an object kind.
	 *
	 * @since 1.5.0
	 * @since x.x.x Takes the object kind, to cover taxonomies.
	 *
	 * @param array<string, mixed> $kind The object kind.
	 * @return bool True when core declares show_in_abilities on the kind's class.
	 */
	private function core_declares_object_flag( array $kind ): bool {
		return property_exists( $kind['class'], 'show_in_abilities' );
	}

	/**
	 * Registers the `ai_exposure_probe` post type or taxonomy, for the normalization tests.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $kind The object kind.
	 * @param array<string, mixed> $args The registration arguments.
	 * @return object The registered object.
	 */
	private function register_probe( array $kind, array $args ): object {
		if ( \WP_Taxonomy::class === $kind['class'] ) {
			return register_taxonomy( 'ai_exposure_probe', 'post', $args );
		}

		return register_post_type( 'ai_exposure_probe', $args );
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
	 * @since 1.5.0 The setting is flagged with `true` instead of a copy of its REST API schema.
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
	 * The curated core object kinds: the methods that mark them, how core gets and registers
	 * them, their curated names, an uncurated name, the class core would declare the flag on,
	 * and the hooks and functions the normalization tests use.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: array<string, mixed>}> Data sets keyed by object kind.
	 */
	public function data_object_kinds(): array {
		return array(
			'post types' => array(
				array(
					'mark'            => 'mark_post_type',
					'mark_registered' => 'mark_registered_post_types',
					'get'             => 'get_post_type_object',
					'register_again'  => 'create_initial_post_types',
					'curated'         => array( 'post', 'page' ),
					'uncurated'       => 'wpai_not_curated_cpt',
					'class'           => \WP_Post_Type::class,
					'args_hooks'      => array( 'register_post_type_args', 'register_ai_exposure_probe_post_type_args' ),
					'normalize'       => 'normalize_post_type',
					'registered_hook' => 'registered_post_type',
					'list'            => 'get_post_types',
					'unregister'      => 'unregister_post_type',
				),
			),
			'taxonomies' => array(
				array(
					'mark'            => 'mark_taxonomy',
					'mark_registered' => 'mark_registered_taxonomies',
					'get'             => 'get_taxonomy',
					'register_again'  => 'create_initial_taxonomies',
					'curated'         => array( 'category', 'post_tag' ),
					'uncurated'       => 'nav_menu',
					'class'           => \WP_Taxonomy::class,
					'args_hooks'      => array( 'register_taxonomy_args', 'register_ai_exposure_probe_taxonomy_args' ),
					'normalize'       => 'normalize_taxonomy',
					'registered_hook' => 'registered_taxonomy',
					'list'            => 'get_taxonomies',
					'unregister'      => 'unregister_taxonomy',
				),
			),
		);
	}

	/**
	 * Curated core objects are marked directly, since core registers them before the filters.
	 *
	 * @since 1.2.0
	 * @since x.x.x Also covers taxonomies.
	 *
	 * @dataProvider data_object_kinds
	 *
	 * @param array<string, mixed> $kind The object kind.
	 */
	public function test_marks_curated_registered_objects( array $kind ): void {
		if ( $this->core_declares_object_flag( $kind ) ) {
			$this->markTestSkipped( 'Core declares show_in_abilities natively; the polyfill stands down.' );
		}

		// $this->show_in_abilities->register() ran in setUp and patches existing objects.
		foreach ( $kind['curated'] as $name ) {
			$this->assertNotEmpty( $kind['get']( $name )->show_in_abilities );
		}
	}

	/**
	 * The args filter marks a curated object when it is registered.
	 *
	 * @since 1.2.0
	 * @since x.x.x Also covers taxonomies.
	 *
	 * @dataProvider data_object_kinds
	 *
	 * @param array<string, mixed> $kind The object kind.
	 */
	public function test_filter_marks_curated_object( array $kind ): void {
		if ( $this->core_declares_object_flag( $kind ) ) {
			$this->markTestSkipped( 'Core declares show_in_abilities natively; the polyfill stands down.' );
		}

		foreach ( $kind['curated'] as $name ) {
			$args = $this->show_in_abilities->{$kind['mark']}( array(), $name );

			$this->assertTrue( $args['show_in_abilities'] );
		}
	}

	/**
	 * The args filter leaves uncurated objects untouched.
	 *
	 * @since 1.2.0
	 * @since x.x.x Also covers taxonomies.
	 *
	 * @dataProvider data_object_kinds
	 *
	 * @param array<string, mixed> $kind The object kind.
	 */
	public function test_filter_skips_uncurated_object( array $kind ): void {
		$args = $this->show_in_abilities->{$kind['mark']}( array(), $kind['uncurated'] );

		$this->assertTrue( empty( $args['show_in_abilities'] ) );
	}

	/**
	 * Argument filters retain the original value until registration finishes.
	 *
	 * @since 1.5.0
	 * @since x.x.x Also covers taxonomies.
	 *
	 * @dataProvider data_object_kinds
	 *
	 * @param array<string, mixed> $kind The object kind.
	 */
	public function test_filter_preserves_value_for_later_filters( array $kind ): void {
		$args = $this->show_in_abilities->{$kind['mark']}(
			array( 'show_in_abilities' => array( 'custom' => true ) ),
			$kind['curated'][0]
		);

		$this->assertSame( array( 'custom' => true ), $args['show_in_abilities'] );
	}

	/**
	 * Both late global filters and object-specific filters are normalized.
	 *
	 * @since 1.5.0
	 * @since x.x.x Also covers taxonomies.
	 *
	 * @dataProvider data_object_exposure
	 *
	 * @param array<string, mixed> $kind     The object kind.
	 * @param string               $hook     Registration argument filter.
	 * @param mixed                $value    Final filter value.
	 * @param bool                 $expected Expected exposure.
	 */
	public function test_normalizes_final_registration_arguments( array $kind, string $hook, $value, bool $expected ): void {
		$filter = static function ( array $args, string $name ) use ( $value ): array {
			if ( 'ai_exposure_probe' === $name ) {
				$args['show_in_abilities'] = $value;
			}
			return $args;
		};

		add_filter( $hook, $filter, 99, 2 );
		try {
			$object = $this->register_probe( $kind, array( 'public' => true ) );

			$this->assertSame( $expected, $object->show_in_abilities );
			$this->assertSame( $expected, in_array( 'ai_exposure_probe', $kind['list']( array( 'show_in_abilities' => true ) ), true ) );
		} finally {
			remove_filter( $hook, $filter, 99 );
			$kind['unregister']( 'ai_exposure_probe' );
		}
	}

	/**
	 * Registration filters and exposure values, for each object kind.
	 *
	 * @since 1.5.0
	 * @since x.x.x Also covers taxonomies.
	 *
	 * @return array<string, array<mixed>> Test cases.
	 */
	public function data_object_exposure(): array {
		$cases = array();
		foreach ( $this->data_object_kinds() as $kind_name => [ $kind ] ) {
			foreach ( $kind['args_hooks'] as $hook ) {
				foreach ( array( true, false, 1, 'true', array( 'query' => true ), null ) as $index => $value ) {
					$cases[ $kind_name . ' ' . $hook . '_' . $index ] = array( $kind, $hook, $value, true === $value );
				}
			}
		}
		return $cases;
	}

	/**
	 * Objects registered before the polyfill also receive strict normalization.
	 *
	 * @since 1.5.0
	 * @since x.x.x Also covers taxonomies.
	 *
	 * @dataProvider data_object_kinds
	 *
	 * @param array<string, mixed> $kind The object kind.
	 */
	public function test_normalizes_previously_registered_objects( array $kind ): void {
		if ( $this->core_declares_object_flag( $kind ) ) {
			$this->markTestSkipped( 'Core normalizes the flag during registration.' );
		}

		$normalize = array( $this->show_in_abilities, $kind['normalize'] );
		remove_action( $kind['registered_hook'], $normalize, 0 );
		try {
			$object = $this->register_probe( $kind, array( 'show_in_abilities' => array( 'query' => true ) ) );
			$this->assertIsArray( $object->show_in_abilities );

			$this->show_in_abilities->{$kind['mark_registered']}();

			$this->assertFalse( $object->show_in_abilities );
		} finally {
			$kind['unregister']( 'ai_exposure_probe' );
			add_action( $kind['registered_hook'], $normalize, 0, 2 );
		}
	}

	/**
	 * An explicit `show_in_abilities => false` opt-out passed to the args filter is preserved.
	 *
	 * @since 1.2.0
	 * @since x.x.x Also covers taxonomies.
	 *
	 * @dataProvider data_object_kinds
	 *
	 * @param array<string, mixed> $kind The object kind.
	 */
	public function test_filter_respects_explicit_false_object_value( array $kind ): void {
		$args = $this->show_in_abilities->{$kind['mark']}(
			array( 'show_in_abilities' => false ),
			$kind['curated'][0]
		);

		$this->assertFalse( $args['show_in_abilities'] );
	}

	/**
	 * An explicit `show_in_abilities => false` opt-out on a registered object is preserved.
	 *
	 * @since 1.2.0
	 * @since x.x.x Also covers taxonomies.
	 *
	 * @dataProvider data_object_kinds
	 *
	 * @param array<string, mixed> $kind The object kind.
	 */
	public function test_direct_patch_respects_explicit_false( array $kind ): void {
		if ( $this->core_declares_object_flag( $kind ) ) {
			$this->markTestSkipped( 'Core declares show_in_abilities natively; the polyfill stands down.' );
		}

		$name = $kind['curated'][0];

		$kind['get']( $name )->show_in_abilities = false;

		$this->show_in_abilities->{$kind['mark_registered']}();

		$this->assertFalse( $kind['get']( $name )->show_in_abilities );
	}

	/**
	 * The curated objects are marked again when core registers them again.
	 *
	 * Core registers its post types and taxonomies again on `change_locale`, replacing the
	 * patched objects, so the filters have to mark the new ones.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_object_kinds
	 *
	 * @param array<string, mixed> $kind The object kind.
	 */
	public function test_marks_curated_objects_registered_again( array $kind ): void {
		if ( $this->core_declares_object_flag( $kind ) ) {
			$this->markTestSkipped( 'Core declares show_in_abilities natively; the polyfill stands down.' );
		}

		$kind['register_again']();

		foreach ( $kind['curated'] as $name ) {
			$this->assertTrue( $kind['get']( $name )->show_in_abilities );
		}
	}

	/**
	 * Core does not declare the flag yet, so the polyfill is still needed.
	 *
	 * This is a tripwire. When core declares `show_in_abilities` on `WP_Post_Type` or
	 * `WP_Taxonomy`, both polyfill paths stand down for that kind and core owns the flag. If
	 * that lands, the curated objects are only exposed when core exposes them, so review
	 * `Show_In_Abilities` and the `core/content-query` or `core/terms-query` registration
	 * before deleting this test.
	 *
	 * @since 1.2.0
	 * @since x.x.x Also covers taxonomies.
	 *
	 * @dataProvider data_object_kinds
	 *
	 * @param array<string, mixed> $kind The object kind.
	 */
	public function test_core_does_not_yet_declare_the_object_flag( array $kind ): void {
		if ( $this->core_declares_object_flag( $kind ) ) {
			$this->markTestSkipped( 'Core now declares show_in_abilities natively; the polyfill has stood down.' );
		}

		$this->assertFalse(
			property_exists( $kind['class'], 'show_in_abilities' ),
			"Core now declares show_in_abilities on {$kind['class']}; the polyfill must step aside."
		);
	}
}

<?php
/**
 * Integration tests for the core/settings-get Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Settings
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Settings;

use WP_Ability;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Settings\Settings;
use WordPress\AI\Abilities\Show_In_Abilities;

/**
 * Settings ability test case.
 *
 * @since 1.1.0
 */
class SettingsTest extends WP_UnitTestCase {

	/**
	 * The settings exposure component. Held so the same instance can detach its filter on tear down.
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

		// Mark the curated core settings, then register them (as happens on rest_api_init).
		$this->show_in_abilities = new Show_In_Abilities();
		$this->show_in_abilities->register();
		register_initial_settings();

		// A non-core setting flagged for the Abilities API, to verify that any registered
		// setting (not just the core ones) is exposed by the ability.
		register_setting(
			'general',
			'core_settings_get_ability_test_option',
			array(
				'type'              => 'integer',
				'label'             => 'Custom Ability Setting',
				'description'       => 'A custom setting exposed through the Abilities API.',
				'show_in_abilities' => true,
				'default'           => 42,
			)
		);
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.1.0
	 */
	public function tearDown(): void {
		foreach ( array( 'core/settings-get', 'core/read-settings' ) as $ability_name ) {
			if ( ! wp_has_ability( $ability_name ) ) {
				continue;
			}

			wp_unregister_ability( $ability_name );
		}

		remove_filter( 'register_setting_args', array( $this->show_in_abilities, 'mark_setting' ), 10 );
		unregister_setting( 'general', 'core_settings_get_ability_test_option' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Registers the plugin's core/settings-get ability inside a faked init action.
	 *
	 * @since 1.1.0
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Settings() )->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Logs in as an administrator so the ability's permission check passes.
	 *
	 * @since 1.1.0
	 */
	private function become_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Core settings are exposed when abilities initialize before the REST API.
	 *
	 * Simulates cron, WP-CLI, or any request that uses the Abilities API before
	 * `rest_api_init` registers core's initial settings.
	 *
	 * @since 1.2.0
	 */
	public function test_core_settings_get_registers_initial_settings_without_rest_api_init(): void {
		global $wp_registered_settings, $wp_actions;

		$registered_settings_backup = $wp_registered_settings;
		$rest_api_init_count        = $wp_actions['rest_api_init'] ?? null;
		$wp_registered_settings     = array(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Simulating WordPress before its settings are registered.
		unset( $wp_actions['rest_api_init'] );

		try {
			$this->register_ability();

			$ability = wp_get_ability( 'core/settings-get' );
			$this->assertArrayHasKey( 'blogname', $ability->get_output_schema()['properties'] );

			$this->become_admin();
			$result = $ability->execute( array( 'fields' => array( 'blogname' ) ) );

			$this->assertArrayHasKey( 'blogname', $result );
		} finally {
			if ( wp_has_ability( 'core/settings-get' ) ) {
				wp_unregister_ability( 'core/settings-get' );
			}

			$wp_registered_settings = $registered_settings_backup; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restoring the WordPress test global.
			if ( null === $rest_api_init_count ) {
				unset( $wp_actions['rest_api_init'] );
			} else {
				$wp_actions['rest_api_init'] = $rest_api_init_count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the WordPress test global.
			}
		}
	}

	/**
	 * Tests that registering initial settings for abilities does not pollute $new_allowed_options.
	 *
	 * @since 1.4.0
	 */
	public function test_register_preserves_new_allowed_options(): void {
		global $new_allowed_options;

		$prev_actions_count  = $GLOBALS['wp_actions']['rest_api_init'] ?? null;
		$prev_allowed_backup = $new_allowed_options;
		unset( $GLOBALS['wp_actions']['rest_api_init'] );

		// Simulate an existing custom setting already in $new_allowed_options.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Simulating WordPress core global.
		$new_allowed_options = array(
			'general' => array( 'my_custom_option' ),
		);

		try {
			$this->register_ability();

			// 'admin_email' must NOT be in $new_allowed_options['general'].
			$this->assertNotContains( 'admin_email', $new_allowed_options['general'] );
			// Prior allowed options must be preserved.
			$this->assertContains( 'my_custom_option', $new_allowed_options['general'] );
		} finally {
			$new_allowed_options = $prev_allowed_backup; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restoring the WordPress test global.
			if ( null === $prev_actions_count ) {
				unset( $GLOBALS['wp_actions']['rest_api_init'] );
			} else {
				$GLOBALS['wp_actions']['rest_api_init'] = $prev_actions_count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the WordPress test global.
			}
		}
	}

	/**
	 * The ability is registered in the `site` category and flagged read-only.
	 *
	 * @since 1.1.0
	 */
	public function test_core_settings_get_ability_is_registered(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/settings-get' );

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertSame( 'core/settings-get', $ability->get_name() );
		$this->assertSame( 'site', $ability->get_category() );
		$this->assertTrue( $ability->get_meta_item( 'public', false ) );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ) );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertTrue( $annotations['readonly'] );
		$this->assertFalse( $annotations['destructive'] );
	}

	/**
	 * When core already provides core/settings-get, the plugin's version replaces it.
	 *
	 * @since 1.1.0
	 */
	public function test_override_replaces_existing_core_settings_get(): void {
		// Simulate a core-provided ability with a different (minimal) shape.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				'core/settings-get',
				array(
					'label'               => 'Core Provided',
					'description'         => 'Core provided settings ability.',
					'category'            => 'site',
					'execute_callback'    => static function (): array {
						return array();
					},
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->assertSame( 'Core Provided', wp_get_ability( 'core/settings-get' )->get_label() );

		$this->register_ability();

		$ability = wp_get_ability( 'core/settings-get' );
		$this->assertSame( 'Settings Get', $ability->get_label() );
		// The plugin's shape exposes optional `group` and `fields` filters.
		$this->assertArrayHasKey( 'fields', $ability->get_input_schema()['properties'] );
	}

	/**
	 * The input schema exposes optional `group` and `fields` filters.
	 *
	 * @since 1.1.0
	 */
	public function test_core_settings_get_input_schema_exposes_group_and_fields_filters(): void {
		$this->register_ability();

		$schema = wp_get_ability( 'core/settings-get' )->get_input_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'default', $schema );
		$this->assertArrayNotHasKey( 'oneOf', $schema );

		$this->assertContains( 'general', $schema['properties']['group']['enum'] );
		$this->assertContains( 'reading', $schema['properties']['group']['enum'] );

		$this->assertContains( 'blogname', $schema['properties']['fields']['items']['enum'] );
		$this->assertContains( 'posts_per_page', $schema['properties']['fields']['items']['enum'] );
	}

	/**
	 * Without input the ability returns a flat map of correctly typed setting values.
	 *
	 * @since 1.1.0
	 */
	public function test_core_settings_get_returns_flat_typed_values(): void {
		$this->become_admin();
		$this->register_ability();

		update_option( 'blogname', 'My Test Site' );
		update_option( 'posts_per_page', 7 );
		update_option( 'use_smilies', '1' );

		$result = wp_get_ability( 'core/settings-get' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( 'My Test Site', $result['blogname'] );
		$this->assertSame( 7, $result['posts_per_page'] );
		$this->assertTrue( $result['use_smilies'] );
	}

	/**
	 * The `group` filter narrows the response to a single settings group.
	 *
	 * @since 1.1.0
	 */
	public function test_core_settings_get_filters_by_group(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-get' )->execute( array( 'group' => 'reading' ) );

		$this->assertArrayHasKey( 'posts_per_page', $result );
		$this->assertArrayNotHasKey( 'blogname', $result );
	}

	/**
	 * The `fields` filter narrows the response to the requested setting names.
	 *
	 * @since 1.1.0
	 */
	public function test_core_settings_get_filters_by_fields(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-get' )->execute( array( 'fields' => array( 'blogname', 'posts_per_page' ) ) );

		$this->assertEqualSets( array( 'blogname', 'posts_per_page' ), array_keys( $result ) );
	}

	/**
	 * Supplying both `group` and `fields` narrows the response to their intersection.
	 *
	 * @since 1.1.0
	 */
	public function test_core_settings_get_combines_group_and_fields_filters(): void {
		$this->become_admin();
		$this->register_ability();

		// `blogname` is in the `general` group and `posts_per_page` in `reading`; only the
		// latter satisfies both filters.
		$result = wp_get_ability( 'core/settings-get' )->execute(
			array(
				'group'  => 'reading',
				'fields' => array( 'blogname', 'posts_per_page' ),
			)
		);

		$this->assertEqualSets( array( 'posts_per_page' ), array_keys( $result ) );
	}

	/**
	 * Users without `manage_options` cannot run the ability.
	 *
	 * @since 1.1.0
	 */
	public function test_core_settings_get_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-get' )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * A setting registered with `show_in_abilities` (for example by a plugin) is exposed by the ability.
	 *
	 * @since 1.1.0
	 */
	public function test_core_settings_get_exposes_a_custom_registered_setting(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/settings-get' );

		// Present in both the input `fields` enum and the output schema built at registration.
		$this->assertContains( 'core_settings_get_ability_test_option', $ability->get_input_schema()['properties']['fields']['items']['enum'] );
		$this->assertArrayHasKey( 'core_settings_get_ability_test_option', $ability->get_output_schema()['properties'] );

		// And returned, correctly typed, by execute.
		$this->become_admin();
		update_option( 'core_settings_get_ability_test_option', 7 );

		$result = $ability->execute( array( 'fields' => array( 'core_settings_get_ability_test_option' ) ) );

		$this->assertSame( array( 'core_settings_get_ability_test_option' => 7 ), $result );
	}

	/**
	 * The old `core/read-settings` name is kept as a deprecated alias.
	 *
	 * @since 1.4.0
	 */
	public function test_registers_deprecated_read_settings_alias(): void {
		$this->register_ability();

		$alias   = wp_get_ability( 'core/read-settings' );
		$current = wp_get_ability( 'core/settings-get' );

		$this->assertInstanceOf( WP_Ability::class, $alias, 'The deprecated core/read-settings alias should be registered.' );
		$this->assertSame( 'Settings Get (deprecated)', $alias->get_label(), 'The alias label should mark it as deprecated.' );
		$this->assertStringContainsString( 'Use `core/settings-get` instead.', $alias->get_description(), 'The alias description should name the replacement.' );
		$this->assertSame( $current->get_category(), $alias->get_category(), 'The alias should share the replacement category.' );
		$this->assertSame( $current->get_input_schema(), $alias->get_input_schema(), 'The alias should share the replacement input schema.' );
		$this->assertSame( $current->get_output_schema(), $alias->get_output_schema(), 'The alias should share the replacement output schema.' );
		$this->assertTrue( $alias->get_meta_item( 'show_in_rest', false ), 'The alias should stay exposed over REST.' );
		$this->assertSame(
			array(
				'since'       => '1.4.0',
				'replacement' => 'core/settings-get',
			),
			$alias->get_meta_item( 'deprecated' ),
			'The alias meta should describe the deprecation.'
		);
	}

	/**
	 * Executing the deprecated alias forwards to `core/settings-get` and notifies.
	 *
	 * @since 1.4.0
	 */
	public function test_deprecated_read_settings_alias_forwards_to_settings_get(): void {
		$this->setExpectedDeprecated( 'core/read-settings' );

		$this->become_admin();
		$this->register_ability();

		$expected = wp_get_ability( 'core/settings-get' )->execute( array( 'fields' => array( 'blogname' ) ) );
		$result   = wp_get_ability( 'core/read-settings' )->execute( array( 'fields' => array( 'blogname' ) ) );

		$this->assertSame( $expected, $result, 'The alias should return the same result as the replacement ability.' );
	}

	/**
	 * The deprecated alias fails closed for users without `manage_options`.
	 *
	 * @since 1.4.0
	 */
	public function test_deprecated_read_settings_alias_forwards_permission_check(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->register_ability();

		$result = wp_get_ability( 'core/read-settings' )->execute( array() );

		$this->assertWPError( $result, 'The alias should reject users without manage_options like the replacement does.' );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), 'The alias should use the invalid permissions error.' );
	}
}

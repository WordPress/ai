<?php
/**
 * Integration tests for the core/settings-get and core/settings-update Abilities provided by the plugin.
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
		foreach ( array( 'core/settings-get', 'core/read-settings', 'core/settings-update' ) as $ability_name ) {
			if ( ! wp_has_ability( $ability_name ) ) {
				continue;
			}

			wp_unregister_ability( $ability_name );
		}

		remove_filter( 'register_setting_args', array( $this->show_in_abilities, 'mark_setting' ), 10 );
		unregister_setting( 'general', 'core_settings_get_ability_test_option' );
		unregister_setting( 'somegroup', 'mycustomsetting' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Registers the plugin's settings abilities inside a faked init action.
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
			$this->assertArrayHasKey( 'title', $ability->get_output_schema()['properties'] );

			$this->become_admin();
			$result = $ability->execute( array( 'fields' => array( 'title' ) ) );

			$this->assertArrayHasKey( 'title', $result );
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
	 * Settings exposed with `show_in_abilities => true` use the same names as in the
	 * REST API settings endpoint.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_get_uses_rest_api_setting_names(): void {
		$this->register_ability();

		$properties = wp_get_ability( 'core/settings-get' )->get_output_schema()['properties'];

		foreach ( get_registered_settings() as $option_name => $args ) {
			if ( empty( $args['show_in_abilities'] ) || empty( $args['show_in_rest'] ) ) {
				continue;
			}

			$rest_name = is_array( $args['show_in_rest'] ) && ! empty( $args['show_in_rest']['name'] ) ? $args['show_in_rest']['name'] : $option_name;
			$this->assertArrayHasKey( $rest_name, $properties, "The {$option_name} setting should use its REST API name." );
		}
	}

	/**
	 * A setting exposed with `show_in_abilities => true` reuses its REST API name and schema,
	 * while an array is used instead of the REST API arguments.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_get_inherits_rest_api_exposure(): void {
		register_setting(
			'general',
			'core_settings_get_inherit_test_option',
			array(
				'show_in_rest'      => array(
					'name'   => 'inherited_name',
					'schema' => array( 'enum' => array( 'a', 'b' ) ),
				),
				'show_in_abilities' => true,
			)
		);
		register_setting(
			'general',
			'core_settings_get_override_test_option',
			array(
				'show_in_rest'      => array(
					'name' => 'rest_name',
				),
				'show_in_abilities' => array(
					'name' => 'ability_name',
				),
			)
		);

		try {
			$this->register_ability();
			$properties = wp_get_ability( 'core/settings-get' )->get_output_schema()['properties'];

			$this->assertSame( array( 'a', 'b' ), $properties['inherited_name']['enum'] );
			$this->assertArrayHasKey( 'ability_name', $properties );
			$this->assertArrayNotHasKey( 'rest_name', $properties );
		} finally {
			unregister_setting( 'general', 'core_settings_get_inherit_test_option' );
			unregister_setting( 'general', 'core_settings_get_override_test_option' );
		}
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
		$this->assertSame( array(), $schema['default'] );
		$this->assertArrayNotHasKey( 'oneOf', $schema );

		$this->assertContains( 'general', $schema['properties']['group']['enum'] );
		$this->assertContains( 'reading', $schema['properties']['group']['enum'] );

		$this->assertContains( 'title', $schema['properties']['fields']['items']['enum'] );
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
		$this->assertSame( 'My Test Site', $result['title'] );
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
		$this->assertArrayNotHasKey( 'title', $result );
	}

	/**
	 * The `fields` filter narrows the response to the requested setting names.
	 *
	 * @since 1.1.0
	 */
	public function test_core_settings_get_filters_by_fields(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-get' )->execute( array( 'fields' => array( 'title', 'posts_per_page' ) ) );

		$this->assertEqualSets( array( 'title', 'posts_per_page' ), array_keys( $result ) );
	}

	/**
	 * Supplying both `group` and `fields` narrows the response to their intersection.
	 *
	 * @since 1.1.0
	 */
	public function test_core_settings_get_combines_group_and_fields_filters(): void {
		$this->become_admin();
		$this->register_ability();

		// `title` is in the `general` group and `posts_per_page` in `reading`; only the
		// latter satisfies both filters.
		$result = wp_get_ability( 'core/settings-get' )->execute(
			array(
				'group'  => 'reading',
				'fields' => array( 'title', 'posts_per_page' ),
			)
		);

		$this->assertEqualSets( array( 'posts_per_page' ), array_keys( $result ) );
	}

	/**
	 * Input passed as an object is filtered like input passed as an array.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_get_filters_object_input(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-get' )->execute( (object) array( 'group' => 'reading' ) );

		$this->assertArrayHasKey( 'posts_per_page', $result );
		$this->assertArrayNotHasKey( 'title', $result );
	}

	/**
	 * A `fields` list passed as a comma-separated string is filtered like a `fields` array.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_get_filters_fields_passed_as_a_string(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-get' )->execute( array( 'fields' => 'title,posts_per_page' ) );

		$this->assertEqualSets( array( 'title', 'posts_per_page' ), array_keys( $result ) );
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
	 * A setting shown in the REST API without `show_in_abilities` is not exposed.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_get_skips_a_setting_only_shown_in_rest(): void {
		$option = 'core_settings_get_ability_rest_only_test_option';

		register_setting(
			'general',
			$option,
			array(
				'show_in_rest' => true,
			)
		);

		try {
			$this->register_ability();

			$this->assertArrayNotHasKey( $option, wp_get_ability( 'core/settings-get' )->get_output_schema()['properties'] );
		} finally {
			unregister_setting( 'general', $option );
		}
	}

	/**
	 * A value that does not match its schema is left out instead of failing the whole call.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_get_drops_values_that_fail_their_schema(): void {
		$this->become_admin();
		$this->register_ability();

		// sanitize_option() only coerces '0' and '' to 'closed', so this out-of-enum value sticks.
		update_option( 'default_ping_status', 'not-a-valid-status' );

		$result = wp_get_ability( 'core/settings-get' )->execute( array() );

		$this->assertNotWPError( $result, 'One bad value must not fail the whole ability.' );
		$this->assertArrayHasKey( 'title', $result, 'The other settings should still be returned.' );
		$this->assertArrayNotHasKey( 'default_ping_status', $result, 'Only the bad value should be left out.' );
	}

	/**
	 * Stored values are validated against their schema, left out when it rejects them, and
	 * sanitized otherwise.
	 *
	 * @since x.x.x
	 *
	 * @dataProvider data_stored_values
	 *
	 * @param string               $type     The setting type.
	 * @param mixed                $stored   The stored option value.
	 * @param string|null          $expected The value as JSON, or null when it is left out.
	 * @param array<string, mixed> $schema   Optional. The `show_in_abilities` schema of the setting. Default empty array.
	 */
	public function test_core_settings_get_reads_stored_values( string $type, $stored, ?string $expected, array $schema = array() ): void {
		// A numeric name, which PHP turns into an integer array key, must still match `fields`.
		$option = '123';

		register_setting(
			'general',
			$option,
			array(
				'type'              => $type,
				'show_in_abilities' => array( 'schema' => $schema ),
			)
		);
		update_option( $option, $stored );

		try {
			$this->become_admin();
			$this->register_ability();

			$result = wp_get_ability( 'core/settings-get' )->execute( array( 'fields' => array( $option ) ) );
		} finally {
			unregister_setting( 'general', $option );
		}

		$this->assertSame( $expected, isset( $result[ $option ] ) ? wp_json_encode( $result[ $option ] ) : null );
	}

	/**
	 * Stored values, and the JSON `core/settings-get` reads them as.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: string|null, 3?: array<string, mixed>}> Data sets keyed by description.
	 */
	public function data_stored_values(): array {
		return array(
			'"false" for a boolean'               => array( 'boolean', 'false', 'false' ),
			'an empty string for a boolean'       => array( 'boolean', '', 'false' ),
			'a stdClass for an object'            => array(
				'object',
				(object) array( 'a' => 1 ),
				'{"a":1}',
				array( 'properties' => array( 'a' => array( 'type' => 'integer' ) ) ),
			),
			'an undeclared property in an object' => array( 'object', array( 'a' => 1 ), null ),
			'an empty array for an object'        => array( 'object', array(), '{}' ),
			'a list with gaps for an array'       => array(
				'array',
				array(
					0 => 'a',
					2 => 'b',
				),
				'["a","b"]',
			),
			'a numeric string for an integer'     => array( 'integer', '7', '7' ),
			'a non-numeric string for an integer' => array( 'integer', 'abc', null ),
		);
	}

	/**
	 * A setting of a type the settings endpoint does not support is not exposed.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_get_skips_a_setting_with_an_unsupported_type(): void {
		register_setting(
			'somegroup',
			'mycustomsetting',
			array(
				'type'              => 'foo',
				'show_in_abilities' => true,
			)
		);
		update_option( 'mycustomsetting', 'value' );

		$this->become_admin();
		$this->register_ability();

		$ability = wp_get_ability( 'core/settings-get' );

		$this->assertArrayNotHasKey( 'mycustomsetting', $ability->get_output_schema()['properties'] );
		$this->assertArrayNotHasKey( 'mycustomsetting', $ability->execute( array() ) );
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

		$expected = wp_get_ability( 'core/settings-get' )->execute( array( 'fields' => array( 'title' ) ) );
		$result   = wp_get_ability( 'core/read-settings' )->execute( array( 'fields' => array( 'title' ) ) );

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

	/**
	 * Neither settings ability is registered when no setting is exposed to abilities.
	 *
	 * @since x.x.x
	 */
	public function test_settings_abilities_are_not_registered_without_exposed_settings(): void {
		global $wp_registered_settings, $wp_actions;

		$registered_settings_backup  = $wp_registered_settings;
		$rest_api_init_count         = $wp_actions['rest_api_init'] ?? null;
		$wp_registered_settings      = array(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Simulating a site without exposed settings.
		$wp_actions['rest_api_init'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Keeps register() from registering core's initial settings.

		try {
			$this->register_ability();

			$this->assertFalse( wp_has_ability( 'core/settings-get' ) );
			$this->assertFalse( wp_has_ability( 'core/settings-update' ) );
		} finally {
			$wp_registered_settings = $registered_settings_backup; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restoring the WordPress test global.
			if ( null === $rest_api_init_count ) {
				unset( $wp_actions['rest_api_init'] );
			} else {
				$wp_actions['rest_api_init'] = $rest_api_init_count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the WordPress test global.
			}
		}
	}

	/**
	 * The update ability is not registered when every exposed setting is read-only, while the get
	 * ability still is.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_update_is_not_registered_without_writable_settings(): void {
		global $wp_registered_settings, $wp_actions;

		if ( is_multisite() ) {
			$this->markTestSkipped( 'Core registers siteurl and admin_email on single sites only.' );
		}

		$registered_settings_backup  = $wp_registered_settings;
		$rest_api_init_count         = $wp_actions['rest_api_init'] ?? null;
		$wp_registered_settings      = array_intersect_key( $wp_registered_settings, array_flip( array( 'siteurl', 'admin_email' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Simulating a site that exposes only read-only settings.
		$wp_actions['rest_api_init'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Keeps register() from registering core's initial settings.

		try {
			$this->register_ability();

			$this->assertTrue( wp_has_ability( 'core/settings-get' ) );
			$this->assertFalse( wp_has_ability( 'core/settings-update' ) );
		} finally {
			$wp_registered_settings = $registered_settings_backup; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restoring the WordPress test global.
			if ( null === $rest_api_init_count ) {
				unset( $wp_actions['rest_api_init'] );
			} else {
				$wp_actions['rest_api_init'] = $rest_api_init_count; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the WordPress test global.
			}
		}
	}

	/**
	 * The update ability is registered in the `site` category and flagged as a destructive write.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_update_ability_is_registered(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/settings-update' );

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertSame( 'Settings Update', $ability->get_label() );
		$this->assertSame( 'site', $ability->get_category() );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ) );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertFalse( $annotations['readonly'] );
		$this->assertTrue( $annotations['destructive'] );
		$this->assertFalse( $annotations['idempotent'] );
	}

	/**
	 * Every setting the get ability reads is writable, except `url` and `email`, and
	 * accepts null. The answer can hold only the writable settings.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_update_schemas_cover_the_exposed_settings(): void {
		$this->register_ability();

		$get_output = wp_get_ability( 'core/settings-get' )->get_output_schema();
		$input      = wp_get_ability( 'core/settings-update' )->get_input_schema();
		$output     = wp_get_ability( 'core/settings-update' )->get_output_schema();
		$read_only  = array( 'url', 'email' );

		$this->assertSame( 'object', $input['type'] );
		$this->assertSame( 1, $input['minProperties'] );
		$this->assertFalse( $input['additionalProperties'] );
		$this->assertSame(
			array_values( array_diff( array_keys( $get_output['properties'] ), $read_only ) ),
			array_keys( $input['properties'] )
		);
		$this->assertSame( array( 'string', 'null' ), $input['properties']['title']['type'] );
		$this->assertSame( array( 'open', 'closed', null ), $input['properties']['default_ping_status']['enum'] );

		$this->assertSame(
			array_diff_key( $get_output['properties'], array_flip( $read_only ) ),
			$output['properties']
		);
		// An update can answer with no setting, when none reads back a value its schema accepts.
		$this->assertArrayNotHasKey( 'minProperties', $output );
		$this->assertFalse( $output['additionalProperties'] );
	}

	/**
	 * @since x.x.x
	 */
	public function test_update_item(): void {
		$this->become_admin();
		$this->register_ability();

		$data = wp_get_ability( 'core/settings-update' )->execute( array( 'title' => 'The new title!' ) );

		$this->assertSame( 'The new title!', $data['title'] );
		$this->assertSame( get_option( 'blogname' ), $data['title'] );
		// The answer holds only the updated setting.
		$this->assertSame( array( 'title' ), array_keys( $data ) );
	}

	/**
	 * A setting with a numeric name, which PHP turns into an integer array key, is in the answer.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_update_answers_with_a_numeric_setting_name(): void {
		$option = '123';

		register_setting(
			'general',
			$option,
			array(
				'type'              => 'integer',
				'show_in_abilities' => true,
			)
		);

		try {
			$this->become_admin();
			$this->register_ability();

			$data = wp_get_ability( 'core/settings-update' )->execute( array( $option => 5 ) );
		} finally {
			unregister_setting( 'general', $option );
		}

		$this->assertSame( array( $option => 5 ), $data );
	}

	/**
	 * @since x.x.x
	 */
	public function test_update_item_with_array(): void {
		register_setting(
			'somegroup',
			'mycustomsetting',
			array(
				'show_in_abilities' => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'integer',
						),
					),
				),
				'type'              => 'array',
			)
		);

		$this->become_admin();
		$this->register_ability();
		$ability = wp_get_ability( 'core/settings-update' );

		$data = $ability->execute( array( 'mycustomsetting' => array( '1', '2' ) ) );
		$this->assertSame( array( 1, 2 ), $data['mycustomsetting'] );
		$this->assertSame( array( 1, 2 ), get_option( 'mycustomsetting' ) );

		// Setting an empty array.
		$data = $ability->execute( array( 'mycustomsetting' => array() ) );
		$this->assertSame( array(), $data['mycustomsetting'] );
		$this->assertSame( array(), get_option( 'mycustomsetting' ) );

		// Setting an invalid array.
		$result = $ability->execute( array( 'mycustomsetting' => array( 'invalid' ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * @since x.x.x
	 */
	public function test_update_item_with_nested_object(): void {
		register_setting(
			'somegroup',
			'mycustomsetting',
			array(
				'show_in_abilities' => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'a' => array(
								'type'       => 'object',
								'properties' => array(
									'b' => array(
										'type' => 'number',
									),
								),
							),
						),
					),
				),
				'type'              => 'object',
			)
		);

		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-update' )->execute(
			array(
				'mycustomsetting' => array(
					'a' => array(
						'b' => 1,
						'c' => 1,
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Objects come back as objects, which serialize as JSON objects even when empty.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_with_object(): void {
		register_setting(
			'somegroup',
			'mycustomsetting',
			array(
				'show_in_abilities' => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'a' => array(
								'type' => 'integer',
							),
						),
					),
				),
				'type'              => 'object',
			)
		);

		$this->become_admin();
		$this->register_ability();
		$ability = wp_get_ability( 'core/settings-update' );

		$data = $ability->execute( array( 'mycustomsetting' => array( 'a' => 1 ) ) );
		$this->assertEquals( (object) array( 'a' => 1 ), $data['mycustomsetting'] );
		$this->assertSame( array( 'a' => 1 ), get_option( 'mycustomsetting' ) );

		// Setting an empty object.
		$data = $ability->execute( array( 'mycustomsetting' => array() ) );
		$this->assertEquals( (object) array(), $data['mycustomsetting'] );
		$this->assertSame( array(), get_option( 'mycustomsetting' ) );

		// Provide more keys.
		$result = $ability->execute(
			array(
				'mycustomsetting' => array(
					'a' => 1,
					'b' => 2,
				),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );

		// Setting an invalid object.
		$result = $ability->execute( array( 'mycustomsetting' => array( 'a' => 'invalid' ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * @since x.x.x
	 */
	public function test_update_item_with_invalid_type(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-update' )->execute( array( 'title' => array( 'rendered' => 'This should fail.' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * @since x.x.x
	 */
	public function test_update_item_with_integer(): void {
		$this->become_admin();
		$this->register_ability();

		$data = wp_get_ability( 'core/settings-update' )->execute( array( 'posts_per_page' => 11 ) );

		$this->assertSame( 11, $data['posts_per_page'] );
	}

	/**
	 * @since x.x.x
	 */
	public function test_update_item_with_invalid_float_for_integer(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-update' )->execute( array( 'posts_per_page' => 10.5 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Setting an item to "null" will essentially restore it to its default value.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_with_null(): void {
		update_option( 'posts_per_page', 9 );

		$this->become_admin();
		$this->register_ability();

		$data = wp_get_ability( 'core/settings-update' )->execute( array( 'posts_per_page' => null ) );

		$this->assertSame( 10, $data['posts_per_page'] );
		$this->assertFalse( get_option( 'posts_per_page', false ) );
	}

	/**
	 * An invalid value fails the whole call before any setting is written.
	 *
	 * @since x.x.x
	 */
	public function test_update_item_with_invalid_enum(): void {
		update_option( 'blogname', 'Original Name' );

		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-update' )->execute(
			array(
				'title'               => 'Should Not Persist',
				'default_ping_status' => 'open&closed',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertSame( 'Original Name', get_option( 'blogname' ) );
	}

	/**
	 * @since x.x.x
	 */
	public function test_update_item_with_invalid_stored_value_in_options(): void {
		register_setting(
			'somegroup',
			'mycustomsetting',
			array(
				'show_in_abilities' => true,
				'type'              => 'string',
			)
		);
		update_option( 'mycustomsetting', array( 'A sneaky array!' ) );

		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-update' )->execute( array( 'mycustomsetting' => null ) );

		$this->assertWPError( $result );
		$this->assertSame( 'settings_invalid_stored_value', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	/**
	 * @since x.x.x
	 */
	public function test_register_setting_with_custom_additional_properties_value(): void {
		register_setting(
			'somegroup',
			'mycustomsetting',
			array(
				'type'              => 'object',
				'show_in_abilities' => array(
					'schema' => array(
						'type'                 => 'object',
						'properties'           => array(
							'test1' => array(
								'type' => 'string',
							),
						),
						'additionalProperties' => array(
							'type' => 'integer',
						),
					),
				),
			)
		);

		$this->become_admin();
		$this->register_ability();

		$data = wp_get_ability( 'core/settings-update' )->execute(
			array(
				'mycustomsetting' => array(
					'test1' => 'my-string',
					'test2' => '2',
					'test3' => 3,
				),
			)
		);

		$this->assertSame( 'my-string', $data['mycustomsetting']->test1 );
		$this->assertSame( 2, $data['mycustomsetting']->test2 );
		$this->assertSame( 3, $data['mycustomsetting']->test3 );
	}

	/**
	 * Values are sanitized against their schema before they are stored, as the settings endpoint does.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_update_sanitizes_values_against_their_schema(): void {
		$this->become_admin();
		$this->register_ability();

		// The string passes validation; stored unsanitized, "false" would read back as true.
		$data = wp_get_ability( 'core/settings-update' )->execute( array( 'use_smilies' => 'false' ) );

		$this->assertFalse( $data['use_smilies'] );
		$this->assertFalse( (bool) get_option( 'use_smilies' ) );
	}

	/**
	 * A value that fails sanitizing fails the whole call before any setting is written.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_update_rejects_a_value_that_fails_sanitizing(): void {
		register_setting(
			'somegroup',
			'mycustomsetting',
			array(
				'type'              => 'array',
				'show_in_abilities' => array(
					'schema' => array(
						'items'       => array( 'type' => 'integer' ),
						'uniqueItems' => true,
					),
				),
			)
		);
		update_option( 'blogname', 'Original Name' );

		$this->become_admin();
		$this->register_ability();

		// Unique as strings, so the list validates, but both items sanitize to 1.
		$result = wp_get_ability( 'core/settings-update' )->execute(
			array(
				'title'           => 'Should Not Persist',
				'mycustomsetting' => array( '1', '01' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'settings_invalid_param', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'Original Name', get_option( 'blogname' ) );
		$this->assertFalse( get_option( 'mycustomsetting' ) );
	}

	/**
	 * A null value is refused while the stored value fails validation, and nothing is written,
	 * not even the settings registered before it, which the settings endpoint writes first.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_update_refused_null_writes_no_setting(): void {
		update_option( 'blogname', 'Original Name' );
		update_option( 'core_settings_get_ability_test_option', 'not a number' );

		$this->become_admin();
		$this->register_ability();

		// `title` comes last in the input but was registered first, so the endpoint writes it first.
		$result = wp_get_ability( 'core/settings-update' )->execute(
			array(
				'core_settings_get_ability_test_option' => null,
				'title'                                 => 'Renamed Site',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'settings_invalid_stored_value', $result->get_error_code() );
		$this->assertSame( 'not a number', get_option( 'core_settings_get_ability_test_option' ) );
		$this->assertSame( 'Original Name', get_option( 'blogname' ) );
	}

	/**
	 * Settings are written in the order they were registered, as in the settings endpoint,
	 * whatever their order in the input.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_update_writes_in_registration_order(): void {
		$this->become_admin();
		$this->register_ability();

		$written  = array();
		$listener = static function ( $option ) use ( &$written ): void {
			$written[] = $option;
		};
		add_action( 'updated_option', $listener );

		// `title` comes last in the input but was registered first, so it is written first.
		wp_get_ability( 'core/settings-update' )->execute(
			array(
				'posts_per_page' => 7,
				'title'          => 'Renamed Site',
			)
		);
		remove_action( 'updated_option', $listener );

		$this->assertSame( array( 'blogname', 'posts_per_page' ), $written );
	}

	/**
	 * A setting without a registered default reads back without a value its schema accepts once
	 * reset to null, so both abilities leave it out instead of failing for every setting.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_update_null_on_a_setting_without_a_default_leaves_it_out(): void {
		$this->become_admin();
		$this->register_ability();

		// No registered default: the deleted option reads back as false, which its schema rejects.
		$data = wp_get_ability( 'core/settings-update' )->execute( array( 'default_ping_status' => null ) );

		// Nothing to answer with, as an object so it is serialized as {}, not [].
		$this->assertSame( '{}', wp_json_encode( $data ) );
		$this->assertSame( 'missing', get_option( 'default_ping_status', 'missing' ) );

		$settings = wp_get_ability( 'core/settings-get' )->execute( array() );
		$this->assertArrayNotHasKey( 'default_ping_status', $settings );
		$this->assertArrayHasKey( 'title', $settings );
	}

	/**
	 * Unknown setting names are rejected.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_update_rejects_an_unknown_setting(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-update' )->execute( array( 'not_a_registered_setting' => 'value' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * `url` and `email` are read-only for now: the update ability rejects them, and the get
	 * ability still reads them.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_update_rejects_siteurl_and_admin_email(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Core registers siteurl and admin_email on single sites only.' );
		}

		$this->become_admin();
		$this->register_ability();

		$values = array(
			'url'   => get_option( 'siteurl' ),
			'email' => get_option( 'admin_email' ),
		);

		$result = wp_get_ability( 'core/settings-update' )->execute( array( 'url' => 'https://example.com/elsewhere' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );

		$result = wp_get_ability( 'core/settings-update' )->execute( array( 'email' => 'someone@example.com' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );

		$this->assertSame( $values, wp_get_ability( 'core/settings-get' )->execute( array( 'fields' => array( 'url', 'email' ) ) ) );
	}

	/**
	 * @since x.x.x
	 */
	public function test_update_item_privacy_policy_page(): void {
		// Core registers the setting since WordPress 7.2.
		$registered = isset( get_registered_settings()['wp_page_for_privacy_policy'] );
		if ( ! $registered ) {
			register_setting(
				'reading',
				'wp_page_for_privacy_policy',
				array(
					'show_in_rest' => array( 'name' => 'page_for_privacy_policy' ),
					'type'         => 'integer',
				)
			);
		}

		try {
			$this->become_admin();
			if ( is_multisite() ) {
				grant_super_admin( get_current_user_id() );
			}
			$this->register_ability();
			$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

			$data = wp_get_ability( 'core/settings-update' )->execute( array( 'page_for_privacy_policy' => $page_id ) );

			$this->assertSame( $page_id, $data['page_for_privacy_policy'] );
			$this->assertSame( $page_id, (int) get_option( 'wp_page_for_privacy_policy' ) );
		} finally {
			if ( ! $registered ) {
				unregister_setting( 'reading', 'wp_page_for_privacy_policy' );
			}
		}
	}

	/**
	 * Only users who can manage privacy options may change the privacy policy page. The settings
	 * endpoint skips the setting for other users, while the ability refuses the whole update, so
	 * nothing is written.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_update_refuses_the_privacy_policy_page_without_capability(): void {
		// Core registers the setting since WordPress 7.2.
		$registered = isset( get_registered_settings()['wp_page_for_privacy_policy'] );
		if ( ! $registered ) {
			register_setting(
				'reading',
				'wp_page_for_privacy_policy',
				array(
					'show_in_rest' => array( 'name' => 'page_for_privacy_policy' ),
					'type'         => 'integer',
				)
			);
		}

		// As for a site administrator on multisite, where the capability maps to manage_network.
		$deny_manage_privacy_options = static function ( array $caps, string $cap ): array {
			return 'manage_privacy_options' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny_manage_privacy_options, 10, 2 );

		try {
			update_option( 'blogname', 'Original Name' );
			$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
			update_option( 'wp_page_for_privacy_policy', $page_id );
			$other_page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

			$this->become_admin();
			$this->register_ability();

			$result = wp_get_ability( 'core/settings-update' )->execute(
				array(
					'title'                   => 'Renamed Site',
					'page_for_privacy_policy' => $other_page_id,
				)
			);

			$this->assertWPError( $result );
			$this->assertSame( 'settings_cannot_manage_privacy_options', $result->get_error_code() );
			$this->assertSame( 403, $result->get_error_data()['status'] );
			$this->assertSame( $page_id, (int) get_option( 'wp_page_for_privacy_policy' ) );
			$this->assertSame( 'Original Name', get_option( 'blogname' ) );
		} finally {
			remove_filter( 'map_meta_cap', $deny_manage_privacy_options, 10 );
			if ( ! $registered ) {
				unregister_setting( 'reading', 'wp_page_for_privacy_policy' );
			}
		}
	}

	/**
	 * Empty input is rejected: at least one setting must be provided.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_update_rejects_empty_input(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-update' )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Users without `manage_options` cannot run the update ability, and nothing is written.
	 *
	 * @since x.x.x
	 */
	public function test_core_settings_update_requires_manage_options(): void {
		update_option( 'blogname', 'Original Name' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-update' )->execute( array( 'title' => 'Nope' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 'Original Name', get_option( 'blogname' ) );
	}
}

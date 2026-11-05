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
		if ( wp_has_ability( 'core/settings-get' ) ) {
			wp_unregister_ability( 'core/settings-get' );
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
		$this->assertSame( 'Get Settings', $ability->get_label() );
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
		$this->assertSame( 'Get Settings', $ability->get_label() );
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
		// Core registers `wp_page_for_privacy_policy` as a setting since WordPress 7.2.
		if ( isset( get_registered_settings()['wp_page_for_privacy_policy'] ) ) {
			$this->assertContains( 'page_for_privacy_policy', $schema['properties']['fields']['items']['enum'] );
		}
		$this->assertTrue( $schema['properties']['fields']['uniqueItems'] );
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
	 * Neither settings ability is registered when no setting is exposed to abilities.
	 *
	 * @since 1.5.0
	 */
	public function test_settings_abilities_are_not_registered_without_exposed_settings(): void {
		global $wp_registered_settings, $wp_actions;

		$registered_settings_backup = $wp_registered_settings;
		$rest_api_init_count        = $wp_actions['rest_api_init'] ?? null;
		$wp_registered_settings     = array(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Simulating WordPress without exposed settings.
		// Skip registering core's initial settings, so no setting is exposed.
		$wp_actions['rest_api_init'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating a request where the REST API already loaded.

		try {
			$this->register_ability();

			$this->assertFalse( wp_has_ability( 'core/settings-get' ), 'The settings ability should not be registered when no setting is exposed.' );
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
	 * Settings exposed with `show_in_abilities => true` use the same names as in the
	 * REST API settings endpoint.
	 *
	 * @since 1.5.0
	 */
	public function test_core_settings_get_uses_rest_api_setting_names(): void {
		$this->register_ability();

		$properties = wp_get_ability( 'core/settings-get' )->get_output_schema()['properties'];

		foreach ( get_registered_settings() as $option_name => $args ) {
			if ( true !== ( $args['show_in_abilities'] ?? false ) || empty( $args['show_in_rest'] ) ) {
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
	 * @since 1.5.0
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

			$this->assertSame( array( 'a', 'b' ), $properties['inherited_name']['enum'], 'A setting exposed with true should reuse its REST API schema.' );
			$this->assertArrayHasKey( 'ability_name', $properties, 'A setting exposed with an array should use the name from that array.' );
			$this->assertArrayNotHasKey( 'rest_name', $properties, 'A setting exposed with an array should not use its REST API name.' );
		} finally {
			unregister_setting( 'general', 'core_settings_get_inherit_test_option' );
			unregister_setting( 'general', 'core_settings_get_override_test_option' );
		}
	}

	/**
	 * Two settings exposed under the same name trigger a notice, and the later one is exposed.
	 *
	 * @since 1.5.0
	 */
	public function test_core_settings_get_warns_about_a_duplicate_exposed_name(): void {
		$this->setExpectedIncorrectUsage( Settings::class . '::get_exposed_settings' );

		register_setting(
			'general',
			'core_settings_get_ability_duplicate_test_option_a',
			array(
				'type'              => 'string',
				'show_in_abilities' => array( 'name' => 'core_settings_get_ability_duplicate_name' ),
			)
		);
		register_setting(
			'general',
			'core_settings_get_ability_duplicate_test_option_b',
			array(
				'type'              => 'integer',
				'show_in_abilities' => array( 'name' => 'core_settings_get_ability_duplicate_name' ),
			)
		);

		try {
			$this->register_ability();
			$properties = wp_get_ability( 'core/settings-get' )->get_output_schema()['properties'];

			$this->assertSame( 'integer', $properties['core_settings_get_ability_duplicate_name']['type'], 'The setting registered later should be the one exposed under the shared name.' );
		} finally {
			unregister_setting( 'general', 'core_settings_get_ability_duplicate_test_option_a' );
			unregister_setting( 'general', 'core_settings_get_ability_duplicate_test_option_b' );
		}
	}

	/**
	 * Input passed as an object is filtered like input passed as an array.
	 *
	 * @since 1.5.0
	 */
	public function test_core_settings_get_filters_object_input(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-get' )->execute( (object) array( 'group' => 'reading' ) );

		$this->assertArrayHasKey( 'posts_per_page', $result, 'A setting of the requested group should be returned for object input.' );
		$this->assertArrayNotHasKey( 'title', $result, 'A setting of another group should be left out for object input.' );
	}

	/**
	 * A `fields` list passed as a comma-separated string is filtered like a `fields` array.
	 *
	 * @since 1.5.0
	 */
	public function test_core_settings_get_filters_fields_passed_as_a_string(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings-get' )->execute( array( 'fields' => 'title,posts_per_page' ) );

		$this->assertEqualSets( array( 'title', 'posts_per_page' ), array_keys( $result ), 'A comma-separated fields string should select the requested settings.' );
	}

	/**
	 * A setting shown in the REST API without `show_in_abilities` is not exposed.
	 *
	 * @since 1.5.0
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

			$this->assertArrayNotHasKey( $option, wp_get_ability( 'core/settings-get' )->get_output_schema()['properties'], 'A setting only shown in the REST API should not be exposed.' );
		} finally {
			unregister_setting( 'general', $option );
		}
	}

	/**
	 * A value that does not match its schema is left out instead of failing the whole call.
	 *
	 * @since 1.5.0
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
	 * Stored values are validated against their schema before and after sanitizing, and left out
	 * when it rejects them.
	 *
	 * @since 1.5.0
	 *
	 * @dataProvider data_stored_values
	 *
	 * @param string                $type     The setting type.
	 * @param mixed                 $stored   The stored option value.
	 * @param string|null           $expected The value as JSON, or null when it is left out.
	 * @param array<string, mixed>  $schema   Optional. The `show_in_abilities` schema of the setting. Default empty array.
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
			$this->register_ability();
			$this->become_admin();

			$result = wp_get_ability( 'core/settings-get' )->execute( array( 'fields' => array( $option ) ) );
		} finally {
			unregister_setting( 'general', $option );
		}

		$this->assertSame( $expected, isset( $result[ $option ] ) ? wp_json_encode( $result[ $option ] ) : null, 'The stored value should be read as expected, or left out.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: string|null, 3?: array<string, mixed>}> Stored values, and the JSON they are read as.
	 */
	public static function data_stored_values(): array {
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
			'an email that sanitizing breaks'     => array( 'string', '%ab@x.co', null, array( 'format' => 'email' ) ),
		);
	}

	/**
	 * A setting that was never saved reads as its registered default, or is left out when it has
	 * none, as the settings endpoint answers null for it, instead of reading the false that
	 * get_option() returns as a value.
	 *
	 * @since 1.5.0
	 *
	 * @dataProvider data_never_saved_settings
	 *
	 * @param array<string, mixed> $args     The setting registration arguments.
	 * @param string|null          $expected The value as JSON, or null when it is left out.
	 */
	public function test_core_settings_get_reads_a_setting_that_was_never_saved( array $args, ?string $expected ): void {
		$option = 'core_settings_get_ability_never_saved_test_option';

		register_setting( 'general', $option, $args + array( 'show_in_abilities' => true ) );

		try {
			$this->register_ability();
			$this->become_admin();

			$result = wp_get_ability( 'core/settings-get' )->execute( array( 'fields' => array( $option ) ) );
		} finally {
			unregister_setting( 'general', $option );
		}

		$this->assertSame( $expected, isset( $result[ $option ] ) ? wp_json_encode( $result[ $option ] ) : null, 'The setting should read as its registered default, or be left out.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}> Registration arguments, and the JSON the setting is read as.
	 */
	public static function data_never_saved_settings(): array {
		return array(
			'a boolean without a default'       => array( array( 'type' => 'boolean' ), null ),
			'an array without a default'        => array( array( 'type' => 'array' ), null ),
			'a boolean with a default of false' => array(
				array(
					'type'    => 'boolean',
					'default' => false,
				),
				'false',
			),
		);
	}

	/**
	 * A setting of a type the settings endpoint does not support is not exposed.
	 *
	 * @since 1.5.0
	 */
	public function test_core_settings_get_skips_a_setting_with_an_unsupported_type(): void {
		$option = 'core_settings_get_ability_type_test_option';

		register_setting(
			'general',
			$option,
			array(
				'type'              => 'foo',
				'show_in_abilities' => true,
			)
		);
		update_option( $option, 'value' );

		try {
			$this->register_ability();
			$this->become_admin();

			$ability = wp_get_ability( 'core/settings-get' );

			$this->assertArrayNotHasKey( $option, $ability->get_output_schema()['properties'], 'A setting of an unsupported type should not be described in the output schema.' );
			$this->assertArrayNotHasKey( $option, $ability->execute( array() ), 'A setting of an unsupported type should not be returned.' );
		} finally {
			unregister_setting( 'general', $option );
		}
	}
}

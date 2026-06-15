<?php
/**
 * Integration tests for the core/settings Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Settings
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Settings;

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
	 * Set up test case.
	 *
	 * @since 1.1.0
	 */
	public function setUp(): void {
		parent::setUp();

		// Mark the curated core settings, then register them (as happens on rest_api_init).
		Show_In_Abilities::register();
		register_initial_settings();

		$this->ensure_site_category();
	}

	/**
	 * Tear down test case.
	 *
	 * @since 1.1.0
	 */
	public function tearDown(): void {
		if ( wp_has_ability( 'core/settings' ) ) {
			wp_unregister_ability( 'core/settings' );
		}

		remove_filter( 'register_setting_args', array( Show_In_Abilities::class, 'mark_setting' ), 10 );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Ensures the `site` ability category exists for the ability to attach to.
	 *
	 * @since 1.1.0
	 */
	private function ensure_site_category(): void {
		if ( wp_has_ability_category( 'site' ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability_category(
				'site',
				array(
					'label'       => 'Site',
					'description' => 'Site.',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Registers the plugin's core/settings ability inside a faked init action.
	 *
	 * @since 1.1.0
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			Settings::register();
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
	 * The ability is registered in the `site` category and flagged read-only.
	 *
	 * @since 1.1.0
	 */
	public function test_registers_core_settings_ability(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/settings' );

		$this->assertNotNull( $ability );
		$this->assertSame( 'core/settings', $ability->get_name() );
		$this->assertSame( 'site', $ability->get_category() );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertTrue( $annotations['readonly'] );
	}

	/**
	 * When core already provides core/settings, the plugin's version replaces it.
	 *
	 * @since 1.1.0
	 */
	public function test_override_replaces_existing_core_settings(): void {
		// Simulate a core-provided ability with a different (minimal) shape.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				'core/settings',
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

		$this->assertSame( 'Core Provided', wp_get_ability( 'core/settings' )->get_label() );

		$this->register_ability();

		$ability = wp_get_ability( 'core/settings' );
		$this->assertSame( 'Get Settings', $ability->get_label() );
		// The plugin's shape uses a mutually-exclusive oneOf input schema.
		$this->assertArrayHasKey( 'oneOf', $ability->get_input_schema() );
	}

	/**
	 * The input schema exposes mutually exclusive `group` and `settings` filters.
	 *
	 * @since 1.1.0
	 */
	public function test_input_schema_is_one_of_group_or_settings(): void {
		$this->register_ability();

		$schema = wp_get_ability( 'core/settings' )->get_input_schema();

		$this->assertCount( 3, $schema['oneOf'] );
		$this->assertContains( 'reading', $schema['oneOf'][1]['properties']['group']['enum'] );
		$this->assertContains( 'blogname', $schema['oneOf'][2]['properties']['settings']['items']['enum'] );
	}

	/**
	 * Without input the ability returns a flat map of correctly typed setting values.
	 *
	 * @since 1.1.0
	 */
	public function test_returns_flat_typed_values(): void {
		$this->become_admin();
		$this->register_ability();

		update_option( 'blogname', 'My Test Site' );
		update_option( 'posts_per_page', 7 );
		update_option( 'use_smilies', '1' );

		$result = wp_get_ability( 'core/settings' )->execute( array() );

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
	public function test_filters_by_group(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings' )->execute( array( 'group' => 'reading' ) );

		$this->assertArrayHasKey( 'posts_per_page', $result );
		$this->assertArrayNotHasKey( 'blogname', $result );
	}

	/**
	 * The `settings` filter narrows the response to the requested setting names.
	 *
	 * @since 1.1.0
	 */
	public function test_filters_by_settings(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings' )->execute( array( 'settings' => array( 'blogname', 'posts_per_page' ) ) );

		$this->assertSame( array( 'blogname', 'posts_per_page' ), array_keys( $result ) );
	}

	/**
	 * Supplying both `group` and `settings` violates the `oneOf` and is rejected.
	 *
	 * @since 1.1.0
	 */
	public function test_rejects_group_and_settings_together(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings' )->execute(
			array(
				'group'    => 'reading',
				'settings' => array( 'blogname' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Users without `manage_options` cannot run the ability.
	 *
	 * @since 1.1.0
	 */
	public function test_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->register_ability();

		$result = wp_get_ability( 'core/settings' )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}

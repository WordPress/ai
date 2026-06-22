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
 * @since x.x.x
 */
class SettingsTest extends WP_UnitTestCase {

	/**
	 * The settings exposure component. Held so the same instance can detach its filter on tear down.
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

		// Mark the curated core settings, then register them (as happens on rest_api_init).
		$this->show_in_abilities = new Show_In_Abilities();
		$this->show_in_abilities->register();
		register_initial_settings();

		$this->ensure_site_category();
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		if ( wp_has_ability( 'core/settings' ) ) {
			wp_unregister_ability( 'core/settings' );
		}

		remove_filter( 'register_setting_args', array( $this->show_in_abilities, 'mark_setting' ), 10 );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Ensures the `site` ability category exists for the ability to attach to.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	private function become_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * The ability is registered in the `site` category and flagged read-only.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
		// The plugin's shape exposes optional `group` and `fields` filters.
		$this->assertArrayHasKey( 'fields', $ability->get_input_schema()['properties'] );
	}

	/**
	 * The input schema exposes optional `group` and `fields` filters.
	 *
	 * @since x.x.x
	 */
	public function test_input_schema_exposes_group_and_fields_filters(): void {
		$this->register_ability();

		$schema = wp_get_ability( 'core/settings' )->get_input_schema();

		$this->assertArrayNotHasKey( 'oneOf', $schema );
		$this->assertContains( 'reading', $schema['properties']['group']['enum'] );
		$this->assertContains( 'blogname', $schema['properties']['fields']['items']['enum'] );
	}

	/**
	 * Without input the ability returns a flat map of correctly typed setting values.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
	 */
	public function test_filters_by_group(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings' )->execute( array( 'group' => 'reading' ) );

		$this->assertArrayHasKey( 'posts_per_page', $result );
		$this->assertArrayNotHasKey( 'blogname', $result );
	}

	/**
	 * The `fields` filter narrows the response to the requested setting names.
	 *
	 * @since x.x.x
	 */
	public function test_filters_by_fields(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/settings' )->execute( array( 'fields' => array( 'blogname', 'posts_per_page' ) ) );

		$this->assertEqualSets( array( 'blogname', 'posts_per_page' ), array_keys( $result ) );
	}

	/**
	 * Supplying both `group` and `fields` narrows the response to their intersection.
	 *
	 * @since x.x.x
	 */
	public function test_combines_group_and_fields_filters(): void {
		$this->become_admin();
		$this->register_ability();

		// `blogname` is in the `general` group and `posts_per_page` in `reading`; only the
		// latter satisfies both filters.
		$result = wp_get_ability( 'core/settings' )->execute(
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
	 * @since x.x.x
	 */
	public function test_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->register_ability();

		$result = wp_get_ability( 'core/settings' )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}

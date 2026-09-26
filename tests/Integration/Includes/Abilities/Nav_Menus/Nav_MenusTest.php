<?php
/**
 * Integration tests for the core/read-nav-menus Ability provided by the plugin.
 *
 * @package WordPress\AI\Tests\Integration\Includes\Abilities\Nav_Menus
 */

namespace WordPress\AI\Tests\Integration\Includes\Abilities\Nav_Menus;

use WP_Ability;
use WP_UnitTestCase;
use WordPress\AI\Abilities\Nav_Menus\Nav_Menus;

/**
 * Nav_Menus ability test case.
 *
 * @since x.x.x
 */
class Nav_MenusTest extends WP_UnitTestCase {

	/**
	 * A nav menu created for the test, and assigned to the `primary` location.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private $menu_id;

	/**
	 * Set up test case.
	 *
	 * @since x.x.x
	 */
	public function setUp(): void {
		parent::setUp();

		$this->ensure_ability_category();

		register_nav_menu( 'primary', 'Primary Menu' );

		$this->menu_id = (int) wp_create_nav_menu( 'Test Menu' );

		wp_update_nav_menu_item(
			$this->menu_id,
			0,
			array(
				'menu-item-title'  => 'Home',
				'menu-item-url'    => home_url( '/' ),
				'menu-item-status' => 'publish',
				'menu-item-type'   => 'custom',
			)
		);

		set_theme_mod( 'nav_menu_locations', array( 'primary' => $this->menu_id ) );
	}

	/**
	 * Makes sure the navigation category exists before the tests register its ability.
	 *
	 * @since x.x.x
	 */
	private function ensure_ability_category(): void {
		if ( wp_has_ability_category( 'navigation' ) ) {
			return;
		}

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_categories_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Nav_Menus() )->register_category();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Tear down test case.
	 *
	 * @since x.x.x
	 */
	public function tearDown(): void {
		if ( wp_has_ability( 'core/read-nav-menus' ) ) {
			wp_unregister_ability( 'core/read-nav-menus' );
		}

		wp_delete_nav_menu( $this->menu_id );
		remove_theme_mod( 'nav_menu_locations' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Registers the plugin's core/read-nav-menus ability inside a faked init action.
	 *
	 * @since x.x.x
	 */
	private function register_ability(): void {
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			( new Nav_Menus() )->register();
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
	 * The ability is registered in the `navigation` category and flagged read-only.
	 *
	 * @since x.x.x
	 */
	public function test_core_read_nav_menus_ability_is_registered(): void {
		$this->register_ability();

		$ability = wp_get_ability( 'core/read-nav-menus' );

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertSame( 'core/read-nav-menus', $ability->get_name() );
		$this->assertSame( 'navigation', $ability->get_category() );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ) );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertTrue( $annotations['readonly'] );
		$this->assertFalse( $annotations['destructive'] );
		$this->assertTrue( $annotations['idempotent'] );
	}

	/**
	 * When core already provides core/read-nav-menus, the plugin's version replaces it.
	 *
	 * @since x.x.x
	 */
	public function test_override_replaces_existing_core_read_nav_menus(): void {
		// Simulate a core-provided ability with a different (minimal) shape.
		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Faking the action context to register within it.
		try {
			wp_register_ability(
				'core/read-nav-menus',
				array(
					'label'               => 'Core Provided',
					'description'         => 'Core provided nav menus ability.',
					'category'            => 'navigation',
					'execute_callback'    => static function (): array {
						return array();
					},
					'permission_callback' => '__return_true',
				)
			);
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->assertSame( 'Core Provided', wp_get_ability( 'core/read-nav-menus' )->get_label() );

		$this->register_ability();

		$ability = wp_get_ability( 'core/read-nav-menus' );
		$this->assertSame( 'Read Nav Menus', $ability->get_label() );
		// The plugin's shape exposes a `oneOf` input schema; the minimal core stand-in did not.
		$this->assertArrayHasKey( 'oneOf', $ability->get_input_schema() );
	}

	/**
	 * A single menu, with its items, is returned by ID.
	 *
	 * @since x.x.x
	 */
	public function test_core_read_nav_menus_returns_a_single_menu_by_id(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/read-nav-menus' )->execute( array( 'id' => $this->menu_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $this->menu_id, $result['id'] );
		$this->assertSame( 'Test Menu', $result['name'] );
		$this->assertSame( array( 'primary' ), $result['locations'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'Home', $result['items'][0]['title'] );
		$this->assertSame( home_url( '/' ), $result['items'][0]['url'] );
	}

	/**
	 * A single menu, with its items, is returned by slug.
	 *
	 * @since x.x.x
	 */
	public function test_core_read_nav_menus_returns_a_single_menu_by_slug(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/read-nav-menus' )->execute( array( 'slug' => 'test-menu' ) );

		$this->assertSame( $this->menu_id, $result['id'] );
		$this->assertCount( 1, $result['items'] );
	}

	/**
	 * The menu assigned to a registered theme location is returned.
	 *
	 * @since x.x.x
	 */
	public function test_core_read_nav_menus_returns_the_menu_assigned_to_a_location(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/read-nav-menus' )->execute( array( 'location' => 'primary' ) );

		$this->assertSame( $this->menu_id, $result['id'] );
	}

	/**
	 * A location with no assigned menu errors instead of returning an empty result.
	 *
	 * @since x.x.x
	 */
	public function test_core_read_nav_menus_errors_when_location_has_no_assigned_menu(): void {
		register_nav_menu( 'footer', 'Footer Menu' );

		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/read-nav-menus' )->execute( array( 'location' => 'footer' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * An unknown menu ID errors instead of returning an empty result.
	 *
	 * @since x.x.x
	 */
	public function test_core_read_nav_menus_errors_when_menu_not_found(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/read-nav-menus' )->execute( array( 'id' => 999999 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Without input, the ability lists every menu alongside registered locations and their
	 * current assignments.
	 *
	 * @since x.x.x
	 */
	public function test_core_read_nav_menus_lists_all_menus_with_registered_locations_and_assignments(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/read-nav-menus' )->execute( array() );

		$this->assertCount( 1, $result['menus'] );
		$this->assertSame( $this->menu_id, $result['menus'][0]['id'] );
		$this->assertArrayNotHasKey( 'items', $result['menus'][0] );

		$this->assertSame( 'Primary Menu', $result['registered_locations']->primary );
		$this->assertSame( $this->menu_id, $result['location_assignments']->primary );
	}

	/**
	 * The `search` filter narrows the collection to menus matching the term.
	 *
	 * @since x.x.x
	 */
	public function test_core_read_nav_menus_filters_collection_by_search(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/read-nav-menus' )->execute( array( 'search' => 'Nonexistent' ) );

		$this->assertSame( array(), $result['menus'] );
	}

	/**
	 * Users without `edit_theme_options` cannot run the ability.
	 *
	 * @since x.x.x
	 */
	public function test_core_read_nav_menus_requires_edit_theme_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->register_ability();

		$result = wp_get_ability( 'core/read-nav-menus' )->execute( array( 'id' => $this->menu_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Schema-valid object input behaves like its array form.
	 *
	 * @since x.x.x
	 */
	public function test_core_read_nav_menus_accepts_object_input(): void {
		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/read-nav-menus' )->execute(
			(object) array(
				'id' => $this->menu_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $this->menu_id, $result['id'] );
	}

	/**
	 * Non-array and non-object inputs fall back to default collection queries.
	 *
	 * @since x.x.x
	 */
	public function test_core_read_nav_menus_handles_non_array_input(): void {
		$this->become_admin();
		$this->register_ability();

		$nav_menus = new Nav_Menus();
		$result    = $nav_menus->execute_get_nav_menus( null );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'menus', $result );
	}

	/**
	 * Non-WP_Term elements returned by wp_get_nav_menus are skipped in collection queries.
	 *
	 * @since x.x.x
	 */
	public function test_core_read_nav_menus_skips_non_term_elements_in_collection(): void {
		$this->become_admin();
		$this->register_ability();

		$filter = static function ( $menus ) {
			return array_merge( (array) $menus, array( false ) );
		};
		add_filter( 'wp_get_nav_menus', $filter );

		try {
			$result = wp_get_ability( 'core/read-nav-menus' )->execute( array() );
		} finally {
			remove_filter( 'wp_get_nav_menus', $filter );
		}

		$this->assertCount( 1, $result['menus'] );
		$this->assertSame( $this->menu_id, $result['menus'][0]['id'] );
	}

	/**
	 * Registered theme locations with no assigned menu (ID 0) are omitted from assignments.
	 *
	 * @since x.x.x
	 */
	public function test_core_read_nav_menus_omits_unassigned_locations_from_assignments(): void {
		register_nav_menu( 'footer', 'Footer Menu' );
		set_theme_mod(
			'nav_menu_locations',
			array(
				'primary' => $this->menu_id,
				'footer'  => 0,
			)
		);

		$this->become_admin();
		$this->register_ability();

		$result = wp_get_ability( 'core/read-nav-menus' )->execute( array() );

		$this->assertTrue( property_exists( $result['location_assignments'], 'primary' ) );
		$this->assertSame( $this->menu_id, $result['location_assignments']->primary );
		$this->assertFalse( property_exists( $result['location_assignments'], 'footer' ) );
	}

	/**
	 * Menu locations assigned to a different menu are skipped when resolving menu locations.
	 *
	 * @since x.x.x
	 */
	public function test_core_read_nav_menus_filters_out_other_menu_locations(): void {
		register_nav_menu( 'footer', 'Footer Menu' );
		$second_menu_id = (int) wp_create_nav_menu( 'Second Menu' );

		set_theme_mod(
			'nav_menu_locations',
			array(
				'primary' => $this->menu_id,
				'footer'  => $second_menu_id,
			)
		);

		$this->become_admin();
		$this->register_ability();

		try {
			$result = wp_get_ability( 'core/read-nav-menus' )->execute( array( 'id' => $this->menu_id ) );

			$this->assertSame( array( 'primary' ), $result['locations'] );
		} finally {
			wp_delete_nav_menu( $second_menu_id );
		}
	}
}

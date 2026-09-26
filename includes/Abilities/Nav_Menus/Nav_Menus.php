<?php
/**
 * The `core/read-nav-menus` WordPress Ability.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Nav_Menus;

use WP_Error;
use WP_Post;
use WP_Term;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Nav_Menus
 *
 * Registers the read-only `core/read-nav-menus` ability, which retrieves one or more nav
 * menus. Supports fetching a single menu (with its items) by ID, slug, or registered theme
 * location, or querying the full collection of nav menus alongside the site's registered
 * theme locations and their current menu assignments.
 *
 * This class is written to be easy to compare against a future core implementation, and is
 * kept close in shape to the plugin's other ability classes (e.g. `Users`, `Settings`).
 * Differences that would not exist in a core version are marked with `// Plugin:` comments.
 * Additionally, all user-facing strings use the 'ai' text domain.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Nav_Menus {

	/**
	 * The ability category used for nav menu abilities.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const CATEGORY = 'navigation';

	/**
	 * Hooks the ability, and the category it needs, into the Abilities API.
	 *
	 * Plugin: this method has no equivalent in a core implementation. In core, register()
	 * would be invoked directly from wp_register_core_abilities(), and the `navigation`
	 * category would be registered by wp_register_core_ability_categories(). Core does not
	 * yet register a `navigation` category, so the plugin provides one here. Both hooks run
	 * at priority 11, one tick after core's own registration, so the plugin's copies win.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ), 11 );
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers the `navigation` ability category.
	 *
	 * @since x.x.x
	 */
	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Navigation', 'ai' ),
				'description' => __( 'Abilities that retrieve or modify navigation menus and their items.', 'ai' ),
			)
		);
	}

	/**
	 * Registers all nav menu abilities.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		$this->register_get_nav_menus();
	}

	/**
	 * Registers the read-only `core/read-nav-menus` ability.
	 *
	 * @since x.x.x
	 */
	private function register_get_nav_menus(): void {
		// Plugin: unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/read-nav-menus' ) ) {
			wp_unregister_ability( 'core/read-nav-menus' );
		}

		wp_register_ability(
			'core/read-nav-menus',
			array(
				'label'               => __( 'Read Nav Menus', 'ai' ),
				'description'         => __( 'Retrieves one or more nav menus. Fetch a single menu, with its items, by ID, slug, or registered theme location, or query the full collection of nav menus alongside the registered theme locations and their current menu assignments.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_nav_menus_input_schema(),
				'output_schema'       => $this->get_nav_menus_output_schema(),
				'execute_callback'    => array( $this, 'execute_get_nav_menus' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission callback for the `core/read-nav-menus` ability.
	 *
	 * Nav menus are ordinarily rendered to every site visitor, but a menu not currently
	 * assigned to any location, or the full location-assignment map, exposes more of the
	 * site's structure than the front end does. The ability is gated behind the same
	 * capability WordPress requires to manage menus in wp-admin.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the current user can manage nav menus.
	 */
	public function check_permission(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Executes the `core/read-nav-menus` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error Menu data, collection data, or a WP_Error on failure.
	 */
	public function execute_get_nav_menus( $input = array() ) {
		$input = $this->to_input_array( $input );

		if ( array_key_exists( 'id', $input ) ) {
			return $this->get_single_menu_response( $this->input_int( $input['id'] ) );
		}

		if ( array_key_exists( 'slug', $input ) ) {
			return $this->get_single_menu_response( is_string( $input['slug'] ) ? $input['slug'] : '' );
		}

		if ( array_key_exists( 'location', $input ) ) {
			return $this->get_menu_by_location_response( is_string( $input['location'] ) ? $input['location'] : '' );
		}

		$search = isset( $input['search'] ) && is_string( $input['search'] ) ? $input['search'] : '';

		return $this->get_collection_response( $search );
	}

	/**
	 * Casts raw ability input to an array.
	 *
	 * Schema validation accepts object input, so it must be treated as equivalent to its
	 * array form rather than discarded. Any other non-array input is replaced with an
	 * empty array.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input The raw ability input.
	 * @return array<mixed> The input as an array.
	 */
	private function to_input_array( $input ): array {
		if ( $input instanceof \stdClass ) {
			$input = (array) $input;
		}

		return is_array( $input ) ? $input : array();
	}

	/**
	 * Casts a raw input value to a non-negative integer.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The raw input value.
	 * @return int The value as a non-negative integer, or 0 when not scalar.
	 */
	private function input_int( $value ): int {
		return is_scalar( $value ) ? absint( $value ) : 0;
	}

	/**
	 * Resolves and formats a single menu by ID or slug.
	 *
	 * @since x.x.x
	 *
	 * @param int|string $menu Menu ID or slug.
	 * @return array<string, mixed>|\WP_Error The formatted menu, or a WP_Error when not found.
	 */
	private function get_single_menu_response( $menu ) {
		$term = '' !== $menu && 0 !== $menu ? wp_get_nav_menu_object( $menu ) : false;

		if ( ! $term instanceof WP_Term ) {
			return new WP_Error(
				'ability_invalid_input',
				__( 'The requested nav menu was not found.', 'ai' )
			);
		}

		return $this->format_menu( $term, true );
	}

	/**
	 * Resolves and formats the menu currently assigned to a registered theme location.
	 *
	 * @since x.x.x
	 *
	 * @param string $location Registered theme location slug.
	 * @return array<string, mixed>|\WP_Error The formatted menu, or a WP_Error when the
	 *                                        location has no assigned menu.
	 */
	private function get_menu_by_location_response( string $location ) {
		$locations = get_nav_menu_locations();
		$menu_id   = isset( $locations[ $location ] ) ? (int) $locations[ $location ] : 0;

		if ( 0 === $menu_id ) {
			return new WP_Error(
				'ability_invalid_input',
				__( 'No nav menu is assigned to that location.', 'ai' )
			);
		}

		return $this->get_single_menu_response( $menu_id );
	}

	/**
	 * Builds the collection response: every nav menu, alongside registered theme locations
	 * and their current menu assignments.
	 *
	 * @since x.x.x
	 *
	 * @param string $search Optional. Limit menus to this name or slug search term. Default empty string.
	 * @return array<string, mixed> The collection response.
	 */
	private function get_collection_response( string $search ): array {
		$args = array();
		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		$menus = array();
		foreach ( wp_get_nav_menus( $args ) as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$menus[] = $this->format_menu( $term, false );
		}

		$registered_locations = array();
		foreach ( get_registered_nav_menus() as $location => $label ) {
			$registered_locations[ $location ] = (string) $label;
		}

		$location_assignments = array();
		foreach ( get_nav_menu_locations() as $location => $menu_id ) {
			if ( 0 === (int) $menu_id ) {
				continue;
			}
			$location_assignments[ $location ] = (int) $menu_id;
		}

		return array(
			'menus'                => $menus,
			// Cast to object so an empty map serializes as {}, not [], consistent with the
			// output schema's `object` type for these two properties.
			'registered_locations' => (object) $registered_locations,
			'location_assignments' => (object) $location_assignments,
		);
	}

	/**
	 * Formats a nav menu term into the ability output shape.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Term $term       The nav menu term.
	 * @param bool     $with_items Whether to include the menu's items.
	 * @return array<string, mixed> The formatted menu data.
	 */
	private function format_menu( WP_Term $term, bool $with_items ): array {
		$menu_id = (int) $term->term_id;

		$data = array(
			'id'        => $menu_id,
			'name'      => (string) $term->name,
			'slug'      => (string) $term->slug,
			'count'     => (int) $term->count,
			'locations' => $this->get_menu_locations( $menu_id ),
		);

		if ( $with_items ) {
			$items         = wp_get_nav_menu_items( $term );
			$data['items'] = is_array( $items ) ? array_map( array( $this, 'format_menu_item' ), $items ) : array();
		}

		return $data;
	}

	/**
	 * Finds the registered theme locations currently assigned to a menu.
	 *
	 * A menu can be assigned to more than one location at once, so this walks every
	 * assignment rather than doing a single reverse lookup.
	 *
	 * @since x.x.x
	 *
	 * @param int $menu_id The nav menu term ID.
	 * @return string[] The location slugs this menu is assigned to.
	 */
	private function get_menu_locations( int $menu_id ): array {
		$locations = array();
		foreach ( get_nav_menu_locations() as $location => $assigned_menu_id ) {
			if ( (int) $assigned_menu_id !== $menu_id ) {
				continue;
			}
			$locations[] = $location;
		}

		return $locations;
	}

	/**
	 * Formats a single nav menu item into the ability output shape.
	 *
	 * Nav menu items are `WP_Post` objects, but the fields below (other than `ID` and
	 * `menu_order`, which are native post fields) are dynamic properties that
	 * wp_setup_nav_menu_item() adds at runtime and are not part of WP_Post's declared
	 * property list. They are read via get_object_vars() rather than direct property
	 * access so static analysis does not treat them as undefined.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $item A nav menu item, as returned by wp_get_nav_menu_items().
	 * @return array<string, mixed> The formatted menu item data.
	 */
	private function format_menu_item( WP_Post $item ): array {
		$fields  = get_object_vars( $item );
		$classes = isset( $fields['classes'] ) && is_array( $fields['classes'] ) ? $fields['classes'] : array();

		return array(
			'id'          => (int) $item->ID,
			'parent'      => isset( $fields['menu_item_parent'] ) ? (int) $fields['menu_item_parent'] : 0,
			'title'       => isset( $fields['title'] ) ? (string) $fields['title'] : '',
			'url'         => isset( $fields['url'] ) ? (string) $fields['url'] : '',
			'target'      => isset( $fields['target'] ) ? (string) $fields['target'] : '',
			'classes'     => array_values( array_filter( $classes, static fn( $menu_item_class ) => '' !== $menu_item_class ) ),
			'description' => isset( $fields['description'] ) ? (string) $fields['description'] : '',
			'menu_order'  => (int) $item->menu_order,
			'type'        => isset( $fields['type'] ) ? (string) $fields['type'] : '',
			'object'      => isset( $fields['object'] ) ? (string) $fields['object'] : '',
			'object_id'   => isset( $fields['object_id'] ) ? (int) $fields['object_id'] : 0,
		);
	}

	/**
	 * Builds the input schema for the `core/read-nav-menus` ability.
	 *
	 * The ability has four mutually exclusive modes, modeled as a `oneOf` so invalid
	 * combinations are rejected rather than silently ignored:
	 *
	 *   - Get a single menu, with its items, by `id`.
	 *   - Get a single menu, with its items, by `slug`.
	 *   - Get the menu, with its items, currently assigned to a registered theme `location`.
	 *   - Query the full collection of menus, optionally filtered by `search`.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_nav_menus_input_schema(): array {
		// Input enum intentionally reflects registered theme locations at ability
		// registration time. This makes the schema a stable contract that themes and
		// plugins can filter when registering the ability, matching how the `roles` enum
		// is resolved in the plugin's Users ability.
		$locations = array_keys( get_registered_nav_menus() );

		return array(
			'type'    => 'object',
			'default' => (object) array(),
			'oneOf'   => array(
				array(
					'title'                => __( 'Get a single nav menu, with its items, by ID', 'ai' ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Retrieve a single nav menu, with its items, by its term ID.', 'ai' ),
						),
					),
				),
				array(
					'title'                => __( 'Get a single nav menu, with its items, by slug', 'ai' ),
					'required'             => array( 'slug' ),
					'additionalProperties' => false,
					'properties'           => array(
						'slug' => array(
							'type'        => 'string',
							'description' => __( 'Retrieve a single nav menu, with its items, by its slug.', 'ai' ),
						),
					),
				),
				array(
					'title'                => __( 'Get the nav menu assigned to a registered theme location', 'ai' ),
					'required'             => array( 'location' ),
					'additionalProperties' => false,
					'properties'           => array(
						'location' => array(
							'type'        => 'string',
							'enum'        => $locations,
							'description' => __( 'Retrieve the nav menu, with its items, currently assigned to this registered theme location.', 'ai' ),
						),
					),
				),
				array(
					'title'                => __( 'Query all nav menus', 'ai' ),
					'additionalProperties' => false,
					'properties'           => array(
						'search' => array(
							'type'        => 'string',
							'description' => __( 'Limit the returned menus to those whose name or slug matches this search term.', 'ai' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Builds the output schema for the `core/read-nav-menus` ability.
	 *
	 * Single-menu modes (by ID, slug, or location) return the menu object directly, with its
	 * items. Collection mode returns every menu without items, alongside the registered
	 * theme locations and their current menu assignments.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_nav_menus_output_schema(): array {
		$menu_item_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'id'          => array(
					'type'        => 'integer',
					'description' => __( 'The menu item ID.', 'ai' ),
				),
				'parent'      => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the parent menu item, or 0 for a top-level item.', 'ai' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'The menu item title.', 'ai' ),
				),
				'url'         => array(
					'type'        => 'string',
					'description' => __( 'The URL the menu item links to.', 'ai' ),
				),
				'target'      => array(
					'type'        => 'string',
					'description' => __( 'The link target attribute (for example _blank), or an empty string.', 'ai' ),
				),
				'classes'     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'CSS classes applied to the menu item.', 'ai' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'The menu item description.', 'ai' ),
				),
				'menu_order'  => array(
					'type'        => 'integer',
					'description' => __( 'The menu item order among its siblings.', 'ai' ),
				),
				'type'        => array(
					'type'        => 'string',
					'description' => __( 'The menu item type: custom, post_type, or taxonomy.', 'ai' ),
				),
				'object'      => array(
					'type'        => 'string',
					'description' => __( 'The underlying object type for post_type and taxonomy items (for example page or category). Empty for custom links.', 'ai' ),
				),
				'object_id'   => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the linked post or term, or 0 for a custom link.', 'ai' ),
				),
			),
		);

		$menu_properties = array(
			'id'        => array(
				'type'        => 'integer',
				'description' => __( 'The nav menu term ID.', 'ai' ),
			),
			'name'      => array(
				'type'        => 'string',
				'description' => __( 'The nav menu name.', 'ai' ),
			),
			'slug'      => array(
				'type'        => 'string',
				'description' => __( 'The nav menu slug.', 'ai' ),
			),
			'count'     => array(
				'type'        => 'integer',
				'description' => __( 'Number of items in the menu.', 'ai' ),
			),
			'locations' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Registered theme locations this menu is currently assigned to.', 'ai' ),
			),
		);

		$menu_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array_merge(
				$menu_properties,
				array(
					'items' => array(
						'type'        => 'array',
						'items'       => $menu_item_schema,
						'description' => __( 'The menu items, in menu order.', 'ai' ),
					),
				)
			),
		);

		$menu_summary_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $menu_properties,
		);

		$collection_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'menus', 'registered_locations', 'location_assignments' ),
			'properties'           => array(
				'menus'                => array(
					'type'        => 'array',
					'items'       => $menu_summary_schema,
					'description' => __( 'All nav menus on the site.', 'ai' ),
				),
				'registered_locations' => array(
					'type'                 => 'object',
					'description'          => __( 'Registered theme locations, keyed by location slug, with a human-readable label.', 'ai' ),
					'additionalProperties' => array( 'type' => 'string' ),
				),
				'location_assignments' => array(
					'type'                 => 'object',
					'description'          => __( 'The nav menu term ID currently assigned to each registered theme location, keyed by location slug. A location with no assigned menu is omitted.', 'ai' ),
					'additionalProperties' => array( 'type' => 'integer' ),
				),
			),
		);

		return array(
			'oneOf' => array(
				$menu_schema,
				$collection_schema,
			),
		);
	}
}

<?php
/**
 * The `core/post-types-query` WordPress Ability.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Post_Types;

use WP_Error;
use WP_Post_Type;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Post_Types
 *
 * Registers the read-only `core/post-types-query` ability, which retrieves the post types
 * exposed to abilities via `show_in_abilities`: a single post type by slug, or all of them.
 * Edit-only fields require permission to edit posts of the type.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Post_Types {

	/**
	 * The ability category used for post type abilities.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const CATEGORY = 'content';

	/**
	 * Fields that expose edit-context post type data.
	 *
	 * A request that includes any of these fields is answered in the edit context, which
	 * requires permission to edit posts of the type.
	 *
	 * @since x.x.x
	 * @var list<string>
	 */
	private array $edit_fields = array(
		'capabilities',
		'viewable',
		'labels',
		'supports',
		'visibility',
	);

	/**
	 * Default fields returned when the caller does not request a field subset.
	 *
	 * @since x.x.x
	 * @var list<string>
	 */
	private array $default_fields = array(
		'description',
		'hierarchical',
		'name',
		'slug',
		'has_archive',
		'taxonomies',
		'icon',
		'template',
		'template_lock',
	);

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * Registers slightly later than core (priority 11) so it can replace any core-provided
	 * copy, and registers the category as a fallback in case core has not.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ), 11 );
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers the `content` ability category if it is not already registered.
	 *
	 * @since x.x.x
	 */
	public function register_category(): void {
		if ( wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Content', 'ai' ),
				'description' => __( 'Abilities that retrieve or manage posts and other content.', 'ai' ),
			)
		);
	}

	/**
	 * Registers the read-only `core/post-types-query` ability.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		/*
		 * Post types must be registered with `show_in_abilities` before the ability is
		 * registered so they are included in its input schema.
		 */
		$post_types = array_values( get_post_types( array( 'show_in_abilities' => true ) ) );
		if ( empty( $post_types ) ) {
			return;
		}

		// Unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/post-types-query' ) ) {
			wp_unregister_ability( 'core/post-types-query' );
		}

		wp_register_ability(
			'core/post-types-query',
			array(
				'label'               => __( 'Post Types Query', 'ai' ),
				'description'         => __( 'Reads the post types exposed to abilities, which are the post types core/content-query accepts. A single post type requested by slug is returned directly; otherwise every exposed post type is listed. Capabilities, labels, supported features, viewability, and visibility require permission to edit posts of the type. Requires an authenticated user.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_input_schema( $post_types ),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => array( $this, 'execute_post_types_query' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						// MCP clients assume open-world (may reach external systems) when the
						// hint is absent; this ability only reads the local database.
						'open_world'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission callback for the `core/post-types-query` ability.
	 *
	 * Requires an authenticated user. A single post type must be exposed to abilities, and
	 * with an edit-only field the user must be able to edit its posts; a missing post type
	 * is denied like a hidden one. A list with an edit-only field requires permission to
	 * edit the posts of at least one exposed post type. {@see self::execute_post_types_query()}
	 * reports the specific errors: the Abilities API replaces any error returned here with
	 * a generic one.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_permission( $input = array() ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$request = $this->prepare_request( rest_sanitize_object( $input ) );

		if ( isset( $request['type'] ) ) {
			return ! is_wp_error( $this->get_item( $request ) );
		}

		return true === $this->get_items_permissions_check( $request );
	}

	/**
	 * Executes the `core/post-types-query` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error A single post type, a `post_types` list, or an error.
	 */
	public function execute_post_types_query( $input = array() ) {
		$request = $this->prepare_request( rest_sanitize_object( $input ) );

		if ( isset( $request['type'] ) ) {
			return $this->get_item( $request );
		}

		$permission = $this->get_items_permissions_check( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		return $this->get_items( $request );
	}

	/**
	 * Builds the request parameters from the ability input.
	 *
	 * Requesting an edit-only field, or a field nested in one, selects the edit context. GET
	 * requests deliver lists as CSV strings, so `fields` is parsed in both forms.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return array<string, mixed> The request parameters.
	 */
	private function prepare_request( array $input ): array {
		$fields = isset( $input['fields'] ) && ( is_array( $input['fields'] ) || is_string( $input['fields'] ) )
			? array_values( array_filter( wp_parse_list( $input['fields'] ), 'is_string' ) )
			: array();

		$requested_edit_fields = array_filter(
			$this->edit_fields,
			static fn( string $field ): bool => rest_is_field_included( $field, $fields )
		);

		$request = array(
			'context' => array() === $requested_edit_fields ? 'view' : 'edit',
			'fields'  => $fields,
		);

		if ( array_key_exists( 'slug', $input ) ) {
			$request['type'] = is_string( $input['slug'] ) ? $input['slug'] : '';
		}

		return $request;
	}

	/**
	 * Returns the fields to include in a response.
	 *
	 * Without requested fields, every field of the view context is included.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $request The request parameters.
	 * @return list<string> The field names.
	 */
	private function get_fields_for_response( array $request ): array {
		return array() !== $request['fields'] ? $request['fields'] : $this->default_fields;
	}

	/**
	 * Checks whether a given request has permission to read types.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $request The request parameters.
	 * @return true|\WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	private function get_items_permissions_check( array $request ) {
		if ( 'edit' === $request['context'] ) {
			$types = get_post_types( array( 'show_in_abilities' => true ), 'objects' );

			foreach ( $types as $type ) {
				if ( current_user_can( $type->cap->edit_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
					return true;
				}
			}

			return new WP_Error(
				'post_types_cannot_view',
				__( 'Sorry, you are not allowed to edit posts in this post type.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Retrieves all exposed post types.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $request The request parameters.
	 * @return array<string, mixed> The `post_types` list.
	 */
	private function get_items( array $request ): array {
		$data  = array();
		$types = get_post_types( array( 'show_in_abilities' => true ), 'objects' );

		foreach ( $types as $type ) {
			if ( 'edit' === $request['context'] && ! current_user_can( $type->cap->edit_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
				continue;
			}

			$data[] = $this->prepare_item_for_response( $type, $request );
		}

		return array( 'post_types' => $data );
	}

	/**
	 * Retrieves a specific post type.
	 *
	 * A post type that is not exposed to abilities is reported like a missing one.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $request The request parameters.
	 * @return array<string, mixed>|\WP_Error The post type data, or an error.
	 */
	private function get_item( array $request ) {
		$obj = get_post_type_object( $request['type'] );

		if ( empty( $obj ) || empty( $obj->show_in_abilities ) ) {
			return new WP_Error(
				'post_types_type_invalid',
				__( 'Invalid post type.', 'ai' ),
				array( 'status' => 404 )
			);
		}

		if ( 'edit' === $request['context'] && ! current_user_can( $obj->cap->edit_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
			return new WP_Error(
				'post_types_forbidden_context',
				__( 'Sorry, you are not allowed to edit posts in this post type.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return $this->prepare_item_for_response( $obj, $request );
	}

	/**
	 * Prepares a post type object for serialization.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post_Type        $item    Post type object.
	 * @param array<string, mixed> $request The request parameters.
	 * @return array<string, mixed> The post type data.
	 */
	private function prepare_item_for_response( WP_Post_Type $item, array $request ): array {
		// Restores the more descriptive, specific name for use within this method.
		$post_type = $item;

		$taxonomies = wp_list_filter( get_object_taxonomies( $post_type->name, 'objects' ), array( 'show_in_rest' => true ) );
		$taxonomies = wp_list_pluck( $taxonomies, 'name' );
		$supports   = get_all_post_type_supports( $post_type->name );

		$fields = $this->get_fields_for_response( $request );
		$data   = array();

		if ( rest_is_field_included( 'capabilities', $fields ) ) {
			$data['capabilities'] = $post_type->cap;
		}

		if ( rest_is_field_included( 'description', $fields ) ) {
			$data['description'] = $post_type->description;
		}

		if ( rest_is_field_included( 'hierarchical', $fields ) ) {
			$data['hierarchical'] = $post_type->hierarchical;
		}

		if ( rest_is_field_included( 'has_archive', $fields ) ) {
			$data['has_archive'] = $post_type->has_archive;
		}

		if ( rest_is_field_included( 'visibility', $fields ) ) {
			$data['visibility'] = array(
				'show_in_nav_menus' => (bool) $post_type->show_in_nav_menus,
				'show_ui'           => (bool) $post_type->show_ui,
			);
		}

		if ( rest_is_field_included( 'viewable', $fields ) ) {
			$data['viewable'] = is_post_type_viewable( $post_type );
		}

		if ( rest_is_field_included( 'labels', $fields ) ) {
			$data['labels'] = $post_type->labels;
		}

		if ( rest_is_field_included( 'name', $fields ) ) {
			$data['name'] = $post_type->label;
		}

		if ( rest_is_field_included( 'slug', $fields ) ) {
			$data['slug'] = $post_type->name;
		}

		if ( rest_is_field_included( 'icon', $fields ) ) {
			$data['icon'] = $post_type->menu_icon;
		}

		if ( rest_is_field_included( 'supports', $fields ) ) {
			// An empty array would be encoded as a JSON array, not an object.
			$data['supports'] = array() === $supports ? (object) array() : $supports;
		}

		if ( rest_is_field_included( 'taxonomies', $fields ) ) {
			$data['taxonomies'] = array_values( $taxonomies );
		}

		if ( rest_is_field_included( 'template', $fields ) ) {
			$data['template'] = $post_type->template ?? array(); // @phpstan-ignore nullCoalesce.property (register_post_type() can set the template to null.)
		}

		if ( rest_is_field_included( 'template_lock', $fields ) ) {
			$data['template_lock'] = ! empty( $post_type->template_lock ) ? $post_type->template_lock : false;
		}

		return $data;
	}

	/**
	 * Retrieves the post type's schema, conforming to JSON Schema.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array<string, mixed>> Post type field definitions.
	 */
	private function get_item_schema(): array {
		return array(
			'capabilities'  => array(
				'description' => __( 'All capabilities used by the post type.', 'ai' ),
				'type'        => 'object',
			),
			'description'   => array(
				'description' => __( 'A human-readable description of the post type.', 'ai' ),
				'type'        => 'string',
			),
			'hierarchical'  => array(
				'description' => __( 'Whether or not the post type should have children.', 'ai' ),
				'type'        => 'boolean',
			),
			'viewable'      => array(
				'description' => __( 'Whether or not the post type can be viewed.', 'ai' ),
				'type'        => 'boolean',
			),
			'labels'        => array(
				'description' => __( 'Human-readable labels for the post type for various contexts.', 'ai' ),
				'type'        => 'object',
			),
			'name'          => array(
				'description' => __( 'The title for the post type.', 'ai' ),
				'type'        => 'string',
			),
			'slug'          => array(
				'description' => __( 'An alphanumeric identifier for the post type.', 'ai' ),
				'type'        => 'string',
			),
			'supports'      => array(
				'description' => __( 'All features, supported by the post type.', 'ai' ),
				'type'        => 'object',
			),
			'has_archive'   => array(
				'description' => __( 'If the value is a string, the value will be used as the archive slug. If the value is false the post type has no archive.', 'ai' ),
				'type'        => array( 'string', 'boolean' ),
			),
			'taxonomies'    => array(
				'description' => __( 'Taxonomies associated with post type.', 'ai' ),
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
				),
			),
			'visibility'    => array(
				'description' => __( 'The visibility settings for the post type.', 'ai' ),
				'type'        => 'object',
				'properties'  => array(
					'show_ui'           => array(
						'description' => __( 'Whether to generate a default UI for managing this post type.', 'ai' ),
						'type'        => 'boolean',
					),
					'show_in_nav_menus' => array(
						'description' => __( 'Whether to make the post type available for selection in navigation menus.', 'ai' ),
						'type'        => 'boolean',
					),
				),
			),
			'icon'          => array(
				'description' => __( 'The icon for the post type.', 'ai' ),
				'type'        => array( 'string', 'null' ),
			),
			'template'      => array(
				'type'        => array( 'array' ),
				'description' => __( 'The block template associated with the post type.', 'ai' ),
			),
			'template_lock' => array(
				'type'        => array( 'string', 'boolean' ),
				'enum'        => array( 'all', 'insert', 'contentOnly', false ),
				'description' => __( 'The template_lock associated with the post type, or false if none.', 'ai' ),
			),
		);
	}

	/**
	 * Builds the input schema for the `core/post-types-query` ability.
	 *
	 * The ability has two mutually exclusive modes, modeled as a `oneOf` so invalid
	 * combinations are rejected rather than silently ignored: get a single post type by
	 * `slug`, or list every exposed post type. `fields` is accepted in both. Omitting the
	 * input lists the post types.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $post_types Exposed post type names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_input_schema( array $post_types ): array {
		$fields = array(
			'type'        => 'array',
			'uniqueItems' => true,
			'items'       => array(
				'type' => 'string',
				'enum' => array_keys( $this->get_item_schema() ),
			),
			'description' => __( 'Limit each returned post type to these fields. If omitted, every field except capabilities, labels, supports, viewable, and visibility is returned. Those fields require permission to edit posts of the type, so requesting one lists only the post types the current user can edit.', 'ai' ),
		);

		return array(
			'type'    => 'object',
			'default' => (object) array(),
			'oneOf'   => array(
				// Listed first so that invalid list input is reported against this mode: core
				// picks the mode sharing the most properties with the input, the first on a tie.
				array(
					'title'                => __( 'List the post types', 'ai' ),
					'additionalProperties' => false,
					'properties'           => array(
						'fields' => $fields,
					),
				),
				array(
					'title'                => __( 'Get a single post type by slug', 'ai' ),
					'required'             => array( 'slug' ),
					'additionalProperties' => false,
					'properties'           => array(
						'slug'   => array(
							'type'        => 'string',
							'enum'        => $post_types,
							'description' => __( 'An alphanumeric identifier for the post type.', 'ai' ),
						),
						'fields' => $fields,
					),
				),
			),
		);
	}

	/**
	 * Builds the output schema for the `core/post-types-query` ability.
	 *
	 * No field is marked required because the `fields` input lets the caller request any
	 * subset. Single mode returns the post type directly, while list mode returns a wrapper;
	 * neither accepts unknown properties, so a response matches one.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_output_schema(): array {
		$item_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $this->get_item_schema(),
		);

		return array(
			'type'  => 'object',
			'oneOf' => array(
				$item_schema,
				array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'post_types' ),
					'properties'           => array(
						'post_types' => array(
							'type'        => 'array',
							'description' => __( 'The post types exposed to abilities, or, when an edit-only field is requested, those whose posts the current user can edit.', 'ai' ),
							'items'       => $item_schema,
						),
					),
				),
			),
		);
	}
}

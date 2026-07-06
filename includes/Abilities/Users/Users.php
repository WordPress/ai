<?php
/**
 * The `core/read-users` WordPress Ability.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Users;

use WP_Error;
use WP_User;
use WP_User_Query;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Users
 *
 * Registers the read-only `core/read-users` ability, which retrieves one or more
 * readable WordPress users. Supports fetching a single readable user by ID,
 * email, username, or slug, or querying a paginated collection optionally
 * filtered by roles, published-post authorship, or included IDs. Field-level access is enforced
 * per user by omitting fields the current user cannot view.
 *
 * This class is kept almost identical to the proposed WordPress core implementation
 * so the two implementations stay in sync. Most differences from the core version are marked with
 * `// Plugin:` comments. Additionally, all user-facing strings use the 'ai' text domain.
 *
 * Plugin: the class is final and instance-based (with private helpers), matching the
 * plugin's other ability classes (e.g. `Settings`) and core's `WP_Settings_Abilities`.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Users {

	/**
	 * The ability category used for user abilities.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const CATEGORY = 'user';

	/**
	 * Default number of users returned per page in collection mode.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const DEFAULT_PER_PAGE = 10;

	/**
	 * Maximum number of users returned per page in collection mode.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Lookup type returned for collection requests.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const LOOKUP_COLLECTION = 'collection';

	/**
	 * Default fields returned when the caller does not request a field subset.
	 *
	 * @since x.x.x
	 * @var string[]
	 */
	private const DEFAULT_FIELDS = array(
		'id',
		'name',
		'link',
		'slug',
		'avatar_urls',
	);

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * Plugin: this method has no equivalent in the core class. In core, register() is
	 * invoked directly from wp_register_core_abilities() (already on the
	 * `wp_abilities_api_init` hook). The plugin instead hooks register() slightly later
	 * (priority 11) so it can override any core-provided copy.
	 *
	 * @since x.x.x
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers all user abilities.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		$this->register_get_users();
	}

	/**
	 * Registers the read-only `core/read-users` ability.
	 *
	 * @since x.x.x
	 */
	private function register_get_users(): void {
		// Plugin: unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/read-users' ) ) {
			wp_unregister_ability( 'core/read-users' );
		}

		wp_register_ability(
			'core/read-users',
			array(
				'label'               => __( 'Read Users', 'ai' ),
				'description'         => __( 'Retrieves one or more readable WordPress users. Fetch a single readable user by ID, email, username, or slug, or query a paginated collection optionally filtered by roles, published-post authorship, or included IDs.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_users_input_schema(),
				'output_schema'       => $this->get_users_output_schema(),
				'execute_callback'    => array( $this, 'execute_get_users' ),
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
	 * Permission callback for the `core/read-users` ability.
	 *
	 * Performs request-level checks. Single-user requests are checked against
	 * the target user, while collection requests rely on query arguments in
	 * {@see self::execute_get_users()} for row-level access.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_permission( $input = array() ): bool {
		$input = is_array( $input ) ? $input : array();

		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( ! empty( $input['roles'] ) && ! current_user_can( 'list_users' ) ) {
			return false;
		}

		$lookup_type = $this->get_lookup_type( $input );
		if ( self::LOOKUP_COLLECTION === $lookup_type ) {
			return true;
		}

		$user = $this->find_user( $input );
		if ( ! $user instanceof WP_User || ! $this->is_user_member_of_site( $user ) ) {
			return false;
		}

		return $this->can_read_user_for_lookup( $user, $lookup_type );
	}

	/**
	 * Executes the `core/read-users` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error User data, paginated collection data, or a WP_Error on failure.
	 */
	public function execute_get_users( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$fields = $this->normalize_fields( $input );

		$lookup_type = $this->get_lookup_type( $input );
		if ( self::LOOKUP_COLLECTION !== $lookup_type ) {
			$user = $this->find_user( $input );
			if ( ! $user instanceof WP_User
				|| ! $this->is_user_member_of_site( $user )
				|| ! $this->can_read_user_for_lookup( $user, $lookup_type )
			) {
				return new WP_Error(
					'ability_invalid_permissions',
					__( 'The requested user cannot be read.', 'ai' )
				);
			}

			return $this->format_user( $user, $fields );
		}

		$per_page = $this->normalize_per_page( $input );
		$page     = isset( $input['page'] ) ? max( 1, $this->input_int( $input['page'] ) ) : 1;

		$query_args = array(
			'number'      => $per_page,
			'offset'      => ( $page - 1 ) * $per_page,
			'count_total' => true,
		);

		$include = $this->normalize_include( $input );
		if ( array() !== $include ) {
			// Default ordering is kept so queries with the same include set can
			// share the WP_User_Query cache regardless of the requested order.
			$query_args['include'] = $include;
		}

		if ( ! empty( $input['roles'] ) && current_user_can( 'list_users' ) ) {
			$query_args['role__in'] = $this->normalize_string_list( $input['roles'] );
		}

		if ( current_user_can( 'list_users' ) ) {
			$has_published_posts = $this->normalize_has_published_posts( $input );
			if ( null !== $has_published_posts ) {
				$query_args['has_published_posts'] = $has_published_posts;
			}
		} else {
			// Callers who cannot list users only see public authors in a collection,
			// matching core. This intentionally excludes the caller's own account when
			// they have no published posts. Self is read through a single-user lookup
			// (like the REST `/users/me` endpoint) instead.
			$public_post_types   = $this->get_public_post_types();
			$has_published_posts = $this->normalize_has_published_posts( $input );

			if ( is_array( $has_published_posts ) ) {
				$public_post_types = array_values( array_intersect( $public_post_types, $has_published_posts ) );
			}

			if ( array() === $public_post_types ) {
				return array(
					'users'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				);
			}

			$query_args['has_published_posts'] = $public_post_types;
		}

		$query = new WP_User_Query( $query_args );

		$users = array();
		foreach ( $query->get_results() as $user ) {
			// WP_User_Query already scopes multisite collections to current-site members.
			// Keep this as a defensive guard for filtered/custom query results.
			if ( ! $user instanceof WP_User || ! $this->is_user_member_of_site( $user ) ) {
				continue;
			}

			$users[] = $this->format_user( $user, $fields );
		}

		$total_users = (int) $query->get_total();

		return array(
			'users'       => $users,
			'total'       => $total_users,
			'total_pages' => (int) ceil( $total_users / $per_page ),
		);
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
	 * Determines the single-user lookup type represented by the input.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return string The lookup type, or {@see self::LOOKUP_COLLECTION}.
	 */
	private function get_lookup_type( array $input ): string {
		foreach ( array( 'id', 'email', 'username', 'slug' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				return $key;
			}
		}

		return self::LOOKUP_COLLECTION;
	}

	/**
	 * Finds a user by one of the supported unique input identifiers.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return \WP_User|null User object, or null when not found.
	 */
	private function find_user( array $input ): ?WP_User {
		if ( array_key_exists( 'id', $input ) ) {
			$user = get_userdata( $this->input_int( $input['id'] ) );
			return $user instanceof WP_User ? $user : null;
		}

		if ( array_key_exists( 'email', $input ) ) {
			if ( ! is_string( $input['email'] ) ) {
				return null;
			}

			$user = get_user_by( 'email', sanitize_email( $input['email'] ) );
			return $user instanceof WP_User ? $user : null;
		}

		if ( array_key_exists( 'username', $input ) ) {
			if ( ! is_string( $input['username'] ) ) {
				return null;
			}

			$user = get_user_by( 'login', $input['username'] );
			return $user instanceof WP_User ? $user : null;
		}

		if ( array_key_exists( 'slug', $input ) ) {
			if ( ! is_string( $input['slug'] ) ) {
				return null;
			}

			$user = get_user_by( 'slug', sanitize_title( $input['slug'] ) );
			return $user instanceof WP_User ? $user : null;
		}

		return null;
	}

	/**
	 * Checks whether a user belongs to the current site.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User $user User object.
	 * @return bool Whether the user belongs to the current site.
	 */
	private function is_user_member_of_site( WP_User $user ): bool {
		return ! is_multisite() || is_user_member_of_blog( (int) $user->ID );
	}

	/**
	 * Checks whether a single-user lookup may return the target user.
	 *
	 * Email and username are identifier-sensitive lookup modes and do not use the
	 * public-author fallback.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User $user        User object.
	 * @param string   $lookup_type Lookup type.
	 * @return bool Whether the user can be read for that lookup type.
	 */
	private function can_read_user_for_lookup( WP_User $user, string $lookup_type ): bool {
		if ( $this->is_current_user( $user ) ) {
			return true;
		}

		if ( current_user_can( 'edit_user', $user->ID ) || current_user_can( 'list_users' ) ) {
			return true;
		}

		if ( 'email' === $lookup_type || 'username' === $lookup_type ) {
			return false;
		}

		return $this->is_public_author( $user );
	}

	/**
	 * Checks whether the current user is the target user.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User $user User object.
	 * @return bool Whether the current user is the target user.
	 */
	private function is_current_user( WP_User $user ): bool {
		return get_current_user_id() === (int) $user->ID;
	}

	/**
	 * Checks whether a user has published posts in publicly viewable post types.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User $user User object.
	 * @return bool Whether the user is publicly visible as an author.
	 */
	private function is_public_author( WP_User $user ): bool {
		$post_types = $this->get_public_post_types();
		if ( array() === $post_types ) {
			return false;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts -- Public-author checks only consider publicly viewable post types.
		return count_user_posts( (int) $user->ID, $post_types ) > 0;
	}

	/**
	 * Returns publicly viewable post types.
	 *
	 * Uses {@see is_post_type_viewable()} rather than the `public` registration
	 * argument, since `public` alone does not guarantee a post type is viewable
	 * on the front end. Deliberately resolved on every call rather than cached:
	 * post types can be unregistered or re-registered with different arguments
	 * between the ability being registered and the ability being used.
	 *
	 * @since x.x.x
	 *
	 * @return string[] Publicly viewable post type names.
	 */
	private function get_public_post_types(): array {
		return array_values( array_filter( get_post_types(), 'is_post_type_viewable' ) );
	}

	/**
	 * Returns the requested fields, or a lean default set when none are given.
	 *
	 * An empty or absent `fields` value selects a lean set of common read fields.
	 * Otherwise the requested fields are returned as-is. The input schema has already
	 * validated them against the supported set before the ability executes.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return string[] List of requested field names.
	 */
	private function normalize_fields( array $input ): array {
		if ( empty( $input['fields'] ) || ! is_array( $input['fields'] ) ) {
			return $this->get_default_fields();
		}

		/** @var string[] $fields */
		$fields = $input['fields'];

		return $fields;
	}

	/**
	 * Returns the user field definitions, keyed by field name in output order.
	 *
	 * This is the single source of truth for the ability's user fields: the output
	 * schema uses the definitions directly, while the input schema and field
	 * normalization use the keys. The `avatar_urls` field is dropped when the
	 * `show_avatars` option is disabled. Deliberately resolved on every call rather
	 * than cached, since the option and the registered roles can change between the
	 * ability being registered and the ability being used.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> User field definitions.
	 */
	private function get_user_properties(): array {
		$user_properties = array(
			'id'              => array(
				'type'        => 'integer',
				'description' => __( 'The user ID.', 'ai' ),
			),
			'name'            => array(
				'type'        => 'string',
				'description' => __( 'The display name for the user.', 'ai' ),
			),
			'description'     => array(
				'type'        => 'string',
				'description' => __( 'Description of the user.', 'ai' ),
			),
			'url'             => array(
				'type'        => 'string',
				'description' => __( 'URL of the user.', 'ai' ),
			),
			'link'            => array(
				'type'        => 'string',
				'description' => __( 'Author archive URL for the user.', 'ai' ),
			),
			'slug'            => array(
				'type'        => 'string',
				'description' => __( 'An alphanumeric identifier for the user.', 'ai' ),
			),
			'avatar_urls'     => array(
				'type'                 => 'object',
				'description'          => __( 'Avatar URLs for the user at various sizes.', 'ai' ),
				'additionalProperties' => array(
					'type' => 'string',
				),
			),
			'username'        => array(
				'type'        => 'string',
				'description' => __( 'Login name for the user. Present when the current user can view it.', 'ai' ),
			),
			'email'           => array(
				'type'        => 'string',
				'format'      => 'email',
				'description' => __( 'The email address for the user. Present when the current user can view it.', 'ai' ),
			),
			'first_name'      => array(
				'type'        => 'string',
				'description' => __( 'First name for the user. Present when the current user can view it.', 'ai' ),
			),
			'last_name'       => array(
				'type'        => 'string',
				'description' => __( 'Last name for the user. Present when the current user can view it.', 'ai' ),
			),
			'nickname'        => array(
				'type'        => 'string',
				'description' => __( 'The nickname for the user. Present when the current user can view it.', 'ai' ),
			),
			'locale'          => array(
				'type'        => 'string',
				'description' => __( 'Locale for the user. Present when the current user can view it.', 'ai' ),
			),
			'registered_date' => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( 'Registration date for the user. Present when the current user can view it.', 'ai' ),
			),
			'roles'           => array(
				'type'        => 'array',
				'description' => __( 'Roles assigned to the user. Present when the current user can view them.', 'ai' ),
				'items'       => array(
					'type' => 'string',
					'enum' => $this->get_role_names(),
				),
			),
		);

		if ( ! get_option( 'show_avatars' ) ) {
			unset( $user_properties['avatar_urls'] );
		}

		return $user_properties;
	}

	/**
	 * Returns the default field list in output order.
	 *
	 * Unavailable fields are dropped through {@see self::get_user_properties()}.
	 *
	 * @since x.x.x
	 *
	 * @return string[] Default field names.
	 */
	private function get_default_fields(): array {
		return array_values( array_intersect( array_keys( $this->get_user_properties() ), self::DEFAULT_FIELDS ) );
	}

	/**
	 * Returns registered role names.
	 *
	 * Deliberately resolved on every call rather than cached, since roles can be
	 * registered or unregistered at runtime.
	 *
	 * @since x.x.x
	 *
	 * @return string[] Role names.
	 */
	private function get_role_names(): array {
		return array_keys( wp_roles()->roles );
	}

	/**
	 * Normalizes the requested per-page value to the supported bounds.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return int The clamped per-page value.
	 */
	private function normalize_per_page( array $input ): int {
		$per_page = isset( $input['per_page'] ) ? $this->input_int( $input['per_page'] ) : self::DEFAULT_PER_PAGE;

		return max( 1, min( self::MAX_PER_PAGE, $per_page ) );
	}

	/**
	 * Normalizes a mixed value into a list of non-empty strings.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value Raw value.
	 * @return string[] Normalized strings.
	 */
	private function normalize_string_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$strings = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === $item ) {
				continue;
			}

			$strings[] = $item;
		}

		return array_values( array_unique( $strings ) );
	}

	/**
	 * Normalizes collection-mode included user IDs.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return int[] User IDs.
	 */
	private function normalize_include( array $input ): array {
		if ( empty( $input['include'] ) || ! is_array( $input['include'] ) ) {
			return array();
		}

		$ids = array_map( array( $this, 'input_int' ), $input['include'] );
		$ids = array_filter( $ids );

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Normalizes the `has_published_posts` collection input.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return bool|string[]|null Normalized query value, or null when omitted.
	 */
	private function normalize_has_published_posts( array $input ) {
		if ( ! array_key_exists( 'has_published_posts', $input ) ) {
			return null;
		}

		if ( true === $input['has_published_posts'] ) {
			return true;
		}

		$post_types = $this->normalize_string_list( $input['has_published_posts'] );

		return array() === $post_types ? null : $post_types;
	}

	/**
	 * Builds the input schema for the `core/read-users` ability.
	 *
	 * The ability has five mutually exclusive modes, modeled as a `oneOf` so invalid
	 * combinations are rejected rather than silently ignored:
	 *
	 *   - Get a single readable user by `id`.
	 *   - Get a single readable user by `email`.
	 *   - Get a single readable user by `username`.
	 *   - Get a single readable user by `slug`.
	 *   - Query a collection of readable users.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_users_input_schema(): array {
		/*
		 * The schema enums reflect the roles and post types available at registration
		 * time; the permission/execute callbacks re-resolve these sets at call time,
		 * since both can change in between.
		 */
		$role_names        = $this->get_role_names();
		$public_post_types = $this->get_public_post_types();
		$fields            = array(
			'type'        => 'array',
			'uniqueItems' => true,
			'minItems'    => 1,
			'items'       => array(
				'type' => 'string',
				'enum' => array_keys( $this->get_user_properties() ),
			),
			'description' => __( 'Limit each returned user to these fields. If omitted, a lean set of common read fields is returned.', 'ai' ),
		);
		$include           = array(
			'type'        => 'array',
			'uniqueItems' => true,
			'minItems'    => 1,
			'items'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'description' => __( 'Limit the query to these user IDs. Collection results are limited to users the caller can read, which for callers without permission to list users means only public authors. To read your own account, use a single-user lookup by ID.', 'ai' ),
		);

		return array(
			'type'    => 'object',
			'default' => (object) array(),
			'oneOf'   => array(
				array(
					'title'                => __( 'Get a single readable user by ID', 'ai' ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Retrieve a single readable user by ID.', 'ai' ),
						),
						'fields' => $fields,
					),
				),
				array(
					'title'                => __( 'Get a single readable user by email address', 'ai' ),
					'required'             => array( 'email' ),
					'additionalProperties' => false,
					'properties'           => array(
						'email'  => array(
							'type'        => 'string',
							'format'      => 'email',
							'description' => __( 'Retrieve a single readable user by email address. Resolving another user by email requires permission to list or edit users.', 'ai' ),
						),
						'fields' => $fields,
					),
				),
				array(
					'title'                => __( 'Get a single readable user by username', 'ai' ),
					'required'             => array( 'username' ),
					'additionalProperties' => false,
					'properties'           => array(
						'username' => array(
							'type'        => 'string',
							'description' => __( 'Retrieve a single readable user by username. Resolving another user by username requires permission to list or edit users.', 'ai' ),
						),
						'fields'   => $fields,
					),
				),
				array(
					'title'                => __( 'Get a single readable user by slug', 'ai' ),
					'required'             => array( 'slug' ),
					'additionalProperties' => false,
					'properties'           => array(
						'slug'   => array(
							'type'        => 'string',
							'description' => __( 'Retrieve a single readable user by slug.', 'ai' ),
						),
						'fields' => $fields,
					),
				),
				array(
					'title'                => __( 'Query readable users', 'ai' ),
					'additionalProperties' => false,
					'properties'           => array(
						'roles'               => array(
							'type'        => 'array',
							'uniqueItems' => true,
							'minItems'    => 1,
							'items'       => array(
								'type' => 'string',
								'enum' => $role_names,
							),
							'description' => __( 'Filter users by one or more roles. Requires permission to list users.', 'ai' ),
						),
						'has_published_posts' => array(
							'oneOf'       => array(
								array(
									'type' => 'boolean',
									'enum' => array( true ),
								),
								array(
									'type'        => 'array',
									'uniqueItems' => true,
									'minItems'    => 1,
									'items'       => array(
										'type' => 'string',
										'enum' => $public_post_types,
									),
								),
							),
							'description' => __( 'Limit results to users with published posts. Use true for all post types, or provide post type names.', 'ai' ),
						),
						'include'             => $include,
						'fields'              => $fields,
						'page'                => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Page of results to return.', 'ai' ),
						),
						'per_page'            => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => self::MAX_PER_PAGE,
							'description' => __( 'Maximum number of users to return per page.', 'ai' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Builds the output schema for the `core/read-users` ability.
	 *
	 * No user field is marked required because the `fields` input lets the caller
	 * request any subset, and restricted fields are omitted when unavailable.
	 * Single-user mode returns the user object directly, while collection mode returns
	 * a paginated wrapper.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_users_output_schema(): array {
		$user_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $this->get_user_properties(),
		);

		$collection_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'users', 'total', 'total_pages' ),
			'properties'           => array(
				'users'       => array(
					'type'        => 'array',
					'description' => __( 'The readable users matching the collection request.', 'ai' ),
					'items'       => $user_schema,
				),
				'total'       => array(
					'type'        => 'integer',
					'description' => __( 'Total number of users matching the query, across all pages.', 'ai' ),
				),
				'total_pages' => array(
					'type'        => 'integer',
					'description' => __( 'Total number of result pages available for the query.', 'ai' ),
				),
			),
		);

		return array(
			'oneOf' => array(
				$user_schema,
				$collection_schema,
			),
		);
	}

	/**
	 * Formats a user into the ability output shape.
	 *
	 * Only the requested fields the current user can see are included.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User $user   The user object.
	 * @param string[] $fields The requested field names.
	 * @return array<string, mixed> The formatted user data.
	 */
	private function format_user( WP_User $user, array $fields ): array {
		$fields_requested = static function ( string $field ) use ( $fields ): bool {
			return in_array( $field, $fields, true );
		};

		$user_id            = (int) $user->ID;
		$can_view_sensitive = $this->is_current_user( $user ) || current_user_can( 'edit_user', $user_id );
		$can_view_roles     = current_user_can( 'list_users' ) || current_user_can( 'edit_user', $user_id );

		$data = array();

		if ( $fields_requested( 'id' ) ) {
			$data['id'] = $user_id;
		}
		if ( $fields_requested( 'name' ) ) {
			$data['name'] = (string) $user->display_name;
		}
		if ( $fields_requested( 'description' ) ) {
			$data['description'] = (string) $user->description;
		}
		if ( $fields_requested( 'url' ) ) {
			$data['url'] = (string) $user->user_url;
		}
		if ( $fields_requested( 'link' ) ) {
			$data['link'] = (string) get_author_posts_url( $user_id, $user->user_nicename );
		}
		if ( $fields_requested( 'slug' ) ) {
			$data['slug'] = (string) $user->user_nicename;
		}
		if ( $fields_requested( 'avatar_urls' ) ) {
			$data['avatar_urls'] = rest_get_avatar_urls( $user );
		}

		if ( $can_view_sensitive ) {
			if ( $fields_requested( 'username' ) ) {
				$data['username'] = (string) $user->user_login;
			}
			if ( $fields_requested( 'email' ) ) {
				$data['email'] = (string) $user->user_email;
			}
			if ( $fields_requested( 'first_name' ) ) {
				$data['first_name'] = (string) $user->first_name;
			}
			if ( $fields_requested( 'last_name' ) ) {
				$data['last_name'] = (string) $user->last_name;
			}
			if ( $fields_requested( 'nickname' ) ) {
				$data['nickname'] = (string) $user->nickname;
			}
			if ( $fields_requested( 'locale' ) ) {
				$data['locale'] = (string) get_user_locale( $user );
			}
			if ( $fields_requested( 'registered_date' ) ) {
				$registered_timestamp = strtotime( $user->user_registered );
				if ( false !== $registered_timestamp ) {
					$data['registered_date'] = gmdate( 'c', $registered_timestamp );
				}
			}
		}

		if ( $fields_requested( 'roles' ) && $can_view_roles ) {
			$data['roles'] = $this->normalize_string_list( $user->roles );
		}

		return $data;
	}
}

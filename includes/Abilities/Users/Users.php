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
 * user email, user login, or user nicename, or querying a paginated collection optionally
 * filtered by roles or published-post authorship. Field-level access is enforced
 * per user by omitting fields the current user cannot view.
 *
 * This class is kept almost identical to the WordPress core class `WP_Users_Abilities`
 * so the two implementations stay in sync. Most differences from the core class are marked with
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
	 * Public/read-context user fields.
	 *
	 * @since x.x.x
	 * @var string[]
	 */
	private array $read_fields = array(
		'id',
		'display_name',
		'description',
		'user_url',
		'link',
		'user_nicename',
	);

	/**
	 * Fields that expose edit-context user data.
	 *
	 * @since x.x.x
	 * @var string[]
	 */
	private array $sensitive_fields = array(
		'user_login',
		'user_email',
		'first_name',
		'last_name',
		'nickname',
		'locale',
		'user_registered',
	);

	/**
	 * Default fields returned when the caller does not request a field subset.
	 *
	 * @since x.x.x
	 * @var string[]
	 */
	private array $default_fields = array(
		'id',
		'display_name',
		'link',
		'user_nicename',
	);

	/**
	 * Cached public post type names.
	 *
	 * @since x.x.x
	 * @var string[]|null
	 */
	private ?array $public_post_types = null;

	/**
	 * Cached supported field list.
	 *
	 * @since x.x.x
	 * @var string[]|null
	 */
	private ?array $fields = null;

	/**
	 * Whether the cached supported field list includes avatars.
	 *
	 * @since x.x.x
	 * @var bool|null
	 */
	private ?bool $fields_include_avatars = null;

	/**
	 * Cached role names.
	 *
	 * @since x.x.x
	 * @var string[]|null
	 */
	private ?array $role_names = null;

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
				'description'         => __( 'Retrieves one or more readable WordPress users. Fetch a single readable user by ID, user email, user login, or user nicename, or query a paginated collection optionally filtered by roles or published-post authorship.', 'ai' ),
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
					'pagination'   => true,
				),
			)
		);
	}

	/**
	 * Permission callback for the `core/read-users` ability.
	 *
	 * Implements defense in depth: this gate decides whether the request may proceed at
	 * all, while the per-user read checks in {@see self::execute_get_users()} are the
	 * authoritative, row-level enforcement.
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
		if ( '' === $lookup_type ) {
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
	 * @return array<string, mixed>|\WP_Error A user object in single-user mode, a map with a `users` list in collection mode, or a WP_Error on failure.
	 */
	public function execute_get_users( $input = array() ) {
		$input  = is_array( $input ) ? $input : array();
		$fields = $this->normalize_fields( $input );

		$lookup_type = $this->get_lookup_type( $input );
		if ( '' !== $lookup_type ) {
			$user = $this->find_user( $input );
			if ( ! $user instanceof WP_User
				|| ! $this->is_user_member_of_site( $user )
				|| ! $this->can_read_user_for_lookup( $user, $lookup_type )
			) {
				return $this->not_found_error();
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

		if ( ! empty( $input['roles'] ) && current_user_can( 'list_users' ) ) {
			$query_args['role__in'] = $this->normalize_string_list( $input['roles'] );
		}

		if ( current_user_can( 'list_users' ) ) {
			$has_published_posts = $this->normalize_has_published_posts( $input );
			if ( null !== $has_published_posts ) {
				$query_args['has_published_posts'] = $has_published_posts;
			}
		} else {
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
			if ( ! $user instanceof WP_User || ! $this->is_user_member_of_site( $user ) || ! $this->can_read_user( $user ) ) {
				continue;
			}

			$users[] = $this->format_user( $user, $fields );
		}

		$total_users = (int) $query->get_total();

		return array(
			'users'       => $users,
			'total'       => $total_users,
			'total_pages' => $per_page > 0 ? (int) ceil( $total_users / $per_page ) : 0,
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
	 * @return string The lookup type, or an empty string for collection mode.
	 */
	private function get_lookup_type( array $input ): string {
		foreach ( array( 'id', 'user_email', 'user_login', 'user_nicename' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				return $key;
			}
		}

		return '';
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

		if ( array_key_exists( 'user_email', $input ) ) {
			if ( ! is_string( $input['user_email'] ) ) {
				return null;
			}

			$user = get_user_by( 'email', sanitize_email( $input['user_email'] ) );
			return $user instanceof WP_User ? $user : null;
		}

		if ( array_key_exists( 'user_login', $input ) ) {
			if ( ! is_string( $input['user_login'] ) ) {
				return null;
			}

			$user = get_user_by( 'login', $input['user_login'] );
			return $user instanceof WP_User ? $user : null;
		}

		if ( array_key_exists( 'user_nicename', $input ) ) {
			if ( ! is_string( $input['user_nicename'] ) ) {
				return null;
			}

			$user = get_user_by( 'slug', sanitize_title( $input['user_nicename'] ) );
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
	 * User email and login are identifier-sensitive lookup modes and do not use the
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

		if ( 'user_email' === $lookup_type || 'user_login' === $lookup_type ) {
			return false;
		}

		return $this->is_public_author( $user );
	}

	/**
	 * Checks whether a user may be included in collection results.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User $user User object.
	 * @return bool Whether the user can be read.
	 */
	private function can_read_user( WP_User $user ): bool {
		return $this->is_current_user( $user )
			|| current_user_can( 'edit_user', $user->ID )
			|| current_user_can( 'list_users' )
			|| $this->is_public_author( $user );
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
	 * Checks whether a user has published posts in public post types.
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

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts -- Public-author checks only consider public post types.
		return count_user_posts( (int) $user->ID, $post_types ) > 0;
	}

	/**
	 * Returns public post types.
	 *
	 * @since x.x.x
	 *
	 * @return string[] Public post type names.
	 */
	private function get_public_post_types(): array {
		if ( null !== $this->public_post_types ) {
			return $this->public_post_types;
		}

		$post_types = array();

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( ! is_string( $post_type ) ) {
				continue;
			}

			$post_types[] = $post_type;
		}

		$this->public_post_types = $post_types;

		return $this->public_post_types;
	}

	/**
	 * Normalizes the requested fields to the supported set, defaulting to a lean field set.
	 *
	 * An empty or absent `fields` value selects common read-context fields. Restricted
	 * fields are still omitted per user when the current user cannot access them.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return string[] List of requested field names.
	 */
	private function normalize_fields( array $input ): array {
		$available_fields = $this->get_fields();

		if ( empty( $input['fields'] ) || ! is_array( $input['fields'] ) ) {
			return $this->get_default_fields();
		}

		$requested_fields = array_filter( $input['fields'], 'is_string' );
		$fields           = array_intersect( $available_fields, $requested_fields );

		return array() === $fields ? $this->get_default_fields() : array_values( $fields );
	}

	/**
	 * Returns the supported field list in output order.
	 *
	 * @since x.x.x
	 *
	 * @return string[] Supported field names.
	 */
	private function get_fields(): array {
		$include_avatars = (bool) get_option( 'show_avatars' );

		if ( null !== $this->fields && $include_avatars === $this->fields_include_avatars ) {
			return $this->fields;
		}

		$fields = $this->read_fields;

		if ( $include_avatars ) {
			$fields[] = 'avatar_urls';
		}

		$this->fields                 = array_merge( $fields, $this->sensitive_fields, array( 'roles' ) );
		$this->fields_include_avatars = $include_avatars;

		return $this->fields;
	}

	/**
	 * Returns the default field list in output order.
	 *
	 * @since x.x.x
	 *
	 * @return string[] Default field names.
	 */
	private function get_default_fields(): array {
		$fields = $this->default_fields;

		if ( get_option( 'show_avatars' ) ) {
			$fields[] = 'avatar_urls';
		}

		return $fields;
	}

	/**
	 * Returns registered role names.
	 *
	 * @since x.x.x
	 *
	 * @return string[] Role names.
	 */
	private function get_role_names(): array {
		if ( null !== $this->role_names ) {
			return $this->role_names;
		}

		$this->role_names = array_keys( wp_roles()->roles );

		return $this->role_names;
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
	 * Normalizes the `has_published_posts` collection input.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return bool|string[]|null Normalized query value, or null when absent/invalid.
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
	 *   - Get a single readable user by `user_email`.
	 *   - Get a single readable user by `user_login`.
	 *   - Get a single readable user by `user_nicename`.
	 *   - Query a collection of readable users.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_users_input_schema(): array {
		$role_names        = $this->get_role_names();
		$public_post_types = $this->get_public_post_types();
		$fields            = array(
			'type'        => 'array',
			'uniqueItems' => true,
			'items'       => array(
				'type' => 'string',
				'enum' => $this->get_fields(),
			),
			'description' => __( 'Limit each returned user to these fields. If omitted, a lean set of common read fields is returned.', 'ai' ),
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
					'required'             => array( 'user_email' ),
					'additionalProperties' => false,
					'properties'           => array(
						'user_email' => array(
							'type'        => 'string',
							'format'      => 'email',
							'description' => __( 'Retrieve a single readable user by email address. Resolving another user by email requires permission to list or edit users.', 'ai' ),
						),
						'fields'     => $fields,
					),
				),
				array(
					'title'                => __( 'Get a single readable user by login', 'ai' ),
					'required'             => array( 'user_login' ),
					'additionalProperties' => false,
					'properties'           => array(
						'user_login' => array(
							'type'        => 'string',
							'description' => __( 'Retrieve a single readable user by login. Resolving another user by login requires permission to list or edit users.', 'ai' ),
						),
						'fields'     => $fields,
					),
				),
				array(
					'title'                => __( 'Get a single readable user by nicename', 'ai' ),
					'required'             => array( 'user_nicename' ),
					'additionalProperties' => false,
					'properties'           => array(
						'user_nicename' => array(
							'type'        => 'string',
							'description' => __( 'Retrieve a single readable user by nicename.', 'ai' ),
						),
						'fields'        => $fields,
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
		$user_properties = array(
			'id'              => array(
				'type'        => 'integer',
				'description' => __( 'The user ID.', 'ai' ),
			),
			'display_name'    => array(
				'type'        => 'string',
				'description' => __( 'The display name for the user.', 'ai' ),
			),
			'description'     => array(
				'type'        => 'string',
				'description' => __( 'Description of the user.', 'ai' ),
			),
			'user_url'        => array(
				'type'        => 'string',
				'description' => __( 'URL of the user.', 'ai' ),
			),
			'link'            => array(
				'type'        => 'string',
				'description' => __( 'Author archive URL for the user.', 'ai' ),
			),
			'user_nicename'   => array(
				'type'        => 'string',
				'description' => __( 'An alphanumeric identifier for the user.', 'ai' ),
			),
			'user_login'      => array(
				'type'        => 'string',
				'description' => __( 'Login name for the user. Present when the current user can view it.', 'ai' ),
			),
			'user_email'      => array(
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
			'user_registered' => array(
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

		if ( get_option( 'show_avatars' ) ) {
			$user_properties['avatar_urls'] = array(
				'type'                 => 'object',
				'description'          => __( 'Avatar URLs for the user at various sizes.', 'ai' ),
				'additionalProperties' => array(
					'type' => 'string',
				),
			);
		}

		$user_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $user_properties,
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
					'description' => __( 'Total number of users matching the query, across all pages, after applying the permission filter to the query. Surfaced over REST as the X-WP-Total header.', 'ai' ),
				),
				'total_pages' => array(
					'type'        => 'integer',
					'description' => __( 'Total number of query result pages available after applying the permission filter to the query. Surfaced over REST as the X-WP-TotalPages header.', 'ai' ),
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
		if ( $fields_requested( 'display_name' ) ) {
			$data['display_name'] = (string) $user->display_name;
		}
		if ( $fields_requested( 'description' ) ) {
			$data['description'] = (string) $user->description;
		}
		if ( $fields_requested( 'user_url' ) ) {
			$data['user_url'] = (string) $user->user_url;
		}
		if ( $fields_requested( 'link' ) ) {
			$data['link'] = (string) get_author_posts_url( $user_id, $user->user_nicename );
		}
		if ( $fields_requested( 'user_nicename' ) ) {
			$data['user_nicename'] = (string) $user->user_nicename;
		}
		if ( $fields_requested( 'avatar_urls' ) && get_option( 'show_avatars' ) ) {
			$data['avatar_urls'] = rest_get_avatar_urls( $user );
		}

		if ( $can_view_sensitive ) {
			if ( $fields_requested( 'user_login' ) ) {
				$data['user_login'] = (string) $user->user_login;
			}
			if ( $fields_requested( 'user_email' ) ) {
				$data['user_email'] = (string) $user->user_email;
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
			if ( $fields_requested( 'user_registered' ) ) {
				$registered_timestamp = strtotime( $user->user_registered );
				if ( false !== $registered_timestamp ) {
					$data['user_registered'] = gmdate( 'c', $registered_timestamp );
				}
			}
		}

		if ( $fields_requested( 'roles' ) && $can_view_roles ) {
			$data['roles'] = $this->normalize_string_list( $user->roles );
		}

		return $data;
	}

	/**
	 * Returns a generic not-found error for missing or inaccessible user lookups.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Error Not found error.
	 */
	private function not_found_error(): WP_Error {
		return new WP_Error(
			'user_not_found',
			__( 'The requested user was not found.', 'ai' ),
			array( 'status' => 404 )
		);
	}
}

<?php
/**
 * The `core/users-query` and `core/user-*` WordPress Abilities.
 *
 * @package WordPress\AI
 *
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Users;

use WP_Error;
use WP_User;
use WP_User_Query;
use stdClass;

use function WordPress\AI\register_deprecated_ability_alias;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Users
 *
 * Registers the read-only `core/users-query` ability, which retrieves one or more
 * readable WordPress users. Supports fetching a single readable user by ID,
 * email, username, or slug, or querying a paginated collection optionally
 * filtered by roles, published-post authorship, or included IDs. Field-level access is enforced
 * per user by omitting fields the current user cannot view.
 *
 * Unlike the other core abilities, which are self-contained closures registered
 * directly in wp_register_core_abilities(), the users ability lives in a dedicated
 * class because its callbacks and schemas share helpers: the permission and execute
 * callbacks resolve and authorize the requested user through the same code, and the
 * input schema, output schema, and field normalization are built from the same
 * field definitions. Future write-oriented user abilities can reuse them as well.
 *
 * Also registers `core/user-create`, `core/user-update`, and `core/user-delete`, which
 * write users and return them through the same field projection, in the edit context.
 *
 * This class is kept almost identical to the proposed WordPress core implementation
 * so the two implementations stay in sync. Most differences from the core version are marked with
 * `// Plugin:` comments. Additionally, all user-facing strings use the 'ai' text domain.
 * The write abilities and their helpers, and the `$edit_context` parameter format_user()
 * takes for them, are not part of the core class yet, so they carry no markers.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 1.2.0
 */
final class Users {

	/**
	 * The ability category used for user abilities.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const CATEGORY = 'user';

	/**
	 * Default number of users returned per page in collection mode.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private const DEFAULT_PER_PAGE = 10;

	/**
	 * Maximum number of users returned per page in collection mode.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Lookup type returned for collection requests.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const LOOKUP_COLLECTION = 'collection';

	/**
	 * Default fields returned when the caller does not request a field subset.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	private const DEFAULT_FIELDS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
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
	 * @since 1.2.0
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers all user abilities.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since 1.2.0
	 */
	public function register(): void {
		$this->register_users_query();
		$this->register_user_write_abilities();
	}

	/**
	 * Registers the read-only `core/users-query` ability.
	 *
	 * Also registers `core/read-users` as a deprecated alias.
	 *
	 * @since 1.2.0
	 * @since 1.4.0 Renamed from `core/read-users`.
	 */
	private function register_users_query(): void {
		// Plugin: unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/users-query' ) ) {
			wp_unregister_ability( 'core/users-query' );
		}

		wp_register_ability(
			'core/users-query',
			array(
				'label'               => __( 'Users Query', 'ai' ),
				'description'         => __( 'Retrieves one or more readable WordPress users. Fetch a single readable user by ID, email, username, or slug, or query a paginated collection optionally filtered by roles, published-post authorship, or included IDs.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_users_query_input_schema(),
				'output_schema'       => $this->get_users_query_output_schema(),
				'execute_callback'    => array( $this, 'execute_users_query' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'public'       => true,
					// Plugin: core sets only `public`, which WordPress 7.0 ignores, so also set `show_in_rest`.
					'show_in_rest' => true,
				),
			)
		);

		// @todo Remove the alias after a few releases.
		register_deprecated_ability_alias( 'core/read-users', 'core/users-query', '1.4.0' );
	}

	/**
	 * Registers the `core/user-create`, `core/user-update`, and `core/user-delete` abilities.
	 *
	 * @since x.x.x
	 */
	private function register_user_write_abilities(): void {
		$abilities = array(
			'core/user-create' => array(
				'label'               => __( 'User Create', 'ai' ),
				'description'         => __( 'Creates a user. Requires a username, an email address, and a password, and accepts a display name, first and last name, URL, description, locale, nickname, slug, and roles. Returns the created user; use `fields` to choose which user fields are returned. Requires an authenticated user who can create users.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_user_create_input_schema(),
				'output_schema'       => $this->get_user_output_schema(),
				'execute_callback'    => array( $this, 'execute_user_create' ),
				'permission_callback' => array( $this, 'check_create_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						// Every call creates a new user.
						'idempotent'  => false,
						'open_world'  => false,
					),
					'show_in_rest' => true,
				),
			),
			'core/user-update' => array(
				'label'               => __( 'User Update', 'ai' ),
				'description'         => __( 'Updates a user by ID. Accepts a display name, first and last name, email address, URL, description, locale, nickname, slug, roles, and password; the username cannot be changed. Returns the updated user; use `fields` to choose which user fields are returned. Requires an authenticated user who can edit the user, or who can promote the user when only the roles are given.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_user_update_input_schema(),
				'output_schema'       => $this->get_user_output_schema(),
				'execute_callback'    => array( $this, 'execute_user_update' ),
				'permission_callback' => array( $this, 'check_update_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						// Overwritten values are not kept.
						'destructive' => true,
						/*
						 * Destructive idempotent abilities are served over DELETE, which would put
						 * the password in the query string.
						 */
						'idempotent'  => false,
						'open_world'  => false,
					),
					'show_in_rest' => true,
				),
			),
			'core/user-delete' => array(
				'label'               => __( 'User Delete', 'ai' ),
				'description'         => __( 'Permanently deletes a user by ID. Users cannot be trashed, so `force` must be true, and `reassign` takes the ID of the user who receives the deleted user\'s posts and links, or false to delete them. Returns the deleted user under `previous`; use `fields` to choose which user fields are returned. Not supported on multisite. Requires an authenticated user who can delete the user.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_user_delete_input_schema(),
				'output_schema'       => $this->get_user_delete_output_schema(),
				'execute_callback'    => array( $this, 'execute_user_delete' ),
				'permission_callback' => array( $this, 'check_delete_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						/*
						 * Repeating a deletion has no further effect; the Abilities API serves
						 * destructive idempotent abilities over the DELETE method.
						 */
						'idempotent'  => true,
						'open_world'  => false,
					),
					'show_in_rest' => true,
				),
			),
		);

		foreach ( $abilities as $name => $args ) {
			// Unregister any core-provided copy first so the plugin's version wins.
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}

			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Permission callback for the `core/users-query` ability.
	 *
	 * Performs request-level checks. Single-user requests are checked against
	 * the target user, while collection requests rely on query arguments in
	 * {@see self::execute_users_query()} for row-level access.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_permission( $input = array() ): bool {
		$input = $this->to_input_array( $input );

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

		return $this->resolve_readable_user( $input, $lookup_type ) instanceof WP_User;
	}

	/**
	 * Checks permission for the `core/user-create` ability.
	 *
	 * The rest of the input is checked during execution.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_create_permission(): bool {
		return is_user_logged_in() && current_user_can( 'create_users' );
	}

	/**
	 * Checks permission for the `core/user-update` ability.
	 *
	 * The current user must be able to edit the user, or to promote the user when only the
	 * roles are given. A roles change still needs the promote capability, which is checked
	 * during execution so the caller learns that the roles were refused.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_update_permission( $input = array() ): bool {
		$input = $this->to_input_array( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user = $this->get_user( $input['id'] ?? 0 );
		if ( is_wp_error( $user ) ) {
			return false;
		}

		// The roles as the update reads them, where a string such as ',' is an empty list.
		if ( ! empty( rest_sanitize_array( $input['roles'] ?? array() ) ) && current_user_can( 'promote_user', $user->ID ) ) {
			$request_params = array_keys( $input );
			sort( $request_params );
			/*
			 * If only 'id' and 'roles' are specified (we are only trying to
			 * edit roles), then only the 'promote_user' cap is required.
			 */
			if ( array( 'id', 'roles' ) === $request_params ) {
				return true;
			}
		}

		return current_user_can( 'edit_user', $user->ID );
	}

	/**
	 * Checks permission for the `core/user-delete` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_delete_permission( $input = array() ): bool {
		$input = $this->to_input_array( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user = $this->get_user( $input['id'] ?? 0 );
		if ( is_wp_error( $user ) ) {
			return false;
		}

		return current_user_can( 'delete_user', $user->ID );
	}

	/**
	 * Executes the `core/users-query` ability.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\stdClass|\WP_Error User data, paginated collection data, or a WP_Error on failure.
	 */
	public function execute_users_query( $input = array() ) {
		$input  = $this->to_input_array( $input );
		$fields = $this->normalize_fields( $input );

		$lookup_type = $this->get_lookup_type( $input );
		if ( self::LOOKUP_COLLECTION !== $lookup_type ) {
			$user = $this->resolve_readable_user( $input, $lookup_type );
			if ( ! $user instanceof WP_User ) {
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
			/*
			 * The include order is not applied as `orderby`. Keeping the default
			 * ordering lets WP_User_Query share cached results with other queries.
			 */
			$query_args['include'] = $include;
		}

		if ( ! empty( $input['roles'] ) && current_user_can( 'list_users' ) ) {
			$query_args['role__in'] = $this->normalize_string_list( $input['roles'] );
		}

		$has_published_posts = $this->normalize_has_published_posts( $input );

		/*
		 * Callers who cannot list users only see public authors in a collection,
		 * matching core, so the filter is always applied for them. This intentionally
		 * excludes the caller's own account when they have no published posts. Self is
		 * read through a single-user lookup (like the REST `/users/me` endpoint) instead.
		 */
		$requires_published_posts = ! current_user_can( 'list_users' );

		if ( null !== $has_published_posts || $requires_published_posts ) {
			/*
			 * The post types are always resolved here rather than passed as `true`.
			 * WP_User_Query reads `true` as `get_post_types( array( 'public' => true ) )`,
			 * which is not the same as the publicly viewable set the rest of the ability
			 * uses. Resolving here also picks up post types registered or unregistered
			 * after the input schema was built.
			 */
			$public_post_types = $this->get_public_post_types();

			$has_published_posts = is_array( $has_published_posts )
				? array_values( array_intersect( $public_post_types, $has_published_posts ) )
				: $public_post_types;

			if ( array() === $has_published_posts ) {
				return array(
					'users'       => array(),
					'total'       => 0,
					'total_pages' => 0,
				);
			}

			$query_args['has_published_posts'] = $has_published_posts;
		}

		$query = new WP_User_Query( $query_args );

		$users = array();
		foreach ( $query->get_results() as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$users[] = $this->format_user( $user, $fields );
		}

		/*
		 * `users` and `total`/`total_pages` all derive from the same WP_User_Query,
		 * so the row count and the reported totals stay in agreement. Collections
		 * are not post-filtered by site membership, matching the REST users
		 * controller, whose collection endpoint applies no per-row membership check
		 * and reports `get_total()` directly. On multisite the collection is still
		 * scoped to the current site: WP_User_Query adds a capabilities meta clause
		 * restricting results to members of the queried blog whenever `blog_id`
		 * (defaulted to the current blog) is set, even for a bare query with no
		 * roles/has_published_posts. Callers who cannot list users are additionally
		 * narrowed by the forced `has_published_posts`, which joins the current
		 * blog's posts table. Single-user lookups remain site-scoped via
		 * {@see self::is_user_member_of_site()}, matching the controller's
		 * single-user membership check.
		 */
		$total_users = (int) $query->get_total();

		return array(
			'users'       => $users,
			'total'       => $total_users,
			'total_pages' => (int) ceil( $total_users / $per_page ),
		);
	}

	/**
	 * Casts raw ability input to an array.
	 *
	 * Schema validation accepts object input (`rest_is_object()` allows a
	 * `stdClass`, and the input schema's own `default` is one), so it must be
	 * treated as equivalent to its array form rather than discarded. Any other
	 * non-array input is replaced with an empty array.
	 *
	 * The Abilities API validates input against the schema but does not coerce
	 * it, and REST `GET` requests (the only method for read-only abilities)
	 * deliver every scalar as a string. Each normalizer below therefore accepts
	 * the string forms validation accepted (`'true'` for `true`, CSV strings
	 * for arrays, numeric strings for integers).
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $input The raw ability input.
	 * @return array<mixed> The input as an array.
	 */
	private function to_input_array( $input ): array {
		if ( $input instanceof stdClass ) {
			$input = (array) $input;
		}

		return is_array( $input ) ? $input : array();
	}

	/**
	 * Casts a raw input value to a non-negative integer.
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * Resolves the target of a single-user lookup when the current user may read it.
	 *
	 * Shared by the permission and execute callbacks so the single-user
	 * authorization decision has exactly one implementation.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $input       The ability input.
	 * @param string       $lookup_type The single-user lookup type.
	 * @return \WP_User|null The readable user, or null when not found or not readable.
	 */
	private function resolve_readable_user( array $input, string $lookup_type ): ?WP_User {
		$user = $this->find_user( $input );
		if ( ! $user instanceof WP_User || ! $this->is_user_member_of_site( $user ) ) {
			return null;
		}

		return $this->can_read_user_for_lookup( $user, $lookup_type ) ? $user : null;
	}

	/**
	 * Finds a user by one of the supported unique input identifiers.
	 *
	 * @since 1.2.0
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

			$user = get_user_by( 'login', sanitize_user( $input['username'] ) );
			return $user instanceof WP_User ? $user : null;
		}

		if ( array_key_exists( 'slug', $input ) ) {
			if ( ! is_string( $input['slug'] ) ) {
				return null;
			}

			/*
			 * Query the raw nicename, matching the REST users controller. Applying
			 * sanitize_title() here would miss users whose stored user_nicename is
			 * not a sanitize_title() fixed point (e.g. set via the pre_user_nicename
			 * filter or an import).
			 */
			$user = get_user_by( 'slug', $input['slug'] );
			return $user instanceof WP_User ? $user : null;
		}

		return null;
	}

	/**
	 * Checks whether a user belongs to the current site.
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
	 *
	 * @param \WP_User $user User object.
	 * @return bool Whether the current user is the target user.
	 */
	private function is_current_user( WP_User $user ): bool {
		return get_current_user_id() === (int) $user->ID;
	}

	/**
	 * Checks whether the current user can see a post by this user in a publicly
	 * viewable post type.
	 *
	 * Published posts always count. Private posts also count for a caller who holds
	 * `read_private_posts` for the post type, because `count_user_posts()` defaults to
	 * `$public_only = false`. This matches how the REST users controller resolves a
	 * single user. Collection mode is filtered by `WP_User_Query`'s
	 * `has_published_posts`, which matches published posts only, so the two modes
	 * disagree about an author whose posts are all private.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_User $user User object.
	 * @return bool Whether the user is visible as an author to the current user.
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
	 * @since 1.2.0
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
	 * Otherwise the requested fields are returned; REST `GET` requests may
	 * deliver the list as a CSV string. The input schema has already validated
	 * the names against the supported set before the ability executes.
	 *
	 * The `id` field is always included, matching the REST users controller
	 * where `id` is present in every context. This also guarantees the result
	 * is never empty, so it always serializes as a JSON object.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $input The ability input.
	 * @return string[] List of requested field names.
	 */
	private function normalize_fields( array $input ): array {
		$fields = isset( $input['fields'] ) ? $this->normalize_string_list( $input['fields'] ) : array();
		if ( array() === $fields ) {
			$fields = $this->get_default_fields();
		}

		if ( ! in_array( 'id', $fields, true ) ) {
			array_unshift( $fields, 'id' );
		}

		return $fields;
	}

	/**
	 * Returns the user field definitions, keyed by field name in output order.
	 *
	 * This is the single source of truth for the ability's user fields: the output
	 * schema uses the definitions directly, while the input schema and field
	 * normalization use the keys. The field set is deliberately unconditional:
	 * the registered schemas are a registration-time snapshot, so conditional
	 * availability (such as `avatar_urls` honoring the `show_avatars` option) is
	 * enforced per call in {@see self::format_user()} instead of here, where the
	 * option could change between registration and use. Resolved on every call
	 * rather than cached: it is the single source of truth for the field set and
	 * inexpensive to rebuild.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, mixed> User field definitions.
	 */
	private function get_user_properties(): array {
		return array(
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
				/*
				 * Unlike the REST users controller, `url` declares no `uri` format. It is
				 * empty for users without a website, and clients that check formats,
				 * such as the abilities JS client when it re-validates the output, would
				 * reject the empty string and fail the whole call.
				 */
				'type'        => 'string',
				'description' => __( 'URL of the user.', 'ai' ),
			),
			'link'            => array(
				'type'        => 'string',
				'format'      => 'uri',
				'description' => __( 'Author archive URL for the user.', 'ai' ),
			),
			'slug'            => array(
				'type'        => 'string',
				'description' => __( 'An alphanumeric identifier for the user.', 'ai' ),
			),
			'avatar_urls'     => array(
				'type'                 => 'object',
				'description'          => __( 'Avatar URLs for the user, keyed by image size in pixels. A size is null when no avatar URL can be resolved for it. Present when the show_avatars option is enabled.', 'ai' ),
				'additionalProperties' => array(
					'type'   => array( 'string', 'null' ),
					'format' => 'uri',
				),
			),
			'username'        => array(
				'type'        => 'string',
				'description' => __( 'Login name for the user. Present when the current user can view it.', 'ai' ),
			),
			'email'           => array(
				'type'        => array( 'string', 'null' ),
				'format'      => 'email',
				'description' => __( 'The email address for the user. Null when the user has no stored address, or when the stored address is not a valid email. Present when the current user can view it.', 'ai' ),
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
				/*
				 * Output roles are not pinned to an enum. The schema is a
				 * registration-time snapshot, but a role can be registered after
				 * registration and still be held by a returned user; a snapshot enum
				 * would reject that legitimate value during output validation and
				 * fail the whole call. This also matches the REST users controller,
				 * whose `roles` output items are plain strings.
				 */
				'items'       => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Returns the default field list in output order.
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
	 *
	 * @return string[] Role names.
	 */
	private function get_role_names(): array {
		return array_keys( wp_roles()->roles );
	}

	/**
	 * Normalizes the requested per-page value to the supported bounds.
	 *
	 * @since 1.2.0
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
	 * Accepts arrays and CSV strings, since REST `GET` requests deliver list
	 * input as strings that schema validation coerces only for the check.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value Raw value.
	 * @return string[] Normalized strings.
	 */
	private function normalize_string_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = wp_parse_list( $value );
		}

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
	 * Accepts arrays and CSV strings via {@see wp_parse_id_list()}, which also
	 * deduplicates IDs that only differ as strings (e.g. `'1'` and `'01'`).
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $input The ability input.
	 * @return int[] User IDs.
	 */
	private function normalize_include( array $input ): array {
		if ( empty( $input['include'] ) ) {
			return array();
		}

		$include = $input['include'];
		if ( is_scalar( $include ) ) {
			$include = (string) $include;
		} elseif ( ! is_array( $include ) ) {
			return array();
		}

		return array_values( array_filter( wp_parse_id_list( $include ) ) );
	}

	/**
	 * Normalizes the `has_published_posts` collection input.
	 *
	 * Accepts the string and integer forms of `true` that schema validation
	 * accepts for REST `GET` input, alongside the native boolean.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $input The ability input.
	 * @return bool|string[]|null Normalized query value, or null when omitted.
	 */
	private function normalize_has_published_posts( array $input ) {
		if ( ! array_key_exists( 'has_published_posts', $input ) ) {
			return null;
		}

		$value = $input['has_published_posts'];

		if ( true === $value || 1 === $value
			|| ( is_string( $value ) && in_array( strtolower( $value ), array( 'true', '1' ), true ) )
		) {
			return true;
		}

		$post_types = $this->normalize_string_list( $value );

		return array() === $post_types ? null : $post_types;
	}

	/**
	 * Builds the input schema for the `core/users-query` ability.
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
	 * @since 1.2.0
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_users_query_input_schema(): array {
		/*
		 * Input enums intentionally reflect roles and post types available at
		 * ability registration time. This makes the schema a stable contract that
		 * developers can filter when registering the ability.
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
							'description' => __( 'Limit results to users with published posts. Use true for all publicly viewable post types, or provide post type names.', 'ai' ),
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
	 * Builds the output schema for the `core/users-query` ability.
	 *
	 * No user field is marked required because the `fields` input lets the caller
	 * request any subset, and restricted fields are omitted when unavailable.
	 * Single-user mode returns the user object directly, while collection mode returns
	 * a paginated wrapper.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_users_query_output_schema(): array {
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
	 * Only the requested fields the current user can see are included, except
	 * `id`, which {@see self::normalize_fields()} always requests.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_User $user         The user object.
	 * @param string[] $fields       The requested field names.
	 * @param bool     $edit_context Optional. Whether to return the edit-context fields even when the
	 *                               current user cannot edit the user, as a write is answered. Default false.
	 * @return array<string, mixed>|\stdClass The formatted user data. An empty
	 *                                        result is returned as an object so
	 *                                        it serializes as `{}` rather than
	 *                                        `[]`; unreachable while `id` is
	 *                                        ungated, since REST post-processing
	 *                                        (`_fields`) cannot handle a
	 *                                        top-level object response.
	 */
	private function format_user( WP_User $user, array $fields, bool $edit_context = false ) {
		$fields_requested = static function ( string $field ) use ( $fields ): bool {
			return in_array( $field, $fields, true );
		};

		$user_id            = (int) $user->ID;
		$can_view_sensitive = $this->is_current_user( $user ) || current_user_can( 'edit_user', $user_id );

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
		/*
		 * The schemas always declare avatar_urls; availability is enforced here,
		 * since the option can change after the schemas are registered.
		 */
		if ( $fields_requested( 'avatar_urls' ) && get_option( 'show_avatars' ) ) {
			$data['avatar_urls'] = array_map(
				static function ( $url ) {
					return is_string( $url ) ? $url : null;
				},
				rest_get_avatar_urls( $user )
			);
		}

		if ( $can_view_sensitive || $edit_context ) {
			if ( $fields_requested( 'username' ) ) {
				$data['username'] = (string) $user->user_login;
			}
			if ( $fields_requested( 'email' ) ) {
				$data['email'] = is_email( $user->user_email ) ? (string) $user->user_email : null;
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

		/*
		 * Roles reveal a user's privilege level, so they are gated like the other
		 * sensitive fields: visible only for the current user or a user the caller
		 * can edit. `list_users` alone (which grants no edit rights) is not enough,
		 * matching the REST users controller, where `roles` is an edit-context
		 * field and rows the caller cannot edit are dropped from collections.
		 * A write answers in the edit context, where `list_users` is enough.
		 */
		if ( $fields_requested( 'roles' ) && ( $can_view_sensitive || ( $edit_context && current_user_can( 'list_users' ) ) ) ) {
			$data['roles'] = $this->normalize_string_list( $user->roles );
		}

		// An empty result must serialize as a JSON object, not an empty array.
		return array() === $data ? (object) $data : $data;
	}

	/**
	 * Executes the `core/user-create` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\stdClass|\WP_Error The created user, or a WP_Error.
	 */
	public function execute_user_create( $input = array() ) {
		$input = $this->sanitize_params( $this->to_input_array( $input ) );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		if ( ! empty( $input['roles'] ) ) {
			$check_permission = $this->check_role_update( null, $input['roles'] );

			if ( is_wp_error( $check_permission ) ) {
				return $check_permission;
			}
		}

		$user = $this->prepare_item_for_database( $input );

		if ( is_multisite() ) {
			$ret = wpmu_validate_user_signup( $user->user_login, $user->user_email );

			if ( is_wp_error( $ret['errors'] ) && $ret['errors']->has_errors() ) {
				$error = new WP_Error(
					'users_invalid_param',
					__( 'Invalid user parameter(s).', 'ai' ),
					array( 'status' => 400 )
				);

				foreach ( $ret['errors']->errors as $code => $messages ) {
					foreach ( $messages as $message ) {
						$error->add( $code, $message );
					}

					$error_data = $error->get_error_data( $code );

					if ( ! $error_data ) {
						continue;
					}

					$error->add_data( $error_data, $code );
				}
				return $error;
			}
		}

		if ( is_multisite() ) {
			$user_id = wpmu_create_user( $user->user_login, $user->user_pass, $user->user_email );

			if ( ! $user_id ) {
				return new WP_Error(
					'users_user_create',
					__( 'Error creating new user.', 'ai' ),
					array( 'status' => 500 )
				);
			}

			$user->ID = $user_id;
			$user_id  = wp_update_user( wp_slash( (array) $user ) );

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}

			$result = add_user_to_blog( get_current_blog_id(), $user_id, '' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} else {
			$user_id = wp_insert_user( wp_slash( (array) $user ) );

			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}

		$user = new WP_User( $user_id );

		if ( ! empty( $input['roles'] ) ) {
			array_map( array( $user, 'add_role' ), $input['roles'] );
		}

		return $this->format_user( $user, $this->normalize_fields( $input ), true );
	}

	/**
	 * Executes the `core/user-update` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\stdClass|\WP_Error The updated user, or a WP_Error.
	 */
	public function execute_user_update( $input = array() ) {
		$input = $this->sanitize_params( $this->to_input_array( $input ) );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$user = $this->get_user( $input['id'] ?? 0 );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! empty( $input['roles'] ) && ! current_user_can( 'promote_user', $user->ID ) ) {
			return new WP_Error(
				'users_cannot_edit_roles',
				__( 'Sorry, you are not allowed to edit roles of this user.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$id = $user->ID;

		$owner_id = false;
		if ( is_string( $input['email'] ?? null ) ) {
			$owner_id = email_exists( $input['email'] );
		}

		if ( $owner_id && $owner_id !== $id ) {
			return new WP_Error(
				'users_user_invalid_email',
				__( 'Invalid email address.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( ! empty( $input['slug'] ) && $input['slug'] !== $user->user_nicename && get_user_by( 'slug', $input['slug'] ) ) {
			return new WP_Error(
				'users_user_invalid_slug',
				__( 'Invalid slug.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( ! empty( $input['roles'] ) ) {
			$check_permission = $this->check_role_update( $id, $input['roles'] );

			if ( is_wp_error( $check_permission ) ) {
				return $check_permission;
			}
		}

		$user = $this->prepare_item_for_database( $input );

		// Ensure we're operating on the same user we already checked.
		$user->ID = $id;

		$user_id = wp_update_user( wp_slash( (array) $user ) );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = new WP_User( $user_id );

		if ( ! empty( $input['roles'] ) ) {
			array_map( array( $user, 'add_role' ), $input['roles'] );
		}

		return $this->format_user( $user, $this->normalize_fields( $input ), true );
	}

	/**
	 * Executes the `core/user-delete` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error A `deleted`/`previous` pair, or a WP_Error.
	 */
	public function execute_user_delete( $input = array() ) {
		$input = $this->sanitize_params( $this->to_input_array( $input ) );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		// We don't support delete requests in multisite.
		if ( is_multisite() ) {
			return new WP_Error(
				'users_cannot_delete',
				__( 'The user cannot be deleted.', 'ai' ),
				array( 'status' => 501 )
			);
		}

		$user = $this->get_user( $input['id'] ?? 0 );

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$id       = $user->ID;
		$reassign = false === $input['reassign'] ? null : absint( $input['reassign'] );
		$force    = isset( $input['force'] ) && rest_is_boolean( $input['force'] ) && rest_sanitize_boolean( (string) $input['force'] );

		// We don't support trashing for users.
		if ( ! $force ) {
			return new WP_Error(
				'users_trash_not_supported',
				/* translators: %s: force=true */
				sprintf( __( "Users do not support trashing. Set '%s' to delete.", 'ai' ), 'force=true' ),
				array( 'status' => 501 )
			);
		}

		if ( ! empty( $reassign ) ) {
			if ( $reassign === $id || ! get_userdata( $reassign ) ) {
				return new WP_Error(
					'users_user_invalid_reassign',
					__( 'Invalid user ID for reassignment.', 'ai' ),
					array( 'status' => 400 )
				);
			}
		}

		$previous = $this->format_user( $user, $this->normalize_fields( $input ), true );

		// Include user admin functions to get access to wp_delete_user().
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$result = wp_delete_user( $id, $reassign );

		if ( ! $result ) {
			return new WP_Error(
				'users_cannot_delete',
				__( 'The user cannot be deleted.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'deleted'  => true,
			'previous' => $previous,
		);
	}

	/**
	 * Checks for a valid value for the reassign parameter when deleting users.
	 *
	 * The value can be an integer, 'false', false, or ''.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The value passed to the reassign parameter.
	 * @return mixed The value, false to delete the user's posts and links, or a WP_Error.
	 */
	private function check_reassign( $value ) {
		if ( is_numeric( $value ) ) {
			return $value;
		}

		if ( empty( $value ) || 'false' === $value ) {
			return false;
		}

		return new WP_Error(
			'users_invalid_param',
			__( 'Invalid user parameter(s).', 'ai' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Gets the user, if the ID is valid.
	 *
	 * A user who does not exist, or does not belong to the current site, gets the denial
	 * `core/users-query` gives for a user it cannot read.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $id Supplied ID.
	 * @return \WP_User|\WP_Error The user if the ID is valid, WP_Error otherwise.
	 */
	private function get_user( $id ) {
		$error = new WP_Error(
			'ability_invalid_permissions',
			__( 'The requested user cannot be read.', 'ai' )
		);

		if ( (int) $id <= 0 ) {
			return $error;
		}

		$user = get_userdata( (int) $id );
		if ( empty( $user ) || ! $user->exists() ) {
			return $error;
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user->ID ) ) {
			return $error;
		}

		return $user;
	}

	/**
	 * Prepares a single user for creation or update.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The sanitized ability input.
	 * @return \stdClass User object.
	 */
	private function prepare_item_for_database( array $input ): stdClass {
		$prepared_user = new stdClass();

		// Required arguments.
		if ( isset( $input['email'] ) ) {
			$prepared_user->user_email = $input['email'];
		}

		if ( isset( $input['username'] ) ) {
			$prepared_user->user_login = $input['username'];
		}

		if ( isset( $input['password'] ) ) {
			$prepared_user->user_pass = $input['password'];
		}

		// Optional arguments.
		if ( isset( $input['id'] ) ) {
			$prepared_user->ID = absint( $input['id'] );
		}

		if ( isset( $input['name'] ) ) {
			$prepared_user->display_name = $input['name'];
		}

		if ( isset( $input['first_name'] ) ) {
			$prepared_user->first_name = $input['first_name'];
		}

		if ( isset( $input['last_name'] ) ) {
			$prepared_user->last_name = $input['last_name'];
		}

		if ( isset( $input['nickname'] ) ) {
			$prepared_user->nickname = $input['nickname'];
		}

		if ( isset( $input['slug'] ) ) {
			$prepared_user->user_nicename = $input['slug'];
		}

		if ( isset( $input['description'] ) ) {
			$prepared_user->description = $input['description'];
		}

		if ( isset( $input['url'] ) ) {
			$prepared_user->user_url = $input['url'];
		}

		if ( isset( $input['locale'] ) ) {
			$prepared_user->locale = $input['locale'];
		}

		// Setting roles will be handled outside of this function.
		if ( isset( $input['roles'] ) ) {
			$prepared_user->role = false;
		}

		return $prepared_user;
	}

	/**
	 * Determines if the current user is allowed to make the desired roles change.
	 *
	 * @since x.x.x
	 *
	 * @global \WP_Roles $wp_roles WordPress role management object.
	 *
	 * @param int|null $user_id User ID, or null when creating a user.
	 * @param string[] $roles   New user roles.
	 * @return true|\WP_Error True if the current user is allowed to make the role change,
	 *                        otherwise a WP_Error object.
	 */
	private function check_role_update( ?int $user_id, array $roles ) {
		global $wp_roles;

		foreach ( $roles as $role ) {
			if ( ! isset( $wp_roles->role_objects[ $role ] ) ) {
				return new WP_Error(
					'users_user_invalid_role',
					/* translators: %s: Role key. */
					sprintf( __( 'The role %s does not exist.', 'ai' ), $role ),
					array( 'status' => 400 )
				);
			}

			$potential_role = $wp_roles->role_objects[ $role ];

			/*
			 * Don't let anyone with 'edit_users' (admins) edit their own role to something without it.
			 * Multisite super admins can freely edit their blog roles -- they possess all caps.
			 */
			if ( ! ( is_multisite()
				&& current_user_can( 'manage_sites' ) )
				&& get_current_user_id() === $user_id
				&& ! $potential_role->has_cap( 'edit_users' )
			) {
				return new WP_Error(
					'users_user_invalid_role',
					__( 'Sorry, you are not allowed to give users that role.', 'ai' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

			// Include user admin functions to get access to get_editable_roles().
			require_once ABSPATH . 'wp-admin/includes/user.php';

			// The new role must be editable by the logged-in user.
			$editable_roles = get_editable_roles();

			if ( empty( $editable_roles[ $role ] ) ) {
				return new WP_Error(
					'users_user_invalid_role',
					__( 'Sorry, you are not allowed to give users that role.', 'ai' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Checks a username.
	 *
	 * Performs a couple of checks like edit_user() in wp-admin/includes/user.php.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The username submitted in the input.
	 * @return string|\WP_Error The sanitized username, if valid, otherwise an error.
	 */
	private function check_username( $value ) {
		$username = (string) $value;

		if ( ! validate_username( $username ) ) {
			return new WP_Error(
				'users_user_invalid_username',
				__( 'This username is invalid because it uses illegal characters. Please enter a valid username.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		/** This filter is documented in wp-includes/user.php */
		$illegal_logins = (array) apply_filters( 'illegal_user_logins', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		if ( in_array( strtolower( $username ), array_map( 'strtolower', $illegal_logins ), true ) ) {
			return new WP_Error(
				'users_user_invalid_username',
				__( 'Sorry, that username is not allowed.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		return $username;
	}

	/**
	 * Checks a user password.
	 *
	 * Performs a couple of checks like edit_user() in wp-admin/includes/user.php.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The password submitted in the input.
	 * @return string|\WP_Error The sanitized password, if valid, otherwise an error.
	 */
	private function check_user_password(
		// phpcs:ignore PHPCompatibility.Attributes.NewAttributes.PHPNativeAttributeFound -- PHP 7.4 reads the attribute as a comment.
		#[\SensitiveParameter]
		$value
	) {
		$password = (string) $value;

		if ( empty( $password ) ) {
			return new WP_Error(
				'users_user_invalid_password',
				__( 'Passwords cannot be empty.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		if ( str_contains( $password, '\\' ) ) {
			return new WP_Error(
				'users_user_invalid_password',
				sprintf(
					/* translators: %s: The '\' character. */
					__( 'Passwords cannot contain the "%s" character.', 'ai' ),
					'\\'
				),
				array( 'status' => 400 )
			);
		}

		return $password;
	}

	/**
	 * Sanitizes the input of a write ability.
	 *
	 * Every value is checked before anything is written, and every value that fails is
	 * reported with its reason.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return array<mixed>|\WP_Error The sanitized input, or a WP_Error naming the invalid parameters.
	 */
	private function sanitize_params( array $input ) {
		$sanitize_callbacks = array(
			'username'   => array( $this, 'check_username' ),
			'name'       => 'sanitize_text_field',
			'first_name' => 'sanitize_text_field',
			'last_name'  => 'sanitize_text_field',
			'email'      => 'sanitize_text_field',
			'url'        => 'sanitize_url',
			'nickname'   => 'sanitize_text_field',
			'slug'       => 'sanitize_title',
			'roles'      => 'rest_sanitize_array',
			'password'   => array( $this, 'check_user_password' ),
			'reassign'   => array( $this, 'check_reassign' ),
		);

		$invalid_params  = array();
		$invalid_details = array();

		foreach ( $input as $key => $value ) {
			if ( ! isset( $sanitize_callbacks[ $key ] ) ) {
				continue;
			}

			$sanitized_value = call_user_func( $sanitize_callbacks[ $key ], $value );

			if ( is_wp_error( $sanitized_value ) ) {
				$invalid_params[ $key ]  = implode( ' ', $sanitized_value->get_error_messages() );
				$invalid_details[ $key ] = rest_convert_error_to_response( $sanitized_value )->get_data();
			} else {
				$input[ $key ] = $sanitized_value;
			}
		}

		if ( $invalid_params ) {
			return new WP_Error(
				'users_invalid_param',
				/* translators: %s: List of invalid parameters. */
				sprintf( __( 'Invalid parameter(s): %s', 'ai' ), implode( ', ', array_keys( $invalid_params ) ) ),
				array(
					'status'  => 400,
					'params'  => $invalid_params,
					'details' => $invalid_details,
				)
			);
		}

		return $input;
	}

	/**
	 * Returns the input properties shared by the create and update abilities, keyed by field name.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> Write property definitions.
	 */
	private function get_user_write_properties(): array {
		return array(
			'name'        => array(
				'type'        => 'string',
				'description' => __( 'Display name for the user.', 'ai' ),
			),
			'first_name'  => array(
				'type'        => 'string',
				'description' => __( 'First name for the user.', 'ai' ),
			),
			'last_name'   => array(
				'type'        => 'string',
				'description' => __( 'Last name for the user.', 'ai' ),
			),
			'email'       => array(
				'type'        => 'string',
				'format'      => 'email',
				'description' => __( 'The email address for the user.', 'ai' ),
			),
			'url'         => array(
				'type'        => 'string',
				'description' => __( 'URL of the user.', 'ai' ),
			),
			'description' => array(
				'type'        => 'string',
				'description' => __( 'Description of the user.', 'ai' ),
			),
			'locale'      => array(
				'type'        => 'string',
				'enum'        => array_merge( array( '', 'en_US' ), get_available_languages() ),
				'description' => __( 'Locale for the user. An empty string uses the site locale.', 'ai' ),
			),
			'nickname'    => array(
				'type'        => 'string',
				'description' => __( 'The nickname for the user.', 'ai' ),
			),
			'slug'        => array(
				'type'        => 'string',
				'description' => __( 'An alphanumeric identifier for the user.', 'ai' ),
			),
			'roles'       => array(
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
				),
				'description' => __( 'Roles assigned to the user. Changing the roles of an existing user requires permission to promote users.', 'ai' ),
			),
			'password'    => array(
				'type'        => 'string',
				'description' => __( 'Password for the user. It is never returned.', 'ai' ),
			),
		);
	}

	/**
	 * Builds the input schema for the `core/user-create` ability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_user_create_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'username', 'email', 'password' ),
			'additionalProperties' => false,
			'properties'           => array_merge(
				array(
					'username' => array(
						'type'        => 'string',
						'description' => __( 'Login name for the user. It cannot be changed later.', 'ai' ),
					),
				),
				$this->get_user_write_properties(),
				array( 'fields' => $this->get_fields_input_schema() )
			),
		);
	}

	/**
	 * Builds the input schema for the `core/user-update` ability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_user_update_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'additionalProperties' => false,
			'properties'           => array_merge(
				array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the user to update.', 'ai' ),
					),
				),
				$this->get_user_write_properties(),
				array( 'fields' => $this->get_fields_input_schema() )
			),
		);
	}

	/**
	 * Builds the input schema for the `core/user-delete` ability.
	 *
	 * `reassign` also takes a string so the query-string forms of an ID and of false are read
	 * like the ID and false themselves.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_user_delete_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'reassign' ),
			'additionalProperties' => false,
			'properties'           => array(
				'id'       => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The ID of the user to delete.', 'ai' ),
				),
				'force'    => array(
					'type'        => 'boolean',
					'description' => __( 'Required to be true, as users do not support trashing.', 'ai' ),
				),
				'reassign' => array(
					// Strings come first, so input coercion keeps them as sent: 'FALSE' is not false.
					'type'        => array( 'string', 'integer', 'boolean' ),
					'description' => __( 'The ID of the user to reassign the deleted user\'s posts and links to, or false to delete them.', 'ai' ),
				),
				'fields'   => $this->get_fields_input_schema(),
			),
		);
	}

	/**
	 * Builds the schema of the `fields` input shared by the write abilities.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The `fields` JSON Schema.
	 */
	private function get_fields_input_schema(): array {
		return array(
			'type'        => 'array',
			'uniqueItems' => true,
			'minItems'    => 1,
			'items'       => array(
				'type' => 'string',
				'enum' => array_keys( $this->get_user_properties() ),
			),
			'description' => __( 'Limit the returned user to these fields. If omitted, a lean set of common read fields is returned.', 'ai' ),
		);
	}

	/**
	 * Builds the output schema of a single user, shared by the write abilities.
	 *
	 * No field is marked required because the `fields` input lets the caller request any subset.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The user JSON Schema.
	 */
	private function get_user_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $this->get_user_properties(),
		);
	}

	/**
	 * Builds the output schema for the `core/user-delete` ability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_user_delete_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'deleted', 'previous' ),
			'properties'           => array(
				'deleted'  => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the user was deleted.', 'ai' ),
				),
				'previous' => $this->get_user_output_schema(),
			),
		);
	}
}

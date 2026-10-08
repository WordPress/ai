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
 * This class is kept almost identical to the WordPress core class `WP_Abilities_Users`
 * so the two implementations stay in sync. Most differences from the core class are marked with
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
	 * The get_user_by() field for each single-user lookup type, in the order they are checked.
	 *
	 * @since x.x.x
	 * @var array<string, string>
	 */
	private const LOOKUP_FIELDS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'id'       => 'id',
		'email'    => 'email',
		'username' => 'login',
		'slug'     => 'slug',
	);

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
	 * Fields a single-user lookup returns for a user the current user can only see as an author.
	 *
	 * They only identify the user. The profile fields (`description` and `url`), which a user
	 * writes to show next to their published posts, are left out.
	 *
	 * @since x.x.x
	 * @var string[]
	 */
	private const AUTHOR_FIELDS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
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
				'label'               => __( 'Query Users', 'ai' ),
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
				'label'               => __( 'Create User', 'ai' ),
				'description'         => __( 'Creates a user. Requires a username and an email address, and accepts a password, display name, first and last name, URL, description, locale, nickname, slug, and roles. Without a password, one is generated and the user is emailed a link to set their own. Returns the created user; use `fields` to choose which user fields are returned. Requires an authenticated user who can create users, and who can promote users to give the user roles.', 'ai' ),
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
					'public'       => true,
					'show_in_rest' => true,
				),
			),
			'core/user-update' => array(
				'label'               => __( 'Update User', 'ai' ),
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
					'public'       => true,
					'show_in_rest' => true,
				),
			),
			'core/user-delete' => array(
				'label'               => __( 'Delete User', 'ai' ),
				'description'         => __( 'Permanently deletes a user by ID; users cannot be trashed. `reassign` takes the ID of the user who receives the deleted user\'s posts and links, or false to delete them, which moves posts and pages to the trash. Returns the deleted user as it was before the deletion; use `fields` to choose which user fields are returned. Not supported on multisite, and users cannot delete their own account. Requires an authenticated user who can delete the user.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_user_delete_input_schema(),
				'output_schema'       => $this->get_user_output_schema(),
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
					'public'       => true,
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
		$input = rest_sanitize_object( $input );

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

		$user = $this->find_user( $input, $lookup_type );

		return $user instanceof WP_User && null !== $this->get_readable_fields( $user, $lookup_type );
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
	 * roles are given, an empty list included. A roles change still needs the promote
	 * capability, which is checked during execution so the caller learns that the roles
	 * were refused.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_update_permission( $input = array() ): bool {
		$input = rest_sanitize_object( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user = $this->get_user( $input['id'] ?? 0 );
		if ( is_wp_error( $user ) ) {
			return false;
		}

		// An empty list is a roles change too: it removes every role.
		if ( isset( $input['roles'] ) && current_user_can( 'promote_user', $user->ID ) ) {
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
		$input = rest_sanitize_object( $input );

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
	 * @return array<string, mixed>|\WP_Error User data, paginated collection data, or a WP_Error on failure.
	 */
	public function execute_users_query( $input = array() ) {
		$input  = rest_sanitize_object( $input );
		$fields = $this->normalize_fields( $input );

		$lookup_type = $this->get_lookup_type( $input );
		if ( self::LOOKUP_COLLECTION !== $lookup_type ) {
			$user = $this->find_user( $input, $lookup_type );
			if ( ! $user instanceof WP_User ) {
				return $this->not_found_error();
			}

			$readable_fields = $this->get_readable_fields( $user, $lookup_type );
			if ( null === $readable_fields ) {
				return $this->not_found_error();
			}

			return $this->format_user( $user, array_values( array_intersect( $fields, $readable_fields ) ) );
		}

		$include        = ! empty( $input['include'] ) ? wp_parse_id_list( $input['include'] ) : array();
		$per_page       = $this->normalize_per_page( $input, $include );
		$page           = isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1;
		$can_list_users = current_user_can( 'list_users' );

		$query_args = array(
			'number' => $per_page,
			'paged'  => $page,
		);

		if ( array() !== $include ) {
			/*
			 * The include list filters the results but does not order them, as in the REST
			 * users controller. Results keep WP_User_Query's default order, by login.
			 */
			$query_args['include'] = $include;
		}

		if ( ! empty( $input['roles'] ) && $can_list_users ) {
			$query_args['role__in'] = $this->normalize_string_list( $input['roles'] );
		}

		$has_published_posts = $this->normalize_has_published_posts( $input );

		/*
		 * Callers who cannot list users only see public authors in a collection,
		 * matching core, so the filter is always applied for them. This intentionally
		 * excludes the caller's own account when they have no published posts. Self is
		 * read through a single-user lookup (like the REST `/users/me` endpoint) instead.
		 */
		if ( null !== $has_published_posts || ! $can_list_users ) {
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

		/*
		 * The rows and the totals come from the same query, so they agree. As in the REST
		 * users controller, rows are not filtered by site membership afterwards: on
		 * multisite, WP_User_Query already limits the query to members of the current site.
		 */
		$total_users = $query->get_total();
		$total_pages = (int) ceil( $total_users / $per_page );

		/*
		 * Paging past the last page is a caller error rather than an empty collection, so
		 * report it instead of returning a bare empty list, as the REST posts controller
		 * does. A genuinely empty result set still returns zero totals and no error.
		 */
		if ( $total_users > 0 && $page > $total_pages ) {
			return new WP_Error(
				'users_invalid_page_number',
				__( 'The page number requested is larger than the number of pages available.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$users = array();
		foreach ( $query->get_results() as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$users[] = $this->format_user( $user, $fields );
		}

		return array(
			'users'       => $users,
			'total'       => $total_users,
			'total_pages' => $total_pages,
		);
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
		foreach ( array_keys( self::LOOKUP_FIELDS ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				return $key;
			}
		}

		return self::LOOKUP_COLLECTION;
	}

	/**
	 * Finds the user a single-user lookup asks for.
	 *
	 * Whether the current user may read that user is decided separately, by
	 * {@see self::get_readable_fields()}.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $input       The ability input.
	 * @param string       $lookup_type The single-user lookup type.
	 * @return \WP_User|null The user, or null when no user matches.
	 */
	private function find_user( array $input, string $lookup_type ): ?WP_User {
		$value = $input[ $lookup_type ];

		// WP_Ability::check_permissions() does not validate the input, so the value may not be a scalar.
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		/*
		 * get_user_by() sanitizes a login itself, and matches a slug as given, like the REST
		 * users controller, so a stored nicename that sanitize_title() would change is found.
		 * The value is passed as a string because validation also accepts a float ID, like 5.0.
		 */
		$user = get_user_by( self::LOOKUP_FIELDS[ $lookup_type ], (string) $value );

		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Returns the fields a single-user lookup may return for a user.
	 *
	 * Shared by the permission and execute callbacks so the single-user
	 * authorization decision has exactly one implementation. The per-field checks in
	 * {@see self::format_user()}, such as the ones for sensitive fields, still apply.
	 *
	 * Email and username are identifier-sensitive lookup modes: another user can only be
	 * found by them with permission to list or edit users. Collections do not list the
	 * users that the author fields are returned for, because they only list public
	 * authors to callers who cannot list users.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_User $user        User object.
	 * @param string   $lookup_type Lookup type.
	 * @return string[]|null The field names the lookup may return, or null when the user cannot be read.
	 */
	private function get_readable_fields( WP_User $user, string $lookup_type ): ?array {
		$all_fields = array_keys( $this->get_user_properties() );

		/*
		 * The current user can always read their own account, like the REST `/users/me`
		 * endpoint, even on a site they are not a member of.
		 */
		if ( $this->is_current_user( $user ) ) {
			return $all_fields;
		}

		// The capabilities only reveal users of the site, not of the whole network.
		$is_site_member = ! is_multisite() || is_user_member_of_blog( $user->ID );
		if ( $is_site_member && ( current_user_can( 'edit_user', $user->ID ) || current_user_can( 'list_users' ) ) ) {
			return $all_fields;
		}

		if ( 'email' === $lookup_type || 'username' === $lookup_type ) {
			return null;
		}

		/*
		 * Public authors are visible on the front end, so they can be read even when they
		 * are not members of the site, such as a super admin who published posts on it.
		 */
		if ( $this->is_public_author( $user ) ) {
			return $all_fields;
		}

		/*
		 * A caller who can assign posts to other users can make any user of the site an
		 * author, so they may identify those users, but not read their profiles.
		 */
		if ( $is_site_member && $this->can_assign_authors() ) {
			return self::AUTHOR_FIELDS;
		}

		return null;
	}

	/**
	 * Checks whether the current user can assign posts to other users.
	 *
	 * A user who can edit others' posts of a post type that supports authors can make any
	 * user of the site the author of such a post.
	 *
	 * @since x.x.x
	 *
	 * @return bool Whether the current user can assign posts to other users.
	 */
	private function can_assign_authors(): bool {
		foreach ( get_post_types_by_support( 'author' ) as $post_type ) {
			$post_type_object = get_post_type_object( $post_type );
			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
			if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->edit_others_posts ) ) {
				continue;
			}

			return true;
		}

		return false;
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
		return get_current_user_id() === $user->ID;
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
		return count_user_posts( $user->ID, $post_types ) > 0;
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
	 * Otherwise the requested fields are returned. The input schema has already
	 * validated the names against the supported set before the ability executes.
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
			$fields = self::DEFAULT_FIELDS;
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
	 * option could change between registration and use.
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
				'description' => __( 'Description of the user. Present when the current user can view it.', 'ai' ),
			),
			'url'             => array(
				/*
				 * Unlike the REST users controller, `url` declares no `uri` format. It is
				 * empty for users without a website, and clients that check formats,
				 * such as the abilities JS client when it re-validates the output, would
				 * reject the empty string and fail the whole call.
				 */
				'type'        => 'string',
				'description' => __( 'URL of the user. Present when the current user can view it.', 'ai' ),
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
				'type'        => array( 'string', 'null' ),
				'format'      => 'date-time',
				'description' => __( 'Registration date for the user. Null when the stored date is not a valid date. Present when the current user can view it.', 'ai' ),
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
	 * Normalizes the requested per-page value to the supported bounds.
	 *
	 * An explicit `per_page` always wins. Otherwise an `include` request pages to the
	 * number of requested IDs, so a caller loading a known set of users receives all of
	 * them in one call rather than silently losing the ones past the default page size.
	 * The input schema caps `include` at {@see self::MAX_PER_PAGE} so it always fits.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $input       The ability input.
	 * @param int[]        $include_ids Parsed included user IDs; empty when not requested.
	 * @return int The clamped per-page value.
	 */
	private function normalize_per_page( array $input, array $include_ids ): int {
		$default  = array() === $include_ids ? self::DEFAULT_PER_PAGE : count( $include_ids );
		$per_page = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : $default;

		return max( 1, min( self::MAX_PER_PAGE, $per_page ) );
	}

	/**
	 * Normalizes a mixed value into a list of strings.
	 *
	 * Accepts arrays and CSV strings, which it parses with wp_parse_list(), as schema
	 * validation does. Schema validation accepts a CSV string for an array, and only the
	 * REST run controller converts input to the schema types, so callers that bypass it,
	 * such as a direct WP_Ability::execute() call, can pass one. Empty and duplicate
	 * items need no handling here, because validation has already rejected them.
	 *
	 * Plugin: the REST run controller only converts input since WordPress 7.1, so on 7.0 a
	 * GET request can pass one too.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value Raw value.
	 * @return string[] Normalized strings.
	 */
	private function normalize_string_list( $value ): array {
		if ( ! is_array( $value ) && ! is_string( $value ) ) {
			return array();
		}

		return array_values( array_filter( wp_parse_list( $value ), 'is_string' ) );
	}

	/**
	 * Normalizes the `has_published_posts` collection input.
	 *
	 * Accepts the string and integer forms of `true` alongside the native boolean.
	 * Schema validation accepts them, and only the REST run controller converts input
	 * to the schema types, so callers that bypass it, such as a direct
	 * WP_Ability::execute() call, can pass one.
	 *
	 * Plugin: the REST run controller only converts input since WordPress 7.1, so on 7.0 a
	 * GET request can pass one too.
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

		// Plugin: core uses rest_is_boolean() and rest_sanitize_boolean(), which PHPStan cannot check on a mixed value.
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
		$role_names        = array_keys( wp_roles()->roles );
		$public_post_types = $this->get_public_post_types();
		$fields            = array(
			'type'        => 'array',
			'uniqueItems' => true,
			'items'       => array(
				'type' => 'string',
				'enum' => array_keys( $this->get_user_properties() ),
			),
			'description' => __( 'Limit each returned user to these fields. If omitted or empty, a lean set of common read fields is returned. The `id` field is always included, and fields the current user cannot view are omitted rather than causing an error.', 'ai' ),
		);
		$include           = array(
			'type'        => 'array',
			'uniqueItems' => true,
			'minItems'    => 1,
			'maxItems'    => self::MAX_PER_PAGE,
			'items'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'description' => __( 'Limit the query to these user IDs. The order of the IDs does not affect the order of the results. If `per_page` is omitted, the page size defaults to the number of included IDs, capped at the maximum. Collection results are limited to users the caller can read, which for callers without permission to list users means only public authors. To read your own account, use a single-user lookup by ID.', 'ai' ),
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
							'description' => __( 'Page of results to return. Requesting a page beyond the last one is an error. Check `total_pages` before requesting later pages.', 'ai' ),
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
			'type'  => 'object',
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
	 * @return array<string, mixed> The formatted user data.
	 */
	private function format_user( WP_User $user, array $fields, bool $edit_context = false ): array {
		$fields_requested = static function ( string $field ) use ( $fields ): bool {
			return in_array( $field, $fields, true );
		};

		$can_view_sensitive = $this->is_current_user( $user ) || current_user_can( 'edit_user', $user->ID );

		$data = array();

		if ( $fields_requested( 'id' ) ) {
			$data['id'] = $user->ID;
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
			$data['link'] = (string) get_author_posts_url( $user->ID, $user->user_nicename );
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
				/*
				 * The zero date, the column default, formats with a negative year that the
				 * `date-time` format rejects, so it is reported as null, like an unusable email.
				 */
				$registered_timestamp    = strtotime( $user->user_registered );
				$registered_date         = false !== $registered_timestamp ? gmdate( 'c', $registered_timestamp ) : '';
				$data['registered_date'] = rest_parse_date( $registered_date ) ? $registered_date : null;
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
			$data['roles'] = array_values( $user->roles );
		}

		return $data;
	}

	/**
	 * Builds the error for a single-user lookup that does not resolve to a readable user.
	 *
	 * Unreachable through gated transports, which run {@see self::check_permission()}
	 * first and deny the same lookups. It is kept so that a direct call to the execute
	 * callback still fails closed. A user the current user cannot read is reported like a
	 * missing one, so the error does not reveal whether such an account exists.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Error The not-found error.
	 */
	private function not_found_error(): WP_Error {
		return new WP_Error(
			'users_not_found',
			__( 'The requested user was not found.', 'ai' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Executes the `core/user-create` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The created user, or a WP_Error.
	 */
	public function execute_user_create( $input = array() ) {
		$input = $this->sanitize_params( rest_sanitize_object( $input ) );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		if ( ! empty( $input['roles'] ) ) {
			// As in wp-admin, giving a new user roles takes the capability to promote users.
			if ( ! current_user_can( 'promote_users' ) ) {
				return new WP_Error(
					'users_cannot_edit_roles',
					__( 'Sorry, you are not allowed to give users roles.', 'ai' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

			$check_permission = $this->check_role_update( null, $input['roles'] );

			if ( is_wp_error( $check_permission ) ) {
				return $check_permission;
			}
		}

		$user = $this->prepare_item_for_database( $input );

		// As in wp-admin, a password is generated when none is given; the user sets their own from the email sent below.
		if ( ! isset( $user->user_pass ) ) {
			$user->user_pass = wp_generate_password( 24 );
		}

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

			// The database can refuse the row, e.g. an email too long for its column, and then the ID is 0.
			if ( ! $user_id ) {
				return new WP_Error(
					'users_user_create',
					__( 'Error creating new user.', 'ai' ),
					array( 'status' => 500 )
				);
			}
		}

		$user = new WP_User( $user_id );

		if ( ! empty( $input['roles'] ) ) {
			array_map( array( $user, 'add_role' ), $input['roles'] );
		}

		if ( ! isset( $input['password'] ) ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}

		return $this->format_user( $user, $this->normalize_fields( $input ), true );
	}

	/**
	 * Executes the `core/user-update` ability.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The updated user, or a WP_Error.
	 */
	public function execute_user_update( $input = array() ) {
		$input = $this->sanitize_params( rest_sanitize_object( $input ) );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$user = $this->get_user( $input['id'] ?? 0 );
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		/*
		 * The current roles, in their order, are no change, so a user read with core/users-query
		 * can be sent back. The order counts, as the first role is the user's primary role.
		 */
		if ( isset( $input['roles'] ) && array_values( array_unique( $input['roles'] ) ) === array_values( $user->roles ) ) {
			unset( $input['roles'] );
		}

		if ( isset( $input['roles'] ) && ! current_user_can( 'promote_user', $user->ID ) ) {
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

		if ( isset( $input['roles'] ) ) {
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
	 * Users cannot be trashed, so the user is deleted permanently and returned as it was just
	 * before the deletion.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The deleted user, or a WP_Error.
	 */
	public function execute_user_delete( $input = array() ) {
		$input = $this->sanitize_params( rest_sanitize_object( $input ) );
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

		// As in wp-admin, users cannot delete their own account.
		if ( get_current_user_id() === $user->ID ) {
			return new WP_Error(
				'users_cannot_delete',
				__( 'Sorry, you are not allowed to delete your own account.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$id       = $user->ID;
		$reassign = false === $input['reassign'] ? null : absint( $input['reassign'] );

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

		return $previous;
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
	 * A user who does not exist, or does not belong to the current site, gets the error
	 * `core/users-query` gives for a user it cannot read.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $id Supplied ID.
	 * @return \WP_User|\WP_Error The user if the ID is valid, WP_Error otherwise.
	 */
	private function get_user( $id ) {
		$error = $this->not_found_error();

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
	 * @param string[] $roles   New user roles. An empty list removes every role.
	 * @return true|\WP_Error True if the current user is allowed to make the role change,
	 *                        otherwise a WP_Error object.
	 */
	private function check_role_update( ?int $user_id, array $roles ) {
		global $wp_roles;

		// Without a role, users cannot edit users either, so they cannot remove their own roles.
		if ( array() === $roles
			&& ! ( is_multisite() && current_user_can( 'manage_sites' ) )
			&& get_current_user_id() === $user_id
		) {
			return new WP_Error(
				'users_user_invalid_role',
				__( 'Sorry, you are not allowed to remove your own roles.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

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
				'description' => __( 'Roles assigned to the user. An empty list leaves the user without a role. Giving a user roles, or changing them, requires permission to promote users.', 'ai' ),
			),
			'password'    => array(
				'type'        => 'string',
				'description' => __( 'Password for the user. It is never returned. When a user is created without one, a password is generated and the user is emailed a link to set their own.', 'ai' ),
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
			'required'             => array( 'username', 'email' ),
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
				'reassign' => array(
					// Strings come first, so input coercion keeps them as sent: 'FALSE' is not false.
					'type'        => array( 'string', 'integer', 'boolean' ),
					'description' => __( 'The ID of the user to reassign the deleted user\'s posts and links to, or false to delete them, which moves posts and pages to the trash.', 'ai' ),
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
			'items'       => array(
				'type' => 'string',
				'enum' => array_keys( $this->get_user_properties() ),
			),
			'description' => __( 'Limit the returned user to these fields. If omitted or empty, a lean set of common read fields is returned. The `id` field is always included, and fields the current user cannot view are omitted rather than causing an error.', 'ai' ),
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
}

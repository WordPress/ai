<?php
/**
 * The `core/content-*` WordPress Abilities.
 *
 * @package WordPress\AI
 *
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content;

use WP_Error;
use WP_Post;
use WP_Query;

use function WordPress\AI\register_deprecated_ability_alias;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Content
 *
 * Registers the read-only `core/content-query` ability, which retrieves readable posts of a
 * post type exposed to abilities via `show_in_abilities`. Supports fetching a single
 * readable post by ID or by post type and slug, or querying multiple readable posts filtered
 * by post type, status, author, parent, or included IDs. Raw fields are only returned for
 * posts the current user can edit.
 *
 * Also registers `core/content-create`, `core/content-update`, and `core/content-delete`,
 * which write posts of the same post types under the field names the query returns, and
 * return them through the same field projection and edit-access rules.
 *
 * This class is kept almost identical to the WordPress core class `WP_Content_Abilities`
 * so the two implementations stay in sync. Differences from the core class are marked with
 * `// Plugin:` comments. Additionally, all user-facing strings use the 'ai' text domain.
 * The write abilities and the helpers only they use are not part of the core class yet,
 * so they carry no markers.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 1.2.0
 */
final class Content {

	/**
	 * The ability category used for content abilities.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private const CATEGORY = 'content';

	/**
	 * Default number of posts returned per page in query mode.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private const DEFAULT_PER_PAGE = 10;

	/**
	 * Maximum number of posts returned per page in query mode.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Fields that expose edit-context post data.
	 *
	 * Requests that explicitly include any of these fields require edit access.
	 *
	 * @since 1.2.0
	 * @var list<string>
	 */
	private array $edit_fields = array(
		'title_raw',
		'excerpt_raw',
		'content_raw',
	);

	/**
	 * Fields whose output may read post meta or terms.
	 *
	 * Requests that include any of these prime the post meta and term caches for the
	 * page. Rendered excerpts and content may read either, and permalinks read terms
	 * when the permalink structure contains `%category%`. Other fields, such as the
	 * rendered title, do not need that cache priming.
	 *
	 * @since 1.2.0
	 * @var list<string>
	 */
	private array $cache_priming_fields = array(
		'link',
		'excerpt_rendered',
		'content_rendered',
	);

	/**
	 * Cached post field definitions, keyed by field name in output order.
	 *
	 * @since 1.2.0
	 * @var array<string, mixed>|null
	 */
	private ?array $post_properties = null;

	/**
	 * Default fields returned when the caller does not request a field subset.
	 *
	 * @since 1.2.0
	 * @var list<string>
	 */
	private array $default_fields = array(
		'id',
		'post_type',
		'status',
		'date',
		'slug',
		'title_rendered',
	);

	/**
	 * Globals that rendering a post changes: the global post and the globals that
	 * setup_postdata() populates.
	 *
	 * @since x.x.x
	 * @var list<string>
	 */
	private array $loop_globals = array(
		'post',
		'id',
		'authordata',
		'currentday',
		'currentmonth',
		'page',
		'pages',
		'multipage',
		'more',
		'numpages',
	);

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * Plugin: this method has no equivalent in the core class. In core, register() is
	 * invoked directly from wp_register_core_abilities() (already on the
	 * `wp_abilities_api_init` hook). The plugin instead hooks register() slightly later
	 * (priority 11) so it can override any core-provided copy, and registers the category
	 * as a fallback in case core has not.
	 *
	 * @since 1.2.0
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ), 11 );
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 11 );
	}

	/**
	 * Registers the `content` ability category if it is not already registered.
	 *
	 * Plugin: this method has no equivalent in the core class; core relies on
	 * wp_register_core_ability_categories() to register the `content` category.
	 *
	 * @since 1.2.0
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
	 * Registers all content abilities.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since 1.2.0
	 */
	public function register(): void {
		$this->register_content_query();
		$this->register_content_write_abilities();
	}

	/**
	 * Registers the read-only `core/content-query` ability.
	 *
	 * Also registers `core/read-content` as a deprecated alias.
	 *
	 * @since 1.2.0
	 * @since 1.4.0 Renamed from `core/read-content`.
	 */
	private function register_content_query(): void {
		/*
		 * Post types must be registered with `show_in_abilities` before the ability is
		 * registered so they are included in its input schema.
		 */
		$post_types = array_values( get_post_types( array( 'show_in_abilities' => true ) ) );
		if ( empty( $post_types ) ) {
			return;
		}

		// Plugin: unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/content-query' ) ) {
			wp_unregister_ability( 'core/content-query' );
		}

		/*
		 * Internal statuses (e.g. `inherit`) are excluded, so post types that rely on
		 * them (attachments) are only reachable by ID. Revisit if such a post type is
		 * ever exposed via `show_in_abilities`.
		 */
		$statuses = array_values( get_post_stati( array( 'internal' => false ) ) );

		wp_register_ability(
			'core/content-query',
			array(
				'label'               => __( 'Content Query', 'ai' ),
				'description'         => __( 'Reads content from post types exposed to abilities. Single-post lookups by ID or by post type and slug return the post object directly. Query mode returns readable posts filtered by post type, status, author, parent, or included IDs. Requires an authenticated user. Lookups and filters are exact-match only; the ability does not perform full-text search.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_content_query_input_schema( $post_types, $statuses ),
				'output_schema'       => $this->get_content_query_output_schema(),
				'execute_callback'    => array( $this, 'execute_content_query' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
						/*
						 * MCP clients assume open-world (may reach external systems) when the
						 * hint is absent; this ability only reads the local database.
						 */
						'open_world'  => false,
					),
					'public'       => true,
					'show_in_rest' => true,
				),
			)
		);

		// @todo Remove the alias after a few releases.
		register_deprecated_ability_alias( 'core/read-content', 'core/content-query', '1.4.0' );
	}

	/**
	 * Registers the `core/content-create`, `core/content-update`, and `core/content-delete` abilities.
	 *
	 * @since x.x.x
	 */
	private function register_content_write_abilities(): void {
		/*
		 * Post types must be registered with `show_in_abilities` before the abilities are
		 * registered so they are included in their input schemas.
		 */
		$post_types = array_values( get_post_types( array( 'show_in_abilities' => true ) ) );
		if ( empty( $post_types ) ) {
			return;
		}

		$create_schema = $this->get_content_create_input_schema( $post_types );

		$abilities = array(
			'core/content-create' => array(
				'label'               => __( 'Content Create', 'ai' ),
				'description'         => __( 'Creates a post of a post type exposed to abilities. Accepts title_raw, content_raw, excerpt_raw, status, slug, date, date_gmt, author_slug, and parent, the field names `core/content-query` returns. Fields the post type does not support are rejected. Returns the created post; use `fields` to choose which post fields are returned. Requires an authenticated user who can create posts of the post type.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $create_schema,
				'output_schema'       => $this->get_content_output_schema(),
				'execute_callback'    => array( $this, 'execute_content_create' ),
				'permission_callback' => array( $this, 'check_create_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						// Every call creates a new post.
						'idempotent'  => false,
						'open_world'  => false,
					),
					'public'       => true,
					'show_in_rest' => true,
				),
			),
			'core/content-update' => array(
				'label'               => __( 'Content Update', 'ai' ),
				'description'         => __( 'Updates a post by ID. Accepts title_raw, content_raw, excerpt_raw, status, slug, date, date_gmt, author_slug, and parent, the field names `core/content-query` returns. Fields left out keep their current values. Fields the post type does not support are rejected. Returns the updated post; use `fields` to choose which post fields are returned. Requires an authenticated user who can edit the post.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_content_update_input_schema( $create_schema ),
				'output_schema'       => $this->get_content_output_schema(),
				'execute_callback'    => array( $this, 'execute_content_update' ),
				'permission_callback' => array( $this, 'check_update_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						// Overwritten values are not always kept in a revision.
						'destructive' => true,
						/*
						 * Every call touches the modified date, and destructive idempotent
						 * abilities are served over DELETE, which cannot carry post content.
						 */
						'idempotent'  => false,
						'open_world'  => false,
					),
					'public'       => true,
					'show_in_rest' => true,
				),
			),
			'core/content-delete' => array(
				'label'               => __( 'Content Delete', 'ai' ),
				'description'         => __( 'Moves a post to the trash by ID, or deletes it permanently when `force` is true. Trashing a post that is already in the trash is an error, as is trashing when the site has the trash disabled; set `force` to delete permanently in that case. Returns the trashed post, or the deleted post as it was before the deletion; use `fields` to choose which post fields are returned. Requires an authenticated user who can delete the post.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_content_delete_input_schema( $post_types ),
				'output_schema'       => $this->get_content_output_schema(),
				'execute_callback'    => array( $this, 'execute_content_delete' ),
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
	 * Permission callback for the `core/content-query` ability.
	 *
	 * This gate is the authoritative permission decision for single-post modes: it
	 * resolves the requested post and denies missing, mismatched, or unreadable posts
	 * before execution. Query mode is only gated coarsely here (collection status
	 * capabilities); {@see self::execute_content_query()} enforces row-level read/edit
	 * permissions, since individual rows are unknown until the query runs. Requests
	 * that explicitly ask for edit-context fields require edit access before execution.
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

		$requires_edit = $this->has_explicit_edit_fields( $input );

		// Single-post mode (by ID).
		if ( ! empty( $input['id'] ) ) {
			$post = $this->get_content_by_id( $input );
			if ( ! $post ) {
				return false;
			}

			return $requires_edit ? current_user_can( 'edit_post', $post->ID ) : $this->check_read_permission( $post );
		}

		// Single-post mode (by slug) and query mode require an exposed post type.
		$post_type_object = $this->get_exposed_post_type( $input['post_type'] ?? null );
		if ( ! $post_type_object ) {
			return false;
		}

		$post_type = $post_type_object->name;
		if ( isset( $input['slug'] ) && is_string( $input['slug'] ) && '' !== $input['slug'] ) {
			$post = $this->get_post_by_slug( $post_type, $input['slug'] );
			if ( ! $post ) {
				return false;
			}

			return $requires_edit ? current_user_can( 'edit_post', $post->ID ) : $this->check_read_permission( $post );
		}

		if ( $requires_edit ) {
			return current_user_can( $post_type_object->cap->edit_posts ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
		}

		return $this->can_query_statuses( $input, $post_type_object );
	}

	/**
	 * Checks permission for the `core/content-create` ability.
	 *
	 * The current user must be able to create posts of the requested post type. The rest
	 * of the input is checked during execution.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_create_permission( $input = array() ): bool {
		$input = rest_sanitize_object( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$post_type_object = $this->get_exposed_post_type( $input['post_type'] ?? null );
		if ( ! $post_type_object ) {
			return false;
		}

		return current_user_can( $post_type_object->cap->create_posts ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
	}

	/**
	 * Checks permission for the `core/content-update` ability.
	 *
	 * The post must exist in an exposed post type (and match the `post_type` guard when
	 * given), and the current user must be able to edit it. The rest of the input is
	 * checked during execution.
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

		$post = $this->get_content_by_id( $input );
		if ( ! $post ) {
			return false;
		}

		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Checks permission for the `core/content-delete` ability.
	 *
	 * The post must exist in an exposed post type (and match the `post_type` guard when
	 * given), and the current user must be able to delete it. As in `core/content-query`, a
	 * request that explicitly asks for edit-context fields also requires edit access, so it
	 * is refused before anything is deleted.
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

		$post = $this->get_content_by_id( $input );
		if ( ! $post ) {
			return false;
		}

		if ( $this->has_explicit_edit_fields( $input ) && ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		return current_user_can( 'delete_post', $post->ID );
	}

	/**
	 * Checks whether the current user may write the post as another author.
	 *
	 * This runs during execution, still before anything is written, because the Abilities
	 * API replaces any error a permission callback returns with a generic one, and the
	 * caller should learn which field was refused.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed>  $input            The ability input.
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @param bool          $creating         True when creating a post, false when updating.
	 * @return \WP_Error|null A WP_Error when the author may not be assigned, or null.
	 */
	private function check_write_permission( array $input, \WP_Post_Type $post_type_object, bool $creating ): ?WP_Error {
		/*
		 * Another user's slug is refused before it is looked up, so the refusal says nothing
		 * about that user. A slug that names no user is rejected when the post is prepared.
		 */
		$author_slug = $input['author_slug'] ?? null;
		if ( null !== $author_slug
			&& wp_get_current_user()->user_nicename !== $author_slug
			&& ! current_user_can( $post_type_object->cap->edit_others_posts ) // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
		) {
			return new WP_Error(
				'content_cannot_edit_others',
				$creating
					? __( 'Sorry, you are not allowed to create posts as this user.', 'ai' )
					: __( 'Sorry, you are not allowed to update posts as this user.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return null;
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
	 * Parses a raw input value into an integer of at least a minimum, or null when invalid.
	 *
	 * Unlike {@see self::input_int()}, which coerces any non-integer to 0, this rejects
	 * values that are not integers, so an ID or a parent that cannot be honored fails
	 * loudly instead of being read as 0: a `parent` filter of 0 asks for top-level posts.
	 * Accepts native integers and unsigned integer strings. Schema validation accepts an
	 * integer string, and only the REST run controller converts input to the schema types,
	 * so callers that bypass it, such as a direct WP_Ability::execute() call, can pass one.
	 *
	 * Plugin: the REST run controller only converts input since WordPress 7.1, so on 7.0 a
	 * GET request can pass one too.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value The raw input value.
	 * @param int   $min   The smallest acceptable value.
	 * @return int|null The parsed integer, or null when the value is not an integer >= $min.
	 */
	private function parse_filter_int( $value, int $min ): ?int {
		if ( is_int( $value ) ) {
			return $value >= $min ? $value : null;
		}

		if ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) {
			$int = (int) $value;

			return $int >= $min ? $int : null;
		}

		return null;
	}

	/**
	 * Parses a raw list input into a list of strings.
	 *
	 * Schema validation accepts a list given as a scalar or comma-separated string, and
	 * only the REST run controller converts input to the schema types, so callers that
	 * bypass it, such as a direct WP_Ability::execute() call, can pass one. This parses
	 * it the same way validation did, with wp_parse_list().
	 *
	 * Plugin: the REST run controller only converts input since WordPress 7.1, so on 7.0 a
	 * GET request can pass one too.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $input The ability input.
	 * @param string       $key   The input key holding the list.
	 * @return list<string> The parsed string values; empty when absent or unparseable.
	 */
	private function parse_list_input( array $input, string $key ): array {
		$value = $input[ $key ] ?? null;
		if ( ! is_array( $value ) && ! is_string( $value ) ) {
			return array();
		}

		return array_values( array_filter( wp_parse_list( $value ), 'is_string' ) );
	}

	/**
	 * Checks whether the input explicitly requests edit-context fields.
	 *
	 * Omitted fields are not treated as edit-intent: default responses include the
	 * fields visible for each individual post.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $input The ability input.
	 * @return bool True if edit-context fields were explicitly requested.
	 */
	private function has_explicit_edit_fields( array $input ): bool {
		return array() !== array_intersect( $this->edit_fields, $this->parse_list_input( $input, 'fields' ) );
	}

	/**
	 * Checks whether the current user may query the requested statuses.
	 *
	 * This mirrors the REST posts controller's conservative collection-status gate:
	 * requesting non-default statuses requires edit access, except `private`, which
	 * may be queried by users who can read private posts.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed>  $input            The ability input.
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @return bool True if the requested statuses may be queried.
	 */
	private function can_query_statuses( array $input, \WP_Post_Type $post_type_object ): bool {
		foreach ( $this->normalize_statuses( $input ) as $status ) {
			if ( 'publish' === $status ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
			if ( 'private' === $status && current_user_can( $post_type_object->cap->read_private_posts ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
			if ( current_user_can( $post_type_object->cap->edit_posts ) ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Checks if a post can be read by the current user.
	 *
	 * Mirrors the REST posts controller's read permission, while keeping this ability
	 * authenticated-only via {@see self::check_permission()}.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_Post         $post             Post object.
	 * @param array<int, true> $checked_post_ids Post IDs already checked while walking inherited parents.
	 * @return bool Whether the post can be read.
	 */
	private function check_read_permission( WP_Post $post, array $checked_post_ids = array() ): bool {
		if ( isset( $checked_post_ids[ $post->ID ] ) ) {
			return false;
		}

		$checked_post_ids[ $post->ID ] = true;

		if ( ! $this->get_exposed_post_type( $post->post_type ) ) {
			return false;
		}

		/*
		 * Treat publicly viewable posts as readable. This checks both the post type
		 * and post status using Core's viewability helpers, which is stricter than
		 * checking the status object's `public` flag alone.
		 */
		if ( is_post_publicly_viewable( $post ) ) {
			return true;
		}

		/*
		 * Use the normalized status for the status object lookup. For attachments,
		 * get_post_status() resolves `inherit` through the parent before returning.
		 */
		$post_status = get_post_status( $post );
		if ( ! is_string( $post_status ) ) {
			return false;
		}

		$post_status_object = get_post_status_object( $post_status );
		if ( ! $post_status_object instanceof \stdClass ) {
			return false;
		}

		/*
		 * Core maps `read_post` for public statuses to the post type's plain `read`
		 * capability. Publicly viewable posts already returned above, so a remaining
		 * public status is public but not viewable and should require edit access.
		 */
		if ( $post_status_object->public ) {
			return current_user_can( 'edit_post', $post->ID );
		}

		/*
		 * For non-public statuses, defer to Core's meta-capability mapping. This
		 * handles own drafts, private posts, and statuses that require edit access.
		 */
		if ( current_user_can( 'read_post', $post->ID ) ) {
			return true;
		}

		/*
		 * Mirror the REST posts controller's inherited-parent behavior, but keep the
		 * ability fail-closed for missing parents or parent loops.
		 */
		if (
			'inherit' === $post->post_status &&
			$post->post_parent > 0 &&
			(int) $post->post_parent !== (int) $post->ID
		) {
			$parent = get_post( $post->post_parent );
			if ( $parent instanceof WP_Post ) {
				return $this->check_read_permission( $parent, $checked_post_ids );
			}
		}

		return false;
	}

	/**
	 * Executes the `core/content-query` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::check_permission()} first, so the
	 * single-post modes only re-validate the lookup itself: existence, exposure, and a
	 * matching post type. Query mode still filters every row by read or edit permission,
	 * because the gate cannot resolve rows before the query runs.
	 *
	 * A post is returned as an empty object when its field projection is empty, so callers
	 * must not assume array access on a post. See {@see self::to_output_post()}.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\stdClass|\WP_Error A single post, a `posts` list with totals in query mode, or a WP_Error.
	 */
	public function execute_content_query( $input = array() ) {
		$input         = rest_sanitize_object( $input );
		$fields        = $this->normalize_fields( $input );
		$requires_edit = $this->has_explicit_edit_fields( $input );

		// Single-post mode (by ID).
		if ( ! empty( $input['id'] ) ) {
			$post = $this->get_content_by_id( $input );
			if ( ! $post ) {
				return $this->not_found_error();
			}

			return $this->to_output_post( $this->format_post( $post, $fields ) );
		}

		// Single-post mode (by slug) and query mode.
		$post_type_object = $this->get_exposed_post_type( $input['post_type'] ?? null );
		if ( ! $post_type_object ) {
			return $this->not_found_error();
		}

		$post_type = $post_type_object->name;
		if ( isset( $input['slug'] ) && is_string( $input['slug'] ) && '' !== $input['slug'] ) {
			$post = $this->get_post_by_slug( $post_type, $input['slug'] );

			if ( ! $post ) {
				return $this->not_found_error();
			}

			return $this->to_output_post( $this->format_post( $post, $fields ) );
		}

		/*
		 * REST only registers the equivalent collection filters for post types that
		 * support them; a shared input schema cannot express that per post type. On
		 * transports that skip schema validation a malformed value would otherwise
		 * coerce to a benign default and silently *widen* the query (`author => 0`
		 * drops the author filter, an empty `post__in` is ignored, `post_parent => 0`
		 * becomes a top-level query). Reject unsupported filters and invalid filter
		 * values loudly so a filter that cannot be honored fails closed instead.
		 */
		$parent = null;
		if ( isset( $input['parent'] ) ) {
			if ( ! is_post_type_hierarchical( $post_type ) ) {
				return new WP_Error(
					'content_invalid_filter',
					__( 'The parent filter is only supported for hierarchical post types.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			$parent = $this->parse_filter_int( $input['parent'], 0 );
			if ( null === $parent ) {
				return new WP_Error(
					'content_invalid_filter',
					__( 'The parent filter must be a non-negative integer.', 'ai' ),
					array( 'status' => 400 )
				);
			}
		}

		$author = null;
		if ( isset( $input['author_slug'] ) ) {
			if ( ! post_type_supports( $post_type, 'author' ) ) {
				return new WP_Error(
					'content_invalid_filter',
					__( 'The author_slug filter is only supported for post types that support authors.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			$author = $this->get_author_by_slug( $input['author_slug'], $post_type_object );
			if ( ! $author ) {
				return new WP_Error(
					'content_invalid_filter',
					__( 'The author_slug filter must be the slug of an existing user.', 'ai' ),
					array( 'status' => 400 )
				);
			}
		}

		$include = $this->normalize_include( $input );

		/*
		 * An include filter that was supplied but parsed to no valid IDs must not fall
		 * through to an unrestricted query: WP_Query ignores an empty `post__in`, which
		 * would return every post of the type — the opposite of the caller's intent.
		 */
		if ( isset( $input['include'] ) && array() === $include ) {
			return new WP_Error(
				'content_invalid_filter',
				__( 'The include filter must list one or more valid post IDs.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$per_page = $this->normalize_per_page( $input, $include );
		$page     = isset( $input['page'] ) ? max( 1, $this->input_int( $input['page'] ) ) : 1;

		$prime_post_caches = $this->should_prime_post_caches( $fields );

		/*
		 * `orderby` is left unset, which orders by `post_date` descending, matching the
		 * default of the REST posts controller.
		 */
		$query_args = array(
			'post_type'              => $post_type,
			'post_status'            => $this->normalize_statuses( $input ),
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'perm'                   => $requires_edit ? 'editable' : 'readable',
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => $prime_post_caches,
			'update_post_term_cache' => $prime_post_caches,
		);

		if ( array() !== $include ) {
			$query_args['post__in'] = $include;
		}

		if ( null !== $author ) {
			$query_args['author'] = $author->ID;
		}

		if ( null !== $parent ) {
			$query_args['post_parent'] = $parent;
		}

		$query       = new WP_Query( $query_args );
		$total       = $this->get_query_total( $query, $query_args, $page );
		$total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;

		/*
		 * Paging past the last page is a caller error rather than an empty collection, so
		 * report it instead of returning a bare empty list. A genuinely empty result set
		 * still returns zero totals and no error.
		 */
		if ( $total > 0 && $page > $total_pages ) {
			return new WP_Error(
				'content_invalid_page_number',
				__( 'The page number requested is larger than the number of pages available.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * Prime the author caches with a single query instead of one user lookup
		 * per post, mirroring the REST posts controller. Besides `author_slug`,
		 * permalinks read the author when the permalink structure contains `%author%`.
		 */
		$author_fields = array_intersect( array( 'author_slug', 'link' ), $fields );
		if ( array() !== $author_fields && post_type_supports( $post_type, 'author' ) ) {
			$query_posts = array_filter(
				$query->posts,
				static function ( $queried_post ): bool {
					return $queried_post instanceof WP_Post;
				}
			);
			update_post_author_caches( $query_posts );
		}

		$posts = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			if ( $requires_edit && ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			if ( ! $requires_edit && ! $this->check_read_permission( $post ) ) {
				continue;
			}
			// Keep rows whose field projection is empty so a caller can still count them.
			$posts[] = $this->to_output_post( $this->format_post( $post, $fields ) );
		}

		/*
		 * Mirror the REST posts controller: totals come from the underlying WP_Query,
		 * while row-level permission checks above may withhold individual returned rows.
		 */
		return array(
			'posts'       => $posts,
			'total'       => $total,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Normalizes the requested per-page value to the supported bounds.
	 *
	 * An explicit `per_page` always wins. Otherwise an `include` request pages to the
	 * number of requested IDs, so a caller loading a known set of posts receives all of
	 * them in one call rather than silently losing the ones past the default page size.
	 * The input schema caps `include` at {@see self::MAX_PER_PAGE} so it always fits.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $input       The ability input.
	 * @param list<int>    $include_ids Normalized included post IDs; empty when not requested.
	 * @return int The clamped per-page value.
	 */
	private function normalize_per_page( array $input, array $include_ids = array() ): int {
		if ( isset( $input['per_page'] ) ) {
			return max( 1, min( self::MAX_PER_PAGE, $this->input_int( $input['per_page'] ) ) );
		}

		if ( array() !== $include_ids ) {
			return max( 1, min( self::MAX_PER_PAGE, count( $include_ids ) ) );
		}

		return self::DEFAULT_PER_PAGE;
	}

	/**
	 * Returns the query total, recovering it when WP_Query skipped the count.
	 *
	 * WP_Query leaves `found_posts` at 0 when a requested page has no rows. Re-run a
	 * minimal unpaged query so the caller can distinguish an out-of-range page from
	 * an empty result set, matching the REST posts controller behavior.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_Query    $query      The executed query.
	 * @param array<mixed> $query_args The arguments used for the executed query.
	 * @param int          $page       The requested page.
	 * @return int Total matching rows across all pages.
	 */
	private function get_query_total( WP_Query $query, array $query_args, int $page ): int {
		$total = (int) $query->found_posts;

		if ( $total > 0 || $page <= 1 ) {
			return $total;
		}

		$count_args                           = $query_args;
		$count_args['fields']                 = 'ids';
		$count_args['posts_per_page']         = 1;
		$count_args['update_post_meta_cache'] = false;
		$count_args['update_post_term_cache'] = false;
		unset( $count_args['paged'], $count_args['no_found_rows'] );

		$count_query = new WP_Query( $count_args );

		return (int) $count_query->found_posts;
	}

	/**
	 * Checks whether requested fields benefit from page-level cache priming.
	 *
	 * @since 1.2.0
	 *
	 * @param list<string> $fields The requested field names.
	 * @return bool True when post meta and term caches should be primed.
	 */
	private function should_prime_post_caches( array $fields ): bool {
		return array() !== array_intersect( $this->cache_priming_fields, $fields );
	}

	/**
	 * Looks up the single post an ID request resolves to.
	 *
	 * The post must exist, belong to a post type exposed to abilities, and match the
	 * `post_type` guard when one is given. As with the integer filters, only an integer or
	 * an unsigned integer string is accepted, so a malformed ID or one beyond the integer
	 * range cannot be coerced onto another post.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return \WP_Post|null The post, or null when it cannot be resolved.
	 */
	private function get_content_by_id( array $input ): ?WP_Post {
		$post_id = isset( $input['id'] ) ? $this->parse_filter_int( $input['id'], 1 ) : null;
		$post    = null === $post_id ? null : get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! $this->get_exposed_post_type( $post->post_type ) ) {
			return null;
		}

		return empty( $input['post_type'] ) || $post->post_type === $input['post_type'] ? $post : null;
	}

	/**
	 * Looks up the user an author slug names.
	 *
	 * The slug is the user's nicename, which the REST API users endpoint returns as `slug`. It
	 * must match exactly one user. A user the current user may not see is reported like a
	 * missing one. The current user can see themselves, any user of the site when they can list
	 * users, and authors with posts in a publicly viewable post type. A user who can edit others'
	 * posts of the post type can also see any user of the site, since they may make any of them
	 * the author, so for them the lookup does tell whether such an account exists.
	 *
	 * On multisite the lookup searches the whole network, so posts by authors who are not
	 * members of the site, such as super admins, can still be filtered. Those users are only
	 * visible as the current user or as authors with posts in a publicly viewable post type, so
	 * the lookup does not tell whether an account exists elsewhere on the network.
	 *
	 * @since x.x.x
	 *
	 * @param mixed         $slug             The author slug.
	 * @param \WP_Post_Type $post_type_object The post type the author is looked up for.
	 * @return \WP_User|null The user, or null when the slug does not name exactly one visible user.
	 */
	private function get_author_by_slug( $slug, \WP_Post_Type $post_type_object ): ?\WP_User {
		if ( ! is_string( $slug ) || '' === $slug ) {
			return null;
		}

		// The database compares nicenames without regard to case, so keep the exact matches only.
		$users = array_values(
			array_filter(
				get_users(
					array(
						'blog_id'     => 0,
						'nicename'    => $slug,
						'count_total' => false,
					)
				),
				static function ( $user ) use ( $slug ): bool {
					return $user instanceof \WP_User && $user->user_nicename === $slug;
				}
			)
		);

		if ( 1 !== count( $users ) ) {
			return null;
		}

		$user = $users[0];
		if ( get_current_user_id() === $user->ID ) {
			return $user;
		}

		// The capabilities only reveal users of the site, not of the whole network.
		if ( ( ! is_multisite() || is_user_member_of_blog( $user->ID ) )
			&& ( current_user_can( 'list_users' ) || current_user_can( $post_type_object->cap->edit_others_posts ) ) // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
		) {
			return $user;
		}

		$public_post_types = array_values( array_filter( get_post_types(), 'is_post_type_viewable' ) );

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts -- Public-author checks only consider publicly viewable post types.
		return array() !== $public_post_types && count_user_posts( $user->ID, $public_post_types ) > 0 ? $user : null;
	}

	/**
	 * Looks up the single post a slug request resolves to.
	 *
	 * Slugs are not unique across statuses (drafts skip slug uniqueness), so the
	 * lookup returns the newest match the current user can read, preferring
	 * publicly viewable posts — a newer draft sharing the slug cannot shadow a
	 * published post. This mirrors the REST API, where slug queries default to
	 * the `publish` status. Which post a slug resolves to is independent of the
	 * requested fields; edit-field requests are gated afterwards on the resolved
	 * post by {@see self::check_permission()}.
	 *
	 * In hierarchical post types, posts under different parents can also share a
	 * slug. The lookup cannot tell them apart and resolves to one of them by the
	 * same rules, so callers that need a specific post should look it up by ID.
	 *
	 * @since 1.2.0
	 *
	 * @param string $post_type The post type.
	 * @param string $slug      The post slug.
	 * @return \WP_Post|null The matching readable post, or null when none exists.
	 */
	private function get_post_by_slug( string $post_type, string $slug ): ?WP_Post {
		$name = sanitize_title( $slug );
		if ( '' === $name ) {
			return null;
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'name'                   => $name,
				'post_status'            => array_values( get_post_stati( array( 'internal' => false ) ) ),
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$viewable = array();
		$hidden   = array();
		foreach ( $query->posts as $candidate ) {
			if ( ! $candidate instanceof WP_Post ) {
				continue;
			}

			if ( is_post_publicly_viewable( $candidate ) ) {
				$viewable[] = $candidate;
				continue;
			}

			$hidden[] = $candidate;
		}

		// Both groups keep the query's newest-first ordering.
		foreach ( array_merge( $viewable, $hidden ) as $candidate ) {
			if ( ! $this->check_read_permission( $candidate ) ) {
				continue;
			}

			return $candidate;
		}

		return null;
	}

	/**
	 * Returns a post type exposed through the Abilities API.
	 *
	 * Deliberately resolved on every call rather than cached: post types can be
	 * unregistered or re-registered with different arguments between the ability
	 * being registered and the ability being used.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $post_type The post type name.
	 * @return \WP_Post_Type|null The post type object, or null when the post type is not exposed.
	 */
	private function get_exposed_post_type( $post_type ): ?\WP_Post_Type {
		$post_type_object = is_string( $post_type ) ? get_post_type_object( $post_type ) : null;

		return empty( $post_type_object->show_in_abilities ) ? null : $post_type_object;
	}

	/**
	 * Normalizes the requested statuses to a non-empty, sanitized list defaulting to publish.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $input The ability input.
	 * @return list<string> Normalized list of post status slugs.
	 */
	private function normalize_statuses( array $input ): array {
		$statuses = $this->parse_list_input( $input, 'status' );

		return array() === $statuses ? array( 'publish' ) : array_map( 'sanitize_key', $statuses );
	}

	/**
	 * Normalizes query-mode included post IDs.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $input The ability input.
	 * @return list<int> Unique positive post IDs.
	 */
	private function normalize_include( array $input ): array {
		$include = $input['include'] ?? null;
		if ( ! is_array( $include ) && ! is_string( $include ) ) {
			return array();
		}

		/*
		 * Schema validation also accepts a comma-separated string, which callers that
		 * bypass the REST run controller can pass; wp_parse_id_list() accepts both forms
		 * and yields unique positive IDs.
		 *
		 * Plugin: the REST run controller only converts input since WordPress 7.1, so on
		 * 7.0 a GET request can pass one too.
		 */
		return array_values( array_filter( wp_parse_id_list( $include ) ) );
	}

	/**
	 * Returns the requested fields, or a lean default set when none are given.
	 *
	 * An empty or absent `fields` value selects a lean set of common read fields.
	 * Otherwise the requested fields are returned as-is. The input schema has already
	 * validated them against the supported set before the ability executes.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $input The ability input.
	 * @return list<string> List of requested field names.
	 */
	private function normalize_fields( array $input ): array {
		$fields = $this->parse_list_input( $input, 'fields' );

		return array() === $fields ? $this->default_fields : $fields;
	}

	/**
	 * Returns the post field definitions, keyed by field name in output order.
	 *
	 * This is the single source of truth for the ability's post fields: the output
	 * schema uses the definitions directly, while the input schema fields enum uses
	 * the keys. Read-context fields are returned for readable posts; the edit-context
	 * fields listed in {@see self::$edit_fields} additionally require edit access.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, mixed> Post field definitions.
	 */
	private function get_post_properties(): array {
		if ( null !== $this->post_properties ) {
			return $this->post_properties;
		}

		$this->post_properties = array(
			'id'                => array(
				'type'        => 'integer',
				'description' => __( 'The post ID.', 'ai' ),
			),
			'post_type'         => array(
				'type'        => 'string',
				'description' => __( 'The post type.', 'ai' ),
			),
			'status'            => array(
				'type'        => 'string',
				'description' => __( 'The post status.', 'ai' ),
			),
			'date'              => array(
				'type'        => 'string',
				'description' => __( "The publication date, in ISO 8601 format using the site's timezone. Empty string when the date cannot be resolved.", 'ai' ),
			),
			'date_gmt'          => array(
				'type'        => 'string',
				'description' => __( 'The publication date, in ISO 8601 format as GMT. Empty string when the date cannot be resolved.', 'ai' ),
			),
			'modified'          => array(
				'type'        => 'string',
				'description' => __( "The last modified date, in ISO 8601 format using the site's timezone. Empty string when the date cannot be resolved.", 'ai' ),
			),
			'modified_gmt'      => array(
				'type'        => 'string',
				'description' => __( 'The last modified date, in ISO 8601 format as GMT. Empty string when the date cannot be resolved.', 'ai' ),
			),
			'slug'              => array(
				'type'        => 'string',
				'description' => __( 'The post slug.', 'ai' ),
			),
			'link'              => array(
				'type'        => 'string',
				'description' => __( 'The permalink URL.', 'ai' ),
			),
			'title_raw'         => array(
				'type'        => 'string',
				'description' => __( 'The raw post title. Present when the post type supports titles and the current user can edit the post.', 'ai' ),
			),
			'title_rendered'    => array(
				'type'        => 'string',
				'description' => __( 'The rendered post title. Present when the post type supports titles.', 'ai' ),
			),
			'excerpt_raw'       => array(
				'type'        => 'string',
				'description' => __( 'The raw post excerpt. Present when the post type supports excerpts and the current user can edit the post.', 'ai' ),
			),
			'excerpt_rendered'  => array(
				'type'        => 'string',
				'description' => __( 'The rendered post excerpt (HTML). Present when the post type supports excerpts. Empty when withheld for a password-protected post.', 'ai' ),
			),
			'excerpt_protected' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the excerpt is protected with a password. Present when the post type supports excerpts.', 'ai' ),
			),
			'content_raw'       => array(
				'type'        => 'string',
				'description' => __( 'The raw, unfiltered post content (block markup). Present when the post type supports the editor and the current user can edit the post.', 'ai' ),
			),
			'content_rendered'  => array(
				'type'        => 'string',
				'description' => __( 'The rendered post content. Present when the post type supports the editor. Empty when withheld for a password-protected post.', 'ai' ),
			),
			'content_protected' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the content is protected with a password. Present when the post type supports the editor.', 'ai' ),
			),
			'author_slug'       => array(
				'type'        => 'string',
				// Plugin: core names the REST API users endpoint instead, since it has no core/users-query yet.
				'description' => __( "The author's user slug, as core/users-query returns it. Present when the post type supports authors. Empty when the author no longer exists.", 'ai' ),
			),
			'parent'            => array(
				'type'        => 'integer',
				'description' => __( 'The parent post ID. Present for hierarchical post types.', 'ai' ),
			),
		);

		return $this->post_properties;
	}

	/**
	 * Builds the schema of the `fields` input, shared by all content abilities.
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
				'enum' => array_keys( $this->get_post_properties() ),
			),
			'description' => __( 'Limit each returned post to these fields. If omitted, a lean set of common read fields is returned. Explicit raw field requests require edit access.', 'ai' ),
		);
	}

	/**
	 * Builds the input schema for the `core/content-query` ability.
	 *
	 * The ability has three mutually exclusive modes, modeled as a `oneOf` so invalid
	 * combinations are rejected rather than silently ignored:
	 *
	 *   - Get a single post by `id` (optionally guarded by `post_type`).
	 *   - Get a single post by `post_type` and `slug`.
	 *   - Query a set of posts by `post_type` plus filters (`status`, `author_slug`, `parent`,
	 *     `include`, `page`, `per_page`).
	 *
	 * Each mode sets `additionalProperties: false`, so e.g. passing `per_page` alongside `id`
	 * fails validation instead of being dropped. `fields` is accepted in every mode.
	 *
	 * @since 1.2.0
	 *
	 * @param list<string> $post_types Exposed post type names.
	 * @param list<string> $statuses   Requestable post status slugs.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_content_query_input_schema( array $post_types, array $statuses ): array {
		$fields  = $this->get_fields_input_schema();
		$include = array(
			'type'        => 'array',
			'minItems'    => 1,
			'maxItems'    => self::MAX_PER_PAGE,
			'uniqueItems' => true,
			'items'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'description' => __( 'Limit the query to these post IDs. The order of the IDs does not affect the order of the results. If `per_page` is omitted, the page size defaults to the number of included IDs, capped at the maximum.', 'ai' ),
		);

		return array(
			'type'  => 'object',
			'oneOf' => array(
				// Mode 1: retrieve a single readable post by ID.
				array(
					'title'                => __( 'Get a single readable post by ID', 'ai' ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'        => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Retrieve a single readable post by ID.', 'ai' ),
						),
						'post_type' => array(
							'type'        => 'string',
							'enum'        => $post_types,
							'description' => __( 'Optional. Restrict the lookup to this post type; the post is returned only if it matches and the current user can read it.', 'ai' ),
						),
						'fields'    => $fields,
					),
				),
				// Mode 2: retrieve a single readable post by post type and slug.
				array(
					'title'                => __( 'Get a single readable post by slug', 'ai' ),
					'required'             => array( 'post_type', 'slug' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_type' => array(
							'type'        => 'string',
							'enum'        => $post_types,
							'description' => __( 'Post type containing the slug. Slugs are not unique across post types.', 'ai' ),
						),
						'slug'      => array(
							'type'        => 'string',
							'minLength'   => 1,
							'description' => __( 'Retrieve a single readable post by slug. Resolves to the newest readable match, preferring published posts. In hierarchical post types, posts under different parents can share a slug; use `id` to get a specific one.', 'ai' ),
						),
						'fields'    => $fields,
					),
				),
				// Mode 3: query a set of readable posts by post type and filters.
				array(
					'title'                => __( 'Query readable posts by post type and filters', 'ai' ),
					'required'             => array( 'post_type' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_type'   => array(
							'type'        => 'string',
							'enum'        => $post_types,
							'description' => __( 'Post type to query for readable posts.', 'ai' ),
						),
						'status'      => array(
							'type'        => 'array',
							'uniqueItems' => true,
							'items'       => array(
								'type' => 'string',
								'enum' => $statuses,
							),
							'description' => __( 'Filter readable posts by one or more post statuses. Defaults to publish. Non-published statuses require the appropriate capabilities.', 'ai' ),
						),
						'author_slug' => array(
							'type'        => 'string',
							'minLength'   => 1,
							// Plugin: core names the REST API users endpoint instead, since it has no core/users-query yet.
							'description' => __( "Filter by the author's user slug, as core/users-query returns it. Only supported for post types that support authors.", 'ai' ),
						),
						'parent'      => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Filter by parent post ID. Only supported for hierarchical post types. Use 0 for top-level posts.', 'ai' ),
						),
						'include'     => $include,
						'fields'      => $fields,
						'page'        => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Page of results to return. Requesting a page beyond the last one is an error. Check `total_pages` before requesting later pages.', 'ai' ),
						),
						'per_page'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => self::MAX_PER_PAGE,
							'description' => __( 'Maximum number of posts to return per page.', 'ai' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Builds the output schema of a single post, shared by all content abilities.
	 *
	 * No field is marked required because the `fields` input lets the caller request any
	 * subset, and a field is only present when its post type supports it.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The post JSON Schema.
	 */
	private function get_content_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $this->get_post_properties(),
		);
	}

	/**
	 * Builds the output schema for the `core/content-query` ability.
	 *
	 * No field is marked required because the `fields` input lets the caller request any
	 * subset, and a field is only present when its post type supports it. Single-post
	 * mode returns the post object directly, while query mode returns a paginated wrapper.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_content_query_output_schema(): array {
		$post_schema = $this->get_content_output_schema();

		$query_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'posts', 'total', 'total_pages' ),
			'properties'           => array(
				'posts'       => array(
					'type'        => 'array',
					'description' => __( 'The readable posts matching the query, ordered by post date, newest first.', 'ai' ),
					'items'       => $post_schema,
				),
				'total'       => array(
					'type'        => 'integer',
					'description' => __( 'Total number of posts matching the underlying query, across all pages. May exceed the number of returned posts when row-level permission checks withhold some of them.', 'ai' ),
				),
				'total_pages' => array(
					'type'        => 'integer',
					'description' => __( 'Total number of query result pages available for the underlying query. May include pages whose rows are withheld by row-level permission checks.', 'ai' ),
				),
			),
		);

		return array(
			'type'  => 'object',
			'oneOf' => array(
				$post_schema,
				$query_schema,
			),
		);
	}

	/**
	 * Prepares a formatted post for output.
	 *
	 * A field projection can legitimately be empty, for example when the only requested
	 * field is one the post type does not support. An empty PHP array encodes as `[]`,
	 * which would break the `object` output schema, so return an empty object instead.
	 *
	 * This deliberately improves on the REST posts controller, which encodes the same
	 * case as `[]` even though it types the response as an object
	 * (`GET /wp/v2/posts/<id>?_fields=parent` on a non-hierarchical post type).
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, mixed> $formatted The formatted post data.
	 * @return array<string, mixed>|\stdClass The post data, or an empty object when the projection is empty.
	 */
	private function to_output_post( array $formatted ) {
		return array() === $formatted ? (object) array() : $formatted;
	}

	/**
	 * Formats a post into the ability output shape.
	 *
	 * For an editor of a password-protected post, the cookie-based password gate is suspended
	 * while the fields are built so rendered fields resolve to real values instead of
	 * protected-post placeholders. The field projection itself is delegated to
	 * {@see self::build_post_fields()}.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_Post $post   The post object.
	 * @param list<string> $fields The requested field names.
	 * @return array<string, mixed> The formatted post data.
	 */
	private function format_post( WP_Post $post, array $fields ): array {
		$can_edit          = current_user_can( 'edit_post', $post->ID );
		$password_required = post_password_required( $post );
		$protected         = $password_required && ! $can_edit;

		/*
		 * Suspend the cookie-based password gate for an editor of this protected post, so
		 * helpers with their own gate (e.g. get_the_excerpt()) resolve the real values. The
		 * filter unlocks only posts the current user can edit, mirroring the REST posts
		 * controller's check_password_required(): an unconditional bypass (e.g. __return_false)
		 * would also expose other protected posts that the content filter may render, such as
		 * posts pulled in by a Query Loop block. The filter is removed in a finally block so a
		 * throw mid-render cannot leave the gate globally disabled for the rest of the request.
		 */
		if ( $password_required && $can_edit ) {
			add_filter( 'post_password_required', array( $this, 'allow_password_content' ), 10, 2 );

			try {
				return $this->build_post_fields( $post, $fields, $can_edit, $protected );
			} finally {
				remove_filter( 'post_password_required', array( $this, 'allow_password_content' ), 10 );
			}
		}

		return $this->build_post_fields( $post, $fields, $can_edit, $protected );
	}

	/**
	 * Builds the requested field projection for a post.
	 *
	 * Only the requested fields that the post type supports and the current user can see are
	 * included. Raw fields are edit-context fields; rendered fields are read-context fields and
	 * are withheld for password-protected posts unless the current user can edit the post,
	 * mirroring the REST API behavior.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_Post $post         The post object.
	 * @param list<string> $fields       The requested field names.
	 * @param bool     $can_edit     Whether the current user can edit the post.
	 * @param bool     $is_protected Whether rendered fields must be withheld as password-protected.
	 * @return array<string, mixed> The formatted post data.
	 */
	private function build_post_fields( WP_Post $post, array $fields, bool $can_edit, bool $is_protected ): array {
		$post_type = $post->post_type;

		// Edit-context fields require edit access; drop them so $edit_fields is the single gate.
		if ( ! $can_edit ) {
			$fields = array_diff( $fields, $this->edit_fields );
		}

		$requested = array_flip( $fields );
		$data      = array();

		if ( isset( $requested['id'] ) ) {
			$data['id'] = (int) $post->ID;
		}
		if ( isset( $requested['post_type'] ) ) {
			$data['post_type'] = $post_type;
		}
		if ( isset( $requested['status'] ) ) {
			$data['status'] = $post->post_status;
		}
		if ( isset( $requested['date'] ) ) {
			$data['date'] = $this->format_local_date( $post, 'date' );
		}
		if ( isset( $requested['date_gmt'] ) ) {
			$data['date_gmt'] = $this->format_gmt_date( $post, 'date' );
		}
		if ( isset( $requested['modified'] ) ) {
			$data['modified'] = $this->format_local_date( $post, 'modified' );
		}
		if ( isset( $requested['modified_gmt'] ) ) {
			$data['modified_gmt'] = $this->format_gmt_date( $post, 'modified' );
		}
		if ( isset( $requested['slug'] ) ) {
			$data['slug'] = $post->post_name;
		}
		if ( isset( $requested['link'] ) ) {
			$data['link'] = (string) get_permalink( $post );
		}

		if ( isset( $requested['title_raw'] ) && post_type_supports( $post_type, 'title' ) ) {
			$data['title_raw'] = $post->post_title;
		}

		if ( isset( $requested['title_rendered'] ) && post_type_supports( $post_type, 'title' ) ) {
			$data['title_rendered'] = $this->get_title( $post );
		}

		if ( isset( $requested['excerpt_raw'] ) && post_type_supports( $post_type, 'excerpt' ) ) {
			$data['excerpt_raw'] = $post->post_excerpt;
		}

		if ( isset( $requested['excerpt_rendered'] ) && post_type_supports( $post_type, 'excerpt' ) ) {
			$data['excerpt_rendered'] = $is_protected ? '' : $this->get_rendered_excerpt( $post );
		}

		if ( isset( $requested['excerpt_protected'] ) && post_type_supports( $post_type, 'excerpt' ) ) {
			$data['excerpt_protected'] = (bool) $post->post_password;
		}

		if ( isset( $requested['content_raw'] ) && post_type_supports( $post_type, 'editor' ) ) {
			$data['content_raw'] = $post->post_content;
		}

		if ( isset( $requested['content_rendered'] ) && post_type_supports( $post_type, 'editor' ) ) {
			$data['content_rendered'] = $is_protected ? '' : $this->get_rendered_content( $post );
		}

		if ( isset( $requested['content_protected'] ) && post_type_supports( $post_type, 'editor' ) ) {
			$data['content_protected'] = (bool) $post->post_password;
		}

		if ( isset( $requested['author_slug'] ) && post_type_supports( $post_type, 'author' ) ) {
			$author              = get_userdata( (int) $post->post_author );
			$data['author_slug'] = $author ? $author->user_nicename : '';
		}

		if ( isset( $requested['parent'] ) && is_post_type_hierarchical( $post_type ) ) {
			$data['parent'] = (int) $post->post_parent;
		}

		return $data;
	}

	/**
	 * Filters {@see post_password_required()} to unlock only posts the current user can edit.
	 *
	 * Added by {@see self::format_post()} while formatting a password-protected post the
	 * current user can edit, so rendered fields resolve to real values without also unlocking
	 * other protected posts that the content filter may render. Mirrors the REST posts
	 * controller's check_password_required().
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $required Whether the post currently requires a password.
	 * @param mixed $post     The post being checked; a WP_Post when invoked by the core filter.
	 * @return bool Whether the post still requires a password.
	 */
	public function allow_password_content( $required, $post ): bool {
		if ( ! $required || ! $post instanceof WP_Post ) {
			return (bool) $required;
		}

		return ! current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Returns the post title with the protected/private prefixes stripped.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_Post $post The post object.
	 * @return string The post title.
	 */
	private function get_title( WP_Post $post ): string {
		$strip = array( $this, 'return_raw_title_format' );
		add_filter( 'protected_title_format', $strip );
		add_filter( 'private_title_format', $strip );

		/*
		 * The format filters are removed in a finally block so a throw from a title
		 * filter cannot leave them attached for the rest of the request.
		 */
		try {
			$title = get_the_title( $post );

			/*
			 * A title filter that returns a non-string would fail the return type, so guard
			 * it as the excerpt and content are.
			 */
			return is_string( $title ) ? $title : '';
		} finally {
			remove_filter( 'protected_title_format', $strip );
			remove_filter( 'private_title_format', $strip );
		}
	}

	/**
	 * Returns the raw title format, used to strip protected/private title prefixes.
	 *
	 * @since 1.2.0
	 *
	 * @return string The unprefixed title format.
	 */
	public function return_raw_title_format(): string {
		return '%s';
	}

	/**
	 * Returns the post excerpt transformed for display.
	 *
	 * Mirrors the REST posts controller by preparing post globals before applying
	 * the `get_the_excerpt` and `the_excerpt` filter chains, then restoring the
	 * previous global post context. This ensures filters that rely on loop globals
	 * render against the requested post.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_Post $post The post object.
	 * @return string Rendered post excerpt.
	 */
	private function get_rendered_excerpt( WP_Post $post ): string {
		$previous_context = $this->set_up_post_context( $post );

		/*
		 * The global post context is restored in a finally block so a throw from an
		 * excerpt filter cannot leave it pointing at the rendered post for the rest
		 * of the request.
		 */
		try {
			/** This filter is documented in wp-includes/post-template.php */
			$excerpt = apply_filters( 'get_the_excerpt', $post->post_excerpt, $post ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying the core excerpt filter to mirror REST rendering.

			/** This filter is documented in wp-includes/post-template.php */
			$excerpt = apply_filters( 'the_excerpt', $excerpt ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying the core excerpt filter to mirror REST rendering.

			return is_string( $excerpt ) ? $excerpt : '';
		} finally {
			$this->restore_post_context( $previous_context );
		}
	}

	/**
	 * Returns post content transformed for display.
	 *
	 * Mirrors the REST posts controller by preparing post globals before applying
	 * `the_content`, then restoring the previous global post context.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_Post $post The post object.
	 * @return string Rendered post content.
	 */
	private function get_rendered_content( WP_Post $post ): string {
		$previous_context = $this->set_up_post_context( $post );

		/*
		 * The global post context is restored in a finally block so a throw from a
		 * content filter cannot leave it pointing at the rendered post for the rest
		 * of the request.
		 */
		try {
			/** This filter is documented in wp-includes/post-template.php */
			$content = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying the core content filter to mirror REST rendering.

			return is_string( $content ) ? $content : '';
		} finally {
			$this->restore_post_context( $previous_context );
		}
	}

	/**
	 * Sets up the global post context for rendering a post.
	 *
	 * Sets the global post and calls setup_postdata(), as the REST posts controller does
	 * before it renders a post, so filters that rely on loop globals render against the
	 * requested post.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The post to render.
	 * @return array<string, mixed> The previous loop globals, keyed by name, leaving out those that were not set.
	 */
	private function set_up_post_context( WP_Post $post ): array {
		$previous_context = array();
		foreach ( $this->loop_globals as $name ) {
			if ( ! array_key_exists( $name, $GLOBALS ) ) {
				continue;
			}

			$previous_context[ $name ] = $GLOBALS[ $name ];
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily mirrors REST post context for rendering.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		return $previous_context;
	}

	/**
	 * Restores the global post context saved by {@see self::set_up_post_context()}.
	 *
	 * Each loop global gets its previous value back, or is unset again when it was not set.
	 * wp_reset_postdata() alone is not enough: it does nothing when the main query has no
	 * post, which would leave the rendered post's data in the globals. When a post was set
	 * up before, setup_postdata() first runs for it again, as wp_reset_postdata() would, so
	 * callbacks on the `the_post` action can restore their own globals too.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $previous_context The loop globals that set_up_post_context() returned.
	 */
	private function restore_post_context( array $previous_context ): void {
		$previous_post = $previous_context['post'] ?? null;
		if ( $previous_post instanceof WP_Post ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous global post context.
			$GLOBALS['post'] = $previous_post;
			setup_postdata( $previous_post );
		}

		foreach ( $this->loop_globals as $name ) {
			if ( array_key_exists( $name, $previous_context ) ) {
				$GLOBALS[ $name ] = $previous_context[ $name ]; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restores the core loop globals.
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
	}

	/**
	 * Formats a post date field as an ISO 8601 string in the site's timezone.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_Post $post  The post object.
	 * @param string   $field Either 'date' or 'modified'. Default 'date'.
	 * @return string The ISO 8601 date, or an empty string if unavailable.
	 */
	private function format_local_date( WP_Post $post, string $field = 'date' ): string {
		$field    = 'modified' === $field ? 'modified' : 'date';
		$datetime = get_post_datetime( $post, $field, 'local' );

		return $datetime ? $datetime->format( 'c' ) : '';
	}

	/**
	 * Formats a post date field as an ISO 8601 string in GMT.
	 *
	 * Reads the stored GMT date directly, deriving it from the local date when missing
	 * (e.g. drafts), mirroring the REST posts controller. get_post_datetime() is avoided
	 * here because it reprojects even GMT-sourced dates into the site timezone, which
	 * would label the returned instant with the site offset instead of UTC.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_Post $post  The post object.
	 * @param string   $field Either 'date' or 'modified'. Default 'date'.
	 * @return string The ISO 8601 date, or an empty string if unavailable.
	 */
	private function format_gmt_date( WP_Post $post, string $field = 'date' ): string {
		$field = 'modified' === $field ? 'modified' : 'date';
		$gmt   = 'modified' === $field ? $post->post_modified_gmt : $post->post_date_gmt;

		if ( ! $this->is_usable_date( $gmt ) ) {
			$local = 'modified' === $field ? $post->post_modified : $post->post_date;
			$gmt   = $this->is_usable_date( $local ) ? get_gmt_from_date( $local ) : '';
		}

		/*
		 * Guard the empty string before `strtotime()`: `strtotime( ' UTC' )` resolves to the
		 * current time, which would report a fabricated date instead of the documented
		 * empty-string sentinel.
		 */
		$timestamp = '' === $gmt ? false : strtotime( $gmt . ' UTC' );

		return false === $timestamp ? '' : gmdate( 'c', $timestamp );
	}

	/**
	 * Checks whether a raw post date column holds a usable date.
	 *
	 * The columns are `NOT NULL` in core's schema, but a post object can reach this class
	 * from a filter or an in-memory row where a date is null or a zero date.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $date The raw date column value.
	 * @return bool True when the value is a non-empty, non-zero date string.
	 */
	private function is_usable_date( $date ): bool {
		return is_string( $date ) && '' !== $date && '0000-00-00 00:00:00' !== $date;
	}

	/**
	 * Executes the `core/content-create` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::check_create_permission()} first,
	 * so this re-validates the post type lookup and leaves the rest of the input to
	 * {@see self::write_content()}, which checks it before writing the post.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\stdClass|\WP_Error The created post, or a WP_Error.
	 */
	public function execute_content_create( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$post_type_object = $this->get_exposed_post_type( $input['post_type'] ?? null );
		if ( ! $post_type_object ) {
			return $this->not_found_error();
		}

		return $this->write_content( $input, $post_type_object, null );
	}

	/**
	 * Executes the `core/content-update` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::check_update_permission()} first,
	 * so this re-validates the post lookup and leaves the rest of the input to
	 * {@see self::write_content()}, which checks it before writing the post.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\stdClass|\WP_Error The updated post, or a WP_Error.
	 */
	public function execute_content_update( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$post_before = $this->get_content_by_id( $input );
		if ( ! $post_before ) {
			return $this->not_found_error();
		}

		// get_content_by_id() only returns posts of an exposed post type, so the type is registered.
		/** @var \WP_Post_Type $post_type_object */
		$post_type_object = get_post_type_object( $post_before->post_type );

		return $this->write_content( $input, $post_type_object, $post_before );
	}

	/**
	 * Creates or updates a post from the ability input.
	 *
	 * Shared by the create and update abilities.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed>  $input            The ability input.
	 * @param \WP_Post_Type $post_type_object The post type of the post being written.
	 * @param \WP_Post|null $post_before      The post being updated, or null when creating.
	 * @return array<string, mixed>|\stdClass|\WP_Error The written post, or a WP_Error.
	 */
	private function write_content( array $input, \WP_Post_Type $post_type_object, ?WP_Post $post_before ) {
		$unsupported = $this->check_unsupported_fields( $input, $post_type_object->name );
		if ( $unsupported instanceof WP_Error ) {
			return $unsupported;
		}

		/*
		 * Sending back the slug of the post's stored author is the same as leaving the field
		 * out: the author does not change, so the update is handled exactly as one without it.
		 * The slug is compared exactly with the one core/content-query returns for that author.
		 */
		$current_author = $post_before ? get_userdata( (int) $post_before->post_author ) : false;
		if ( $current_author instanceof \WP_User && isset( $input['author_slug'] ) && $current_author->user_nicename === $input['author_slug'] ) {
			unset( $input['author_slug'] );
		}

		$refused = $this->check_write_permission( $input, $post_type_object, null === $post_before );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$prepared_post = $this->prepare_content_data( $input, $post_type_object, $post_before );
		if ( $prepared_post instanceof WP_Error ) {
			return $prepared_post;
		}

		// A new post without a status is inserted as a draft.
		$post_status = ! empty( $prepared_post->post_status ) ? $prepared_post->post_status : ( $post_before ? $post_before->post_status : 'draft' );

		/*
		 * `wp_unique_post_slug()` returns the same slug for 'draft' or 'pending' posts.
		 *
		 * To ensure that a unique slug is generated, pass the post data with the 'publish' status.
		 * Pass the parent the post will have too, its current one when none is given, so a child
		 * page is compared with its siblings.
		 */
		if ( ! empty( $prepared_post->post_name ) && in_array( $post_status, array( 'draft', 'pending' ), true ) ) {
			$prepared_post->post_name = wp_unique_post_slug(
				$prepared_post->post_name,
				$post_before ? $post_before->ID : 0,
				'publish',
				$prepared_post->post_type,
				$prepared_post->post_parent ?? ( $post_before ? $post_before->post_parent : 0 )
			);
		}

		// Convert the post object to an array, otherwise wp_update_post() will expect non-escaped input.
		$post_data = wp_slash( (array) $prepared_post );
		$post_id   = $post_before ? wp_update_post( $post_data, true, false ) : wp_insert_post( $post_data, true, false );

		if ( $post_id instanceof WP_Error ) {
			$database_error = in_array( $post_id->get_error_code(), array( 'db_insert_error', 'db_update_error' ), true );
			$post_id->add_data( array( 'status' => $database_error ? 500 : 400 ) );

			return $post_id;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found_error();
		}

		wp_after_insert_post( $post, null !== $post_before, $post_before );

		/*
		 * Raw fields the current user cannot edit are left out on purpose. core/content-query
		 * refuses such a request, but the post is already written here, and an error would
		 * hide that.
		 */
		return $this->to_output_post( $this->format_post( $post, $this->normalize_fields( $input ) ) );
	}

	/**
	 * Executes the `core/content-delete` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::check_delete_permission()} first;
	 * this re-validates the lookup and, because the operation is destructive, checks the
	 * delete capability once more right before anything is removed. Without `force` the
	 * post is moved to the trash and returned; with `force` it is deleted permanently and
	 * returned as it was just before the deletion.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\stdClass|\WP_Error The trashed or deleted post, or a WP_Error.
	 */
	public function execute_content_delete( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$post = $this->get_content_by_id( $input );
		if ( ! $post ) {
			return $this->not_found_error();
		}

		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return new WP_Error(
				'content_cannot_delete',
				__( 'Sorry, you are not allowed to delete this post.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$fields = $this->normalize_fields( $input );
		$force  = isset( $input['force'] ) && rest_is_boolean( $input['force'] ) && rest_sanitize_boolean( (string) $input['force'] );

		$supports_trash = ( EMPTY_TRASH_DAYS > 0 );
		if ( 'attachment' === $post->post_type ) {
			$supports_trash = $supports_trash && constant( 'MEDIA_TRASH' ); // Read with constant(): the WordPress stubs do not define MEDIA_TRASH.
		}

		// If we're forcing, then delete permanently, returning the post as it was just before.
		if ( $force ) {
			$response = $this->to_output_post( $this->format_post( $post, $fields ) );
			$result   = wp_delete_post( $post->ID, true );
		} else {
			// If we don't support trashing for this type, error out.
			if ( ! $supports_trash ) {
				return new WP_Error(
					'content_trash_not_supported',
					__( 'The post does not support trashing. Set `force` to true to delete it permanently.', 'ai' ),
					array( 'status' => 501 )
				);
			}

			// Otherwise, only trash if we haven't already.
			if ( 'trash' === $post->post_status ) {
				return new WP_Error(
					'content_already_trashed',
					__( 'The post has already been deleted.', 'ai' ),
					array( 'status' => 410 )
				);
			}

			$result   = wp_trash_post( $post->ID );
			$post     = get_post( $post->ID );
			$response = $post instanceof WP_Post ? $this->to_output_post( $this->format_post( $post, $fields ) ) : null;
		}

		if ( ! $result || null === $response ) {
			return new WP_Error(
				'content_cannot_delete',
				__( 'The post cannot be deleted.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		return $response;
	}

	/**
	 * Returns the input properties shared by the create and update abilities, keyed by field name.
	 *
	 * Each field carries the name and type of the post field `core/content-query` returns, so
	 * a post can be read and written back unchanged. One schema serves every exposed post
	 * type, so the descriptions state which post types support a field.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> Write property definitions.
	 */
	private function get_content_write_properties(): array {
		return array(
			'title_raw'   => array(
				'type'        => 'string',
				'description' => __( 'The raw post title. Only supported for post types that support titles.', 'ai' ),
			),
			'content_raw' => array(
				'type'        => 'string',
				'description' => __( 'The raw post content, as block markup or HTML. Only supported for post types that support the editor.', 'ai' ),
			),
			'excerpt_raw' => array(
				'type'        => 'string',
				'description' => __( 'The raw post excerpt. Only supported for post types that support excerpts.', 'ai' ),
			),
			'status'      => array(
				'type'        => 'string',
				'enum'        => array_values( get_post_stati( array( 'internal' => false ) ) ),
				'description' => __( 'The post status. Defaults to draft when creating. Publishing, scheduling, making a post private, or giving it any other public status requires the publish capability for the post type.', 'ai' ),
			),
			'slug'        => array(
				'type'        => 'string',
				'description' => __( 'The post slug. Sanitized like a title, and adjusted when it collides with another post of the same type.', 'ai' ),
			),
			'date'        => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( "The publication date in ISO 8601 format. A date without a timezone offset is read in the site's timezone.", 'ai' ),
			),
			'date_gmt'    => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( 'The publication date in ISO 8601 format, as GMT. A date with a timezone offset other than `Z` or `+00:00` is converted to GMT. When `date` is also given, both must refer to the same time.', 'ai' ),
			),
			'author_slug' => array(
				'type'        => 'string',
				'minLength'   => 1,
				'description' => __( "The author's user slug, as core/users-query returns it. Assigning another user requires the capability to edit their posts. Only supported for post types that support authors.", 'ai' ),
			),
			'parent'      => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'The parent post ID: a readable post of the same type, other than the post itself or one of its descendants; 0 for a top-level post. When updating, the current parent can always be kept. Only supported for hierarchical post types.', 'ai' ),
			),
		);
	}

	/**
	 * Builds the input schema for the `core/content-create` ability.
	 *
	 * `additionalProperties: false` rejects unknown fields instead of dropping them, so e.g.
	 * passing an `id` fails validation instead of silently creating a new post.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $post_types Exposed post type names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_content_create_input_schema( array $post_types ): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_type' ),
			'additionalProperties' => false,
			'properties'           => array_merge(
				array(
					'post_type' => array(
						'type'        => 'string',
						'enum'        => $post_types,
						'description' => __( 'The post type of the post to create.', 'ai' ),
					),
				),
				$this->get_content_write_properties(),
				array( 'fields' => $this->get_fields_input_schema() )
			),
		);
	}

	/**
	 * Builds the input schema for the `core/content-update` ability from the create schema.
	 *
	 * The post is identified by `id`, and `post_type` becomes an optional guard. The status
	 * keeps the create schema's enum, so clients can see and check the statuses that can be
	 * set. A post with an internal status, such as `trash`, keeps it when the status is left
	 * out; validation rejects that status before execution, even when it is the current one.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $create_schema The input schema of the `core/content-create` ability.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_content_update_input_schema( array $create_schema ): array {
		$properties = array(
			'id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The ID of the post to update.', 'ai' ),
			),
		) + $create_schema['properties'];

		$properties['post_type']['description']   = __( 'Optional. Restrict the update to this post type; the post is only updated if it matches.', 'ai' );
		$properties['author_slug']['description'] = __( "The author's user slug, as core/users-query returns it. Assigning another user requires the capability to edit their posts, unless the post already has that author. Leave it out to keep the current author, which is the only way when the author no longer exists. Only supported for post types that support authors.", 'ai' );
		$properties['status']['description']      = __( 'The post status. Leave it out to keep the current status; a post with an internal status such as `trash` can only keep it this way. Publishing, scheduling, making a post private, or giving it any other public status requires the publish capability for the post type, unless the post already has that status.', 'ai' );
		$properties['date_gmt']['description']    = __( 'The publication date in ISO 8601 format, as GMT. A date with a timezone offset other than `Z` or `+00:00` is converted to GMT. When `date` is also given, both must refer to the same time, unless one of them is the date the post already has.', 'ai' );

		$create_schema['required']   = array( 'id' );
		$create_schema['properties'] = $properties;

		return $create_schema;
	}

	/**
	 * Builds the input schema for the `core/content-delete` ability.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $post_types Exposed post type names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_content_delete_input_schema( array $post_types ): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'additionalProperties' => false,
			'properties'           => array(
				'id'        => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The ID of the post to delete.', 'ai' ),
				),
				'post_type' => array(
					'type'        => 'string',
					'enum'        => $post_types,
					'description' => __( 'Optional. Restrict the deletion to this post type; the post is only deleted if it matches.', 'ai' ),
				),
				'force'     => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to bypass the trash and delete the post permanently. Defaults to false, which moves the post to the trash.', 'ai' ),
				),
				'fields'    => $this->get_fields_input_schema(),
			),
		);
	}

	/**
	 * Rejects input fields the post type does not support.
	 *
	 * One input schema serves every exposed post type, so it cannot express which fields
	 * apply to which post type; its descriptions state it, and this check enforces it. A field
	 * that cannot be applied fails loudly instead of being dropped, as `core/content-query`
	 * rejects the filters it cannot apply. Write fields not listed here are supported by all
	 * post types.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input     The ability input.
	 * @param string       $post_type The post type name.
	 * @return \WP_Error|null A WP_Error naming the first unsupported field, or null when every field is supported.
	 */
	private function check_unsupported_fields( array $input, string $post_type ): ?WP_Error {
		$field_support = array(
			'title_raw'   => post_type_supports( $post_type, 'title' ),
			'content_raw' => post_type_supports( $post_type, 'editor' ),
			'excerpt_raw' => post_type_supports( $post_type, 'excerpt' ),
			'author_slug' => post_type_supports( $post_type, 'author' ),
			'parent'      => is_post_type_hierarchical( $post_type ),
		);

		foreach ( $field_support as $field => $is_supported ) {
			if ( $is_supported || ! isset( $input[ $field ] ) ) {
				continue;
			}

			return new WP_Error(
				'content_invalid_field',
				sprintf(
					/* translators: 1: Field name, 2: Post type name. */
					__( 'The %1$s field is not supported by the %2$s post type.', 'ai' ),
					$field,
					$post_type
				),
				array( 'status' => 400 )
			);
		}

		return null;
	}

	/**
	 * Prepares a single post for creation or update.
	 *
	 * Fields the post type does not support were already rejected by
	 * {@see self::check_unsupported_fields()}. The Block Hooks metadata
	 * (`update_ignored_hooked_blocks_postmeta()`) is left alone: `core/content-query`
	 * returns the stored content verbatim, so deriving ignored hooked blocks from the
	 * submitted content would mark them as ignored after every read-modify-write cycle.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed>  $input            The ability input.
	 * @param \WP_Post_Type $post_type_object The post type of the post being prepared.
	 * @param \WP_Post|null $post_before      The post being updated, or null when creating.
	 * @return \stdClass|\WP_Error Post object prepared for wp_insert_post() or wp_update_post(), or a WP_Error.
	 */
	private function prepare_content_data( array $input, \WP_Post_Type $post_type_object, ?WP_Post $post_before ) {
		$prepared_post  = new \stdClass();
		$current_status = '';
		$post_type      = $post_type_object->name;

		// Post ID.
		if ( $post_before ) {
			$prepared_post->ID = $post_before->ID;
			$current_status    = $post_before->post_status;
		}

		// Post title.
		if ( isset( $input['title_raw'] ) && is_string( $input['title_raw'] ) ) {
			$prepared_post->post_title = $input['title_raw'];
		}

		// Post content.
		if ( isset( $input['content_raw'] ) && is_string( $input['content_raw'] ) ) {
			$prepared_post->post_content = $input['content_raw'];
		}

		// Post excerpt.
		if ( isset( $input['excerpt_raw'] ) && is_string( $input['excerpt_raw'] ) ) {
			$prepared_post->post_excerpt = $input['excerpt_raw'];
		}

		// Post type: the requested type when creating, the existing type when updating.
		$prepared_post->post_type = $post_type;

		// Post status. Only a change is checked, so a post can keep a status the user could not give it, such as `private`.
		if ( isset( $input['status'] ) && is_string( $input['status'] ) && $current_status !== $input['status'] ) {
			$status = $this->prepare_content_status( $input['status'], $post_type_object );
			if ( $status instanceof WP_Error ) {
				return $status;
			}

			$prepared_post->post_status = $status;
		}

		// Post date. A date and a GMT date given together must refer to the same time.
		$date_data     = ! empty( $input['date'] ) && is_string( $input['date'] ) ? rest_get_date_with_gmt( $input['date'] ) : null;
		$date_gmt_data = ! empty( $input['date_gmt'] ) && is_string( $input['date_gmt'] ) ? rest_get_date_with_gmt( $input['date_gmt'], true ) : null;

		/*
		 * A date the post already has is handled as if it were left out, so both dates
		 * core/content-query returns can be sent back even when they refer to different times:
		 * `date` is read in the site's current timezone, while `date_gmt` is the GMT date stored
		 * when the post was saved. A draft without a fixed date has no GMT date; the query
		 * derives one from its local date.
		 */
		if ( $post_before ) {
			$current_gmt   = $this->is_usable_date( $post_before->post_date_gmt ) ? $post_before->post_date_gmt : get_gmt_from_date( $post_before->post_date );
			$date_data     = $date_data && $post_before->post_date !== $date_data[0] ? $date_data : null;
			$date_gmt_data = $date_gmt_data && $current_gmt !== $date_gmt_data[1] ? $date_gmt_data : null;
		}

		if ( $date_data && $date_gmt_data && $date_data[1] !== $date_gmt_data[1] ) {
			return new WP_Error(
				'content_invalid_field',
				__( 'The date and date_gmt fields refer to different times.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$new_date = $date_data ?? $date_gmt_data;
		if ( $new_date ) {
			[ $prepared_post->post_date, $prepared_post->post_date_gmt ] = $new_date;
			$prepared_post->edit_date                                    = true;
		}

		// Post slug, sanitized like a title.
		if ( isset( $input['slug'] ) && is_string( $input['slug'] ) ) {
			$prepared_post->post_name = sanitize_title( $input['slug'] );
		}

		// Author.
		if ( isset( $input['author_slug'] ) ) {
			$post_author = $this->get_author_by_slug( $input['author_slug'], $post_type_object );

			if ( ! $post_author ) {
				return new WP_Error(
					'content_invalid_field',
					__( 'The author_slug field must be the slug of an existing user.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			$prepared_post->post_author = $post_author->ID;
		}

		// Parent: 0 for a top-level post.
		if ( isset( $input['parent'] ) ) {
			$post_parent = $this->parse_filter_int( $input['parent'], 0 );

			if ( null === $post_parent || ( 0 !== $post_parent && ! $this->is_valid_parent( $post_parent, $post_type, $post_before ) ) ) {
				return new WP_Error(
					'content_invalid_field',
					__( 'The parent field must be 0 or the ID of a readable post of the same type, other than the post itself or one of its descendants.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			$prepared_post->post_parent = $post_parent;
		}

		/*
		 * Force template to null: wp_update_post() merges in the stored template, which
		 * wp_insert_post() rejects when the theme no longer offers it.
		 */
		$prepared_post->page_template = null;

		return $prepared_post;
	}

	/**
	 * Checks whether a post can be the parent of the post being written.
	 *
	 * The parent must be a post of the same type that the current user can read: the
	 * permalink of a post under another type does not resolve, and the permalink of a post
	 * under an unreadable one shows its slug. It cannot be the post itself or one of its
	 * descendants either: wp_insert_post() would silently turn that loop into a top-level
	 * post. Keeping the current parent is always allowed.
	 *
	 * @since x.x.x
	 *
	 * @param int           $parent_id   The requested parent ID.
	 * @param string        $post_type   The post type of the post being written.
	 * @param \WP_Post|null $post_before The post being updated, or null when creating.
	 * @return bool True when the post can be the parent.
	 */
	private function is_valid_parent( int $parent_id, string $post_type, ?WP_Post $post_before ): bool {
		if ( $post_before && (int) $post_before->post_parent === $parent_id ) {
			return true;
		}

		$parent = get_post( $parent_id );
		if ( ! $parent instanceof WP_Post || $post_type !== $parent->post_type || ! $this->check_read_permission( $parent ) ) {
			return false;
		}

		return null === $post_before
			|| ( $post_before->ID !== $parent->ID && ! in_array( $post_before->ID, get_post_ancestors( $parent ), true ) );
	}

	/**
	 * Checks that the current user may give a post the requested status.
	 *
	 * Publishing, scheduling, private posts, and any other public status require the post
	 * type's publish capability.
	 *
	 * @since x.x.x
	 *
	 * @param string        $post_status      Post status.
	 * @param \WP_Post_Type $post_type_object Post type.
	 * @return string|\WP_Error Post status, or WP_Error if lacking the proper permission.
	 */
	private function prepare_content_status( string $post_status, \WP_Post_Type $post_type_object ) {
		switch ( $post_status ) {
			case 'draft':
			case 'pending':
				break;
			case 'private':
				if ( ! current_user_can( $post_type_object->cap->publish_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
					return new WP_Error(
						'content_cannot_publish',
						__( 'Sorry, you are not allowed to make posts private in this post type.', 'ai' ),
						array( 'status' => rest_authorization_required_code() )
					);
				}
				break;
			case 'publish':
			case 'future':
				if ( ! current_user_can( $post_type_object->cap->publish_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
					return new WP_Error(
						'content_cannot_publish',
						__( 'Sorry, you are not allowed to publish posts in this post type.', 'ai' ),
						array( 'status' => rest_authorization_required_code() )
					);
				}
				break;
			default:
				// A status registered as public shows the post to everyone, as publishing does.
				$status_object = get_post_status_object( $post_status );
				if ( $status_object instanceof \stdClass && $status_object->public && ! current_user_can( $post_type_object->cap->publish_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
					return new WP_Error(
						'content_cannot_publish',
						__( 'Sorry, you are not allowed to publish posts in this post type.', 'ai' ),
						array( 'status' => rest_authorization_required_code() )
					);
				}
				break;
		}

		return $post_status;
	}

	/**
	 * Builds the uniform not-found error.
	 *
	 * Gated transports run the ability's permission callback first, which denies the same
	 * lookups, so there it is only returned when a post disappears after that check, such
	 * as a written post a listener deleted. It is kept so that a direct call to an execute
	 * callback still fails closed on a structural lookup failure: a missing post, a post
	 * type that is not exposed, or a post type that does not match the requested one.
	 *
	 * This is not a permission check. The query, create, and update execute callbacks
	 * deliberately do not repeat the read and edit checks that the permission callbacks
	 * already performed, so a direct call bypasses them; only the destructive delete
	 * callback checks its capability again. Only invoke the callbacks through
	 * {@see WP_Ability::execute()}, which always runs the permission callback first.
	 *
	 * @since 1.2.0
	 *
	 * @return \WP_Error The not-found error.
	 */
	private function not_found_error(): WP_Error {
		return new WP_Error(
			'content_not_found',
			__( 'The requested content was not found.', 'ai' ),
			array( 'status' => 404 )
		);
	}
}

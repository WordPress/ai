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
use stdClass;

use function WordPress\AI\register_deprecated_ability_alias;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Content
 *
 * Registers the content abilities, which read and manage posts of the post types exposed
 * to abilities via `show_in_abilities`:
 *
 *   - `core/content-query` (read-only): retrieves a single readable post by ID or by post
 *     type and slug, or queries multiple readable posts filtered by post type, status,
 *     author, parent, or included IDs. Raw fields are only returned for posts the current
 *     user can edit.
 *   - `core/content-create`: creates a post of an exposed post type.
 *   - `core/content-update`: updates a post by ID.
 *   - `core/content-delete`: moves a post to the trash, or deletes it permanently.
 *
 * The write abilities mirror the create, update, and delete endpoints of the REST posts
 * controller (`WP_REST_Posts_Controller`): they accept the same fields, apply the same
 * capability checks, and prepare the post for the database the same way. The
 * transport-agnostic parts of that controller are reproduced here as private helpers
 * ({@see self::prepare_item_for_database()} and the methods around it), named after their
 * REST counterparts so a future core refactor can share them between REST and abilities.
 * REST-specific hooks, request and response objects, and the meta fields machinery are
 * deliberately not reproduced. The written post is returned through the same field
 * projection `core/content-query` uses.
 *
 * This class is kept almost identical to the WordPress core class `WP_Content_Abilities`
 * so the two implementations stay in sync. Differences from the core class are marked with
 * `// Plugin:` comments. Additionally, all user-facing strings use the 'ai' text domain.
 *
 * Plugin: the class is final and instance-based (with private helpers), matching the
 * plugin's other ability classes (e.g. `Settings`) and core's `WP_Settings_Abilities`.
 * Core's `WP_Content_Abilities` is still static; the structures are otherwise equivalent.
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
	 * Fields whose rendering may read post meta or terms.
	 *
	 * Requests that include any of these prime the post meta and term caches for the
	 * page. Other rendered fields, such as the title, do not need that cache priming.
	 *
	 * @since 1.2.0
	 * @var list<string>
	 */
	private array $cache_priming_fields = array(
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
		$this->register_content_create();
		$this->register_content_update();
		$this->register_content_delete();
	}

	/**
	 * Registers the read-only `core/content-query` ability.
	 *
	 * Also registers `core/read-content` as a deprecated alias.
	 *
	 * @since 1.2.0
	 * @since x.x.x Renamed from `core/read-content`.
	 */
	private function register_content_query(): void {
		/*
		 * Post types must be registered with `show_in_abilities` before the ability is
		 * registered so they are included in its input schema.
		 */
		$post_types = array_keys( $this->get_exposed_post_types() );
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
						// MCP clients assume open-world (may reach external systems) when the
						// hint is absent; this ability only reads the local database.
						'open_world'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);

		// @todo Remove the alias after a few releases.
		register_deprecated_ability_alias( 'core/read-content', 'core/content-query', 'x.x.x' );
	}

	/**
	 * Registers the `core/content-create` ability.
	 *
	 * Mirrors the create endpoint of the REST posts controller.
	 *
	 * @since x.x.x
	 */
	private function register_content_create(): void {
		$post_types = array_keys( $this->get_exposed_post_types() );
		if ( empty( $post_types ) ) {
			return;
		}

		// Plugin: unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/content-create' ) ) {
			wp_unregister_ability( 'core/content-create' );
		}

		wp_register_ability(
			'core/content-create',
			array(
				'label'               => __( 'Content Create', 'ai' ),
				'description'         => __( 'Creates a post of a post type exposed to abilities. Accepts the same fields as the REST API posts endpoints: title, content, excerpt, status, slug, date, author, password, parent, menu order, comment and ping status, format, featured media, sticky, template, and taxonomy terms. Fields the post type does not support are rejected rather than ignored. Returns the created post; use `fields` to choose which post fields are returned. Requires an authenticated user who can create posts of the post type.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_content_create_input_schema( $post_types ),
				'output_schema'       => $this->get_post_output_schema(),
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
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Registers the `core/content-update` ability.
	 *
	 * Mirrors the update endpoint of the REST posts controller.
	 *
	 * @since x.x.x
	 */
	private function register_content_update(): void {
		$post_types = array_keys( $this->get_exposed_post_types() );
		if ( empty( $post_types ) ) {
			return;
		}

		// Plugin: unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/content-update' ) ) {
			wp_unregister_ability( 'core/content-update' );
		}

		wp_register_ability(
			'core/content-update',
			array(
				'label'               => __( 'Content Update', 'ai' ),
				'description'         => __( 'Updates a post by ID. Only the provided fields change; omitted fields keep their current values. Accepts the same fields as the REST API posts endpoints: title, content, excerpt, status, slug, date, author, password, parent, menu order, comment and ping status, format, featured media, sticky, template, and taxonomy terms. Fields the post type does not support are rejected rather than ignored. Returns the updated post; use `fields` to choose which post fields are returned. Requires an authenticated user who can edit the post.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_content_update_input_schema( $post_types ),
				'output_schema'       => $this->get_post_output_schema(),
				'execute_callback'    => array( $this, 'execute_content_update' ),
				'permission_callback' => array( $this, 'check_update_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						/*
						 * Not flagged destructive: overwritten content is kept as a revision,
						 * and the Abilities REST run controller routes destructive idempotent
						 * abilities to the DELETE method, whose query-string input cannot carry
						 * post content.
						 */
						'destructive' => false,
						// Repeating the same update leaves the post in the same state.
						'idempotent'  => true,
						'open_world'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Registers the `core/content-delete` ability.
	 *
	 * Mirrors the delete endpoint of the REST posts controller.
	 *
	 * @since x.x.x
	 */
	private function register_content_delete(): void {
		$post_types = array_keys( $this->get_exposed_post_types() );
		if ( empty( $post_types ) ) {
			return;
		}

		// Plugin: unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/content-delete' ) ) {
			wp_unregister_ability( 'core/content-delete' );
		}

		wp_register_ability(
			'core/content-delete',
			array(
				'label'               => __( 'Content Delete', 'ai' ),
				'description'         => __( 'Moves a post to the trash by ID, or deletes it permanently when `force` is true. Trashing a post that is already in the trash is an error, as is trashing when the site has the trash disabled; set `force` to delete permanently in that case. Returns the trashed post, or the deleted post under `previous` when `force` is true; use `fields` to choose which post fields are returned. Requires an authenticated user who can delete the post.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_content_delete_input_schema( $post_types ),
				'output_schema'       => $this->get_content_delete_output_schema(),
				'execute_callback'    => array( $this, 'execute_content_delete' ),
				'permission_callback' => array( $this, 'check_delete_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						// Repeating a deletion has no further effect; the Abilities REST run
						// controller routes destructive idempotent abilities to the DELETE method.
						'idempotent'  => true,
						'open_world'  => false,
					),
					'show_in_rest' => true,
				),
			)
		);
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
		$input   = rest_sanitize_object( $input );
		$exposed = $this->get_exposed_post_types();

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$requires_edit = $this->has_explicit_edit_fields( $input );

		// Single-post mode (by ID).
		if ( ! empty( $input['id'] ) ) {
			$post = $this->get_exposed_post( $input );
			if ( ! $post ) {
				return false;
			}

			return $requires_edit ? current_user_can( 'edit_post', $post->ID ) : $this->check_read_permission( $post );
		}

		// Single-post mode (by slug) and query mode require an exposed post type.
		$post_type = isset( $input['post_type'] ) && is_string( $input['post_type'] ) ? $input['post_type'] : '';
		if ( '' === $post_type || ! isset( $exposed[ $post_type ] ) ) {
			return false;
		}

		if ( isset( $input['slug'] ) && is_string( $input['slug'] ) && '' !== $input['slug'] ) {
			$post = $this->get_post_by_slug( $post_type, $input['slug'] );
			if ( ! $post ) {
				return false;
			}

			return $requires_edit ? current_user_can( 'edit_post', $post->ID ) : $this->check_read_permission( $post );
		}

		$post_type_object = $exposed[ $post_type ];
		if ( $requires_edit ) {
			return current_user_can( $this->post_type_cap( $post_type_object, 'edit_posts' ) ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
		}

		return $this->can_query_statuses( $input, $post_type_object );
	}

	/**
	 * Permission callback for the `core/content-create` ability.
	 *
	 * Mirrors `WP_REST_Posts_Controller::create_item_permissions_check()`: the current user
	 * must be able to create posts of the requested post type, may only create a post as
	 * another author when they can edit others' posts, may only make a post sticky when they
	 * can edit others' posts or publish posts, and must be allowed to assign every provided
	 * term. The Abilities API requires a boolean here, so the distinct REST error codes
	 * collapse into the generic permission error.
	 *
	 * The publish capability required by some statuses is enforced during execution, where
	 * the REST controller enforces it too (in its post preparation).
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

		$post_type_object = $this->get_exposed_post_type( $input );
		if ( ! $post_type_object ) {
			return false;
		}

		if ( ! $this->check_author_and_sticky_permission( $input, $post_type_object ) ) {
			return false;
		}

		if ( ! current_user_can( $this->post_type_cap( $post_type_object, 'create_posts' ) ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
			return false;
		}

		return $this->check_assign_terms_permission( $input, $post_type_object );
	}

	/**
	 * Permission callback for the `core/content-update` ability.
	 *
	 * Mirrors `WP_REST_Posts_Controller::update_item_permissions_check()`: the post must
	 * exist in an exposed post type (and match the `post_type` guard when given), the
	 * current user must be able to edit it, may only reassign it to another author when
	 * they can edit others' posts, may only make it sticky when they can edit others' posts
	 * or publish posts, and must be allowed to assign every provided term. The Abilities
	 * API requires a boolean here, so the distinct REST error codes collapse into the
	 * generic permission error.
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

		$post = $this->get_exposed_post( $input );
		if ( ! $post ) {
			return false;
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object instanceof \WP_Post_Type ) {
			return false;
		}

		// REST's check_update_permission(): an exposed post type and the edit_post meta capability.
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		if ( ! $this->check_author_and_sticky_permission( $input, $post_type_object ) ) {
			return false;
		}

		return $this->check_assign_terms_permission( $input, $post_type_object );
	}

	/**
	 * Permission callback for the `core/content-delete` ability.
	 *
	 * Mirrors `WP_REST_Posts_Controller::delete_item_permissions_check()`: the post must
	 * exist in an exposed post type (and match the `post_type` guard when given), and the
	 * current user must be able to delete it.
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

		$post = $this->get_exposed_post( $input );
		if ( ! $post ) {
			return false;
		}

		// REST's check_delete_permission(): an exposed post type and the delete_post meta capability.
		return current_user_can( 'delete_post', $post->ID );
	}

	/**
	 * Checks the author and sticky parts of the REST create/update permission checks.
	 *
	 * Creating or updating a post as another author requires the post type's
	 * `edit_others_posts` capability. Making a post sticky requires `edit_others_posts` or
	 * `publish_posts`.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed>  $input            The ability input.
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @return bool True if the author and sticky inputs are permitted.
	 */
	private function check_author_and_sticky_permission( array $input, \WP_Post_Type $post_type_object ): bool {
		$author = isset( $input['author'] ) ? $this->input_int( $input['author'] ) : 0;
		if ( $author > 0
			&& get_current_user_id() !== $author
			&& ! current_user_can( $this->post_type_cap( $post_type_object, 'edit_others_posts' ) ) // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
		) {
			return false;
		}

		return true !== $this->input_bool( $input['sticky'] ?? null ) || $this->can_make_sticky( $post_type_object );
	}

	/**
	 * Checks whether the current user may make posts of a post type sticky.
	 *
	 * Mirrors the sticky gate of the REST create/update permission checks: the post type's
	 * `edit_others_posts` or `publish_posts` capability.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @return bool True if the current user may make posts sticky.
	 */
	private function can_make_sticky( \WP_Post_Type $post_type_object ): bool {
		// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
		if ( current_user_can( $this->post_type_cap( $post_type_object, 'edit_others_posts' ) ) ) {
			return true;
		}

		return current_user_can( $this->post_type_cap( $post_type_object, 'publish_posts' ) ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
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
	 * Parses a raw filter value into an integer of at least a minimum, or null when invalid.
	 *
	 * Unlike {@see self::input_int()}, which coerces any non-integer to 0, this rejects
	 * values that are not integers so a filter whose value cannot be honored can fail
	 * loudly instead of silently widening the query: `author => 0` drops the author
	 * filter (matching every author) and `post_parent => 0` becomes a top-level query.
	 * Accepts native integers and unsigned integer strings, mirroring how the JSON
	 * Schema `integer` type and the query-string transport respectively deliver them.
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
	 * Resolves a capability name from a post type's capability map.
	 *
	 * The capability map is a plain object with untyped properties, so guard the
	 * lookup and fail closed with `do_not_allow` when the name cannot be resolved.
	 *
	 * @since 1.2.0
	 *
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @param string        $capability       The capability key, e.g. 'edit_posts'.
	 * @return string The resolved capability name, or 'do_not_allow' when unresolved.
	 */
	private function post_type_cap( \WP_Post_Type $post_type_object, string $capability ): string {
		$cap = $post_type_object->cap->$capability ?? null;

		return is_string( $cap ) && '' !== $cap ? $cap : 'do_not_allow';
	}

	/**
	 * Parses a raw list input into a list of strings.
	 *
	 * A GET request delivers list inputs as scalar/CSV strings; this parses them the
	 * same way schema validation did (wp_parse_list) so they are honored regardless of
	 * transport, until core sanitizes ability input itself.
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
			if ( 'private' === $status && current_user_can( $this->post_type_cap( $post_type_object, 'read_private_posts' ) ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
			if ( current_user_can( $this->post_type_cap( $post_type_object, 'edit_posts' ) ) ) {
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

		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type instanceof \WP_Post_Type || empty( $post_type->show_in_abilities ) ) {
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
		$exposed       = $this->get_exposed_post_types();
		$fields        = $this->normalize_fields( $input );
		$requires_edit = $this->has_explicit_edit_fields( $input );

		// Single-post mode (by ID).
		if ( ! empty( $input['id'] ) ) {
			$post = $this->get_exposed_post( $input );
			if ( ! $post ) {
				return $this->not_found_error();
			}

			return $this->to_output_post( $this->format_post( $post, $fields ) );
		}

		// Single-post mode (by slug) and query mode.
		$post_type = isset( $input['post_type'] ) && is_string( $input['post_type'] ) ? $input['post_type'] : '';
		if ( '' === $post_type || ! isset( $exposed[ $post_type ] ) ) {
			return $this->not_found_error();
		}

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
		if ( isset( $input['author'] ) ) {
			if ( ! post_type_supports( $post_type, 'author' ) ) {
				return new WP_Error(
					'content_invalid_filter',
					__( 'The author filter is only supported for post types that support authors.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			$author = $this->parse_filter_int( $input['author'], 1 );
			if ( null === $author ) {
				return new WP_Error(
					'content_invalid_filter',
					__( 'The author filter must be a positive integer.', 'ai' ),
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

		// `orderby` is left unset, which orders by `post_date` descending, matching the
		// default of the REST posts controller.
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
			$query_args['author'] = $author;
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
		 * per post, mirroring the REST posts controller.
		 */
		if ( in_array( 'author', $fields, true ) && post_type_supports( $post_type, 'author' ) ) {
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
	 * Executes the `core/content-create` ability.
	 *
	 * Mirrors `WP_REST_Posts_Controller::create_item()`. {@see WP_Ability::execute()} always
	 * runs {@see self::check_create_permission()} first, so this only re-validates that the
	 * post type is exposed before preparing and inserting the post. Errors raised after the
	 * post has been inserted (for example while assigning terms) are returned as-is, as the
	 * REST controller does: the post exists at that point and its ID is not reported.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\stdClass|\WP_Error The created post, or a WP_Error.
	 */
	public function execute_content_create( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$post_type_object = $this->get_exposed_post_type( $input );
		if ( ! $post_type_object ) {
			return new WP_Error(
				'content_invalid_post_type',
				__( 'The post type is not exposed to abilities.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$unsupported = $this->check_unsupported_fields( $input, $post_type_object );
		if ( $unsupported instanceof WP_Error ) {
			return $unsupported;
		}

		$template = $this->check_template( $input, $post_type_object->name, null );
		if ( $template instanceof WP_Error ) {
			return $template;
		}

		$prepared_post = $this->prepare_item_for_database( $input, $post_type_object, null );
		if ( $prepared_post instanceof WP_Error ) {
			return $prepared_post;
		}

		if ( ! empty( $prepared_post->post_name )
			&& ! empty( $prepared_post->post_status )
			&& in_array( $prepared_post->post_status, array( 'draft', 'pending' ), true )
		) {
			/*
			 * `wp_unique_post_slug()` returns the same slug for 'draft' or 'pending' posts.
			 *
			 * To ensure that a unique slug is generated, pass the post data with the 'publish' status.
			 */
			$prepared_post->post_name = wp_unique_post_slug(
				$prepared_post->post_name,
				0,
				'publish',
				$prepared_post->post_type,
				$prepared_post->post_parent ?? 0
			);
		}

		$post_id = wp_insert_post( $this->slash_post_data( $prepared_post ), true, false );

		if ( $post_id instanceof WP_Error ) {
			$post_id->add_data( array( 'status' => 'db_insert_error' === $post_id->get_error_code() ? 500 : 400 ) );

			return $post_id;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found_error();
		}

		$extras = $this->handle_post_extras( $post, $input, $post_type_object, true );
		if ( $extras instanceof WP_Error ) {
			return $extras;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found_error();
		}

		wp_after_insert_post( $post, false, null );

		return $this->to_output_post( $this->format_post( $post, $this->normalize_fields( $input ) ) );
	}

	/**
	 * Executes the `core/content-update` ability.
	 *
	 * Mirrors `WP_REST_Posts_Controller::update_item()`. {@see WP_Ability::execute()} always
	 * runs {@see self::check_update_permission()} first, so this only re-validates the
	 * lookup itself before preparing and updating the post. Errors raised after the post
	 * has been updated (for example while assigning terms) are returned as-is, as the REST
	 * controller does.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\stdClass|\WP_Error The updated post, or a WP_Error.
	 */
	public function execute_content_update( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$post_before = $this->get_exposed_post( $input );
		if ( ! $post_before ) {
			return $this->not_found_error();
		}

		$post_type_object = get_post_type_object( $post_before->post_type );
		if ( ! $post_type_object instanceof \WP_Post_Type ) {
			return $this->not_found_error();
		}

		$unsupported = $this->check_unsupported_fields( $input, $post_type_object );
		if ( $unsupported instanceof WP_Error ) {
			return $unsupported;
		}

		$template = $this->check_template( $input, $post_type_object->name, $post_before );
		if ( $template instanceof WP_Error ) {
			return $template;
		}

		$prepared_post = $this->prepare_item_for_database( $input, $post_type_object, $post_before );
		if ( $prepared_post instanceof WP_Error ) {
			return $prepared_post;
		}

		$post_status = ! empty( $prepared_post->post_status ) ? $prepared_post->post_status : $post_before->post_status;

		/*
		 * `wp_unique_post_slug()` returns the same slug for 'draft' or 'pending' posts.
		 *
		 * To ensure that a unique slug is generated, pass the post data with the 'publish' status.
		 */
		if ( ! empty( $prepared_post->post_name ) && in_array( $post_status, array( 'draft', 'pending' ), true ) ) {
			$prepared_post->post_name = wp_unique_post_slug(
				$prepared_post->post_name,
				$post_before->ID,
				'publish',
				$prepared_post->post_type,
				! empty( $prepared_post->post_parent ) ? $prepared_post->post_parent : 0
			);
		}

		$post_id = wp_update_post( $this->slash_post_data( $prepared_post ), true, false );

		if ( $post_id instanceof WP_Error ) {
			$post_id->add_data( array( 'status' => 'db_update_error' === $post_id->get_error_code() ? 500 : 400 ) );

			return $post_id;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found_error();
		}

		$extras = $this->handle_post_extras( $post, $input, $post_type_object, false );
		if ( $extras instanceof WP_Error ) {
			return $extras;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->not_found_error();
		}

		wp_after_insert_post( $post, true, $post_before );

		return $this->to_output_post( $this->format_post( $post, $this->normalize_fields( $input ) ) );
	}

	/**
	 * Executes the `core/content-delete` ability.
	 *
	 * Mirrors `WP_REST_Posts_Controller::delete_item()`. {@see WP_Ability::execute()} always
	 * runs {@see self::check_delete_permission()} first, so this only re-validates the
	 * lookup itself. Without `force` the post is moved to the trash and returned; with
	 * `force` it is deleted permanently and returned under `previous`.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\stdClass|\WP_Error The trashed post, a `deleted`/`previous` pair, or a WP_Error.
	 */
	public function execute_content_delete( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$post = $this->get_exposed_post( $input );
		if ( ! $post ) {
			return $this->not_found_error();
		}

		$fields = $this->normalize_fields( $input );

		// If we're forcing, then delete permanently.
		if ( true === $this->input_bool( $input['force'] ?? null ) ) {
			$previous = $this->to_output_post( $this->format_post( $post, $fields ) );

			if ( ! wp_delete_post( $post->ID, true ) ) {
				return $this->cannot_delete_error();
			}

			return array(
				'deleted'  => true,
				'previous' => $previous,
			);
		}

		// If we don't support trashing for this type, error out.
		if ( ! $this->supports_trash( $post ) ) {
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

		if ( ! wp_trash_post( $post->ID ) ) {
			return $this->cannot_delete_error();
		}

		$trashed = get_post( $post->ID );
		if ( ! $trashed instanceof WP_Post ) {
			return $this->cannot_delete_error();
		}

		return $this->to_output_post( $this->format_post( $trashed, $fields ) );
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
		unset( $count_args['paged'] );

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
	 * Returns the post types exposed through the Abilities API, keyed by name.
	 *
	 * Deliberately resolved on every call rather than cached: post types can be
	 * unregistered or re-registered with different arguments between the ability
	 * being registered and the ability being used.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, \WP_Post_Type> Exposed post type objects keyed by name.
	 */
	private function get_exposed_post_types(): array {
		$exposed_post_types = array();

		foreach ( get_post_types( array( 'show_in_abilities' => true ), 'objects' ) as $post_type_object ) {
			$exposed_post_types[ $post_type_object->name ] = $post_type_object;
		}

		return $exposed_post_types;
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
		return $this->parse_id_list_input( $input, 'include' );
	}

	/**
	 * Parses a raw list input into a list of unique positive IDs.
	 *
	 * A GET request delivers list inputs as scalar/CSV strings; wp_parse_id_list()
	 * accepts both and yields unique positive IDs, matching schema validation.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @param string       $key   The input key holding the list.
	 * @return list<int> Unique positive IDs; empty when absent or unparseable.
	 */
	private function parse_id_list_input( array $input, string $key ): array {
		$value = $input[ $key ] ?? null;
		if ( ! is_array( $value ) && ! is_string( $value ) ) {
			return array();
		}

		return array_values( array_filter( wp_parse_id_list( $value ) ) );
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
			'author'            => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __( 'The author user ID.', 'ai' ),
					),
					'name' => array(
						'type'        => 'string',
						'description' => __( 'The author display name.', 'ai' ),
					),
				),
				'description'          => __( 'The post author. Present when the post type supports authors.', 'ai' ),
			),
			'parent'            => array(
				'type'        => 'integer',
				'description' => __( 'The parent post ID. Present for hierarchical post types.', 'ai' ),
			),
		);

		return $this->post_properties;
	}

	/**
	 * Builds the input schema for the `core/content-query` ability.
	 *
	 * The ability has three mutually exclusive modes, modeled as a `oneOf` so invalid
	 * combinations are rejected rather than silently ignored:
	 *
	 *   - Get a single post by `id` (optionally guarded by `post_type`).
	 *   - Get a single post by `post_type` and `slug`.
	 *   - Query a set of posts by `post_type` plus filters (`status`, `author`, `parent`,
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
							'description' => __( 'Retrieve a single readable post by slug. Resolves to the newest readable match, preferring published posts.', 'ai' ),
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
						'post_type' => array(
							'type'        => 'string',
							'enum'        => $post_types,
							'description' => __( 'Post type to query for readable posts.', 'ai' ),
						),
						'status'    => array(
							'type'        => 'array',
							'uniqueItems' => true,
							'items'       => array(
								'type' => 'string',
								'enum' => $statuses,
							),
							'description' => __( 'Filter readable posts by one or more post statuses. Defaults to publish. Non-published statuses require the appropriate capabilities.', 'ai' ),
						),
						'author'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Filter by author user ID. Only supported for post types that support authors.', 'ai' ),
						),
						'parent'    => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Filter by parent post ID. Only supported for hierarchical post types. Use 0 for top-level posts.', 'ai' ),
						),
						'include'   => $include,
						'fields'    => $fields,
						'page'      => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Page of results to return. Requesting a page beyond the last one is an error. Check `total_pages` before requesting later pages.', 'ai' ),
						),
						'per_page'  => array(
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
		$post_schema = $this->get_post_output_schema();

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
	 * Builds the schema of the `fields` input shared by every content ability.
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
	 * Builds the output schema of a single post, shared by every content ability.
	 *
	 * No field is marked required because the `fields` input lets the caller request any
	 * subset, and a field is only present when its post type supports it.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The post JSON Schema.
	 */
	private function get_post_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $this->get_post_properties(),
		);
	}

	/**
	 * Returns the input properties shared by the create and update abilities, keyed by field name.
	 *
	 * These mirror the writable properties of the REST posts item schema, including one
	 * property per taxonomy the REST API exposes for an exposed post type, under the same
	 * key the REST API uses (e.g. `categories` and `tags` for posts). The REST controller
	 * only registers each property for the post types that support it; a shared schema
	 * cannot express that per post type, so the descriptions state the requirement and
	 * {@see self::check_unsupported_fields()} enforces it at execution time.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> Write property definitions.
	 */
	private function get_content_write_properties(): array {
		$statuses = array_values( get_post_stati( array( 'internal' => false ) ) );

		$properties = array(
			'title'          => array(
				'type'        => 'string',
				'description' => __( 'The raw post title. Only supported for post types that support titles.', 'ai' ),
			),
			'content'        => array(
				'type'        => 'string',
				'description' => __( 'The raw post content, as block markup or HTML. Only supported for post types that support the editor.', 'ai' ),
			),
			'excerpt'        => array(
				'type'        => 'string',
				'description' => __( 'The raw post excerpt. Only supported for post types that support excerpts.', 'ai' ),
			),
			'status'         => array(
				'type'        => 'string',
				'enum'        => $statuses,
				'description' => __( 'The post status. Defaults to draft when creating. Publishing, scheduling, or making a post private requires the publish capability for the post type.', 'ai' ),
			),
			'slug'           => array(
				'type'        => 'string',
				'description' => __( 'The post slug. Sanitized like a title, and adjusted when it collides with another post of the same type.', 'ai' ),
			),
			'date'           => array(
				'type'        => array( 'string', 'null' ),
				'format'      => 'date-time',
				'description' => __( "The publication date in ISO 8601 format, in the site's timezone unless it carries a timezone offset. Pass null to reset the date: the post is dated now, and drafts get a floating date.", 'ai' ),
			),
			'date_gmt'       => array(
				'type'        => array( 'string', 'null' ),
				'format'      => 'date-time',
				'description' => __( 'The publication date in ISO 8601 format, as GMT. Ignored when `date` is also given. Pass null to reset the date.', 'ai' ),
			),
			'author'         => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The author user ID. Assigning another user requires the capability to edit their posts. Only supported for post types that support authors.', 'ai' ),
			),
			'password'       => array(
				'type'        => 'string',
				'description' => __( 'A password to protect access to the content and excerpt. Pass an empty string to remove it. A post cannot be both sticky and password protected.', 'ai' ),
			),
			'parent'         => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'The parent post ID; 0 for a top-level post. Only supported for hierarchical post types.', 'ai' ),
			),
			'menu_order'     => array(
				'type'        => 'integer',
				'description' => __( 'The order of the post in relation to other posts. Only supported for post types that support page attributes.', 'ai' ),
			),
			'comment_status' => array(
				'type'        => 'string',
				'enum'        => array( 'open', 'closed' ),
				'description' => __( 'Whether comments are open on the post. Only supported for post types that support comments.', 'ai' ),
			),
			'ping_status'    => array(
				'type'        => 'string',
				'enum'        => array( 'open', 'closed' ),
				'description' => __( 'Whether the post can be pinged. Only supported for post types that support comments.', 'ai' ),
			),
			'format'         => array(
				'type'        => 'string',
				'enum'        => array_values( get_post_format_slugs() ),
				'description' => __( 'The post format. Only supported for post types that support post formats.', 'ai' ),
			),
			'featured_media' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'The attachment ID of the featured image; 0 removes it. Only supported for post types that support thumbnails.', 'ai' ),
			),
			'sticky'         => array(
				'type'        => 'boolean',
				'description' => __( "Whether the post is sticky. Requires the capability to edit others' posts or to publish posts. Only supported for the post post type.", 'ai' ),
			),
			'template'       => array(
				'type'        => 'string',
				'description' => __( 'The theme template file to display the post with; an empty string selects the default template. Must be one of the templates the active theme offers for the post type.', 'ai' ),
			),
		);

		foreach ( $this->get_all_writable_taxonomies() as $key => $taxonomy ) {
			$properties[ $key ] = array(
				'type'        => 'array',
				'uniqueItems' => true,
				'items'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'description' => sprintf(
					/* translators: %s: Taxonomy name. */
					__( 'The IDs of the terms assigned to the post in the %s taxonomy. Replaces the current terms; an empty list removes them all. Only supported for post types associated with the taxonomy.', 'ai' ),
					$taxonomy->name
				),
			);
		}

		return $properties;
	}

	/**
	 * Builds the input schema for the `core/content-create` ability.
	 *
	 * `additionalProperties: false` rejects unknown fields instead of dropping them, so e.g.
	 * passing an `id` fails validation the way the REST controller rejects creating an
	 * existing post.
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
	 * Builds the input schema for the `core/content-update` ability.
	 *
	 * @since x.x.x
	 *
	 * @param list<string> $post_types Exposed post type names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_content_update_input_schema( array $post_types ): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'additionalProperties' => false,
			'properties'           => array_merge(
				array(
					'id'        => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'The ID of the post to update.', 'ai' ),
					),
					'post_type' => array(
						'type'        => 'string',
						'enum'        => $post_types,
						'description' => __( 'Optional. Restrict the update to this post type; the post is only updated if it matches.', 'ai' ),
					),
				),
				$this->get_content_write_properties(),
				array( 'fields' => $this->get_fields_input_schema() )
			),
		);
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
	 * Builds the output schema for the `core/content-delete` ability.
	 *
	 * Trashing returns the trashed post directly; a forced deletion returns a `deleted`
	 * flag with the deleted post under `previous`, matching the REST controller.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_content_delete_output_schema(): array {
		$post_schema = $this->get_post_output_schema();

		return array(
			'type'  => 'object',
			'oneOf' => array(
				$post_schema,
				array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'deleted', 'previous' ),
					'properties'           => array(
						'deleted'  => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the post was permanently deleted.', 'ai' ),
						),
						'previous' => $post_schema,
					),
				),
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
	 * Plugin: this is a deliberate improvement over the REST posts controller, which
	 * encodes the same case as `[]` even though it types the response as an object
	 * (`GET /wp/v2/posts/<id>?_fields=parent` on a non-hierarchical post type). Keep the
	 * cast when syncing this class with core.
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

		if ( isset( $requested['author'] ) && post_type_supports( $post_type, 'author' ) ) {
			$author         = get_userdata( (int) $post->post_author );
			$data['author'] = array(
				'id'   => (int) $post->post_author,
				'name' => $author ? $author->display_name : '',
			);
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
			return get_the_title( $post );
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
		$previous_post = $GLOBALS['post'] ?? null;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily mirrors REST post context for excerpt rendering.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		/*
		 * The global post context is restored in a finally block so a throw from an
		 * excerpt filter cannot leave it pointing at the rendered post for the rest
		 * of the request.
		 */
		try {
			/** This filter is documented in wp-includes/post-template.php. */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying the core excerpt filter to mirror REST rendering.
			$excerpt = apply_filters( 'get_the_excerpt', $post->post_excerpt, $post );

			/** This filter is documented in wp-includes/post-template.php. */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying the core excerpt filter to mirror REST rendering.
			$excerpt = apply_filters( 'the_excerpt', $excerpt );

			return is_string( $excerpt ) ? $excerpt : '';
		} finally {
			if ( $previous_post instanceof WP_Post ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous global post context.
				$GLOBALS['post'] = $previous_post;
				setup_postdata( $previous_post );
			} else {
				unset( $GLOBALS['post'] );
				wp_reset_postdata();
			}
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
		$previous_post = $GLOBALS['post'] ?? null;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily mirrors REST post context for content rendering.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		/*
		 * The global post context is restored in a finally block so a throw from a
		 * content filter cannot leave it pointing at the rendered post for the rest
		 * of the request.
		 */
		try {
			/** This filter is documented in wp-includes/post-template.php. */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying the core content filter to mirror REST rendering.
			$content = apply_filters( 'the_content', $post->post_content );

			return is_string( $content ) ? $content : '';
		} finally {
			if ( $previous_post instanceof WP_Post ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous global post context.
				$GLOBALS['post'] = $previous_post;
				setup_postdata( $previous_post );
			} else {
				unset( $GLOBALS['post'] );
				wp_reset_postdata();
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
	 * Resolves the exposed post type a `post_type` input refers to.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return \WP_Post_Type|null The post type object, or null when absent or not exposed.
	 */
	private function get_exposed_post_type( array $input ): ?\WP_Post_Type {
		$post_type = isset( $input['post_type'] ) && is_string( $input['post_type'] ) ? $input['post_type'] : '';
		if ( '' === $post_type ) {
			return null;
		}

		return $this->get_exposed_post_types()[ $post_type ] ?? null;
	}

	/**
	 * Resolves the exposed post an `id` input refers to.
	 *
	 * The post must exist, belong to a post type exposed to abilities, and match the
	 * `post_type` guard when one is given. This is the abilities counterpart of the REST
	 * controller's `get_post()` lookup, where a post of another type is not found either
	 * because each REST route is bound to one post type.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return \WP_Post|null The post, or null when it cannot be resolved.
	 */
	private function get_exposed_post( array $input ): ?WP_Post {
		$post_id = isset( $input['id'] ) ? $this->input_int( $input['id'] ) : 0;
		if ( $post_id < 1 ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! isset( $this->get_exposed_post_types()[ $post->post_type ] ) ) {
			return null;
		}

		if ( ! empty( $input['post_type'] ) && $post->post_type !== $input['post_type'] ) {
			return null;
		}

		return $post;
	}

	/**
	 * Casts a raw input value to a boolean, or null when it was not provided.
	 *
	 * Boolean inputs arrive as native booleans from a JSON body and as strings such as
	 * "true" or "0" from a query string. Both are read the way rest_sanitize_boolean()
	 * reads them: the strings "false" and "0" are false, any other value casts to boolean.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The raw input value.
	 * @return bool|null The boolean value, or null when the value is null.
	 */
	private function input_bool( $value ): ?bool {
		if ( null === $value ) {
			return null;
		}

		if ( is_string( $value ) && in_array( strtolower( $value ), array( 'false', '0' ), true ) ) {
			return false;
		}

		return (bool) $value;
	}

	/**
	 * Returns which write fields a post type supports, keyed by input key.
	 *
	 * This is the abilities counterpart of the per-post-type property registration in the
	 * REST posts item schema: the REST controller registers `title`, `content`, `excerpt`,
	 * `author`, `featured_media`, `comment_status`/`ping_status`, `menu_order`, and `format`
	 * only for post types that declare the matching support, `parent` only for hierarchical
	 * post types, and `sticky` only for posts. Fields every post type accepts map to true.
	 *
	 * @since x.x.x
	 *
	 * @param string $post_type The post type name.
	 * @return array<string, bool> Whether each write field is supported.
	 */
	private function get_write_field_support( string $post_type ): array {
		return array(
			'title'          => post_type_supports( $post_type, 'title' ),
			'content'        => post_type_supports( $post_type, 'editor' ),
			'excerpt'        => post_type_supports( $post_type, 'excerpt' ),
			'status'         => true,
			'slug'           => true,
			'date'           => true,
			'date_gmt'       => true,
			'author'         => post_type_supports( $post_type, 'author' ),
			'password'       => true,
			'parent'         => is_post_type_hierarchical( $post_type ),
			'menu_order'     => post_type_supports( $post_type, 'page-attributes' ),
			'comment_status' => post_type_supports( $post_type, 'comments' ),
			'ping_status'    => post_type_supports( $post_type, 'comments' ),
			'format'         => post_type_supports( $post_type, 'post-formats' ),
			'featured_media' => post_type_supports( $post_type, 'thumbnail' ),
			'sticky'         => 'post' === $post_type,
			'template'       => true,
		);
	}

	/**
	 * Returns the taxonomies whose terms a post type accepts through the write abilities,
	 * keyed by input key.
	 *
	 * Mirrors the REST posts controller, which exposes every taxonomy registered for the
	 * post type with `show_in_rest` under its `rest_base` (falling back to the taxonomy
	 * name), so an ability call carries terms under the same keys a REST request would.
	 * A taxonomy whose key collides with another input key is skipped, as the REST
	 * controller reports that registration as incorrect usage.
	 *
	 * @since x.x.x
	 *
	 * @param string $post_type The post type name.
	 * @return array<string, \WP_Taxonomy> Taxonomy objects keyed by input key.
	 */
	private function get_writable_taxonomies( string $post_type ): array {
		$reserved   = array_merge( array( 'id', 'post_type', 'force', 'fields' ), array_keys( $this->get_write_field_support( $post_type ) ) );
		$taxonomies = array();

		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->show_in_rest ) ) {
				continue;
			}

			$key = is_string( $taxonomy->rest_base ) && '' !== $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name;
			if ( in_array( $key, $reserved, true ) ) {
				continue;
			}

			$taxonomies[ $key ] = $taxonomy;
		}

		return $taxonomies;
	}

	/**
	 * Returns the taxonomies accepted across every exposed post type, keyed by input key.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, \WP_Taxonomy> Taxonomy objects keyed by input key.
	 */
	private function get_all_writable_taxonomies(): array {
		$taxonomies = array();

		foreach ( array_keys( $this->get_exposed_post_types() ) as $post_type ) {
			$taxonomies += $this->get_writable_taxonomies( $post_type );
		}

		return $taxonomies;
	}

	/**
	 * Rejects write fields the post type does not support.
	 *
	 * The REST posts controller only registers each field for the post types that support
	 * it and silently drops the rest; a shared input schema cannot express that per post
	 * type. A field that cannot be honored fails loudly here instead, so a caller never
	 * believes it set something that was ignored. This mirrors how `core/content-query`
	 * treats unsupported filters.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed>  $input            The ability input.
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @return \WP_Error|null A WP_Error for the first unsupported field, or null when all fields are supported.
	 */
	private function check_unsupported_fields( array $input, \WP_Post_Type $post_type_object ): ?WP_Error {
		$post_type = $post_type_object->name;
		$supported = $this->get_write_field_support( $post_type );

		$writable_taxonomies = $this->get_writable_taxonomies( $post_type );
		foreach ( array_keys( $this->get_all_writable_taxonomies() ) as $key ) {
			$supported[ $key ] = isset( $writable_taxonomies[ $key ] );
		}

		foreach ( $supported as $field => $is_supported ) {
			if ( $is_supported || ! array_key_exists( $field, $input ) ) {
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
	 * Mirrors `WP_REST_Posts_Controller::prepare_item_for_database()` without its
	 * REST-specific parts: the `rest_pre_insert_{$post_type}` filter, and the Block Hooks
	 * metadata update (`update_ignored_hooked_blocks_postmeta()`). That update assumes the
	 * content being saved was read through REST with hooked blocks inserted, while
	 * `core/content-query` returns the stored content verbatim; running it here would mark
	 * hooked blocks as ignored after every read-modify-write cycle.
	 *
	 * Field support is checked beforehand by {@see self::check_unsupported_fields()}; the
	 * support checks here are the REST controller's schema guards, kept for parity.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed>  $input            The ability input.
	 * @param \WP_Post_Type $post_type_object The post type of the post being prepared.
	 * @param \WP_Post|null $existing_post    The post being updated, or null when creating.
	 * @return \stdClass|\WP_Error Post object prepared for wp_insert_post() or wp_update_post(), or a WP_Error.
	 */
	private function prepare_item_for_database( array $input, \WP_Post_Type $post_type_object, ?WP_Post $existing_post ) {
		$prepared_post  = new stdClass();
		$current_status = '';
		$post_type      = $post_type_object->name;

		// Post ID.
		if ( $existing_post instanceof WP_Post ) {
			$prepared_post->ID = $existing_post->ID;
			$current_status    = $existing_post->post_status;
		}

		// Post title.
		if ( post_type_supports( $post_type, 'title' ) && isset( $input['title'] ) && is_string( $input['title'] ) ) {
			$prepared_post->post_title = $input['title'];
		}

		// Post content.
		if ( post_type_supports( $post_type, 'editor' ) && isset( $input['content'] ) && is_string( $input['content'] ) ) {
			$prepared_post->post_content = $input['content'];
		}

		// Post excerpt.
		if ( post_type_supports( $post_type, 'excerpt' ) && isset( $input['excerpt'] ) && is_string( $input['excerpt'] ) ) {
			$prepared_post->post_excerpt = $input['excerpt'];
		}

		// Post type: the requested type when creating, the existing type when updating.
		$prepared_post->post_type = $post_type;

		// Post status.
		if ( isset( $input['status'] )
			&& is_string( $input['status'] )
			&& ( '' === $current_status || $current_status !== $input['status'] )
		) {
			$status = $this->handle_status_param( $input['status'], $post_type_object );
			if ( $status instanceof WP_Error ) {
				return $status;
			}

			$prepared_post->post_status = $status;
		}

		// Post date.
		if ( ! empty( $input['date'] ) && is_string( $input['date'] ) ) {
			$current_date = $existing_post instanceof WP_Post ? $existing_post->post_date : false;
			$date_data    = rest_get_date_with_gmt( $input['date'] );

			if ( ! empty( $date_data ) && $current_date !== $date_data[0] ) {
				[ $prepared_post->post_date, $prepared_post->post_date_gmt ] = $date_data;

				$prepared_post->edit_date = true;
			}
		} elseif ( ! empty( $input['date_gmt'] ) && is_string( $input['date_gmt'] ) ) {
			$current_date = $existing_post instanceof WP_Post ? $existing_post->post_date_gmt : false;
			$date_data    = rest_get_date_with_gmt( $input['date_gmt'], true );

			if ( ! empty( $date_data ) && $current_date !== $date_data[1] ) {
				[ $prepared_post->post_date, $prepared_post->post_date_gmt ] = $date_data;

				$prepared_post->edit_date = true;
			}
		}

		/*
		 * Sending a null date or date_gmt value resets date and date_gmt to their
		 * default values (`0000-00-00 00:00:00`).
		 */
		if ( ( array_key_exists( 'date_gmt', $input ) && null === $input['date_gmt'] )
			|| ( array_key_exists( 'date', $input ) && null === $input['date'] )
		) {
			$prepared_post->post_date_gmt = null;
			$prepared_post->post_date     = null;
		}

		// Post slug. The REST controller sanitizes its `slug` argument with sanitize_title().
		if ( isset( $input['slug'] ) && is_string( $input['slug'] ) ) {
			$prepared_post->post_name = sanitize_title( $input['slug'] );
		}

		// Author.
		if ( post_type_supports( $post_type, 'author' ) && ! empty( $input['author'] ) ) {
			$post_author = $this->parse_filter_int( $input['author'], 1 );

			if ( null === $post_author || ( get_current_user_id() !== $post_author && ! get_userdata( $post_author ) ) ) {
				return new WP_Error(
					'content_invalid_author',
					__( 'Invalid author ID.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			$prepared_post->post_author = $post_author;
		}

		// Post password. Sticky is only registered for posts, as in the REST posts item schema.
		$sticky = 'post' === $post_type ? $this->input_bool( $input['sticky'] ?? null ) : null;

		if ( isset( $input['password'] ) && is_string( $input['password'] ) ) {
			$prepared_post->post_password = $input['password'];

			if ( '' !== $input['password'] ) {
				if ( true === $sticky ) {
					return $this->invalid_field_error( __( 'A post can not be sticky and have a password.', 'ai' ) );
				}

				if ( ! empty( $prepared_post->ID ) && is_sticky( $prepared_post->ID ) ) {
					return $this->invalid_field_error( __( 'A sticky post can not be password protected.', 'ai' ) );
				}
			}
		}

		if ( true === $sticky && ! empty( $prepared_post->ID ) && post_password_required( $prepared_post->ID ) ) {
			return $this->invalid_field_error( __( 'A password protected post can not be set to sticky.', 'ai' ) );
		}

		// Parent.
		if ( is_post_type_hierarchical( $post_type ) && isset( $input['parent'] ) ) {
			$parent_id   = $this->parse_filter_int( $input['parent'], 0 );
			$parent_post = null !== $parent_id && $parent_id > 0 ? get_post( $parent_id ) : null;

			if ( null === $parent_id || ( $parent_id > 0 && ! $parent_post instanceof WP_Post ) ) {
				return new WP_Error(
					'content_invalid_parent',
					__( 'Invalid post parent ID.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			$prepared_post->post_parent = $parent_post instanceof WP_Post ? (int) $parent_post->ID : 0;
		}

		// Menu order.
		if ( post_type_supports( $post_type, 'page-attributes' ) && isset( $input['menu_order'] ) && is_scalar( $input['menu_order'] ) ) {
			$prepared_post->menu_order = (int) $input['menu_order'];
		}

		// Comment status.
		if ( post_type_supports( $post_type, 'comments' ) && ! empty( $input['comment_status'] ) && is_string( $input['comment_status'] ) ) {
			$prepared_post->comment_status = $input['comment_status'];
		}

		// Ping status.
		if ( post_type_supports( $post_type, 'comments' ) && ! empty( $input['ping_status'] ) && is_string( $input['ping_status'] ) ) {
			$prepared_post->ping_status = $input['ping_status'];
		}

		// Force template to null so that it can be handled exclusively by handle_template().
		$prepared_post->page_template = null;

		return $prepared_post;
	}

	/**
	 * Determines validity and normalizes the given status parameter.
	 *
	 * Mirrors `WP_REST_Posts_Controller::handle_status_param()`.
	 *
	 * @since x.x.x
	 *
	 * @param string        $post_status      Post status.
	 * @param \WP_Post_Type $post_type_object Post type.
	 * @return string|\WP_Error Post status or WP_Error if lacking the proper permission.
	 */
	private function handle_status_param( string $post_status, \WP_Post_Type $post_type_object ) {
		switch ( $post_status ) {
			case 'draft':
			case 'pending':
				break;
			case 'private':
				if ( ! current_user_can( $this->post_type_cap( $post_type_object, 'publish_posts' ) ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
					return new WP_Error(
						'content_cannot_publish',
						__( 'Sorry, you are not allowed to create private posts in this post type.', 'ai' ),
						array( 'status' => rest_authorization_required_code() )
					);
				}
				break;
			case 'publish':
			case 'future':
				if ( ! current_user_can( $this->post_type_cap( $post_type_object, 'publish_posts' ) ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
					return new WP_Error(
						'content_cannot_publish',
						__( 'Sorry, you are not allowed to publish posts in this post type.', 'ai' ),
						array( 'status' => rest_authorization_required_code() )
					);
				}
				break;
			default:
				if ( ! get_post_status_object( $post_status ) ) {
					$post_status = 'draft';
				}
				break;
		}

		return $post_status;
	}

	/**
	 * Applies the parts of a write that live outside the posts table.
	 *
	 * Mirrors the post-insert steps of `WP_REST_Posts_Controller::create_item()` and
	 * `update_item()`: sticky, featured media, post format, template, and terms. Unlike the
	 * REST controller, which discards the result of its featured media handling, an invalid
	 * featured media ID is reported. Meta is not handled; it belongs to the REST meta fields
	 * machinery, which has no abilities counterpart yet.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post      $post             The inserted or updated post.
	 * @param array<mixed>  $input            The ability input.
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @param bool          $creating         True when creating a post, false when updating.
	 * @return \WP_Error|null A WP_Error on failure, null otherwise.
	 */
	private function handle_post_extras( WP_Post $post, array $input, \WP_Post_Type $post_type_object, bool $creating ): ?WP_Error {
		$post_type = $post_type_object->name;
		$post_id   = (int) $post->ID;

		if ( 'post' === $post_type ) {
			$sticky = $this->input_bool( $input['sticky'] ?? null );

			// The REST controller always resolves sticky on creation, defaulting to not sticky.
			if ( $creating || null !== $sticky ) {
				if ( true === $sticky ) {
					stick_post( $post_id );
				} else {
					unstick_post( $post_id );
				}
			}
		}

		if ( post_type_supports( $post_type, 'thumbnail' ) && isset( $input['featured_media'] ) ) {
			$featured_media = $this->handle_featured_media( $this->input_int( $input['featured_media'] ), $post_id );
			if ( $featured_media instanceof WP_Error ) {
				return $featured_media;
			}
		}

		if ( post_type_supports( $post_type, 'post-formats' ) && ! empty( $input['format'] ) && is_string( $input['format'] ) ) {
			set_post_format( $post, $input['format'] );
		}

		if ( isset( $input['template'] ) && is_string( $input['template'] ) ) {
			$this->handle_template( $input['template'], $post_id, $creating );
		}

		return $this->handle_terms( $post_id, $input, $post_type_object );
	}

	/**
	 * Determines the featured media based on an input value.
	 *
	 * Mirrors `WP_REST_Posts_Controller::handle_featured_media()`.
	 *
	 * @since x.x.x
	 *
	 * @param int $featured_media Featured Media ID; 0 removes the featured media.
	 * @param int $post_id        Post ID.
	 * @return bool|\WP_Error Whether the post thumbnail was successfully deleted, otherwise WP_Error.
	 */
	private function handle_featured_media( int $featured_media, int $post_id ) {
		if ( $featured_media > 0 ) {
			if ( set_post_thumbnail( $post_id, $featured_media ) ) {
				return true;
			}

			return new WP_Error(
				'content_invalid_featured_media',
				__( 'Invalid featured media ID.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		return delete_post_thumbnail( $post_id );
	}

	/**
	 * Checks whether the requested template is valid for the post.
	 *
	 * Mirrors `WP_REST_Posts_Controller::check_template()`, which REST runs as the
	 * validation callback of its `template` argument. Updating a post to the template it
	 * already uses is always allowed, even if that template is no longer supported.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed>  $input     The ability input.
	 * @param string        $post_type The post type name.
	 * @param \WP_Post|null $post      The post being updated, or null when creating.
	 * @return true|\WP_Error True if the template is valid or unchanged, or a WP_Error if it is not supported.
	 */
	private function check_template( array $input, string $post_type, ?WP_Post $post ) {
		$template = isset( $input['template'] ) && is_string( $input['template'] ) ? $input['template'] : '';
		if ( '' === $template ) {
			return true;
		}

		$current_template = $post instanceof WP_Post ? (string) get_page_template_slug( $post ) : '';

		// Always allow for updating a post to the same template, even if that template is no longer supported.
		if ( $template === $current_template ) {
			return true;
		}

		// When creating there is no post yet, and the theme falls back to the passed post type.
		$allowed_templates = wp_get_theme()->get_page_templates( $post, $post_type );

		if ( isset( $allowed_templates[ $template ] ) ) {
			return true;
		}

		return new WP_Error(
			'content_invalid_template',
			sprintf(
				/* translators: 1: Parameter, 2: List of valid values. */
				__( '%1$s is not one of %2$s.', 'ai' ),
				'template',
				implode( ', ', array_keys( $allowed_templates ) )
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Sets the template for a post.
	 *
	 * Mirrors `WP_REST_Posts_Controller::handle_template()`.
	 *
	 * @since x.x.x
	 *
	 * @param string $template Page template filename.
	 * @param int    $post_id  Post ID.
	 * @param bool   $validate Whether to validate that the template selected is valid.
	 */
	private function handle_template( string $template, int $post_id, bool $validate = false ): void {
		if ( $validate && ! array_key_exists( $template, wp_get_theme()->get_page_templates( get_post( $post_id ) ) ) ) {
			$template = '';
		}

		update_post_meta( $post_id, '_wp_page_template', $template );
	}

	/**
	 * Updates the post's terms from the ability input.
	 *
	 * Mirrors `WP_REST_Posts_Controller::handle_terms()`. Term lists are normalized to
	 * unique positive IDs first: the REST controller receives them already coerced by its
	 * schema, and wp_set_object_terms() would otherwise create a new term for a string.
	 *
	 * @since x.x.x
	 *
	 * @param int           $post_id          The post ID to update the terms of.
	 * @param array<mixed>  $input            The ability input.
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @return \WP_Error|null WP_Error on an error assigning any of the terms, otherwise null.
	 */
	private function handle_terms( int $post_id, array $input, \WP_Post_Type $post_type_object ): ?WP_Error {
		foreach ( $this->get_writable_taxonomies( $post_type_object->name ) as $key => $taxonomy ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}

			$result = wp_set_object_terms( $post_id, $this->parse_id_list_input( $input, $key ), $taxonomy->name );

			if ( $result instanceof WP_Error ) {
				return $result;
			}
		}

		return null;
	}

	/**
	 * Checks whether the current user can assign all terms sent with the ability input.
	 *
	 * Mirrors `WP_REST_Posts_Controller::check_assign_terms_permission()`.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed>  $input            The ability input.
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @return bool Whether the current user can assign the provided terms.
	 */
	private function check_assign_terms_permission( array $input, \WP_Post_Type $post_type_object ): bool {
		foreach ( $this->get_writable_taxonomies( $post_type_object->name ) as $key => $taxonomy ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}

			foreach ( $this->parse_id_list_input( $input, $key ) as $term_id ) {
				// Invalid terms will be rejected later.
				if ( ! get_term( $term_id, $taxonomy->name ) ) {
					continue;
				}

				if ( ! current_user_can( 'assign_term', $term_id ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Checks whether a post can be moved to the trash rather than deleted permanently.
	 *
	 * Mirrors the trash support resolution of `WP_REST_Posts_Controller::delete_item()`
	 * without its `rest_{$post_type}_trashable` filter. The constants are read through
	 * constant() because they are defined at runtime, and an undefined constant counts as
	 * no trash support so a deletion is never silently made permanent.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The post being deleted.
	 * @return bool Whether the post supports trashing.
	 */
	private function supports_trash( WP_Post $post ): bool {
		$trash_days     = defined( 'EMPTY_TRASH_DAYS' ) ? (int) constant( 'EMPTY_TRASH_DAYS' ) : 0;
		$supports_trash = $trash_days > 0;

		if ( 'attachment' === $post->post_type ) {
			$supports_trash = $supports_trash && defined( 'MEDIA_TRASH' ) && (bool) constant( 'MEDIA_TRASH' );
		}

		return $supports_trash;
	}

	/**
	 * Slashes a prepared post for wp_insert_post() or wp_update_post().
	 *
	 * Both functions expect slashed data, and wp_update_post() expects an array rather than
	 * an object so it does not unslash the input again.
	 *
	 * @since x.x.x
	 *
	 * @param \stdClass $prepared_post The prepared post.
	 * @return array<string, mixed> The slashed post data.
	 */
	private function slash_post_data( stdClass $prepared_post ): array {
		$slashed = wp_slash( (array) $prepared_post );

		return is_array( $slashed ) ? $slashed : array();
	}

	/**
	 * Builds the error returned when an input combination is invalid.
	 *
	 * @since x.x.x
	 *
	 * @param string $message The error message.
	 * @return \WP_Error The invalid-field error.
	 */
	private function invalid_field_error( string $message ): WP_Error {
		return new WP_Error( 'content_invalid_field', $message, array( 'status' => 400 ) );
	}

	/**
	 * Builds the error returned when trashing or deleting a post fails.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Error The cannot-delete error.
	 */
	private function cannot_delete_error(): WP_Error {
		return new WP_Error(
			'content_cannot_delete',
			__( 'The post cannot be deleted.', 'ai' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Builds the uniform not-found error.
	 *
	 * Unreachable through gated transports, which run the ability's permission callback
	 * first and deny the same lookups. It is kept so that a direct call to an execute
	 * callback still fails closed on a structural lookup failure: a missing post, a post
	 * type that is not exposed, or a post type that does not match the requested one.
	 *
	 * This is not a permission check. The execute callbacks deliberately do not repeat
	 * the read, edit, or delete checks that the permission callbacks already performed,
	 * so a direct call bypasses them. Only invoke the callbacks through
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

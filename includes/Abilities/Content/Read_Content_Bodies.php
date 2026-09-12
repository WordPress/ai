<?php
/**
 * The `ai/read-content-bodies` WordPress Ability.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content;

use WP_Post;
use WP_Post_Type;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Read_Content_Bodies
 *
 * Registers the read-only `ai/read-content-bodies` ability, which returns the full body
 * of a small number of posts named by ID.
 *
 * This is a sibling of {@see Search_Content}, and exists for the same reason: a
 * conversational surface needs to read the posts it finds, and `core/read-content` is
 * registered behind the Custom Abilities experiment. Depending on that ability would
 * make this tool appear and disappear with a switch that has nothing to do with it, so
 * the reading path is registered here instead and every ability consumer — the AI
 * Workspace, the MCP surface, and the Abilities Explorer — gets the same behavior.
 *
 * Two properties are load bearing:
 *
 *  - At most {@see self::MAX_POSTS} posts are returned per call. That is a context
 *    limit for the model, not an access control, and it is enforced both in the input
 *    schema and again in {@see self::execute_read_content_bodies()} so it survives a
 *    transport that never validated the input.
 *  - Every returned body is filtered at execute time by the requesting user's own
 *    capabilities, using the same read permission walk {@see Search_Content} performs,
 *    so a body the user could not otherwise read is never returned. The permission
 *    callback can only gate the request coarsely, because which posts the given IDs
 *    resolve to is unknown until they are loaded.
 *
 * An ID that names no post and an ID that names a post the user may not read are
 * reported identically, in `unavailable`. The caller supplied the IDs, so this
 * discloses nothing it did not already know, and it keeps the two cases
 * indistinguishable.
 *
 * For that reason this ability deliberately reports no withheld count, even though
 * {@see Search_Content} does. This is not an oversight to be tidied away: splitting
 * `unavailable` into "no such post" and "withheld", even as a bare number, answers
 * "does post 4211 exist?" for the exact IDs the caller named, one call at a time.
 * A search aggregates over a query the caller never enumerated, so a count there
 * stands for no particular post and discloses nothing comparable.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Read_Content_Bodies {

	/**
	 * The ability name.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const ABILITY = 'ai/read-content-bodies';

	/**
	 * The ability category used for content abilities.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const CATEGORY = 'content';

	/**
	 * Maximum number of posts whose bodies are returned in one call.
	 *
	 * A body is far longer than a search row, so the cap is much lower than the search
	 * ability's. See {@see self::normalize_ids()}.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const MAX_POSTS = 5;

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * The `content` category is registered as a fallback so the ability can be enabled
	 * on its own, without the experiment that registers {@see Content}.
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
	 * Registers the read-only `ai/read-content-bodies` ability.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		/*
		 * Nothing is readable when no post type is exposed to abilities, so the tool is
		 * not offered at all rather than offered and always empty.
		 */
		if ( array() === $this->get_exposed_post_types() ) {
			return;
		}

		if ( wp_has_ability( self::ABILITY ) ) {
			wp_unregister_ability( self::ABILITY );
		}

		wp_register_ability(
			self::ABILITY,
			array(
				'label'               => __( 'Read Content Bodies', 'ai' ),
				'description'         => sprintf(
					/* translators: %d: the maximum number of posts whose bodies are returned per call. */
					__( 'Returns the full body text of posts named by ID, for at most %d posts per call. Bodies are filtered by the current user\'s capabilities at the moment of the call, so a post the user cannot read is never returned; an ID that was not returned is listed under "unavailable", which means either that no such post exists or that the user may not read it. The body of a password-protected post is empty unless the user can edit that post. Requires an authenticated user.', 'ai' ),
					self::MAX_POSTS
				),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => array( $this, 'execute_read_content_bodies' ),
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
	 * Permission callback for the `ai/read-content-bodies` ability.
	 *
	 * This gate is coarse by necessity: which posts the requested IDs resolve to, and
	 * therefore which capabilities apply, is unknown until they are loaded. It only
	 * checks that the caller is authenticated and that at least one post type is exposed
	 * to abilities. Row-level read permission is enforced in
	 * {@see self::execute_read_content_bodies()}, which is the check that keeps bodies
	 * the caller cannot read out of the result.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_permission( $input = array() ): bool {
		unset( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		return array() !== $this->get_exposed_post_types();
	}

	/**
	 * Executes the `ai/read-content-bodies` ability.
	 *
	 * {@see \WP_Ability::execute()} always runs {@see self::check_permission()} first, so
	 * this only needs to enforce what the gate could not: every requested post is checked
	 * against the current user's read permission before its body is included, mirroring
	 * the row filtering {@see Search_Content} performs.
	 *
	 * Unreadable IDs are returned in `unavailable` alongside IDs that match no post, and
	 * are deliberately not counted separately; see the class docblock.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array{posts: list<array<string, mixed>>, unavailable: list<int>} The bounded, filtered result set.
	 */
	public function execute_read_content_bodies( $input = array() ): array {
		$input = rest_sanitize_object( $input );

		$posts       = array();
		$unavailable = array();

		foreach ( $this->normalize_ids( $input ) as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post || ! $this->check_read_permission( $post ) ) {
				$unavailable[] = $post_id;
				continue;
			}

			$posts[] = $this->format_post( $post );
		}

		return array(
			'posts'       => $posts,
			'unavailable' => $unavailable,
		);
	}

	/**
	 * Normalizes the requested post IDs to a unique, bounded list.
	 *
	 * Clamping here as well as in the input schema keeps the bound in force on transports
	 * that do not validate input against the schema. A GET request delivers list inputs as
	 * scalar/CSV strings, so they are parsed the same way schema validation did
	 * (wp_parse_list) and are honored regardless of transport, mirroring {@see Content}.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return list<int> Up to {@see self::MAX_POSTS} unique, positive post IDs.
	 */
	private function normalize_ids( array $input ): array {
		$value = $input['ids'] ?? null;
		if ( ! is_array( $value ) && ! is_string( $value ) ) {
			return array();
		}

		$ids = array();

		foreach ( wp_parse_list( $value ) as $raw_id ) {
			if ( ! is_scalar( $raw_id ) ) {
				continue;
			}

			$post_id = absint( $raw_id );
			if ( 0 === $post_id || in_array( $post_id, $ids, true ) ) {
				continue;
			}

			$ids[] = $post_id;

			if ( count( $ids ) === self::MAX_POSTS ) {
				break;
			}
		}

		return $ids;
	}

	/**
	 * Returns the post types exposed through the Abilities API, keyed by name.
	 *
	 * Deliberately resolved on every call rather than cached: post types can be
	 * unregistered or re-registered with different arguments between the ability
	 * being registered and the ability being used.
	 *
	 * @since x.x.x
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
	 * Checks if a post can be read by the current user.
	 *
	 * Mirrors `check_read_permission()` in {@see Search_Content} — itself a mirror of the
	 * REST posts controller's read permission — including the inherited-parent walk, so
	 * the abilities cannot disagree about what a user may see. This is the filter that
	 * keeps a body the caller cannot read out of the result.
	 *
	 * @since x.x.x
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
		if ( ! $post_type instanceof WP_Post_Type || empty( $post_type->show_in_abilities ) ) {
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
	 * Formats a readable post into a result row.
	 *
	 * The body of a password-protected post is withheld unless the current user can edit
	 * that post, mirroring the REST posts controller. For such an editor the cookie-based
	 * password gate is suspended only while this post's body is built, so other protected
	 * posts a filter may render stay locked.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The post object.
	 * @return array<string, mixed> The formatted result row.
	 */
	private function format_post( WP_Post $post ): array {
		$can_edit          = current_user_can( 'edit_post', $post->ID );
		$password_required = post_password_required( $post );

		if ( $password_required && $can_edit ) {
			add_filter( 'post_password_required', array( $this, 'allow_password_content' ), 10, 2 );

			try {
				return $this->build_result( $post, false );
			} finally {
				remove_filter( 'post_password_required', array( $this, 'allow_password_content' ), 10 );
			}
		}

		return $this->build_result( $post, $password_required );
	}

	/**
	 * Builds the result row for a post.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post         The post object.
	 * @param bool     $is_protected Whether the body must be withheld as password-protected.
	 * @return array<string, mixed> The formatted result row.
	 */
	private function build_result( WP_Post $post, bool $is_protected ): array {
		return array(
			'id'                => (int) $post->ID,
			'post_type'         => $post->post_type,
			'status'            => $post->post_status,
			'date'              => $this->format_date( $post ),
			'slug'              => $post->post_name,
			'link'              => (string) get_permalink( $post ),
			'title'             => post_type_supports( $post->post_type, 'title' ) ? $this->get_title( $post ) : '',
			'content'           => $is_protected ? '' : $this->get_content( $post ),
			'content_protected' => '' !== (string) $post->post_password,
			'edit_link'         => $this->get_edit_link( $post ),
		);
	}

	/**
	 * Returns the editor URL for a post the current user can edit.
	 *
	 * The URL doubles as the permission proof: it is present only when the current user
	 * can edit the post, so a consumer offering an edit affordance does not have to
	 * re-derive the capability, and cannot construct an editor URL for a post the user
	 * may only read. Mirrors {@see Search_Content}, including the deliberately redundant
	 * capability check: the emptiness of this field is a permission guarantee consumers
	 * rely on, and that guarantee should not rest on the internals of a core function
	 * that is free to change.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The post being formatted.
	 * @return string The editor URL, or an empty string when the user cannot edit the post.
	 */
	private function get_edit_link( WP_Post $post ): string {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return '';
		}

		return (string) get_edit_post_link( $post, 'raw' );
	}

	/**
	 * Filters {@see post_password_required()} to unlock only posts the current user can edit.
	 *
	 * Added by {@see self::format_post()} while formatting a password-protected post the
	 * current user can edit, so the body resolves to its real value without also
	 * unlocking other protected posts a content filter may render.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
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
	 * @since x.x.x
	 *
	 * @return string The unprefixed title format.
	 */
	public function return_raw_title_format(): string {
		return '%s';
	}

	/**
	 * Returns the whole post body as plain text.
	 *
	 * The body is rendered through the `the_content` filter chain, mirroring the REST
	 * posts controller, and then stripped of markup. The model is given text to reason
	 * about, not markup to reproduce: block comments and HTML attributes are noise in the
	 * context window, and stripping them removes an obvious carrier for markup the
	 * transcript would otherwise have to render inert again downstream.
	 *
	 * Post globals are prepared and restored around the filters so filters relying on
	 * loop globals render against the requested post, mirroring {@see Content}.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The post object.
	 * @return string The plain-text post body.
	 */
	private function get_content( WP_Post $post ): string {
		if ( ! post_type_supports( $post->post_type, 'editor' ) ) {
			return '';
		}

		$previous_post = $GLOBALS['post'] ?? null;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily mirrors REST post context for content rendering.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		/*
		 * The global post context is restored in a finally block so a throw from a
		 * content filter cannot leave it pointing at this post for the rest of the
		 * request.
		 */
		try {
			/** This filter is documented in wp-includes/post-template.php. */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying the core content filter to mirror REST rendering.
			$content = apply_filters( 'the_content', $post->post_content );

			if ( ! is_string( $content ) ) {
				return '';
			}

			return trim( (string) preg_replace( '/[ \t]*\n[ \t\n]*/', "\n", wp_strip_all_tags( $content ) ) );
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
	 * Formats the post publication date as an ISO 8601 string in the site's timezone.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The post object.
	 * @return string The ISO 8601 date, or an empty string if unavailable.
	 */
	private function format_date( WP_Post $post ): string {
		$datetime = get_post_datetime( $post, 'date', 'local' );

		return $datetime ? $datetime->format( 'c' ) : '';
	}

	/**
	 * Builds the input schema for the `ai/read-content-bodies` ability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'ids' ),
			'additionalProperties' => false,
			'properties'           => array(
				'ids' => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'minItems'    => 1,
					'maxItems'    => self::MAX_POSTS,
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'description' => sprintf(
						/* translators: %d: the maximum number of posts whose bodies are returned per call. */
						__( 'The IDs of the posts to read, at most %d per call. Ask for a later batch in a separate call rather than a longer list.', 'ai' ),
						self::MAX_POSTS
					),
				),
			),
		);
	}

	/**
	 * Builds the output schema for the `ai/read-content-bodies` ability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_output_schema(): array {
		$post_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id', 'post_type', 'status', 'date', 'slug', 'link', 'title', 'content', 'content_protected', 'edit_link' ),
			'properties'           => array(
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
				'slug'              => array(
					'type'        => 'string',
					'description' => __( 'The post slug.', 'ai' ),
				),
				'link'              => array(
					'type'        => 'string',
					'description' => __( 'The permalink URL.', 'ai' ),
				),
				'title'             => array(
					'type'        => 'string',
					'description' => __( 'The rendered post title. Empty when the post type does not support titles.', 'ai' ),
				),
				'content'           => array(
					'type'        => 'string',
					'description' => __( 'The whole post body as plain text. Empty when withheld for a password-protected post, or when the post type has no editor.', 'ai' ),
				),
				'content_protected' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the post is protected with a password.', 'ai' ),
				),
				'edit_link'         => array(
					'type'        => 'string',
					'description' => __( 'The editor URL for this post, present only when the current user can edit it. Empty string otherwise.', 'ai' ),
				),
			),
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'posts', 'unavailable' ),
			'properties'           => array(
				'posts'       => array(
					'type'        => 'array',
					'description' => __( 'The requested posts the current user may read, with their bodies.', 'ai' ),
					'items'       => $post_schema,
				),
				'unavailable' => array(
					'type'        => 'array',
					'description' => __( 'The requested IDs that were not returned, either because no such post exists or because the current user may not read it. The two cases are not distinguished.', 'ai' ),
					'items'       => array( 'type' => 'integer' ),
				),
			),
		);
	}
}

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
 * Registers the read-only `ai/read-content-bodies` ability, which returns the full body of posts named by ID.
 *
 * Missing and unreadable IDs are both reported in `unavailable`, with no withheld count, so it can't probe existence.
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
	 * @since x.x.x
	 * @var int
	 */
	private const MAX_POSTS = 5;

	/**
	 * Hooks the ability into the Abilities API.
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
		if ( array() === $this->get_exposed_post_types() ) {
			return;
		}

		wp_register_ability(
			self::ABILITY,
			array(
				'label'               => __( 'Read Content Bodies', 'ai' ),
				'description'         => sprintf(
					/* translators: %d: the maximum number of posts whose bodies are returned per call. */
					__( 'Returns the full body text of posts named by ID, for at most %d posts per call. Bodies are filtered by the current user\'s capabilities at the moment of the call, so a post the user cannot read is never returned; an ID that was not returned is listed under "unavailable", which means either that no such post exists or that the user may not read it. The body of a password-protected post is empty unless the user can edit that post. Bodies are plain text by default; pass "raw": true to also receive, as "content_raw", the stored block markup of each returned post the user can edit, which is what a proposed content change must be built from. Requires an authenticated user.', 'ai' ),
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
						// MCP clients assume open-world when the hint is absent.
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
	 * Coarse by necessity; per-post read permission is enforced at execute time.
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
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array{posts: list<array<string, mixed>>, unavailable: list<int>} The bounded, filtered result set.
	 */
	public function execute_read_content_bodies( $input = array() ): array {
		$input = rest_sanitize_object( $input );

		$posts       = array();
		$unavailable = array();

		// GET delivers the flag as a string.
		$raw = isset( $input['raw'] ) && is_scalar( $input['raw'] ) && true === rest_sanitize_boolean( (string) $input['raw'] );

		foreach ( $this->normalize_ids( $input ) as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post || ! $this->check_read_permission( $post ) ) {
				$unavailable[] = $post_id;
				continue;
			}

			$posts[] = $this->format_post( $post, $raw );
		}

		return array(
			'posts'       => $posts,
			'unavailable' => $unavailable,
		);
	}

	/**
	 * Normalizes the requested post IDs to a unique, bounded list.
	 *
	 * Clamped here too, for transports that skip schema validation.
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
	 * Not cached: post types can be re-registered between registration and use.
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
	 * Mirrors {@see Search_Content}'s check, itself a mirror of the REST posts controller.
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

		if ( is_post_publicly_viewable( $post ) ) {
			return true;
		}

		// Resolves an attachment's `inherit` through its parent.
		$post_status = get_post_status( $post );
		if ( ! is_string( $post_status ) ) {
			return false;
		}

		$post_status_object = get_post_status_object( $post_status );
		if ( ! $post_status_object instanceof \stdClass ) {
			return false;
		}

		// Public but not viewable: core's `read_post` would only require `read`.
		if ( $post_status_object->public ) {
			return current_user_can( 'edit_post', $post->ID );
		}

		if ( current_user_can( 'read_post', $post->ID ) ) {
			return true;
		}

		// Inherited-parent walk, fail-closed on missing parents or loops.
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
	 * A password-protected body is withheld unless the user can edit the post, as in REST.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post The post object.
	 * @param bool     $raw  Whether to include the stored markup for a post the user can edit.
	 * @return array<string, mixed> The formatted result row.
	 */
	private function format_post( WP_Post $post, bool $raw ): array {
		$can_edit          = current_user_can( 'edit_post', $post->ID );
		$password_required = post_password_required( $post );

		if ( $password_required && $can_edit ) {
			add_filter( 'post_password_required', array( $this, 'allow_password_content' ), 10, 2 );

			try {
				return $this->build_result( $post, false, $raw );
			} finally {
				remove_filter( 'post_password_required', array( $this, 'allow_password_content' ), 10 );
			}
		}

		return $this->build_result( $post, $password_required, $raw && $can_edit );
	}

	/**
	 * Builds the result row for a post.
	 *
	 * Stored markup is editor-only: it can carry what rendered text hides, such as block attributes.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post         The post object.
	 * @param bool     $is_protected Whether the body must be withheld as password-protected.
	 * @param bool     $include_raw  Whether to include the stored markup; the caller has checked `edit_post`.
	 * @return array<string, mixed> The formatted result row.
	 */
	private function build_result( WP_Post $post, bool $is_protected, bool $include_raw = false ): array {
		$result = array(
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

		// Re-checked: the field's presence is a permission guarantee.
		if (
			$include_raw
			&& ! $is_protected
			&& post_type_supports( $post->post_type, 'editor' )
			&& current_user_can( 'edit_post', $post->ID )
		) {
			$result['content_raw'] = (string) $post->post_content;
		}

		return $result;
	}

	/**
	 * Returns the editor URL for a post the current user can edit.
	 *
	 * Consumers treat a non-empty URL as permission proof, hence the explicit capability check.
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
	 * Returns the post body rendered through `the_content` and stripped to plain text.
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
				'raw' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Also return each post\'s stored block markup, as "content_raw", for the posts you can edit. Use it before proposing a change to a post\'s content, and build the new content from it.', 'ai' ),
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
				'content_raw'       => array(
					'type'        => 'string',
					'description' => __( 'The post body exactly as stored, block markup included. Present only when "raw" was requested and the current user can edit the post.', 'ai' ),
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

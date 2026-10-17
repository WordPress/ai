<?php
/**
 * The `core/comments-query` WordPress Ability.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Comments;

use WP_Comment;
use WP_Error;
use WP_Post;
use stdClass;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Comments
 *
 * Registers the read-only `core/comments-query` ability. This first increment supports
 * fetching a single readable comment by ID; querying a collection of comments (by post,
 * parent, status, and other filters) is added in a follow-up ability-schema increment —
 * see https://github.com/WordPress/ai/issues/1064.
 *
 * Permission and field visibility mirror `WP_REST_Comments_Controller`: an approved
 * comment on a post the current user can read is visible to anyone, including a
 * logged-out visitor. Anything else — an unapproved comment, a comment with no post, or
 * a comment on a post the viewer cannot read — requires `moderate_comments` or being the
 * comment's own author. Raw content, the author's email, and the author's IP address are
 * additionally restricted to `moderate_comments`, matching the REST controller's `edit`
 * context. A comment on a post type not exposed
 * via `show_in_abilities` is never readable through this ability, even by a moderator.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Comments {

	/**
	 * The ability category used for the comments ability.
	 *
	 * Reuses the `content` category that `core/content-query` registers, since comments
	 * are read alongside the content they belong to.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const CATEGORY = 'content';

	/**
	 * Fields that expose edit-context comment data.
	 *
	 * Requests that explicitly include any of these fields require `moderate_comments`,
	 * matching the REST comments controller's `edit` context.
	 *
	 * @since x.x.x
	 * @var list<string>
	 */
	private array $moderator_fields = array(
		'content_raw',
		'author_email',
		'author_ip',
	);

	/**
	 * Cached comment field definitions, keyed by field name in output order.
	 *
	 * @since x.x.x
	 * @var array<string, mixed>|null
	 */
	private ?array $comment_properties = null;

	/**
	 * Default fields returned when the caller does not request a field subset.
	 *
	 * @since x.x.x
	 * @var list<string>
	 */
	private array $default_fields = array(
		'id',
		'post',
		'parent',
		'author',
		'date',
		'content_rendered',
		'status',
		'type',
		'link',
	);

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
	 * Defensive: `core/content-query` already registers this category, but this ability
	 * does not depend on that class running first.
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
	 * Registers all comment abilities.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		$this->register_comments_query();
	}

	/**
	 * Registers the read-only `core/comments-query` ability.
	 *
	 * @since x.x.x
	 */
	private function register_comments_query(): void {
		if ( wp_has_ability( 'core/comments-query' ) ) {
			wp_unregister_ability( 'core/comments-query' );
		}

		wp_register_ability(
			'core/comments-query',
			array(
				'label'               => __( 'Comments Query', 'ai' ),
				'description'         => __( 'Reads comments. Single-comment lookups by ID return the comment object directly. Requires an authenticated moderator to read an unapproved comment or one on a post the current user cannot read; anyone who can read the post may read its approved comments.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_comments_query_input_schema(),
				'output_schema'       => $this->get_comments_query_output_schema(),
				'execute_callback'    => array( $this, 'execute_comments_query' ),
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
	 * Permission callback for the `core/comments-query` ability.
	 *
	 * This gate is the authoritative permission decision for single-comment mode: it
	 * resolves the requested comment and denies a missing or unreadable one before
	 * execution.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_permission( $input = array() ): bool {
		$input = $this->to_input_array( $input );

		if ( ! empty( $input['id'] ) ) {
			return $this->resolve_readable_comment( $this->input_int( $input['id'] ) ) instanceof WP_Comment;
		}

		return false;
	}

	/**
	 * Executes the `core/comments-query` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::check_permission()} first, so
	 * this only re-validates the lookup itself.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\stdClass|\WP_Error A single comment, or a WP_Error.
	 */
	public function execute_comments_query( $input = array() ) {
		$input  = $this->to_input_array( $input );
		$fields = $this->normalize_fields( $input );

		if ( ! empty( $input['id'] ) ) {
			$comment = $this->resolve_readable_comment( $this->input_int( $input['id'] ) );

			if ( ! $comment instanceof WP_Comment ) {
				return $this->not_found_error();
			}

			return $this->to_output_comment( $this->format_comment( $comment, $fields ) );
		}

		return $this->not_found_error();
	}

	/**
	 * Casts raw ability input to an array.
	 *
	 * Schema validation accepts object input (`rest_is_object()` allows a `stdClass`), so
	 * it must be treated as equivalent to its array form rather than discarded. Any other
	 * non-array input is replaced with an empty array.
	 *
	 * @since x.x.x
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
	 * @since x.x.x
	 *
	 * @param mixed $value The raw input value.
	 * @return int The value as a non-negative integer, or 0 when not scalar.
	 */
	private function input_int( $value ): int {
		return is_scalar( $value ) ? absint( $value ) : 0;
	}

	/**
	 * Resolves a comment by ID when the current user may read it.
	 *
	 * Shared by the permission and execute callbacks so the single-comment
	 * authorization decision has exactly one implementation.
	 *
	 * @since x.x.x
	 *
	 * @param int $id The comment ID.
	 * @return \WP_Comment|null The readable comment, or null when not found or not readable.
	 */
	private function resolve_readable_comment( int $id ): ?WP_Comment {
		if ( $id <= 0 ) {
			return null;
		}

		$comment = get_comment( $id );
		if ( ! $comment instanceof WP_Comment ) {
			return null;
		}

		return $this->check_read_permission( $comment ) ? $comment : null;
	}

	/**
	 * Checks whether a comment can be read by the current user.
	 *
	 * Mirrors the REST comments controller's read permission: an approved comment on a
	 * readable post is visible to anyone, including a logged-out visitor. Everything else
	 * requires being logged in and either the comment's own author or able to moderate it.
	 * A `note`-type comment (used by the Editorial Notes experiment) never qualifies for
	 * the public, approved-comment path, since notes are internal editorial content.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Comment $comment The comment object.
	 * @return bool Whether the comment can be read.
	 */
	private function check_read_permission( WP_Comment $comment ): bool {
		if ( ! empty( $comment->comment_post_ID ) ) {
			$post = get_post( (int) $comment->comment_post_ID );

			if ( $post instanceof WP_Post ) {
				$post_type = get_post_type_object( $post->post_type );
				if ( ! $post_type instanceof \WP_Post_Type || empty( $post_type->show_in_abilities ) ) {
					return false;
				}

				if ( 'note' !== $comment->comment_type
					&& $this->check_read_post_permission( $post )
					&& 1 === (int) $comment->comment_approved
				) {
					return true;
				}
			}
		}

		if ( 0 === get_current_user_id() ) {
			return false;
		}

		if ( empty( $comment->comment_post_ID ) && ! current_user_can( 'moderate_comments' ) ) {
			return false;
		}

		if ( ! empty( $comment->user_id ) && get_current_user_id() === (int) $comment->user_id ) {
			return true;
		}

		return current_user_can( 'edit_comment', $comment->comment_ID );
	}

	/**
	 * Checks whether a comment's post can be read by the current user.
	 *
	 * Mirrors `Content::check_read_permission()` (the `core/content-query` ability),
	 * additionally requiring the post's type to be exposed via `show_in_abilities`: a post
	 * type that `core/content-query` cannot reach should not have its comments reachable
	 * through this ability either.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post         $post             Post object.
	 * @param array<int, true> $checked_post_ids Post IDs already checked while walking inherited parents.
	 * @return bool Whether the post can be read.
	 */
	private function check_read_post_permission( WP_Post $post, array $checked_post_ids = array() ): bool {
		if ( isset( $checked_post_ids[ $post->ID ] ) ) {
			return false;
		}

		$checked_post_ids[ $post->ID ] = true;

		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type instanceof \WP_Post_Type || empty( $post_type->show_in_abilities ) ) {
			return false;
		}

		if ( is_post_publicly_viewable( $post ) ) {
			return true;
		}

		$post_status = get_post_status( $post );
		if ( ! is_string( $post_status ) ) {
			return false;
		}

		$post_status_object = get_post_status_object( $post_status );
		if ( ! $post_status_object instanceof \stdClass ) {
			return false;
		}

		if ( $post_status_object->public ) {
			return current_user_can( 'edit_post', $post->ID );
		}

		if ( current_user_can( 'read_post', $post->ID ) ) {
			return true;
		}

		if (
			'inherit' === $post->post_status &&
			$post->post_parent > 0 &&
			(int) $post->post_parent !== (int) $post->ID
		) {
			$parent = get_post( $post->post_parent );
			if ( $parent instanceof WP_Post ) {
				return $this->check_read_post_permission( $parent, $checked_post_ids );
			}
		}

		return false;
	}

	/**
	 * Returns the requested fields, or a lean default set when none are given.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return list<string> List of requested field names.
	 */
	private function normalize_fields( array $input ): array {
		$fields = $this->parse_list_input( $input, 'fields' );

		return array() === $fields ? $this->default_fields : $fields;
	}

	/**
	 * Parses a raw list input into a list of strings.
	 *
	 * A GET request delivers list inputs as scalar/CSV strings; this parses them the same
	 * way schema validation did (`wp_parse_list()`) so they are honored regardless of
	 * transport, until core sanitizes ability input itself.
	 *
	 * @since x.x.x
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
	 * Returns the comment field definitions, keyed by field name in output order.
	 *
	 * This is the single source of truth for the ability's comment fields: the output
	 * schema uses the definitions directly, while the input schema fields enum uses the
	 * keys.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> Comment field definitions.
	 */
	private function get_comment_properties(): array {
		if ( null !== $this->comment_properties ) {
			return $this->comment_properties;
		}

		$this->comment_properties = array(
			'id'                 => array(
				'type'        => 'integer',
				'description' => __( 'The comment ID.', 'ai' ),
			),
			'post'               => array(
				'type'        => 'integer',
				'description' => __( 'The ID of the post the comment belongs to. 0 for a comment with no associated post.', 'ai' ),
			),
			'parent'             => array(
				'type'        => 'integer',
				'description' => __( 'The ID of the parent comment, for a threaded reply. 0 for a top-level comment.', 'ai' ),
			),
			'author'             => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __( 'The author user ID. 0 when the comment was left by a non-logged-in commenter.', 'ai' ),
					),
					'name' => array(
						'type'        => 'string',
						'description' => __( 'The author display name, as submitted with the comment.', 'ai' ),
					),
				),
				'description'          => __( 'The comment author.', 'ai' ),
			),
			'author_email'       => array(
				'type'        => 'string',
				'description' => __( "The author's submitted email address. Present only for users who can moderate comments.", 'ai' ),
			),
			'author_ip'          => array(
				'type'        => 'string',
				'description' => __( "The author's IP address. Present only for users who can moderate comments.", 'ai' ),
			),
			'author_url'         => array(
				'type'        => 'string',
				'description' => __( "The author's submitted website URL.", 'ai' ),
			),
			'date'               => array(
				'type'        => 'string',
				'description' => __( "The comment date, in ISO 8601 format using the site's timezone.", 'ai' ),
			),
			'date_gmt'           => array(
				'type'        => 'string',
				'description' => __( 'The comment date, in ISO 8601 format as GMT.', 'ai' ),
			),
			'content_raw'        => array(
				'type'        => 'string',
				'description' => __( 'The raw, unfiltered comment content. Present only for users who can moderate comments.', 'ai' ),
			),
			'content_rendered'   => array(
				'type'        => 'string',
				'description' => __( 'The rendered comment content (HTML).', 'ai' ),
			),
			'link'               => array(
				'type'        => 'string',
				'description' => __( 'The permalink URL to the comment.', 'ai' ),
			),
			'status'             => array(
				'type'        => 'string',
				'enum'        => array( 'approved', 'hold', 'spam', 'trash' ),
				'description' => __( 'The comment status.', 'ai' ),
			),
			'type'               => array(
				'type'        => 'string',
				'description' => __( "The comment type, e.g. 'comment' or 'pingback'.", 'ai' ),
			),
			'author_avatar_urls' => array(
				'type'                 => 'object',
				'description'          => __( 'Avatar URLs for the comment author, keyed by image size in pixels. Present when the show_avatars option is enabled.', 'ai' ),
				'additionalProperties' => array(
					'type' => 'string',
				),
			),
		);

		return $this->comment_properties;
	}

	/**
	 * Builds the input schema for the `core/comments-query` ability.
	 *
	 * Currently has a single mode: get a single readable comment by `id`. A collection
	 * mode (querying by post, parent, status, and other filters) is added in a follow-up
	 * ability-schema increment — see https://github.com/WordPress/ai/issues/1064.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_comments_query_input_schema(): array {
		$fields = array(
			'type'        => 'array',
			'uniqueItems' => true,
			'items'       => array(
				'type' => 'string',
				'enum' => array_keys( $this->get_comment_properties() ),
			),
			'description' => __( 'Limit the returned comment to these fields. If omitted, a lean set of common read fields is returned. Explicit raw/moderator field requests require the ability to moderate comments.', 'ai' ),
		);

		return array(
			'type'  => 'object',
			'oneOf' => array(
				array(
					'title'                => __( 'Get a single readable comment by ID', 'ai' ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Retrieve a single readable comment by ID.', 'ai' ),
						),
						'fields' => $fields,
					),
				),
			),
		);
	}

	/**
	 * Builds the output schema for the `core/comments-query` ability.
	 *
	 * No field is marked required because the `fields` input lets the caller request any
	 * subset.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_comments_query_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $this->get_comment_properties(),
		);
	}

	/**
	 * Prepares a formatted comment for output.
	 *
	 * A field projection can legitimately be empty, for example when the only requested
	 * field is a moderator-only one and the current user is not a moderator. An empty PHP
	 * array encodes as `[]`, which would break the `object` output schema, so return an
	 * empty object instead.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $formatted The formatted comment data.
	 * @return array<string, mixed>|\stdClass The comment data, or an empty object when the projection is empty.
	 */
	private function to_output_comment( array $formatted ) {
		return array() === $formatted ? (object) array() : $formatted;
	}

	/**
	 * Formats a comment into the ability output shape.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Comment  $comment The comment object.
	 * @param list<string> $fields  The requested field names.
	 * @return array<string, mixed> The formatted comment data.
	 */
	private function format_comment( WP_Comment $comment, array $fields ): array {
		$can_moderate = current_user_can( 'moderate_comments' );

		// Moderator-only fields require moderate_comments; drop them so
		// $moderator_fields is the single gate.
		if ( ! $can_moderate ) {
			$fields = array_diff( $fields, $this->moderator_fields );
		}

		$requested = array_flip( $fields );
		$data      = array();

		if ( isset( $requested['id'] ) ) {
			$data['id'] = (int) $comment->comment_ID;
		}
		if ( isset( $requested['post'] ) ) {
			$data['post'] = (int) $comment->comment_post_ID;
		}
		if ( isset( $requested['parent'] ) ) {
			$data['parent'] = (int) $comment->comment_parent;
		}
		if ( isset( $requested['author'] ) ) {
			$data['author'] = array(
				'id'   => (int) $comment->user_id,
				'name' => (string) $comment->comment_author,
			);
		}
		if ( isset( $requested['author_email'] ) ) {
			$data['author_email'] = (string) $comment->comment_author_email;
		}
		if ( isset( $requested['author_ip'] ) ) {
			$data['author_ip'] = (string) $comment->comment_author_IP;
		}
		if ( isset( $requested['author_url'] ) ) {
			$data['author_url'] = (string) $comment->comment_author_url;
		}
		if ( isset( $requested['date'] ) ) {
			$data['date'] = $this->format_date( (string) $comment->comment_date );
		}
		if ( isset( $requested['date_gmt'] ) ) {
			$data['date_gmt'] = $this->format_date( (string) $comment->comment_date_gmt );
		}
		if ( isset( $requested['content_raw'] ) ) {
			$data['content_raw'] = (string) $comment->comment_content;
		}
		if ( isset( $requested['content_rendered'] ) ) {
			/** This filter is documented in wp-includes/comment-template.php */
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying the core comment-content filter to mirror REST rendering.
			$rendered                 = apply_filters( 'comment_text', $comment->comment_content, $comment, array() );
			$data['content_rendered'] = is_string( $rendered ) ? $rendered : '';
		}
		if ( isset( $requested['status'] ) ) {
			$data['status'] = $this->format_status( $comment->comment_approved );
		}
		if ( isset( $requested['type'] ) ) {
			$data['type'] = (string) get_comment_type( $comment );
		}
		if ( isset( $requested['link'] ) ) {
			$data['link'] = (string) get_comment_link( $comment );
		}
		if ( isset( $requested['author_avatar_urls'] ) && get_option( 'show_avatars' ) ) {
			$data['author_avatar_urls'] = array_map(
				static function ( $url ) {
					return is_string( $url ) ? $url : '';
				},
				rest_get_avatar_urls( $comment )
			);
		}

		return $data;
	}

	/**
	 * Formats a comment date column as an ISO 8601 string.
	 *
	 * @since x.x.x
	 *
	 * @param string $mysql_date A MySQL-format date, e.g. `comment_date` or `comment_date_gmt`.
	 * @return string The ISO 8601 date, or an empty string if unavailable.
	 */
	private function format_date( string $mysql_date ): string {
		if ( '' === $mysql_date || '0000-00-00 00:00:00' === $mysql_date ) {
			return '';
		}

		$formatted = mysql_to_rfc3339( $mysql_date );

		return is_string( $formatted ) ? $formatted : '';
	}

	/**
	 * Maps the raw `comment_approved` column to a stable status string.
	 *
	 * Mirrors the REST comments controller's `prepare_status_response()`.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $comment_approved The raw `comment_approved` column value.
	 * @return string One of 'approved', 'hold', 'spam', or 'trash'.
	 */
	private function format_status( $comment_approved ): string {
		switch ( (string) $comment_approved ) {
			case '0':
			case 'hold':
				return 'hold';
			case '1':
			case 'approve':
				return 'approved';
			case 'spam':
				return 'spam';
			case 'trash':
				return 'trash';
			default:
				return (string) $comment_approved;
		}
	}

	/**
	 * Builds the uniform not-found error.
	 *
	 * Unreachable through gated transports, which run {@see self::check_permission()}
	 * first and deny the same lookups. It is kept so that a direct call to the execute
	 * callback still fails closed on a structural lookup failure.
	 *
	 * This is not a permission check. The execute callback deliberately does not repeat
	 * the read check {@see self::check_permission()} already performed, so a direct call
	 * bypasses it. Only invoke the callback through {@see WP_Ability::execute()}, which
	 * always runs the permission callback first.
	 *
	 * @since x.x.x
	 *
	 * @return \WP_Error The not-found error.
	 */
	private function not_found_error(): WP_Error {
		return new WP_Error(
			'comments_not_found',
			__( 'The requested comment was not found.', 'ai' ),
			array( 'status' => 404 )
		);
	}
}

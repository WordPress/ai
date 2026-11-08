<?php
/**
 * Canonical WordPress abilities: shared base for the content write abilities.
 *
 * Ported from WordPress/ai PR #1025 (https://github.com/WordPress/ai/pull/1025) by @jorgefilipecosta.
 *
 * The helpers shared with `core/content-query` (read permission, post and author
 * lookup, field projection, post rendering) are extracted verbatim from this
 * package's Content_Query port of core's WP_Abilities_Content, with `private`
 * relaxed to `protected` so the write ability subclasses reuse them. The write
 * logic itself (create, update, delete) is a faithful port of the PR, with the
 * settled divergences of the port plan: this package's dotted field paths
 * (`title.raw`, `content.raw`, `excerpt.raw`, `author.slug`, `date.local`,
 * `date.gmt`) everywhere the PR uses its flat renamed ones, the `default` text
 * domain, and registration whenever the Abilities API is present (an empty
 * exposed-post-type list keeps the `type` enum empty rather than skipping
 * registration, as the PR does).
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Content;

use DateTimeZone;
use stdClass;
use WP_Error;
use WP_Post;
use WP_Post_Type;
use WP_Query;
use WP_User;

use WordPress\Abilities\Abstract_Core_Ability;

/**
 * Shared base for the `core/content-create`, `core/content-update`, and
 * `core/content-delete` abilities.
 *
 * Holds everything the three write abilities share: the post field definitions
 * and projection of `core/content-query` (so a post can be read and written back
 * unchanged), the permission and execute callbacks of each ability, and the
 * input schema builders. Only the Abilities API calls the callbacks: callers go
 * through the registry, such as `wp_get_ability( 'core/content-create' )->execute()`,
 * which validates the input and checks permissions before running them.
 *
 * @package WordPress\Abilities
 */
abstract class Abstract_Content_Write extends Abstract_Core_Ability {
	/**
	 * Fields that expose edit-context post data.
	 *
	 * Requests that explicitly include any of these fields require edit access.
	 *
	 * @var list<string>
	 */
	protected const EDIT_FIELDS = array( 'title.raw', 'content.raw', 'excerpt.raw' );   /**
	 * The ability category used for content abilities.
	 *
	 * @since 7.2.0
	 * @var string
	 */
	protected const CATEGORY = 'content';

	/**
	 * Field paths whose output may read post meta or terms.
	 *
	 * Requests that include any of these, directly or through their whole object, prime
	 * the post meta and term caches for the page. Rendered excerpts and content may read
	 * either, and permalinks read terms when the permalink structure contains `%category%`.
	 * Other fields, such as the rendered title, do not need that cache priming.
	 *
	 * @since 7.2.0
	 * @var list<string>
	 */
	protected const CACHE_PRIMING_FIELDS = array( 'link', 'excerpt.rendered', 'content.rendered' );

	/**
	 * Default field paths returned when the caller does not request a field subset.
	 *
	 * @since 7.2.0
	 * @var list<string>
	 */
	protected const DEFAULT_FIELDS = array( 'id', 'type', 'status', 'date', 'slug', 'title.rendered' );

	/**
	 * Globals that rendering a post changes: the global post and the globals that
	 * setup_postdata() populates.
	 *
	 * @since 7.2.0
	 * @var list<string>
	 */
	protected const LOOP_GLOBALS = array( 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );

	/**
	 * Checks if a post can be read by the current user.
	 *
	 * Mirrors the REST posts controller's read permission, while keeping this ability
	 * authenticated-only via the write abilities' permission callbacks.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post          $post             Post object.
	 * @param array<int, true> $checked_post_ids Post IDs already checked while walking inherited parents.
	 * @return bool Whether the post can be read.
	 */
	protected function check_read_permission( WP_Post $post, array $checked_post_ids = array() ): bool {
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
		if ( ! $post_status_object instanceof stdClass ) {
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
		 * ability fail-closed for missing parents or parent loops. No input can request
		 * `inherit`, since internal statuses are left out of the input schema and of the
		 * statuses a query covers by default, but a query filter such as a `pre_get_posts`
		 * callback can still add it, so such posts keep their parent's read check.
		 */
		if ( 'inherit' === $post->post_status && $post->post_parent > 0 ) {
			$parent = get_post( $post->post_parent );
			if ( $parent instanceof WP_Post ) {
				return $this->check_read_permission( $parent, $checked_post_ids );
			}
		}

		return false;
	}

	/**
	 * Checks whether a field path is requested.
	 *
	 * Matches paths as the REST API's `_fields` parameter does, with rest_is_field_included():
	 * a field such as `title` is requested when it or one of its paths, such as
	 * `title.rendered`, is requested, and a path such as `title.rendered` is requested when it
	 * or its whole field is.
	 *
	 * @since 7.2.0
	 *
	 * @param string       $path   The field path, such as `title` or `title.rendered`.
	 * @param list<string> $fields The requested field paths.
	 * @return bool Whether the field path is requested.
	 */
	protected function is_field_requested( string $path, array $fields ): bool {
		return rest_is_field_included( $path, $fields );
	}

	/**
	 * Looks up the user an author slug names.
	 *
	 * The slug is the user's nicename, which the REST API users endpoint returns as `slug`. It is
	 * looked up as given, like that endpoint's `slug` filter, so the database collation decides
	 * whether a variant in another case matches. A user the current user may not see is reported
	 * like a missing one. The current user can see themselves, any user of the site when they can
	 * list users, and authors with posts in a publicly viewable post type. A user who can edit
	 * others' posts of the post type can also see any user of the site, since they may make any of
	 * them the author, so for them the lookup does tell whether such an account exists.
	 *
	 * On multisite the lookup searches the whole network, so posts by authors who are not
	 * members of the site, such as super admins, can still be filtered. Those users are only
	 * visible as the current user or as authors with posts in a publicly viewable post type, so
	 * the lookup does not tell whether an account exists elsewhere on the network.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed        $slug             The author slug.
	 * @param WP_Post_Type $post_type_object The post type the author is looked up for.
	 * @return WP_User|null The user, or null when the slug does not name a visible user.
	 */
	protected function get_author_by_slug( $slug, WP_Post_Type $post_type_object ): ?WP_User {
		$user = is_string( $slug ) ? get_user_by( 'slug', $slug ) : false;
		if ( ! $user ) {
			return null;
		}

		if ( get_current_user_id() === $user->ID ) {
			return $user;
		}

		// The capabilities only reveal users of the site, not of the whole network.
		if ( is_user_member_of_blog( $user->ID )
			&& ( current_user_can( 'list_users' ) || current_user_can( $post_type_object->cap->edit_others_posts ) )
		) {
			return $user;
		}

		$public_post_types = array_values( array_filter( get_post_types(), 'is_post_type_viewable' ) );

		return ! empty( $public_post_types ) && count_user_posts( $user->ID, $public_post_types ) > 0 ? $user : null;
	}

	/**
	 * Returns a post type exposed through the Abilities API.
	 *
	 * Deliberately resolved on every call rather than cached: post types can be
	 * unregistered or re-registered with different arguments between the ability
	 * being registered and the ability being used.
	 *
	 * @since 7.2.0
	 *
	 * @param mixed $post_type The post type name.
	 * @return WP_Post_Type|null The post type object, or null when the post type is not exposed.
	 */
	protected function get_exposed_post_type( $post_type ): ?WP_Post_Type {
		$post_type_object = is_string( $post_type ) ? get_post_type_object( $post_type ) : null;

		return empty( $post_type_object->show_in_abilities ) ? null : $post_type_object;
	}

	/**
	 * Returns the requested field paths, or a lean default set when none are given.
	 *
	 * An empty or absent `fields` value selects a lean set of common read fields.
	 * Otherwise the requested paths are returned. The input schema has already
	 * validated them against the supported set before the ability executes, and
	 * {@see self::build_post_fields()} ignores any other path.
	 *
	 * @since 7.2.0
	 *
	 * @param array<mixed> $input The ability input.
	 * @return list<string> List of requested field paths.
	 */
	protected function normalize_fields( array $input ): array {
		$fields = $this->parse_list_input( $input, 'fields' );

		return empty( $fields ) ? self::DEFAULT_FIELDS : $fields;
	}

	/**
	 * Returns the post field definitions, keyed by field name in output order.
	 *
	 * This is the single source of truth for the ability's post fields: the output
	 * schema uses the definitions directly, while the input schema `fields` enum lists
	 * each field, followed by the path of each of its sub-fields, such as `title.rendered`.
	 * Read-context fields are returned for readable posts; the `raw` sub-fields only for
	 * posts the current user can edit.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, mixed> Post field definitions.
	 */
	protected function get_post_properties(): array {
		return array(
			'id'       => array(
				'type'        => 'integer',
				'description' => __( 'The post ID.' ),
			),
			'type'     => array(
				'type'        => 'string',
				'description' => __( 'The post type.' ),
			),
			'status'   => array(
				'type'        => 'string',
				'description' => __( 'The post status.' ),
			),
			'date'     => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'local' => array(
						'type'        => array( 'string', 'null' ),
						'format'      => 'date-time',
						'description' => __( "The publication date, in ISO 8601 format using the site's timezone. Null when the date cannot be resolved." ),
					),
					'gmt'   => array(
						'type'        => array( 'string', 'null' ),
						'format'      => 'date-time',
						'description' => __( 'The publication date, in ISO 8601 format as GMT. Null when the date cannot be resolved.' ),
					),
				),
				'description'          => __( 'The publication date.' ),
			),
			'modified' => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'local' => array(
						'type'        => array( 'string', 'null' ),
						'format'      => 'date-time',
						'description' => __( "The last modified date, in ISO 8601 format using the site's timezone. Null when the date cannot be resolved." ),
					),
					'gmt'   => array(
						'type'        => array( 'string', 'null' ),
						'format'      => 'date-time',
						'description' => __( 'The last modified date, in ISO 8601 format as GMT. Null when the date cannot be resolved.' ),
					),
				),
				'description'          => __( 'The last modified date.' ),
			),
			'slug'     => array(
				'type'        => 'string',
				'description' => __( 'The post slug.' ),
			),
			'link'     => array(
				'type'        => 'string',
				'description' => __( 'The permalink URL.' ),
			),
			'title'    => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'raw'      => array(
						'type'        => 'string',
						'description' => __( 'The raw post title. Present when the current user can edit the post.' ),
					),
					'rendered' => array(
						'type'        => 'string',
						'description' => __( 'The rendered post title.' ),
					),
				),
				'description'          => __( 'The post title. Present when the post type supports titles.' ),
			),
			'excerpt'  => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'raw'       => array(
						'type'        => 'string',
						'description' => __( 'The raw post excerpt. Present when the current user can edit the post.' ),
					),
					'rendered'  => array(
						'type'        => 'string',
						'description' => __( 'The rendered post excerpt (HTML). Empty when withheld for a password-protected post.' ),
					),
					'protected' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the excerpt is protected with a password.' ),
					),
				),
				'description'          => __( 'The post excerpt. Present when the post type supports excerpts.' ),
			),
			'content'  => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'raw'       => array(
						'type'        => 'string',
						'description' => __( 'The raw, unfiltered post content (block markup). Present when the current user can edit the post.' ),
					),
					'rendered'  => array(
						'type'        => 'string',
						'description' => __( 'The rendered post content. Empty when withheld for a password-protected post.' ),
					),
					'protected' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the content is protected with a password.' ),
					),
				),
				'description'          => __( 'The post content. Present when the post type supports the editor.' ),
			),
			'author'   => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'id'   => array(
						'type'        => 'integer',
						'description' => __( 'The user ID of the author.' ),
					),
					'name' => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( "The author's display name, as the REST API users endpoint returns it in `name`. Null when the author no longer exists." ),
					),
					'slug' => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( "The author's user slug (nicename), as the REST API users endpoint returns it in `slug`, and as the `author_slug` filter takes it. Null when the author no longer exists." ),
					),
				),
				'description'          => __( 'The post author. Present when the post type supports authors.' ),
			),
			'parent'   => array(
				'type'        => 'integer',
				'description' => __( 'The parent post ID. Present for hierarchical post types.' ),
			),
		);
	}

	/**
	 * Returns the field paths the `fields` input accepts, in output order.
	 *
	 * Each post field is followed by the path of each of its sub-fields, such as `title`,
	 * `title.raw` and `title.rendered`. Requesting a field returns all of its sub-fields
	 * that apply, while a sub-field path returns only that sub-field, as the REST API's
	 * `_fields` parameter does.
	 *
	 * @since 7.2.0
	 *
	 * @return list<string> The field paths.
	 */
	protected function get_field_paths(): array {
		$paths = array();
		foreach ( $this->get_post_properties() as $name => $schema ) {
			$paths[] = $name;

			foreach ( array_keys( $schema['properties'] ?? array() ) as $sub_field ) {
				$paths[] = "{$name}.{$sub_field}";
			}
		}

		return $paths;
	}

	/**
	 * Builds the schema of the `fields` input, shared by all content abilities.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, mixed> The `fields` JSON Schema.
	 */
	protected function get_fields_input_schema(): array {
		return array(
			'type'        => 'array',
			'uniqueItems' => true,
			'items'       => array(
				'type' => 'string',
				'enum' => $this->get_field_paths(),
			),
			'description' => sprintf(
				/* translators: %s: Comma-separated list of field paths. */
				__( 'Limit each returned post to these fields. A field such as `title` returns all of its sub-fields the current user can see, while a path such as `title.rendered` returns only that sub-field. The `id` is always included. If omitted or empty, the default fields are returned: %s. Requested fields that do not apply to a post are left out of it: raw sub-fields, such as `content.raw`, when the current user cannot edit the post, and fields its post type does not support, such as `parent` for a post type that is not hierarchical. A returned post may therefore hold only its `id`. Password-protected posts differ: when the current user cannot edit one, its `excerpt.rendered` and `content.rendered` are returned as empty strings rather than left out, with `protected` set to true.' ),
				'`' . implode( '`, `', self::DEFAULT_FIELDS ) . '`'
			),
		);
	}

	/**
	 * Builds the output schema of a single post, shared by all content abilities.
	 *
	 * Only `id` is required, because it is always returned. The other fields are optional
	 * because the `fields` input lets the caller request any subset, and a field is only
	 * present when its post type supports it.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, mixed> The post JSON Schema.
	 */
	protected function get_content_output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id' ),
			'properties'           => $this->get_post_properties(),
		);
	}

	/**
	 * Formats a post into the ability output shape.
	 *
	 * As the REST posts controller does, the post is set up as the global post while its
	 * fields are built, so filters that rely on loop globals, including title filters, see
	 * the requested post. For an editor of a password-protected post, the cookie-based
	 * password gate is also suspended, so rendered fields resolve to real values instead of
	 * protected-post placeholders. The field projection itself is delegated to
	 * {@see self::build_post_fields()}.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post      $post   The post object.
	 * @param list<string> $fields The requested field paths.
	 * @return array<string, mixed> The formatted post data.
	 */
	protected function format_post( WP_Post $post, array $fields ): array {
		$can_edit          = current_user_can( 'edit_post', $post->ID );
		$password_required = post_password_required( $post );
		$unlock_password   = $password_required && $can_edit;
		$previous_context  = $this->get_post_context();
		$the_post_fired    = did_action( 'the_post' ) > 0;

		/*
		 * The filter unlocks only posts the current user can edit, mirroring the REST posts
		 * controller's check_password_required(): an unconditional bypass (e.g. __return_false)
		 * would also expose other protected posts that the content filter may render, such as
		 * posts pulled in by a Query Loop block. The closure is kept in a variable, so the same
		 * instance can be removed again.
		 */
		$allow_password_content = function ( $required, $checked_post ): bool {
			return $this->allow_password_content( $required, $checked_post );
		};

		/*
		 * Set up both inside the try block and undo them in the finally block, so a throw from
		 * a `the_post` callback or mid-render cannot leave the password gate disabled or the
		 * global post pointing at this post for the rest of the request.
		 */
		try {
			$this->set_up_post_context( $post );
			if ( $unlock_password ) {
				add_filter( 'post_password_required', $allow_password_content, 10, 2 );
			}

			return $this->build_post_fields( $post, $fields, $can_edit, $password_required && ! $can_edit );
		} finally {
			if ( $unlock_password ) {
				remove_filter( 'post_password_required', $allow_password_content, 10 );
			}

			$this->restore_post_context( $previous_context, $the_post_fired );
		}
	}

	/**
	 * Builds the requested field projection for a post.
	 *
	 * Fields are selected as the REST API's `_fields` parameter selects them, with
	 * {@see self::is_field_requested()}. The `id` is always included, whether or not it
	 * was requested, so every returned post can be identified. This also means a post is
	 * never empty, so it always encodes as a JSON object.
	 *
	 * Only the fields that the post type supports and the current user can see are included,
	 * and a field none of whose requested sub-fields remain is left out, so it never encodes
	 * as an empty JSON array. Raw sub-fields are edit-context fields, included only when the
	 * current user can edit the post, whether they were requested on their own or through
	 * their field. Rendered sub-fields are read-context fields and are withheld for
	 * password-protected posts unless the current user can edit the post, mirroring the REST
	 * API behavior.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post      $post         The post object.
	 * @param list<string> $fields       The requested field paths.
	 * @param bool         $can_edit     Whether the current user can edit the post.
	 * @param bool         $is_protected Whether rendered fields must be withheld as password-protected.
	 * @return array<string, mixed> The formatted post data.
	 */
	protected function build_post_fields( WP_Post $post, array $fields, bool $can_edit, bool $is_protected ): array {
		$post_type = $post->post_type;
		$data      = array( 'id' => (int) $post->ID );

		if ( $this->is_field_requested( 'type', $fields ) ) {
			$data['type'] = $post_type;
		}
		if ( $this->is_field_requested( 'status', $fields ) ) {
			$data['status'] = $post->post_status;
		}

		foreach ( array( 'date', 'modified' ) as $field ) {
			$dates = array();
			if ( $this->is_field_requested( "{$field}.local", $fields ) ) {
				$dates['local'] = $this->format_date( $post, $field, false );
			}
			if ( $this->is_field_requested( "{$field}.gmt", $fields ) ) {
				$dates['gmt'] = $this->format_date( $post, $field, true );
			}
			if ( ! empty( $dates ) ) {
				$data[ $field ] = $dates;
			}
		}

		if ( $this->is_field_requested( 'slug', $fields ) ) {
			$data['slug'] = $post->post_name;
		}
		if ( $this->is_field_requested( 'link', $fields ) ) {
			$data['link'] = (string) get_permalink( $post );
		}

		/*
		 * The rendered fields share a shape: `raw` for a user who can edit the post,
		 * `rendered`, and `protected` for the excerpt and content, whose rendered values are
		 * withheld for a password-protected post. The title is never withheld, so it has no
		 * `protected` sub-field. Each field lists the feature the post type must support, the
		 * post property holding the raw value, the method rendering it, and whether it can be
		 * protected.
		 */
		$rendered_fields = array(
			'title'   => array( 'title', 'post_title', 'get_title', false ),
			'excerpt' => array( 'excerpt', 'post_excerpt', 'get_rendered_excerpt', true ),
			'content' => array( 'editor', 'post_content', 'get_rendered_content', true ),
		);
		foreach ( $rendered_fields as $field => $spec ) {
			list( $feature, $property, $render, $protectable ) = $spec;
			if ( ! $this->is_field_requested( $field, $fields ) || ! post_type_supports( $post_type, $feature ) ) {
				continue;
			}

			$value = array();
			if ( $can_edit && $this->is_field_requested( "{$field}.raw", $fields ) ) {
				$value['raw'] = $post->$property;
			}
			if ( $this->is_field_requested( "{$field}.rendered", $fields ) ) {
				$value['rendered'] = ( $protectable && $is_protected ) ? '' : $this->$render( $post );
			}
			if ( $protectable && $this->is_field_requested( "{$field}.protected", $fields ) ) {
				$value['protected'] = (bool) $post->post_password;
			}
			if ( ! empty( $value ) ) {
				$data[ $field ] = $value;
			}
		}

		if ( $this->is_field_requested( 'author', $fields ) && post_type_supports( $post_type, 'author' ) ) {
			$user   = get_userdata( (int) $post->post_author );
			$author = array();
			if ( $this->is_field_requested( 'author.id', $fields ) ) {
				$author['id'] = (int) $post->post_author;
			}
			if ( $this->is_field_requested( 'author.name', $fields ) ) {
				$author['name'] = $user ? $user->display_name : null;
			}
			if ( $this->is_field_requested( 'author.slug', $fields ) ) {
				$author['slug'] = $user ? $user->user_nicename : null;
			}
			if ( ! empty( $author ) ) {
				$data['author'] = $author;
			}
		}

		if ( $this->is_field_requested( 'parent', $fields ) && is_post_type_hierarchical( $post_type ) ) {
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
	 * @since 7.2.0
	 *
	 * @param mixed $required Whether the post currently requires a password.
	 * @param mixed $post     The post being checked; a WP_Post when invoked by the core filter.
	 * @return bool Whether the post still requires a password.
	 */
	protected function allow_password_content( $required, $post ): bool {
		if ( ! $required || ! $post instanceof WP_Post ) {
			return (bool) $required;
		}

		return ! current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Returns the post title with the protected/private prefixes stripped.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post $post The post object.
	 * @return string The post title.
	 */
	protected function get_title( WP_Post $post ): string {
		$strip = static function (): string {
			return '%s';
		};
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
			return is_string( $title ) ? $title : ''; // @phpstan-ignore function.alreadyNarrowedType (WP stubs type get_the_title() as string; trunk keeps the runtime guard against a misbehaving title filter.)
		} finally {
			remove_filter( 'protected_title_format', $strip );
			remove_filter( 'private_title_format', $strip );
		}
	}

	/**
	 * Returns the post excerpt transformed for display.
	 *
	 * Applies the `get_the_excerpt` and `the_excerpt` filter chains, as the REST posts
	 * controller does. {@see self::format_post()} has set the post up as the global post.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post $post The post object.
	 * @return string Rendered post excerpt.
	 */
	protected function get_rendered_excerpt( WP_Post $post ): string {
		/** This filter is documented in wp-includes/post-template.php */
		$excerpt = apply_filters( 'get_the_excerpt', $post->post_excerpt, $post );

		/** This filter is documented in wp-includes/post-template.php */
		$excerpt = apply_filters( 'the_excerpt', $excerpt );

		return is_string( $excerpt ) ? $excerpt : '';
	}

	/**
	 * Returns post content transformed for display.
	 *
	 * Applies `the_content`, as the REST posts controller does. {@see self::format_post()}
	 * has set the post up as the global post.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post $post The post object.
	 * @return string Rendered post content.
	 */
	protected function get_rendered_content( WP_Post $post ): string {
		/** This filter is documented in wp-includes/post-template.php */
		$content = apply_filters( 'the_content', $post->post_content );

		return is_string( $content ) ? $content : '';
	}

	/**
	 * Returns the global post context, so it can be restored after a post is rendered.
	 *
	 * @since 7.2.0
	 *
	 * @return array<string, mixed> The loop globals, keyed by name, leaving out those that are not set.
	 */
	protected function get_post_context(): array {
		/*
		 * Copy each global by value. A calling function that binds a global with `global`,
		 * as load_template() and WP_Block::render() do, makes it a reference, which
		 * array_intersect_key( $GLOBALS, ... ) would keep. The saved copy would then follow
		 * the global to the rendered post, and the restore would put that post back.
		 */
		$context = array();
		foreach ( self::LOOP_GLOBALS as $name ) {
			if ( ! array_key_exists( $name, $GLOBALS ) ) {
				continue;
			}

			$context[ $name ] = $GLOBALS[ $name ];
		}

		return $context;
	}

	/**
	 * Sets up the global post context for rendering a post.
	 *
	 * Sets the global post and calls setup_postdata(), as the REST posts controller does
	 * before it renders a post, so filters that rely on loop globals render against the
	 * requested post.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post $post The post to render.
	 */
	protected function set_up_post_context( WP_Post $post ): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily mirrors REST post context for rendering.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
	}

	/**
	 * Restores the global post context saved by {@see self::get_post_context()}.
	 *
	 * Each loop global gets its previous value back, or is unset again when it was not set.
	 * wp_reset_postdata() alone is not enough: it does nothing when the main query has no
	 * post, which would leave the rendered post's data in the globals. When a post was set
	 * up before, setup_postdata() first runs for it again, as wp_reset_postdata() would, so
	 * callbacks on the `the_post` action can restore their own globals too. The saved values
	 * are put back even when such a callback throws. A global post that was never set up
	 * keeps the loop globals that setup_postdata() gives it. The `the_post` count is reset
	 * only when the ability was the first thing to fire that action and no global post was
	 * set, so rendering a post before the main query does not count as starting the loop.
	 *
	 * @since 7.2.0
	 *
	 * @param array<string, mixed> $previous_context The loop globals that get_post_context() returned.
	 * @param bool                 $the_post_fired   Whether the `the_post` action had fired before the ability set up the post.
	 */
	protected function restore_post_context( array $previous_context, bool $the_post_fired ): void {
		$previous_post = $previous_context['post'] ?? null;

		/*
		 * A global post that was never set up, such as the main post before the loop
		 * starts, keeps what setup_postdata() gives it below. Do not put back the values
		 * saved before: `the_post` has fired then, so get_the_content() called without a
		 * post, as the Post Content block and the_content() outside the loop do, reads the
		 * loop globals instead of the post, and an unset `$pages` makes it throw a
		 * TypeError that breaks the page being rendered.
		 */
		$restore_saved_values = ! $previous_post instanceof WP_Post || is_array( $previous_context['pages'] ?? null );

		try {
			if ( $previous_post instanceof WP_Post ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous global post context.
				$GLOBALS['post'] = $previous_post;
				setup_postdata( $previous_post );
			}
		} finally {
			if ( $restore_saved_values ) {
				foreach ( self::LOOP_GLOBALS as $name ) {
					if ( array_key_exists( $name, $previous_context ) ) {
						$GLOBALS[ $name ] = $previous_context[ $name ];
					} else {
						unset( $GLOBALS[ $name ] );
					}
				}
			}

			/*
			 * Only reset the `the_post` count when the ability was the first thing to fire it
			 * and no global post was set. Setting up the rendered post counted the action, and
			 * once it has fired, get_the_content() called without a post reads the loop globals
			 * unset above instead of the post. A global post set later without being set up,
			 * as WP::register_globals() sets the main post after the ability runs on `init`,
			 * would then make it throw a TypeError. When a loop had already fired the action,
			 * or a post was set, the count is left alone.
			 */
			if ( ! $previous_post instanceof WP_Post && ! $the_post_fired ) {
				unset( $GLOBALS['wp_actions']['the_post'] );
			}
		}
	}

	/**
	 * Formats a post date field as an ISO 8601 string, in the site's timezone or in GMT.
	 *
	 * In GMT, it reads the stored GMT date, deriving it from the local date when it is
	 * missing (e.g. drafts), mirroring the REST posts controller.
	 *
	 * @since 7.2.0
	 *
	 * @param WP_Post $post  The post object.
	 * @param string  $field Either 'date' or 'modified'.
	 * @param bool    $gmt   Whether to format the date in GMT instead of the site's timezone.
	 * @return string|null The ISO 8601 date, or null if unavailable.
	 */
	protected function format_date( WP_Post $post, string $field, bool $gmt ): ?string {
		$datetime = $gmt ? get_post_datetime( $post, $field, 'gmt' ) : false;
		if ( ! $datetime ) {
			$datetime = get_post_datetime( $post, $field );
		}

		if ( ! $datetime ) {
			return null;
		}

		/*
		 * A malformed stored date can still parse: a zero month formats with a negative
		 * year. The `date-time` format rejects it, which would fail output validation for
		 * the whole call, so it is reported as null too.
		 */
		$date = ( $gmt ? $datetime->setTimezone( new DateTimeZone( 'UTC' ) ) : $datetime )->format( 'c' );

		return rest_parse_date( $date ) ? $date : null;
	}

	/**
	 * Permission callback for the `core/content-create` ability.
	 *
	 * The current user must be able to create posts of the requested post type. The rest
	 * of the input is checked during execution.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	protected function check_create_permission( $input = array() ): bool {
		$input = rest_sanitize_object( $input );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$post_type_object = $this->get_exposed_post_type( $input['type'] ?? null );
		if ( ! $post_type_object ) {
			return false;
		}

		return current_user_can( $post_type_object->cap->create_posts ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
	}

	/**
	 * Permission callback for the `core/content-update` ability.
	 *
	 * The post must exist in an exposed post type (and match the `type` guard when
	 * given), and the current user must be able to edit it. The rest of the input is
	 * checked during execution.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	protected function check_update_permission( $input = array() ): bool {
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
	 * Permission callback for the `core/content-delete` ability.
	 *
	 * The post must exist in an exposed post type (and match the `type` guard when
	 * given), and the current user must be able to delete it. As in `core/content-query`,
	 * a request that explicitly asks for edit-context fields also requires edit access,
	 * so it is refused before anything is deleted.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	protected function check_delete_permission( $input = array() ): bool {
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
	 * @param array<mixed> $input            The ability input.
	 * @param WP_Post_Type $post_type_object The post type object.
	 * @param bool         $creating         True when creating a post, false when updating.
	 * @return WP_Error|null A WP_Error when the author may not be assigned, or null.
	 */
	protected function check_author_permission( array $input, WP_Post_Type $post_type_object, bool $creating ): ?WP_Error {
		/*
		 * A slug that does not name the current user is refused the same way whether it names
		 * another user or none, so the refusal says nothing about that user. For a user who may
		 * edit others' posts, a slug that names no user is rejected when the post is prepared.
		 */
		$author_slug = $input['author.slug'] ?? null;
		if ( null !== $author_slug
			&& ! $this->author_slug_names_user( $author_slug, get_current_user_id() )
			&& ! current_user_can( $post_type_object->cap->edit_others_posts ) // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
		) {
			return new WP_Error(
				'content_cannot_edit_others',
				$creating
					? __( 'Sorry, you are not allowed to create posts as this user.' )
					: __( 'Sorry, you are not allowed to update posts as this user.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return null;
	}

	/**
	 * Checks whether an author slug names a given user.
	 *
	 * The slug is looked up as {@see self::get_author_by_slug()} looks it up when the post is
	 * prepared, so a variant that the database collation matches, such as one in another case,
	 * names the same user. Unlike that lookup, this does not check that the user is visible to
	 * the current user, so only pass a user they can already see, such as themselves or the
	 * author of a post they can edit.
	 *
	 * @param mixed $slug    The author slug.
	 * @param int   $user_id The user ID.
	 * @return bool True when the slug names the user.
	 */
	protected function author_slug_names_user( $slug, int $user_id ): bool {
		$user = is_string( $slug ) ? get_user_by( 'slug', $slug ) : false;

		return $user instanceof WP_User && $user->ID === $user_id;
	}

	/**
	 * Checks whether the input explicitly requests edit-context fields.
	 *
	 * Omitted fields are not treated as edit-intent: default responses include the
	 * fields visible for each individual post.
	 *
	 * @param array<mixed> $input The ability input.
	 * @return bool True if edit-context fields were explicitly requested.
	 */
	protected function has_explicit_edit_fields( array $input ): bool {
		return ! empty( array_intersect( self::EDIT_FIELDS, $this->parse_list_input( $input, 'fields' ) ) );
	}

	/**
	 * Resolves the post a single-post request names, by ID or by post type and slug.
	 *
	 * The write abilities never reach the slug path: their schemas require an `id`, and
	 * {@see self::get_content_by_id()} only resolves a request that has one. The slug
	 * path is kept as the PR has it, resolving through {@see self::get_post_by_slug()}.
	 *
	 * @param array<mixed> $input The ability input.
	 * @return WP_Post|null The post, or null when it cannot be resolved.
	 */
	protected function get_requested_post( array $input ): ?WP_Post {
		if ( isset( $input['id'] ) ) {
			$post_id = $this->parse_filter_int( $input['id'], 1 );
			$post    = null === $post_id ? null : get_post( $post_id );

			if ( ! $post instanceof WP_Post || ! $this->get_exposed_post_type( $post->post_type ) ) {
				return null;
			}

			return empty( $input['type'] ) || $post->post_type === $input['type'] ? $post : null;
		}

		$slug             = $input['slug'] ?? null;
		$post_type_object = $this->get_exposed_post_type( $input['type'] ?? null );

		return is_string( $slug ) && $post_type_object ? $this->get_post_by_slug( $post_type_object->name, $slug ) : null;
	}

	/**
	 * Looks up the post a write request names by ID.
	 *
	 * The write abilities never look a post up by slug: they require an `id`, and their
	 * `slug` is the slug to write. So, unlike {@see self::get_requested_post()}, this
	 * resolves a request without an ID to no post.
	 *
	 * @param array<mixed> $input The ability input.
	 * @return WP_Post|null The post, or null when it cannot be resolved.
	 */
	protected function get_content_by_id( array $input ): ?WP_Post {
		return isset( $input['id'] ) ? $this->get_requested_post( $input ) : null;
	}

	/**
	 * Looks up the single post a slug request resolves to.
	 *
	 * Slugs are not unique across statuses (drafts skip slug uniqueness), so the
	 * lookup returns the newest match the current user can read, preferring
	 * publicly viewable posts — a newer draft sharing the slug cannot shadow a
	 * published post. This mirrors the REST API, where slug queries default to
	 * the `publish` status.
	 *
	 * In hierarchical post types, posts under different parents can also share a
	 * slug. The lookup cannot tell them apart and resolves to one of them by the
	 * same rules, so callers that need a specific post should look it up by ID.
	 *
	 * @param string $post_type The post type.
	 * @param string $slug      The post slug.
	 * @return WP_Post|null The matching readable post, or null when none exists.
	 */
	protected function get_post_by_slug( string $post_type, string $slug ): ?WP_Post {
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

		// Candidates come newest first; a publicly viewable post is always readable here.
		$readable = null;
		foreach ( $query->posts as $candidate ) {
			/*
			 * Skip posts of other post types, which query filters can add, such as a
			 * `pre_get_posts` callback that adds post types to single views without an
			 * is_main_query() check, since a `name` query is a single view.
			 */
			if ( ! $candidate instanceof WP_Post || $post_type !== $candidate->post_type ) {
				continue;
			}

			if ( is_post_publicly_viewable( $candidate ) ) {
				return $candidate;
			}

			if ( null !== $readable || ! $this->check_read_permission( $candidate ) ) {
				continue;
			}

			$readable = $candidate;
		}

		return $readable;
	}

	/**
	 * Executes the `core/content-create` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::check_create_permission()} first,
	 * so this re-validates the post type lookup and leaves the rest of the input to
	 * {@see self::write_content()}, which checks it before writing the post.
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|WP_Error The created post, or a WP_Error.
	 */
	protected function execute_content_create( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$post_type_object = $this->get_exposed_post_type( $input['type'] ?? null );
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
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|WP_Error The updated post, or a WP_Error.
	 */
	protected function execute_content_update( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$post_before = $this->get_content_by_id( $input );
		if ( ! $post_before ) {
			return $this->not_found_error();
		}

		// get_content_by_id() only returns posts of an exposed post type, so the type is registered.
		/** @var WP_Post_Type $post_type_object */
		$post_type_object = get_post_type_object( $post_before->post_type );

		return $this->write_content( $input, $post_type_object, $post_before );
	}

	/**
	 * Creates or updates a post from the ability input.
	 *
	 * Shared by the create and update abilities.
	 *
	 * @param array<mixed>     $input            The ability input.
	 * @param WP_Post_Type     $post_type_object The post type of the post being written.
	 * @param WP_Post|null     $post_before      The post being updated, or null when creating.
	 * @return array<string, mixed>|WP_Error The written post, or a WP_Error.
	 */
	protected function write_content( array $input, WP_Post_Type $post_type_object, ?WP_Post $post_before ) {
		$unsupported = $this->check_unsupported_fields( $input, $post_type_object->name );
		if ( $unsupported instanceof WP_Error ) {
			return $unsupported;
		}

		/*
		 * Sending back the slug of the post's stored author is the same as leaving the field
		 * out: the author does not change, so the update is handled exactly as one without it.
		 * The slug is looked up as it is when the post is prepared, so a variant that names the
		 * stored author, such as one in another case, counts too.
		 */
		if ( $post_before && isset( $input['author.slug'] ) && $this->author_slug_names_user( $input['author.slug'], (int) $post_before->post_author ) ) {
			unset( $input['author.slug'] );
		}

		$refused = $this->check_author_permission( $input, $post_type_object, null === $post_before );
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
		return $this->format_post( $post, $this->normalize_fields( $input ) );
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
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|WP_Error The trashed or deleted post, or a WP_Error.
	 */
	protected function execute_content_delete( $input = array() ) {
		$input = rest_sanitize_object( $input );

		$post = $this->get_content_by_id( $input );
		if ( ! $post ) {
			return $this->not_found_error();
		}

		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return new WP_Error(
				'content_cannot_delete',
				__( 'Sorry, you are not allowed to delete this post.' ),
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
			$response = $this->format_post( $post, $fields );
			$result   = wp_delete_post( $post->ID, true );
		} else {
			// If we don't support trashing for this type, error out.
			if ( ! $supports_trash ) {
				return new WP_Error(
					'content_trash_not_supported',
					__( 'The post does not support trashing. Set `force` to true to delete it permanently.' ),
					array( 'status' => 501 )
				);
			}

			// Otherwise, only trash if we haven't already.
			if ( 'trash' === $post->post_status ) {
				return new WP_Error(
					'content_already_trashed',
					__( 'The post has already been deleted.' ),
					array( 'status' => 410 )
				);
			}

			$result   = wp_trash_post( $post->ID );
			$post     = get_post( $post->ID );
			$response = $post instanceof WP_Post ? $this->format_post( $post, $fields ) : null;
		}

		if ( ! $result || null === $response ) {
			return new WP_Error(
				'content_cannot_delete',
				__( 'The post cannot be deleted.' ),
				array( 'status' => 500 )
			);
		}

		return $response;
	}

	/**
	 * Returns the input properties shared by the create and update abilities, keyed by field name.
	 *
	 * Each field carries the name and type of the post field `core/content-query` returns, so
	 * a post can be read and written back unchanged. Only the null the query returns for a
	 * date it cannot resolve cannot be written. One schema serves every exposed post type, so
	 * the descriptions state which post types support a field.
	 *
	 * @return array<string, mixed> Write property definitions.
	 */
	protected function get_content_write_properties(): array {
		return array(
			'title.raw'   => array(
				'type'        => 'string',
				'description' => __( 'The raw post title. Only supported for post types that support titles.' ),
			),
			'content.raw' => array(
				'type'        => 'string',
				'description' => __( 'The raw post content, as block markup or HTML. Only supported for post types that support the editor.' ),
			),
			'excerpt.raw' => array(
				'type'        => 'string',
				'description' => __( 'The raw post excerpt. Only supported for post types that support excerpts.' ),
			),
			'status'      => array(
				'type'        => 'string',
				'enum'        => array_values( get_post_stati( array( 'internal' => false ) ) ),
				'description' => __( 'The post status. Defaults to draft when creating. Publishing, scheduling, making a post private, or giving it any other public or publicly viewable status requires the publish capability for the post type.' ),
			),
			'slug'        => array(
				'type'        => 'string',
				'description' => __( 'The post slug. Sanitized like a title, and adjusted when it collides with another post of the same type.' ),
			),
			'date.local'  => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( "The publication date in ISO 8601 format. A date without a timezone offset is read in the site's timezone." ),
			),
			'date.gmt'    => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( 'The publication date in ISO 8601 format, as GMT. A date with a timezone offset other than `Z` or `+00:00` is converted to GMT. When `date.local` is also given, both must refer to the same time.' ),
			),
			'author.slug' => array(
				'type'        => 'string',
				'minLength'   => 1,
				'description' => __( "The author's user slug, as core/users-query returns it. Assigning another user requires the capability to edit their posts. Only supported for post types that support authors." ),
			),
			'parent'      => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'The parent post ID: a readable post of the same type, other than the post itself or one of its descendants; 0 for a top-level post. When updating, the current parent can always be kept. Only supported for hierarchical post types.' ),
			),
		);
	}

	/**
	 * Builds the input schema for the `core/content-create` ability.
	 *
	 * `additionalProperties: false` rejects unknown fields instead of dropping them, so e.g.
	 * passing an `id` fails validation instead of silently creating a new post.
	 *
	 * @param list<string> $post_types Exposed post type names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function get_content_create_input_schema( array $post_types ): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'type' ),
			'additionalProperties' => false,
			'properties'           => array_merge(
				array(
					'type' => array(
						'type'        => 'string',
						'enum'        => $post_types,
						'description' => __( 'The post type of the post to create.' ),
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
	 * The post is identified by `id`, and `type` becomes an optional guard. The status
	 * keeps the create schema's enum, so clients can see and check the statuses that can be
	 * set. A post with an internal status, such as `trash`, keeps it when the status is left
	 * out; validation rejects that status before execution, even when it is the current one.
	 *
	 * @param array<string, mixed> $create_schema The input schema of the `core/content-create` ability.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function get_content_update_input_schema( array $create_schema ): array {
		$properties = array(
			'id' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'The ID of the post to update.' ),
			),
		) + $create_schema['properties'];

		$properties['type']['description']        = __( 'Optional. Restrict the update to this post type; the post is only updated if it matches.' );
		$properties['author.slug']['description'] = __( "The author's user slug, as core/users-query returns it. Assigning another user requires the capability to edit their posts, unless the post already has that author. Leave it out to keep the current author, which is the only way when the author no longer exists. Only supported for post types that support authors." );
		$properties['status']['description']      = __( 'The post status. Leave it out to keep the current status; a post with an internal status such as `trash` can only keep it this way. Publishing, scheduling, making a post private, or giving it any other public or publicly viewable status requires the publish capability for the post type, unless the post already has that status.' );
		$properties['date.gmt']['description']    = __( 'The publication date in ISO 8601 format, as GMT. A date with a timezone offset other than `Z` or `+00:00` is converted to GMT. When `date.local` is also given, both must refer to the same time, unless one of them is the date the post already has.' );

		$create_schema['required']   = array( 'id' );
		$create_schema['properties'] = $properties;

		return $create_schema;
	}

	/**
	 * Builds the input schema for the `core/content-delete` ability.
	 *
	 * @param list<string> $post_types Exposed post type names.
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function get_content_delete_input_schema( array $post_types ): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'additionalProperties' => false,
			'properties'           => array(
				'id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'The ID of the post to delete.' ),
				),
				'type'   => array(
					'type'        => 'string',
					'enum'        => $post_types,
					'description' => __( 'Optional. Restrict the deletion to this post type; the post is only deleted if it matches.' ),
				),
				'force'  => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to bypass the trash and delete the post permanently. Defaults to false, which moves the post to the trash.' ),
				),
				'fields' => $this->get_fields_input_schema(),
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
	 * @param array<mixed> $input     The ability input.
	 * @param string       $post_type The post type name.
	 * @return WP_Error|null A WP_Error naming the first unsupported field, or null when every field is supported.
	 */
	protected function check_unsupported_fields( array $input, string $post_type ): ?WP_Error {
		$field_support = array(
			'title.raw'   => post_type_supports( $post_type, 'title' ),
			'content.raw' => post_type_supports( $post_type, 'editor' ),
			'excerpt.raw' => post_type_supports( $post_type, 'excerpt' ),
			'author.slug' => post_type_supports( $post_type, 'author' ),
			'parent'      => is_post_type_hierarchical( $post_type ),
		);

		foreach ( $field_support as $field => $is_supported ) {
			if ( $is_supported || ! isset( $input[ $field ] ) ) {
				continue;
			}

			return $this->invalid_field_error(
				array( $field ),
				sprintf(
					/* translators: 1: Field name, 2: Post type name. */
					__( 'The %1$s field is not supported by the %2$s post type.' ),
					$field,
					$post_type
				)
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
	 * @param array<mixed>     $input            The ability input.
	 * @param WP_Post_Type     $post_type_object The post type of the post being prepared.
	 * @param WP_Post|null     $post_before      The post being updated, or null when creating.
	 * @return stdClass|WP_Error Post object prepared for wp_insert_post() or wp_update_post(), or a WP_Error.
	 */
	protected function prepare_content_data( array $input, WP_Post_Type $post_type_object, ?WP_Post $post_before ) {
		$prepared_post  = new stdClass();
		$current_status = '';
		$post_type      = $post_type_object->name;

		// Post ID.
		if ( $post_before ) {
			$prepared_post->ID = $post_before->ID;
			$current_status    = $post_before->post_status;
		}

		// Post title.
		if ( isset( $input['title.raw'] ) && is_string( $input['title.raw'] ) ) {
			$prepared_post->post_title = $input['title.raw'];
		}

		// Post content.
		if ( isset( $input['content.raw'] ) && is_string( $input['content.raw'] ) ) {
			$prepared_post->post_content = $input['content.raw'];
		}

		// Post excerpt.
		if ( isset( $input['excerpt.raw'] ) && is_string( $input['excerpt.raw'] ) ) {
			$prepared_post->post_excerpt = $input['excerpt.raw'];
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
		$date_data     = ! empty( $input['date.local'] ) && is_string( $input['date.local'] ) ? rest_get_date_with_gmt( $input['date.local'] ) : null;
		$date_gmt_data = ! empty( $input['date.gmt'] ) && is_string( $input['date.gmt'] ) ? rest_get_date_with_gmt( $input['date.gmt'], true ) : null;

		/*
		 * A date the post already has is handled as if it were left out, so both dates
		 * core/content-query returns can be sent back even when they refer to different times:
		 * `date.local` is read in the site's current timezone, while `date.gmt` is the GMT date
		 * stored when the post was saved. A draft without a fixed date has no GMT date; the
		 * query derives one from its local date.
		 */
		if ( $post_before ) {
			$floating      = '0000-00-00 00:00:00' === $post_before->post_date_gmt;
			$current_gmt   = $floating ? get_gmt_from_date( $post_before->post_date ) : $post_before->post_date_gmt;
			$sent_date     = $date_data ?? $date_gmt_data;
			$date_data     = $date_data && $post_before->post_date !== $date_data[0] ? $date_data : null;
			$date_gmt_data = $date_gmt_data && $current_gmt !== $date_gmt_data[1] ? $date_gmt_data : null;

			/*
			 * Saving a draft without a fixed date moves it to the current time, so a request that
			 * publishes or schedules one keeps the date it sends back, as long as that date is
			 * still ahead: the post is then scheduled for that date. Each condition prevents a
			 * regression:
			 *
			 * - Both `publish` and `future`: wp_insert_post() schedules a post published with a
			 *   future date. With `future` alone, publishing a draft dated next week with its own
			 *   date publishes it at once and loses the date, while the same request with a date
			 *   one second later, or for a draft with a fixed date, schedules it.
			 * - Only those two statuses: a draft that stays a draft must keep its floating date
			 *   when its dates are sent back, as it does when they are left out.
			 * - Only drafts without a fixed date: for a post with a stored GMT date, the two dates
			 *   the query returns differ after a timezone change. Putting `date.local` back here
			 *   would move the post to another time instead of keeping the stored dates.
			 * - Only dates still ahead: a draft without a fixed date is published now, and putting
			 *   its past date back would backdate the post instead.
			 */
			if ( $floating && $sent_date && ! $date_data && ! $date_gmt_data
				&& in_array( $prepared_post->post_status ?? '', array( 'publish', 'future' ), true )
				&& $sent_date[1] > gmdate( 'Y-m-d H:i:s' )
			) {
				$date_data = $sent_date;
			}
		}

		if ( $date_data && $date_gmt_data && $date_data[1] !== $date_gmt_data[1] ) {
			return $this->invalid_field_error(
				array( 'date.local', 'date.gmt' ),
				sprintf(
					/* translators: 1: Field name, 2: Field name. */
					__( 'The %1$s and %2$s fields refer to different times.' ),
					'date.local',
					'date.gmt'
				)
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
		if ( isset( $input['author.slug'] ) ) {
			$post_author = $this->get_author_by_slug( $input['author.slug'], $post_type_object );

			if ( ! $post_author ) {
				return $this->invalid_field_error(
					array( 'author.slug' ),
					/* translators: %s: Field name. */
					sprintf( __( 'The %s field must be the slug of an existing user.' ), 'author.slug' )
				);
			}

			$prepared_post->post_author = $post_author->ID;
		}

		// Parent: 0 for a top-level post.
		if ( isset( $input['parent'] ) ) {
			$post_parent = $this->parse_filter_int( $input['parent'], 0 );

			if ( null === $post_parent || ( 0 !== $post_parent && ! $this->is_valid_parent( $post_parent, $post_type, $post_before ) ) ) {
				return $this->invalid_field_error(
					array( 'parent' ),
					__( 'The parent field must be 0 or the ID of a readable post of the same type, other than the post itself or one of its descendants.' )
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
	 * @param int           $parent_id   The requested parent ID.
	 * @param string        $post_type   The post type of the post being written.
	 * @param WP_Post|null  $post_before The post being updated, or null when creating.
	 * @return bool True when the post can be the parent.
	 */
	protected function is_valid_parent( int $parent_id, string $post_type, ?WP_Post $post_before ): bool {
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
	 * Publishing, scheduling, private posts, and any other status that is public or publicly
	 * viewable require the post type's publish capability.
	 *
	 * @param string       $post_status      Post status.
	 * @param WP_Post_Type $post_type_object Post type.
	 * @return string|WP_Error Post status, or WP_Error if lacking the proper permission.
	 */
	protected function prepare_content_status( string $post_status, WP_Post_Type $post_type_object ) {
		switch ( $post_status ) {
			case 'draft':
			case 'pending':
				break;
			case 'private':
				if ( ! current_user_can( $post_type_object->cap->publish_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
					return new WP_Error(
						'content_cannot_publish',
						__( 'Sorry, you are not allowed to make posts private in this post type.' ),
						array( 'status' => rest_authorization_required_code() )
					);
				}
				break;
			case 'publish':
			case 'future':
				if ( ! current_user_can( $post_type_object->cap->publish_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
					return new WP_Error(
						'content_cannot_publish',
						__( 'Sorry, you are not allowed to publish posts in this post type.' ),
						array( 'status' => rest_authorization_required_code() )
					);
				}
				break;
			default:
				/*
				 * A status that is public or publicly viewable shows the post to everyone, as
				 * publishing does. A status can be publicly queryable without being public.
				 */
				$status_object = get_post_status_object( $post_status );
				$is_viewable   = $status_object instanceof stdClass && ( $status_object->public || is_post_status_viewable( $status_object ) );
				if ( $is_viewable && ! current_user_can( $post_type_object->cap->publish_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
					return new WP_Error(
						'content_cannot_publish',
						__( 'Sorry, you are not allowed to publish posts in this post type.' ),
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
	 * This is not a permission check. The create and update execute callbacks
	 * deliberately do not repeat the read and edit checks that the permission callbacks
	 * already performed, so a direct call bypasses them; only the destructive delete
	 * callback checks its capability again. Only invoke the callbacks through
	 * {@see WP_Ability::execute()}, which always runs the permission callback first.
	 *
	 * @return WP_Error The not-found error.
	 */
	protected function not_found_error(): WP_Error {
		return new WP_Error(
			'content_not_found',
			__( 'The requested content was not found.' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Builds the error for a written field that cannot be honored.
	 *
	 * As in the REST API's `rest_invalid_param` errors, the error data maps each field to
	 * the message under `params`, so callers can tell which field failed without parsing
	 * the translated message.
	 *
	 * @param list<string> $fields  The fields' input names.
	 * @param string       $message The error message.
	 * @return WP_Error The invalid field error.
	 */
	protected function invalid_field_error( array $fields, string $message ): WP_Error {
		return new WP_Error(
			'content_invalid_field',
			$message,
			array(
				'status' => 400,
				'params' => array_fill_keys( $fields, $message ),
			)
		);
	}
}

<?php
/**
 * The `core/media-*` WordPress Abilities.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Media;

use WP_Error;
use WP_Post;
use WP_Query;
use stdClass;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Media
 *
 * Registers the read-only `core/media-query` ability, which retrieves readable media items
 * (attachments). Supports fetching a single readable item by ID, or querying a paginated
 * collection filtered by search, media type, MIME type, parent, author, included or
 * excluded IDs, and status. Raw fields require edit access.
 *
 * Also registers `core/media-upload`, `core/media-update`, and `core/media-delete`, which
 * write attachments and answer with them through the same field projection, in the edit
 * context.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Media {

	/**
	 * The ability category used for media abilities.
	 *
	 * @since x.x.x
	 * @var string
	 */
	private const CATEGORY = 'content';

	/**
	 * Default number of items returned per page in collection mode.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const DEFAULT_PER_PAGE = 10;

	/**
	 * Maximum number of items returned per page in collection mode.
	 *
	 * @since x.x.x
	 * @var int
	 */
	private const MAX_PER_PAGE = 100;

	/**
	 * Fields that expose edit-context attachment data.
	 *
	 * A request that includes any of these fields is answered in the edit context, which
	 * requires edit access.
	 *
	 * @since x.x.x
	 * @var list<string>
	 */
	private array $edit_fields = array(
		'title_raw',
		'description_raw',
		'caption_raw',
	);

	/**
	 * Default fields returned when the caller does not request a field subset.
	 *
	 * @since x.x.x
	 * @var list<string>
	 */
	private array $default_fields = array(
		'id',
		'date',
		'slug',
		'title_rendered',
		'alt_text',
		'media_type',
		'mime_type',
		'source_url',
	);

	/**
	 * Hooks the ability into the Abilities API.
	 *
	 * Registers slightly later than core (priority 11) so it can replace any core-provided
	 * copy, and registers the category as a fallback in case core has not.
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
	 * Registers the read-only `core/media-query` ability, then the write abilities.
	 *
	 * Must run on the `wp_abilities_api_init` hook.
	 *
	 * @since x.x.x
	 */
	public function register(): void {
		// Unregister any core-provided copy first so the plugin's version wins.
		if ( wp_has_ability( 'core/media-query' ) ) {
			wp_unregister_ability( 'core/media-query' );
		}

		wp_register_ability(
			'core/media-query',
			array(
				'label'               => __( 'Media Query', 'ai' ),
				'description'         => __( 'Reads media library items. A single item requested by ID is returned directly. Query mode returns readable items filtered by search (titles, captions, descriptions, and file names), media type, MIME type, parent, author, included or excluded IDs, and status, with totals. Requires an authenticated user.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => $this->get_output_schema(),
				'execute_callback'    => array( $this, 'execute_media_query' ),
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

		$this->register_media_write_abilities();
	}

	/**
	 * Registers the `core/media-upload`, `core/media-update`, and `core/media-delete` abilities.
	 *
	 * @since x.x.x
	 */
	private function register_media_write_abilities(): void {
		$abilities = array(
			'core/media-upload' => array(
				'label'               => __( 'Media Upload', 'ai' ),
				'description'         => __( 'Uploads a file to the media library, from base64 data and a file name, or by downloading an image from a URL. With data, accepts a title, caption, description, alt text, parent post, author, status, slug, and date; a URL upload only accepts the parent post. Returns the new item; use `fields` to choose which fields are returned. Requires an authenticated user who can upload files.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_upload_input_schema(),
				'output_schema'       => $this->get_write_output_schema(),
				'execute_callback'    => array( $this, 'execute_media_upload' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						// Every call creates a new attachment.
						'idempotent'  => false,
						// A URL upload downloads the file from another site.
						'open_world'  => true,
					),
					'show_in_rest' => true,
				),
			),
			'core/media-update' => array(
				'label'               => __( 'Media Update', 'ai' ),
				'description'         => __( 'Updates a media item by ID. Accepts a title, caption, description, alt text, parent post, author, status, slug, and date. Returns the updated item; use `fields` to choose which fields are returned. Requires an authenticated user who can edit the item.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_update_input_schema(),
				'output_schema'       => $this->get_write_output_schema(),
				'execute_callback'    => array( $this, 'execute_media_update' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						// Attachments keep no revisions of the values an update overwrites.
						'destructive' => true,
						// Every call touches the modified date, and destructive idempotent
						// abilities are served over DELETE, which carries the input in the URL.
						'idempotent'  => false,
						'open_world'  => false,
					),
					'show_in_rest' => true,
				),
			),
			'core/media-delete' => array(
				'label'               => __( 'Media Delete', 'ai' ),
				'description'         => __( 'Deletes a media item by ID. With `force` set to true, the item and its files are deleted permanently. Without it, the item is moved to the trash, which is an error unless the site enables the media trash, as is trashing an item that is already in the trash. Returns the trashed item, or the deleted item under `previous` when `force` is true; use `fields` to choose which fields are returned. Requires an authenticated user who can delete the item.', 'ai' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_delete_input_schema(),
				'output_schema'       => $this->get_delete_output_schema(),
				'execute_callback'    => array( $this, 'execute_media_delete' ),
				'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						// Repeating a deletion has no further effect; the Abilities API serves
						// destructive idempotent abilities over the DELETE method.
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
	 * Permission callback for the `core/media-query` ability.
	 *
	 * Requires an authenticated user and attachments shown in REST. A single item must
	 * exist and be readable, and with raw fields also editable; a missing item is denied
	 * like an unreadable one. A collection with raw fields requires permission to edit
	 * attachments. Collection rows are checked one by one in
	 * {@see self::execute_media_query()}.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function check_permission( $input = array() ): bool {
		if ( ! is_user_logged_in() || ! $this->check_is_post_type_allowed( 'attachment' ) ) {
			return false;
		}

		$request = $this->prepare_request( rest_sanitize_object( $input ) );

		if ( isset( $request['id'] ) ) {
			return $this->get_item_permissions_check( $request );
		}

		return $this->get_items_permissions_check( $request );
	}

	/**
	 * Executes the `core/media-query` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::check_permission()} first, so a
	 * single item is only looked up again here, while each collection row is checked. The
	 * global post, which each prepared item replaces, and the filters added while preparing
	 * items are restored afterwards, even when a callback throws.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error A single item, a `media` list with totals, or an error.
	 */
	public function execute_media_query( $input = array() ) {
		$request       = $this->prepare_request( rest_sanitize_object( $input ) );
		$previous_post = $GLOBALS['post'] ?? null;

		try {
			if ( isset( $request['id'] ) ) {
				return $this->get_item( $request );
			}

			$statuses = $this->sanitize_post_statuses( $request['status'] );
			if ( is_wp_error( $statuses ) ) {
				return new WP_Error(
					'media_invalid_param',
					$statuses->get_error_message(),
					array( 'status' => 400 )
				);
			}

			$request['status'] = $statuses;

			return $this->get_items( $request );
		} finally {
			$this->restore_request_state( $previous_post );
		}
	}

	/**
	 * Restores the global post and removes the filters added while preparing items.
	 *
	 * Runs in a `finally` block: a callback that throws would otherwise leave them changed
	 * for the rest of the request.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $previous_post The global post before the ability ran.
	 */
	private function restore_request_state( $previous_post ): void {
		remove_filter( 'protected_title_format', array( $this, 'protected_title_format' ) );
		remove_filter( 'private_title_format', array( $this, 'protected_title_format' ) );
		remove_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );

		if ( $previous_post instanceof WP_Post ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous global post.
			$GLOBALS['post'] = $previous_post;
			setup_postdata( $previous_post );
		} else {
			unset( $GLOBALS['post'] );
			wp_reset_postdata();
		}
	}

	/**
	 * Builds the request parameters from the ability input.
	 *
	 * Fills in the defaults of the collection parameters and applies their sanitization.
	 * Requesting a raw field selects the edit context. GET requests deliver scalars as
	 * strings and lists as CSV strings, so both forms are parsed.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $input The ability input.
	 * @return array<string, mixed> The request parameters.
	 */
	private function prepare_request( array $input ): array {
		$fields = $this->parse_list( $input['fields'] ?? null );

		$request = array(
			'context' => array() === array_intersect( $this->edit_fields, $fields ) ? 'view' : 'edit',
			'fields'  => $fields,
		);

		if ( array_key_exists( 'id', $input ) ) {
			$request['id'] = is_scalar( $input['id'] ) ? $input['id'] : 0;

			return $request;
		}

		$request['page']       = isset( $input['page'] ) ? absint( $input['page'] ) : 1;
		$request['per_page']   = isset( $input['per_page'] ) ? absint( $input['per_page'] ) : self::DEFAULT_PER_PAGE;
		$request['author']     = isset( $input['author'] ) ? wp_parse_id_list( $input['author'] ) : array();
		$request['exclude']    = isset( $input['exclude'] ) ? wp_parse_id_list( $input['exclude'] ) : array(); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Request parameter, not a query.
		$request['include']    = isset( $input['include'] ) ? wp_parse_id_list( $input['include'] ) : array();
		$request['parent']     = isset( $input['parent'] ) ? wp_parse_id_list( $input['parent'] ) : array();
		$request['order']      = isset( $input['order'] ) && is_string( $input['order'] ) ? $input['order'] : 'desc';
		$request['orderby']    = isset( $input['orderby'] ) && is_string( $input['orderby'] ) ? $input['orderby'] : 'date';
		$request['status']     = $input['status'] ?? 'inherit';
		$request['media_type'] = isset( $input['media_type'] ) ? $this->parse_list( $input['media_type'] ) : null;
		$request['mime_type']  = isset( $input['mime_type'] ) ? $this->parse_list( $input['mime_type'] ) : null;

		if ( isset( $input['search'] ) && is_string( $input['search'] ) ) {
			$request['search'] = sanitize_text_field( $input['search'] );
		}

		return $request;
	}

	/**
	 * Parses a list input given as an array or as a CSV string.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $value The raw input value.
	 * @return list<string> The string items; empty when the value is not a list.
	 */
	private function parse_list( $value ): array {
		if ( ! is_array( $value ) && ! is_string( $value ) ) {
			return array();
		}

		return array_values( array_filter( wp_parse_list( $value ), 'is_string' ) );
	}

	/**
	 * Returns the fields to include in a response.
	 *
	 * Without requested fields, the lean default set is used. `id` is always included.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $request The request parameters.
	 * @return list<string> The field names.
	 */
	private function get_fields_for_response( array $request ): array {
		$fields = is_array( $request['fields'] ) && array() !== $request['fields'] ? $request['fields'] : $this->default_fields;

		// Always persist 'id'.
		$fields[] = 'id';

		return array_values( array_filter( $fields, 'is_string' ) );
	}

	/**
	 * Checks if a given request has access to read attachments.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $request The request parameters.
	 * @return bool True if the request has read access, false otherwise.
	 */
	private function get_items_permissions_check( array $request ): bool {

		$post_type = get_post_type_object( 'attachment' );

		return 'edit' !== $request['context'] || ( $post_type && current_user_can( $post_type->cap->edit_posts ) ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
	}

	/**
	 * Retrieves a collection of attachments.
	 *
	 * The totals come from the query, so they also count rows that the per-row permission
	 * checks withhold.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $request The request parameters.
	 * @return array<string, mixed>|\WP_Error The `media` list with totals, or an error.
	 */
	private function get_items( array $request ) {

		// Ensure a search string is set in case the orderby is set to 'relevance'.
		if ( ! empty( $request['orderby'] ) && 'relevance' === $request['orderby'] && empty( $request['search'] ) ) {
			return new WP_Error(
				'media_no_search_term_defined',
				__( 'You need to define a search term to order by relevance.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		// Ensure an include parameter is set in case the orderby is set to 'include'.
		if ( ! empty( $request['orderby'] ) && 'include' === $request['orderby'] && empty( $request['include'] ) ) {
			return new WP_Error(
				'media_orderby_include_missing_include',
				__( 'You need to define an include parameter to order by include.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$args = array();

		/*
		 * This array defines mappings between public API query parameters whose
		 * values are accepted as-passed, and their internal WP_Query parameter
		 * name equivalents (some are the same).
		 */
		$parameter_mappings = array(
			'author'  => 'author__in',
			'exclude' => 'post__not_in', // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- The exclude filter needs it.
			'include' => 'post__in',
			'order'   => 'order',
			'orderby' => 'orderby',
			'page'    => 'paged',
			'parent'  => 'post_parent__in',
			'search'  => 's',
			'status'  => 'post_status',
		);

		/*
		 * For each known parameter which is present in the request,
		 * set the parameter's value on the query $args.
		 */
		foreach ( $parameter_mappings as $api_param => $wp_param ) {
			if ( ! isset( $request[ $api_param ] ) ) {
				continue;
			}

			$args[ $wp_param ] = $request[ $api_param ];
		}

		$args['posts_per_page'] = $request['per_page'];

		// Force the post_type argument, since it's not a user input variable.
		$args['post_type'] = 'attachment';

		$query_args = $this->prepare_items_query( $args, $request );

		$posts_query = new WP_Query();

		/** @var \WP_Post[] $query_result The query does not ask for IDs only. */
		$query_result = $posts_query->query( $query_args );

		$posts = array();

		update_post_author_caches( $query_result );
		update_post_parent_caches( $query_result );

		foreach ( $query_result as $post ) {
			if ( 'edit' === $request['context'] ) {
				$permission = $this->check_update_permission( $post );
			} else {
				$permission = $this->check_read_permission( $post );
			}

			if ( ! $permission ) {
				continue;
			}

			$posts[] = $this->prepare_item_for_response( $post, $request );
		}

		$page        = (int) ( $query_args['paged'] ?? 0 );
		$total_posts = $posts_query->found_posts;

		if ( $total_posts < 1 && $page > 1 ) {
			// Out-of-bounds, run the query without pagination/offset to get the total count.
			unset( $query_args['paged'] );

			$count_query                          = new WP_Query();
			$query_args['fields']                 = 'ids';
			$query_args['posts_per_page']         = 1;
			$query_args['update_post_meta_cache'] = false;
			$query_args['update_post_term_cache'] = false;

			$count_query->query( $query_args );
			$total_posts = $count_query->found_posts;
		}

		$max_pages = (int) ceil( $total_posts / (int) $posts_query->query_vars['posts_per_page'] );

		if ( $page > $max_pages && $total_posts > 0 ) {
			return new WP_Error(
				'media_post_invalid_page_number',
				__( 'The page number requested is larger than the number of pages available.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'media'       => $posts,
			'total'       => (int) $total_posts,
			'total_pages' => (int) $max_pages,
		);
	}

	/**
	 * Gets the attachment, if the ID is valid.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $id Supplied ID.
	 * @return \WP_Post|\WP_Error Attachment object if ID is valid, WP_Error otherwise.
	 */
	private function get_post( $id ) {
		$error = new WP_Error(
			'media_post_invalid_id',
			__( 'Invalid post ID.', 'ai' ),
			array( 'status' => 404 )
		);

		if ( (int) $id <= 0 ) {
			return $error;
		}

		$post = get_post( (int) $id );
		if ( empty( $post ) || empty( $post->ID ) || 'attachment' !== $post->post_type ) {
			return $error;
		}

		return $post;
	}

	/**
	 * Checks if a given request has access to read an attachment.
	 *
	 * A missing attachment is denied like an unreadable one, so the result does not reveal
	 * which IDs exist.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $request The request parameters.
	 * @return bool True if the request has read access for the item, false otherwise.
	 */
	private function get_item_permissions_check( array $request ): bool {
		$post = $this->get_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return false;
		}

		if ( 'edit' === $request['context'] && ! $this->check_update_permission( $post ) ) {
			return false;
		}

		return $this->check_read_permission( $post );
	}

	/**
	 * Retrieves a single attachment.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $request The request parameters.
	 * @return array<string, mixed>|\WP_Error The attachment data, or an error.
	 */
	private function get_item( array $request ) {
		$post = $this->get_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return $this->prepare_item_for_response( $post, $request );
	}

	/**
	 * Determines the allowed query_vars for a get_items() response and prepares
	 * them for WP_Query.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $prepared_args Prepared WP_Query arguments.
	 * @param array<string, mixed> $request       The request parameters.
	 * @return array<string, mixed> Items query arguments.
	 */
	private function prepare_items_query( array $prepared_args, array $request ): array {
		$query_args = $prepared_args;

		$query_args['ignore_sticky_posts'] = true;

		// Map to proper WP_Query orderby param.
		if ( isset( $query_args['orderby'] ) && isset( $request['orderby'] ) ) {
			$orderby_mappings = array(
				'id'            => 'ID',
				'include'       => 'post__in',
				'slug'          => 'post_name',
				'include_slugs' => 'post_name__in',
			);

			if ( isset( $orderby_mappings[ $request['orderby'] ] ) ) {
				$query_args['orderby'] = $orderby_mappings[ $request['orderby'] ];
			}
		}

		if ( empty( $query_args['post_status'] ) ) {
			$query_args['post_status'] = 'inherit';
		}

		$all_mime_types = array();
		$media_types    = $this->get_media_types();

		if ( ! empty( $request['media_type'] ) && is_array( $request['media_type'] ) ) {
			foreach ( $request['media_type'] as $type ) {
				if ( ! isset( $media_types[ $type ] ) ) {
					continue;
				}

				$all_mime_types = array_merge( $all_mime_types, $media_types[ $type ] );
			}
		}

		if ( ! empty( $request['mime_type'] ) && is_array( $request['mime_type'] ) ) {
			foreach ( $request['mime_type'] as $mime_type ) {
				$parts = explode( '/', $mime_type );
				if ( ! isset( $media_types[ $parts[0] ] ) || ! in_array( $mime_type, $media_types[ $parts[0] ], true ) ) {
					continue;
				}

				$all_mime_types[] = $mime_type;
			}
		}

		if ( ! empty( $all_mime_types ) ) {
			$query_args['post_mime_type'] = array_values( array_unique( $all_mime_types ) );
		}

		// Filter query clauses to include filenames.
		if ( isset( $query_args['s'] ) ) {
			add_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );
		}

		return $query_args;
	}

	/**
	 * Checks the post_date_gmt or modified_gmt and prepare any post or
	 * modified date for single post output.
	 *
	 * @since x.x.x
	 *
	 * @param string      $date_gmt GMT publication time.
	 * @param string|null $date     Optional. Local publication time. Default null.
	 * @return string|null ISO8601/RFC3339 formatted datetime.
	 */
	private function prepare_date_response( $date_gmt, $date = null ) {
		// Use the date if passed.
		if ( isset( $date ) ) {
			return mysql_to_rfc3339( $date );
		}

		// Return null if $date_gmt is empty/zeros.
		if ( '0000-00-00 00:00:00' === $date_gmt ) {
			return null;
		}

		// Return the formatted datetime.
		return mysql_to_rfc3339( $date_gmt );
	}

	/**
	 * Checks if a given post type can be viewed or managed.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post_Type|string|null $post_type Post type name or object.
	 * @return bool Whether the post type is allowed.
	 */
	private function check_is_post_type_allowed( $post_type ): bool {
		if ( is_string( $post_type ) ) {
			$post_type = get_post_type_object( $post_type );
		}

		return ! empty( $post_type ) && ! empty( $post_type->show_in_rest );
	}

	/**
	 * Checks if a post can be read.
	 *
	 * Correctly handles posts with the inherit status.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool Whether the post can be read.
	 */
	private function check_read_permission( $post ): bool {
		$post_type = get_post_type_object( $post->post_type );
		if ( ! $this->check_is_post_type_allowed( $post_type ) ) {
			return false;
		}

		// Is the post readable?
		if ( 'publish' === $post->post_status || current_user_can( 'read_post', $post->ID ) ) {
			return true;
		}

		$post_status_obj = get_post_status_object( $post->post_status );
		if ( $post_status_obj && $post_status_obj->public ) {
			return true;
		}

		// Can we read the parent if we're inheriting?
		if ( 'inherit' === $post->post_status && $post->post_parent > 0 ) {
			$parent = get_post( $post->post_parent );
			if ( $parent ) {
				return $this->check_read_permission( $parent );
			}
		}

		/*
		 * If there isn't a parent, but the status is set to inherit, assume
		 * it's published (as per get_post_status()).
		 */
		return 'inherit' === $post->post_status;
	}

	/**
	 * Checks if a post can be edited.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool Whether the post can be edited.
	 */
	private function check_update_permission( $post ): bool {
		$post_type = get_post_type_object( $post->post_type );

		if ( ! $this->check_is_post_type_allowed( $post_type ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Sanitizes and validates the list of post statuses, including whether the
	 * user can query private statuses.
	 *
	 * @since x.x.x
	 *
	 * @param string|array<mixed> $statuses One or more post statuses.
	 * @return array<string>|\WP_Error A list of valid statuses, otherwise WP_Error object.
	 */
	private function sanitize_post_statuses( $statuses ) {
		$statuses = wp_parse_slug_list( $statuses );

		$default_status = 'inherit';

		foreach ( $statuses as $status ) {
			if ( $status === $default_status ) {
				continue;
			}

			$post_type_obj = get_post_type_object( 'attachment' );

			// phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
			if ( $post_type_obj && ( current_user_can( $post_type_obj->cap->edit_posts ) || ( 'private' === $status && current_user_can( $post_type_obj->cap->read_private_posts ) ) ) ) {
				continue;
			}

			return new WP_Error(
				'media_forbidden_status',
				__( 'Status is forbidden.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return $statuses;
	}

	/**
	 * Prepares a single attachment for the response.
	 *
	 * Includes only the requested fields; `id` is always included.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post             $item    Attachment object.
	 * @param array<string, mixed> $request The request parameters.
	 * @return array<string, mixed> The attachment data.
	 */
	private function prepare_item_for_response( $item, array $request ): array {
		// Restores the more descriptive, specific name for use within this method.
		$post = $item;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- execute_media_query() restores the global post.
		$GLOBALS['post'] = $post;

		setup_postdata( $post );

		$fields = $this->get_fields_for_response( $request );

		// Base fields for every post.
		$data = array();

		if ( rest_is_field_included( 'id', $fields ) ) {
			$data['id'] = $post->ID;
		}

		if ( rest_is_field_included( 'date', $fields ) ) {
			$data['date'] = $this->prepare_date_response( $post->post_date_gmt, $post->post_date );
		}

		if ( rest_is_field_included( 'date_gmt', $fields ) ) {
			/*
			 * For drafts, `post_date_gmt` may not be set, indicating that the date
			 * of the draft should be updated each time it is saved (see #38883).
			 * In this case, shim the value based on the `post_date` field
			 * with the site's timezone offset applied.
			 */
			if ( '0000-00-00 00:00:00' === $post->post_date_gmt ) {
				$post_date_gmt = get_gmt_from_date( $post->post_date );
			} else {
				$post_date_gmt = $post->post_date_gmt;
			}
			$data['date_gmt'] = $this->prepare_date_response( $post_date_gmt );
		}

		if ( rest_is_field_included( 'modified', $fields ) ) {
			$data['modified'] = $this->prepare_date_response( $post->post_modified_gmt, $post->post_modified );
		}

		if ( rest_is_field_included( 'modified_gmt', $fields ) ) {
			/*
			 * For drafts, `post_modified_gmt` may not be set (see `post_date_gmt` comments
			 * above). In this case, shim the value based on the `post_modified` field
			 * with the site's timezone offset applied.
			 */
			if ( '0000-00-00 00:00:00' === $post->post_modified_gmt ) {
				$post_modified_gmt = gmdate( 'Y-m-d H:i:s', strtotime( $post->post_modified ) - (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
			} else {
				$post_modified_gmt = $post->post_modified_gmt;
			}
			$data['modified_gmt'] = $this->prepare_date_response( $post_modified_gmt );
		}

		if ( rest_is_field_included( 'slug', $fields ) ) {
			$data['slug'] = $post->post_name;
		}

		if ( rest_is_field_included( 'status', $fields ) ) {
			$data['status'] = $post->post_status;
		}

		if ( rest_is_field_included( 'link', $fields ) ) {
			$data['link'] = (string) get_permalink( $post->ID );
		}

		if ( rest_is_field_included( 'title_raw', $fields ) ) {
			$data['title_raw'] = $post->post_title;
		}
		if ( rest_is_field_included( 'title_rendered', $fields ) ) {
			add_filter( 'protected_title_format', array( $this, 'protected_title_format' ) );
			add_filter( 'private_title_format', array( $this, 'protected_title_format' ) );

			$data['title_rendered'] = get_the_title( $post->ID );

			remove_filter( 'protected_title_format', array( $this, 'protected_title_format' ) );
			remove_filter( 'private_title_format', array( $this, 'protected_title_format' ) );
		}

		if ( rest_is_field_included( 'author', $fields ) ) {
			$data['author'] = (int) $post->post_author;
		}

		if ( in_array( 'description_raw', $fields, true ) ) {
			$data['description_raw'] = $post->post_content;
		}

		if ( in_array( 'description_rendered', $fields, true ) ) {
			/** This filter is documented in wp-includes/post-template.php */
			$data['description_rendered'] = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		}

		if ( in_array( 'caption_raw', $fields, true ) ) {
			$data['caption_raw'] = $post->post_excerpt;
		}

		if ( in_array( 'caption_rendered', $fields, true ) ) {
			/** This filter is documented in wp-includes/post-template.php */
			$caption = apply_filters( 'get_the_excerpt', $post->post_excerpt, $post ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.

			/** This filter is documented in wp-includes/post-template.php */
			$caption = apply_filters( 'the_excerpt', $caption ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.

			$data['caption_rendered'] = $caption;
		}

		if ( in_array( 'alt_text', $fields, true ) ) {
			$data['alt_text'] = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
		}

		if ( in_array( 'media_type', $fields, true ) ) {
			$data['media_type'] = wp_attachment_is_image( $post->ID ) ? 'image' : 'file';
		}

		if ( in_array( 'mime_type', $fields, true ) ) {
			$data['mime_type'] = $post->post_mime_type;
		}

		if ( in_array( 'media_details', $fields, true ) ) {
			$data['media_details'] = wp_get_attachment_metadata( $post->ID );

			// Ensure empty details is an empty object.
			if ( empty( $data['media_details'] ) ) {
				$data['media_details'] = new stdClass();
			} elseif ( ! empty( $data['media_details']['sizes'] ) ) {
				foreach ( $data['media_details']['sizes'] as $size => &$size_data ) {
					if ( isset( $size_data['mime-type'] ) ) {
						$size_data['mime_type'] = $size_data['mime-type'];
						unset( $size_data['mime-type'] );
					}

					// Use the same method image_downsize() does.
					$image_src = wp_get_attachment_image_src( $post->ID, $size );
					if ( ! $image_src ) {
						continue;
					}

					$size_data['source_url'] = $image_src[0];
				}
				unset( $size_data );

				$full_src = wp_get_attachment_image_src( $post->ID, 'full' );

				if ( ! empty( $full_src ) ) {
					$data['media_details']['sizes']['full'] = array(
						'file'       => wp_basename( $full_src[0] ),
						'width'      => $full_src[1],
						'height'     => $full_src[2],
						'mime_type'  => $post->post_mime_type,
						'source_url' => $full_src[0],
					);
				}
			} else {
				$data['media_details']['sizes'] = new stdClass();
			}
		}

		if ( in_array( 'post', $fields, true ) ) {
			$data['post'] = ! empty( $post->post_parent ) ? (int) $post->post_parent : null;
		}

		if ( in_array( 'source_url', $fields, true ) ) {
			$data['source_url'] = (string) wp_get_attachment_url( $post->ID );
		}

		if ( in_array( 'filename', $fields, true ) ) {
			$data['filename'] = $this->get_attachment_filename( $post->ID );
		}

		if ( in_array( 'filesize', $fields, true ) ) {
			$data['filesize'] = $this->get_attachment_filesize( $post->ID );
		}

		return $data;
	}

	/**
	 * Overwrites the default protected and private title format.
	 *
	 * By default, WordPress will show password protected or private posts with a title of
	 * "Protected: %s" or "Private: %s". The status is reported in a machine-readable
	 * format, so the prefix is removed.
	 *
	 * @since x.x.x
	 *
	 * @return string Title format.
	 */
	public function protected_title_format(): string {
		return '%s';
	}

	/**
	 * Retrieves the supported media types.
	 *
	 * Media types are considered the MIME type category.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, list<string>> Array of supported media types.
	 */
	private function get_media_types(): array {
		$media_types = array();

		foreach ( get_allowed_mime_types() as $mime_type ) {
			$parts = explode( '/', $mime_type );

			if ( ! isset( $media_types[ $parts[0] ] ) ) {
				$media_types[ $parts[0] ] = array();
			}

			$media_types[ $parts[0] ][] = $mime_type;
		}

		return $media_types;
	}

	/**
	 * Gets the attachment's original file name.
	 *
	 * @since x.x.x
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null Attachment file name, or null if not found.
	 */
	private function get_attachment_filename( int $attachment_id ): ?string {
		$path = wp_get_original_image_path( $attachment_id );

		if ( $path ) {
			return wp_basename( $path );
		}

		$path = get_attached_file( $attachment_id );

		if ( $path ) {
			return wp_basename( $path );
		}

		return null;
	}

	/**
	 * Gets the attachment's file size in bytes.
	 *
	 * @since x.x.x
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int|null Attachment file size in bytes, or null if not available.
	 */
	private function get_attachment_filesize( int $attachment_id ): ?int {
		$meta = wp_get_attachment_metadata( $attachment_id );

		if ( isset( $meta['filesize'] ) && is_numeric( $meta['filesize'] ) && $meta['filesize'] > 0 ) {
			return (int) $meta['filesize'];
		}

		$original_path = wp_get_original_image_path( $attachment_id );
		$attached_file = $original_path ? $original_path : get_attached_file( $attachment_id );

		if ( is_string( $attached_file ) && is_readable( $attached_file ) ) {
			return wp_filesize( $attached_file );
		}

		return null;
	}

	/**
	 * Builds the input schema for the `core/media-query` ability.
	 *
	 * The ability has two mutually exclusive modes, modeled as a `oneOf` so invalid
	 * combinations are rejected rather than silently ignored: get a single item by `id`,
	 * or query a collection with the collection parameters. `fields` is accepted in both.
	 * Omitting the input queries the collection with its defaults.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_input_schema(): array {
		$fields = array(
			'type'        => 'array',
			'uniqueItems' => true,
			'items'       => array(
				'type' => 'string',
				'enum' => array_keys( $this->get_item_schema() ),
			),
			'description' => __( 'Limit each returned item to these fields. If omitted, a lean set of common fields is returned. The ID is always included. Raw fields require edit access.', 'ai' ),
		);

		return array(
			'type'    => 'object',
			'default' => (object) array(),
			'oneOf'   => array(
				array(
					'title'                => __( 'Get a single readable media item by ID', 'ai' ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Unique identifier for the attachment.', 'ai' ),
						),
						'fields' => $fields,
					),
				),
				array(
					'title'                => __( 'Query readable media items', 'ai' ),
					'additionalProperties' => false,
					'properties'           => array_merge(
						$this->get_collection_params(),
						array( 'fields' => $fields )
					),
				),
			),
		);
	}

	/**
	 * Returns the collection mode parameters.
	 *
	 * Defaults are applied by {@see self::prepare_request()}, so they are only described
	 * here.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array<string, mixed>> Collection parameters.
	 */
	private function get_collection_params(): array {
		return array(
			'page'       => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Current page of the collection. Defaults to 1. Requesting a page beyond the last one is an error.', 'ai' ),
			),
			'per_page'   => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => self::MAX_PER_PAGE,
				'description' => __( 'Maximum number of items to be returned in result set. Defaults to 10.', 'ai' ),
			),
			'search'     => array(
				'type'        => 'string',
				'description' => __( 'Limit results to those matching a string in the title, caption, description, or file name.', 'ai' ),
			),
			'author'     => array(
				'type'        => 'array',
				'items'       => array(
					'type' => 'integer',
				),
				'description' => __( 'Limit result set to attachments assigned to specific authors.', 'ai' ),
			),
			'exclude'    => array( // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Input schema, not a query.
				'type'        => 'array',
				'items'       => array(
					'type' => 'integer',
				),
				'description' => __( 'Ensure result set excludes specific IDs. Ignored when include is given.', 'ai' ),
			),
			'include'    => array(
				'type'        => 'array',
				'items'       => array(
					'type' => 'integer',
				),
				'description' => __( 'Limit result set to specific IDs.', 'ai' ),
			),
			'order'      => array(
				'type'        => 'string',
				'enum'        => array( 'asc', 'desc' ),
				'description' => __( 'Order sort attribute ascending or descending. Defaults to desc.', 'ai' ),
			),
			'orderby'    => array(
				'type'        => 'string',
				'enum'        => array(
					'author',
					'date',
					'id',
					'include',
					'modified',
					'parent',
					'relevance',
					'slug',
					'title',
				),
				'description' => __( 'Sort collection by attachment attribute. Defaults to date. Ordering by relevance requires search, and ordering by include requires include.', 'ai' ),
			),
			'parent'     => array(
				'type'        => 'array',
				'items'       => array(
					'type' => 'integer',
				),
				'description' => __( 'Limit result set to items with particular parent IDs. Use 0 for items that are not attached to a post.', 'ai' ),
			),
			'status'     => array(
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
					'enum' => array( 'inherit', 'private', 'trash' ),
				),
				'description' => __( 'Limit result set to attachments assigned one or more statuses. Defaults to inherit. Private requires permission to read private posts or to edit posts; trash requires permission to edit posts.', 'ai' ),
			),
			'media_type' => array(
				'type'        => 'array',
				// A site that allows no uploads would get an empty enum, which is not a valid schema.
				'items'       => array_filter(
					array(
						'type' => 'string',
						'enum' => array_keys( $this->get_media_types() ),
					)
				),
				'description' => __( 'Limit result set to attachments of a particular media type or media types.', 'ai' ),
			),
			'mime_type'  => array(
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
				),
				'description' => __( 'Limit result set to attachments of a particular MIME type or MIME types. MIME types the site does not allow are ignored.', 'ai' ),
			),
		);
	}

	/**
	 * Returns the attachment field definitions, keyed by field name in output order.
	 *
	 * The output schema uses the definitions directly, while the input schema's `fields`
	 * enum uses the keys. No field is required, because `fields` selects any subset.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array<string, mixed>> Attachment field definitions.
	 */
	private function get_item_schema(): array {
		return array(
			'id'                   => array(
				'type'        => 'integer',
				'description' => __( 'Unique identifier for the attachment.', 'ai' ),
			),
			'date'                 => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( "The date the attachment was published, in the site's timezone.", 'ai' ),
			),
			'date_gmt'             => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'The date the attachment was published, as GMT.', 'ai' ),
			),
			'modified'             => array(
				'type'        => 'string',
				'description' => __( "The date the attachment was last modified, in the site's timezone.", 'ai' ),
			),
			'modified_gmt'         => array(
				'type'        => 'string',
				'description' => __( 'The date the attachment was last modified, as GMT.', 'ai' ),
			),
			'slug'                 => array(
				'type'        => 'string',
				'description' => __( 'An alphanumeric identifier for the attachment.', 'ai' ),
			),
			'status'               => array(
				'type'        => 'string',
				'description' => __( 'A named status for the attachment.', 'ai' ),
			),
			'link'                 => array(
				'type'        => 'string',
				'description' => __( 'URL to the attachment.', 'ai' ),
			),
			'title_raw'            => array(
				'type'        => 'string',
				'description' => __( 'Title for the attachment, as it exists in the database. Requires edit access.', 'ai' ),
			),
			'title_rendered'       => array(
				'type'        => 'string',
				'description' => __( 'HTML title for the attachment, transformed for display.', 'ai' ),
			),
			'author'               => array(
				'type'        => 'integer',
				'description' => __( 'The ID for the author of the attachment.', 'ai' ),
			),
			'description_raw'      => array(
				'type'        => 'string',
				'description' => __( 'Description for the attachment, as it exists in the database. Requires edit access.', 'ai' ),
			),
			'description_rendered' => array(
				'type'        => 'string',
				'description' => __( 'HTML description for the attachment, transformed for display.', 'ai' ),
			),
			'caption_raw'          => array(
				'type'        => 'string',
				'description' => __( 'Caption for the attachment, as it exists in the database. Requires edit access.', 'ai' ),
			),
			'caption_rendered'     => array(
				'type'        => 'string',
				'description' => __( 'HTML caption for the attachment, transformed for display.', 'ai' ),
			),
			'alt_text'             => array(
				'type'        => 'string',
				'description' => __( 'Alternative text to display when attachment is not displayed.', 'ai' ),
			),
			'media_type'           => array(
				'type'        => 'string',
				'enum'        => array( 'image', 'file' ),
				'description' => __( 'Attachment type.', 'ai' ),
			),
			'mime_type'            => array(
				'type'        => 'string',
				'description' => __( 'The attachment MIME type.', 'ai' ),
			),
			'media_details'        => array(
				'type'        => 'object',
				'description' => __( 'Details about the media file, specific to its type, such as image dimensions and sizes.', 'ai' ),
			),
			'post'                 => array(
				'type'        => array( 'integer', 'null' ),
				'description' => __( 'The ID for the associated post of the attachment. Null when the attachment is not attached to a post.', 'ai' ),
			),
			'source_url'           => array(
				'type'        => 'string',
				'description' => __( 'URL to the original attachment file.', 'ai' ),
			),
			'filename'             => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'Original attachment file name.', 'ai' ),
			),
			'filesize'             => array(
				'type'        => array( 'integer', 'null' ),
				'description' => __( 'Attachment file size in bytes.', 'ai' ),
			),
		);
	}

	/**
	 * Builds the output schema for the `core/media-query` ability.
	 *
	 * No field is marked required because the `fields` input lets the caller request any
	 * subset. Single-item mode returns the item directly, while query mode returns a
	 * paginated wrapper; neither accepts unknown properties, so a response matches one.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_output_schema(): array {
		$item_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $this->get_item_schema(),
		);

		return array(
			'type'  => 'object',
			'oneOf' => array(
				$item_schema,
				array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'media', 'total', 'total_pages' ),
					'properties'           => array(
						'media'       => array(
							'type'        => 'array',
							'description' => __( 'The media items matching the query that the user can read, or edit when raw fields are requested.', 'ai' ),
							'items'       => $item_schema,
						),
						'total'       => array(
							'type'        => 'integer',
							'description' => __( 'Total number of items matching the query, across all pages. May exceed the number of returned items when some are withheld from the user.', 'ai' ),
						),
						'total_pages' => array(
							'type'        => 'integer',
							'description' => __( 'Total number of pages for the query.', 'ai' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Permission callback for the `core/media-upload` ability.
	 *
	 * Requires an authenticated user who can create attachments and upload files. The author
	 * and the parent post are checked during execution, where the error can name the refused
	 * field.
	 *
	 * @since x.x.x
	 *
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function create_item_permissions_check(): bool {
		if ( ! is_user_logged_in() || ! $this->check_is_post_type_allowed( 'attachment' ) ) {
			return false;
		}

		/** @var \WP_Post_Type $post_type Built-in post types cannot be unregistered. */
		$post_type = get_post_type_object( 'attachment' );

		return current_user_can( $post_type->cap->create_posts ) && current_user_can( 'upload_files' ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
	}

	/**
	 * Executes the `core/media-upload` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::create_item_permissions_check()}
	 * first. The global post, which preparing the response replaces, is restored afterwards.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The uploaded attachment, or an error.
	 */
	public function execute_media_upload( $input = array() ) {
		$request           = rest_sanitize_object( $input );
		$request['fields'] = $this->parse_list( $request['fields'] ?? null );
		$previous_post     = $GLOBALS['post'] ?? null;

		try {
			return $this->create_item( $request );
		} finally {
			$this->restore_request_state( $previous_post );
		}
	}

	/**
	 * Permission callback for the `core/media-update` ability.
	 *
	 * Requires an authenticated user who can edit the attachment; a missing attachment is
	 * denied like one the user cannot edit. The rest of the input is checked during
	 * execution, where the error can name the refused field.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function update_item_permissions_check( $input = array() ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$post = $this->get_requested_post( rest_sanitize_object( $input ) );

		return ! is_wp_error( $post ) && $this->check_update_permission( $post );
	}

	/**
	 * Executes the `core/media-update` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::update_item_permissions_check()}
	 * first. The global post, which preparing the response replaces, is restored afterwards.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The updated attachment, or an error.
	 */
	public function execute_media_update( $input = array() ) {
		$request           = rest_sanitize_object( $input );
		$request['fields'] = $this->parse_list( $request['fields'] ?? null );
		$previous_post     = $GLOBALS['post'] ?? null;

		try {
			return $this->update_item( $request );
		} finally {
			$this->restore_request_state( $previous_post );
		}
	}

	/**
	 * Permission callback for the `core/media-delete` ability.
	 *
	 * Requires an authenticated user who can delete the attachment; a missing attachment is
	 * denied like one the user cannot delete.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return bool True if the request may proceed, false otherwise.
	 */
	public function delete_item_permissions_check( $input = array() ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$post = $this->get_requested_post( rest_sanitize_object( $input ) );

		return ! is_wp_error( $post ) && $this->check_delete_permission( $post );
	}

	/**
	 * Executes the `core/media-delete` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::delete_item_permissions_check()}
	 * first. The global post, which preparing the response replaces, is restored afterwards.
	 *
	 * @since x.x.x
	 *
	 * @param mixed $input Optional. The ability input. Default empty array.
	 * @return array<string, mixed>|\WP_Error The trashed attachment, a `deleted`/`previous` pair, or an error.
	 */
	public function execute_media_delete( $input = array() ) {
		$request           = rest_sanitize_object( $input );
		$request['fields'] = $this->parse_list( $request['fields'] ?? null );
		$previous_post     = $GLOBALS['post'] ?? null;

		try {
			return $this->delete_item( $request );
		} finally {
			$this->restore_request_state( $previous_post );
		}
	}

	/**
	 * Gets the attachment that the `id` input refers to.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $request The request parameters.
	 * @return \WP_Post|\WP_Error Attachment object if the ID is valid, WP_Error otherwise.
	 */
	private function get_requested_post( array $request ) {
		return $this->get_post( isset( $request['id'] ) && is_scalar( $request['id'] ) ? $request['id'] : 0 );
	}

	/**
	 * Creates a single attachment.
	 *
	 * The URL, the author, and the parent post are checked first, as the request validation
	 * and the create permission checks do, because the Abilities API replaces any error a
	 * permission callback returns with a generic one.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $request The request parameters.
	 * @return array<string, mixed>|\WP_Error The attachment data, or an error.
	 */
	private function create_item( array $request ) {
		/*
		 * Reject URLs that are not safe to request server-side. wp_http_validate_url()
		 * enforces an HTTP(S) scheme and blocks private, local, and otherwise
		 * disallowed hosts, guarding the sideload against SSRF.
		 */
		if ( isset( $request['url'] ) && false === wp_http_validate_url( (string) $request['url'] ) ) {
			return new WP_Error(
				'media_invalid_param',
				__( 'Invalid URL. Provide a valid, publicly reachable HTTP or HTTPS image URL.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		/** @var \WP_Post_Type $post_type Built-in post types cannot be unregistered. */
		$post_type = get_post_type_object( 'attachment' );

		if ( ! empty( $request['author'] ) && get_current_user_id() !== (int) $request['author'] && ! current_user_can( $post_type->cap->edit_others_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
			return new WP_Error(
				'media_cannot_edit_others',
				__( 'Sorry, you are not allowed to create posts as this user.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Attaching media to a post requires ability to edit said post.
		if ( ! empty( $request['post'] ) && ! current_user_can( 'edit_post', (int) $request['post'] ) ) {
			return new WP_Error(
				'media_cannot_edit',
				__( 'Sorry, you are not allowed to upload media to this post.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! empty( $request['post'] ) && in_array( get_post_type( (int) $request['post'] ), array( 'revision', 'attachment' ), true ) ) {
			return new WP_Error(
				'media_invalid_param',
				__( 'Invalid parent type.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		// When a URL is supplied instead of file data, sideload the remote image on the server.
		if ( ! empty( $request['url'] ) ) {
			return $this->create_item_from_url( $request );
		}

		$insert = $this->insert_attachment( $request );

		if ( is_wp_error( $insert ) ) {
			return $insert;
		}

		// Extract by name.
		$attachment_id = $insert['attachment_id'];
		$file          = $insert['file'];

		if ( isset( $request['alt_text'] ) && is_string( $request['alt_text'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $request['alt_text'] ) );
		}

		$attachment = $this->get_post( $attachment_id );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		wp_after_insert_post( $attachment, false, null );

		// Include media and image functions to get access to wp_generate_attachment_metadata().
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		/*
		 * Post-process the upload (create image sub-sizes, make PDF thumbnails, etc.) and insert attachment meta.
		 * At this point the server may run out of resources and post-processing of uploaded images may fail.
		 */
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file ) );

		return $this->prepare_item_for_response( $attachment, $request );
	}

	/**
	 * Sideloads an external image from a URL into the media library.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $request The request parameters.
	 * @return array<string, mixed>|\WP_Error The attachment data, or an error.
	 */
	private function create_item_from_url( array $request ) {
		// Sideloading downloads and stores a file, so require the upload capability.
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'media_cannot_create',
				__( 'Sorry, you are not allowed to upload media on this site.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$url     = sanitize_url( $request['url'] );
		$post_id = ! empty( $request['post'] ) ? (int) $request['post'] : 0;

		// Derive the filename from the URL path before downloading anything.
		$url_path = wp_parse_url( $url, PHP_URL_PATH );
		$filename = $url_path ? wp_basename( $url_path ) : '';
		if ( '' === $filename ) {
			return new WP_Error(
				'media_invalid_url',
				__( 'Could not determine a filename from the provided URL.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * Only download URLs whose extension maps to an allowed image MIME type.
		 * The sideload handler would reject other types anyway (via
		 * wp_check_filetype_and_ext()), but checking first avoids downloading
		 * files that can never be accepted, such as PHP scripts.
		 */
		$filetype = wp_check_filetype( $filename );
		if ( ! $filetype['type'] || ! str_starts_with( $filetype['type'], 'image/' ) ) {
			return new WP_Error(
				'media_invalid_url',
				__( 'The provided URL does not point to a supported image file.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * Cap the download at the same size the site would accept as a direct
		 * upload. check_upload_size() only applies on multisite, so without a
		 * ceiling here a single site has no limit at all on this path: the
		 * `upload_max_filesize` and `post_max_size` directives bound a request
		 * body, not a fetch the server makes itself.
		 *
		 * When `wp_max_upload_size` returns 0, no ceiling is applied.
		 */
		$max_size = (int) wp_max_upload_size();

		/*
		 * Download the remote file with WordPress's HTTP API, which validates
		 * the host and blocks requests to private or local addresses. This is
		 * the same primitive core's media_sideload_image() relies on.
		 *
		 * `limit_response_size` stops the transfer once the limit is passed,
		 * so an oversized remote file is never written to disk in full. One
		 * byte over the ceiling is enough to fail the size check below.
		 */
		$limit_response_size = static function ( $args ) use ( $max_size ) {
			$args['limit_response_size'] = $max_size + 1;
			return $args;
		};

		if ( $max_size > 0 ) {
			add_filter( 'http_request_args', $limit_response_size ); // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.http_request_args -- Only the response size is limited.
		}

		try {
			$tmp_file = download_url( $url );
		} finally {
			// Remove the cap even when a hook throws, so later requests are not cut short.
			remove_filter( 'http_request_args', $limit_response_size );
		}

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp_file,
		);

		$size_check = $this->check_upload_size( $file_array );
		if ( is_wp_error( $size_check ) ) {
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			return $size_check;
		}

		if ( $max_size > 0 && wp_filesize( $tmp_file ) > $max_size ) {
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}

			return new WP_Error(
				'media_upload_file_too_big',
				/* translators: %s: Maximum allowed file size in kilobytes. */
				sprintf( __( 'This file is too big. Files must be less than %s KB in size.', 'ai' ), number_format( $max_size / KB_IN_BYTES ) ),
				array( 'status' => 400 )
			);
		}

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			/*
			 * media_handle_sideload() deletes the temp file on success; remove
			 * it explicitly when the sideload fails.
			 */
			if ( file_exists( $tmp_file ) ) {
				wp_delete_file( $tmp_file );
			}
			return $attachment_id;
		}

		$attachment = $this->get_post( $attachment_id );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		return $this->prepare_item_for_response( $attachment, $request );
	}

	/**
	 * Inserts the attachment post in the database. Does not update the attachment meta.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $request The request parameters.
	 * @return array{attachment_id: int, file: string}|\WP_Error The attachment ID and file path, or an error.
	 */
	private function insert_attachment( array $request ) {
		$time = null;

		// Matches logic in media_handle_upload().
		if ( ! empty( $request['post'] ) ) {
			$post = get_post( (int) $request['post'] );
			// The post date doesn't usually matter for pages, so don't backdate this upload.
			if ( $post && 'page' !== $post->post_type && substr( $post->post_date, 0, 4 ) > 0 ) {
				$time = $post->post_date;
			}
		}

		$data     = is_string( $request['data'] ?? null ) ? base64_decode( $request['data'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the uploaded file.
		$filename = is_string( $request['filename'] ?? null ) ? $request['filename'] : '';

		if ( false === $data ) {
			return new WP_Error(
				'media_invalid_param',
				__( 'The data is not a valid base64 encoded string.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$file = $this->upload_from_data( $data, $filename, $time );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$url  = $file['url'];
		$type = $file['type'];
		$file = $file['file'];
		$alt  = '';

		// Include image functions to get access to wp_read_image_metadata().
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Use image exif/iptc data for title and caption defaults if possible.
		$image_meta = wp_read_image_metadata( $file );

		if ( ! empty( $image_meta ) ) {
			if ( empty( $request['title'] ) && trim( $image_meta['title'] ) && ! is_numeric( sanitize_title( $image_meta['title'] ) ) ) {
				$request['title'] = $image_meta['title'];
			}

			if ( empty( $request['caption'] ) && trim( $image_meta['caption'] ) ) {
				$request['caption'] = $image_meta['caption'];
			}

			if ( empty( $request['alt'] ) && trim( $image_meta['alt'] ) ) {
				$alt = $image_meta['alt'];
			}
		}

		$attachment = $this->prepare_item_for_database( $request, null );

		if ( is_wp_error( $attachment ) ) {
			// The file is stored already, and no attachment will refer to it.
			wp_delete_file( $file );

			return $attachment;
		}

		$attachment->post_mime_type = $type;
		$attachment->guid           = $url;

		// If the title was not set, use the file name.
		if ( empty( $attachment->post_title ) ) {
			$attachment->post_title = preg_replace( '/\.[^.]+$/', '', wp_basename( $file ) );
		}

		// $post_parent is inherited from $attachment['post_parent'].
		$id = wp_insert_attachment( wp_slash( (array) $attachment ), $file, 0, true, false );

		if ( is_wp_error( $id ) ) {
			// No attachment refers to the stored file.
			wp_delete_file( $file );

			if ( 'db_update_error' === $id->get_error_code() ) {
				$id->add_data( array( 'status' => 500 ) );
			} else {
				$id->add_data( array( 'status' => 400 ) );
			}

			return $id;
		}

		if ( trim( $alt ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}

		return array(
			'attachment_id' => $id,
			'file'          => $file,
		);
	}

	/**
	 * Handles an upload of file data.
	 *
	 * @since x.x.x
	 *
	 * @param string      $data     Supplied file data.
	 * @param string      $filename The file name.
	 * @param string|null $time     Optional. Time formatted in 'yyyy/mm'. Default null.
	 * @return array<string, mixed>|\WP_Error Data from wp_handle_sideload(): the `file` path, its `url`, and its MIME `type`.
	 */
	private function upload_from_data( string $data, string $filename, ?string $time = null ) {
		if ( empty( $data ) ) {
			return new WP_Error(
				'media_upload_no_data',
				__( 'No data supplied.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		// Include filesystem functions to get access to wp_tempnam() and wp_handle_sideload().
		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Save the file.
		$tmpfname = wp_tempnam( $filename );

		$fp = fopen( $tmpfname, 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writes to a temporary file.

		if ( ! $fp ) {
			return new WP_Error(
				'media_upload_file_error',
				__( 'Could not open file handle.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		fwrite( $fp, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- Writes to a temporary file.
		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Writes to a temporary file.

		// Now, sideload it in.
		$file_data = array(
			'error'    => 0,
			'tmp_name' => $tmpfname,
			'name'     => $filename,
			'type'     => (string) wp_check_filetype( $filename )['type'],
		);

		$size_check = $this->check_upload_size( $file_data );
		if ( is_wp_error( $size_check ) ) {
			return $size_check;
		}

		$overrides = array(
			'test_form' => false,
		);

		$sideloaded = wp_handle_sideload( $file_data, $overrides, $time );

		if ( isset( $sideloaded['error'] ) ) {
			wp_delete_file( $tmpfname );

			return new WP_Error(
				'media_upload_sideload_error',
				$sideloaded['error'],
				array( 'status' => 500 )
			);
		}

		return $sideloaded;
	}

	/**
	 * Determine if uploaded file exceeds space quota on multisite.
	 *
	 * Replicates check_upload_size().
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $file The `$_FILES`-style array for a given file.
	 * @return true|\WP_Error True if can upload, error for errors.
	 */
	private function check_upload_size( array $file ) {
		if ( ! is_multisite() ) {
			return true;
		}

		if ( get_site_option( 'upload_space_check_disabled' ) ) {
			return true;
		}

		$space_left = get_upload_space_available();

		$file_size = filesize( $file['tmp_name'] );

		if ( $space_left < $file_size ) {
			return new WP_Error(
				'media_upload_limited_space',
				/* translators: %s: Required disk space in kilobytes. */
				sprintf( __( 'Not enough space to upload. %s KB needed.', 'ai' ), number_format( ( $file_size - $space_left ) / KB_IN_BYTES ) ),
				array( 'status' => 400 )
			);
		}

		if ( $file_size > KB_IN_BYTES * get_site_option( 'fileupload_maxk', 1500 ) ) {
			return new WP_Error(
				'media_upload_file_too_big',
				/* translators: %s: Maximum allowed file size in kilobytes. */
				sprintf( __( 'This file is too big. Files must be less than %s KB in size.', 'ai' ), get_site_option( 'fileupload_maxk', 1500 ) ),
				array( 'status' => 400 )
			);
		}

		// Include multisite admin functions to get access to upload_is_user_over_quota().
		require_once ABSPATH . 'wp-admin/includes/ms.php';

		if ( upload_is_user_over_quota( false ) ) {
			return new WP_Error(
				'media_upload_user_quota_exceeded',
				__( 'You have used your space quota. Please delete files before uploading.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Updates a single attachment.
	 *
	 * The status, the author and the parent are checked first, as the update permission
	 * checks do, because the Abilities API replaces any error a permission callback returns
	 * with a generic one.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $request The request parameters.
	 * @return array<string, mixed>|\WP_Error The attachment data, or an error.
	 */
	private function update_item( array $request ) {
		$valid_check = $this->get_requested_post( $request );
		if ( is_wp_error( $valid_check ) ) {
			return $valid_check;
		}

		// Keeping the current status is valid, even an internal one such as `inherit`.
		if ( isset( $request['status'] )
			&& $valid_check->post_status !== $request['status']
			&& ! in_array( $request['status'], get_post_stati( array( 'internal' => false ) ), true )
		) {
			return new WP_Error(
				'media_invalid_param',
				__( 'Invalid post status.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		/** @var \WP_Post_Type $post_type Built-in post types cannot be unregistered. */
		$post_type = get_post_type_object( 'attachment' );

		if ( ! empty( $request['author'] ) && get_current_user_id() !== (int) $request['author'] && ! current_user_can( $post_type->cap->edit_others_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
			return new WP_Error(
				'media_cannot_edit_others',
				__( 'Sorry, you are not allowed to update posts as this user.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! empty( $request['post'] ) && in_array( get_post_type( (int) $request['post'] ), array( 'revision', 'attachment' ), true ) ) {
			return new WP_Error(
				'media_invalid_param',
				__( 'Invalid parent type.', 'ai' ),
				array( 'status' => 400 )
			);
		}

		$attachment_before = $valid_check;
		$post              = $this->prepare_item_for_database( $request, $attachment_before );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! empty( $post->post_status ) ) {
			$post_status = $post->post_status;
		} else {
			$post_status = $attachment_before->post_status;
		}

		/*
		 * `wp_unique_post_slug()` returns the same slug for 'draft' or 'pending' posts.
		 *
		 * To ensure that a unique slug is generated, pass the post data with the 'publish' status.
		 */
		if ( ! empty( $post->post_name ) && in_array( $post_status, array( 'draft', 'pending' ), true ) ) {
			$post_parent     = ! empty( $post->post_parent ) ? $post->post_parent : 0;
			$post->post_name = wp_unique_post_slug(
				$post->post_name,
				$post->ID,
				'publish',
				$post->post_type,
				$post_parent
			);
		}

		// Convert the post object to an array, otherwise wp_update_post() will expect non-escaped input.
		$post_id = wp_update_post( wp_slash( (array) $post ), true, false );

		if ( is_wp_error( $post_id ) ) {
			if ( 'db_update_error' === $post_id->get_error_code() ) {
				$post_id->add_data( array( 'status' => 500 ) );
			} else {
				$post_id->add_data( array( 'status' => 400 ) );
			}
			return $post_id;
		}

		if ( isset( $request['alt_text'] ) && is_string( $request['alt_text'] ) ) {
			update_post_meta( $post_id, '_wp_attachment_image_alt', sanitize_text_field( $request['alt_text'] ) );
		}

		$attachment = $this->get_post( $post_id );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		wp_after_insert_post( $attachment, true, $attachment_before );

		return $this->prepare_item_for_response( $attachment, $request );
	}

	/**
	 * Deletes a single attachment.
	 *
	 * The delete capability is checked once more right before anything is removed. Without
	 * `force` the attachment is moved to the trash and returned; with `force` it is deleted
	 * permanently, with its files, and returned under `previous`.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $request The request parameters.
	 * @return array<string, mixed>|\WP_Error The trashed attachment, a `deleted`/`previous` pair, or an error.
	 */
	private function delete_item( array $request ) {
		$post = $this->get_requested_post( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$id    = $post->ID;
		$force = isset( $request['force'] ) && rest_is_boolean( $request['force'] ) && rest_sanitize_boolean( (string) $request['force'] );

		$supports_trash = ( EMPTY_TRASH_DAYS > 0 );

		if ( 'attachment' === $post->post_type ) {
			$supports_trash = $supports_trash && constant( 'MEDIA_TRASH' ); // Read with constant(): the WordPress stubs do not define MEDIA_TRASH.
		}

		if ( ! $this->check_delete_permission( $post ) ) {
			return new WP_Error(
				'media_cannot_delete',
				__( 'Sorry, you are not allowed to delete this post.', 'ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// If we're forcing, then delete permanently.
		if ( $force ) {
			$previous = $this->prepare_item_for_response( $post, $request );
			$result   = wp_delete_post( $id, true );
			$response = array(
				'deleted'  => true,
				'previous' => $previous,
			);
		} else {
			// If we don't support trashing for this type, error out.
			if ( ! $supports_trash ) {
				return new WP_Error(
					'media_trash_not_supported',
					__( 'The post does not support trashing. Set `force` to true to delete it permanently.', 'ai' ),
					array( 'status' => 501 )
				);
			}

			// Otherwise, only trash if we haven't already.
			if ( 'trash' === $post->post_status ) {
				return new WP_Error(
					'media_already_trashed',
					__( 'The post has already been deleted.', 'ai' ),
					array( 'status' => 410 )
				);
			}

			/*
			 * (Note that internally this falls through to `wp_delete_post()`
			 * if the Trash is disabled.)
			 */
			$result   = wp_trash_post( $id );
			$post     = get_post( $id );
			$response = $post instanceof WP_Post ? $this->prepare_item_for_response( $post, $request ) : null;
		}

		if ( ! $result || null === $response ) {
			return new WP_Error(
				'media_cannot_delete',
				__( 'The post cannot be deleted.', 'ai' ),
				array( 'status' => 500 )
			);
		}

		return $response;
	}

	/**
	 * Checks if a post can be deleted.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool Whether the post can be deleted.
	 */
	private function check_delete_permission( $post ): bool {
		$post_type = get_post_type_object( $post->post_type );

		if ( ! $this->check_is_post_type_allowed( $post_type ) ) {
			return false;
		}

		return current_user_can( 'delete_post', $post->ID );
	}

	/**
	 * Prepares a single attachment for create or update.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed>  $request       The request parameters.
	 * @param \WP_Post|null $existing_post The attachment being updated, or null when creating.
	 * @return \stdClass|\WP_Error Post object or WP_Error.
	 */
	private function prepare_item_for_database( array $request, ?WP_Post $existing_post ) {
		$prepared_post  = new stdClass();
		$current_status = '';

		// Post ID.
		if ( $existing_post instanceof WP_Post ) {
			$prepared_post->ID = $existing_post->ID;
			$current_status    = $existing_post->post_status;
		}

		// Post title. An empty `raw` title is ignored, unlike an empty title string.
		$title = $this->get_text_input( $request, 'title', false );
		if ( null !== $title ) {
			$prepared_post->post_title = $title;
		}

		// Post type.
		$prepared_post->post_type = 'attachment';

		/** @var \WP_Post_Type $post_type Built-in post types cannot be unregistered. */
		$post_type = get_post_type_object( $prepared_post->post_type );

		// Post status.
		if ( isset( $request['status'] ) && is_string( $request['status'] ) && ( ! $current_status || $current_status !== $request['status'] ) ) {
			$status = $this->handle_status_param( $request['status'], $post_type );

			if ( is_wp_error( $status ) ) {
				return $status;
			}

			$prepared_post->post_status = $status;
		}

		// Post date.
		if ( ! empty( $request['date'] ) && is_string( $request['date'] ) ) {
			$current_date = $existing_post instanceof WP_Post ? $existing_post->post_date : false;
			$date_data    = rest_get_date_with_gmt( $request['date'] );

			if ( ! empty( $date_data ) && $current_date !== $date_data[0] ) {
				[ $prepared_post->post_date, $prepared_post->post_date_gmt ] = $date_data;
				$prepared_post->edit_date                                    = true;
			}
		} elseif ( ! empty( $request['date_gmt'] ) && is_string( $request['date_gmt'] ) ) {
			$current_date = $existing_post instanceof WP_Post ? $existing_post->post_date_gmt : false;
			$date_data    = rest_get_date_with_gmt( $request['date_gmt'], true );

			if ( ! empty( $date_data ) && $current_date !== $date_data[1] ) {
				[ $prepared_post->post_date, $prepared_post->post_date_gmt ] = $date_data;
				$prepared_post->edit_date                                    = true;
			}
		}

		/*
		 * Sending a null date or date_gmt value resets date and date_gmt to their
		 * default values (`0000-00-00 00:00:00`).
		 */
		if (
			( array_key_exists( 'date_gmt', $request ) && null === $request['date_gmt'] ) ||
			( array_key_exists( 'date', $request ) && null === $request['date'] )
		) {
			$prepared_post->post_date_gmt = null;
			$prepared_post->post_date     = null;
		}

		// Post slug, sanitized like a title.
		if ( isset( $request['slug'] ) && is_string( $request['slug'] ) ) {
			$prepared_post->post_name = sanitize_title( $request['slug'] );
		}

		// Author.
		if ( ! empty( $request['author'] ) ) {
			$post_author = (int) $request['author'];

			if ( get_current_user_id() !== $post_author ) {
				$user_obj = get_userdata( $post_author );

				if ( ! $user_obj ) {
					return new WP_Error(
						'media_invalid_author',
						__( 'Invalid author ID.', 'ai' ),
						array( 'status' => 400 )
					);
				}
			}

			$prepared_post->post_author = $post_author;
		}

		/*
		 * Force template to null: wp_update_post() merges in the stored template, which
		 * wp_insert_post() rejects when the theme no longer offers it.
		 */
		$prepared_post->page_template = null;

		// Attachment caption (post_excerpt internally).
		$caption = $this->get_text_input( $request, 'caption', true );
		if ( null !== $caption ) {
			$prepared_post->post_excerpt = $caption;
		}

		// Attachment description (post_content internally).
		$description = $this->get_text_input( $request, 'description', true );
		if ( null !== $description ) {
			$prepared_post->post_content = $description;
		}

		if ( isset( $request['post'] ) ) {
			$prepared_post->post_parent = (int) $request['post'];
		}

		return $prepared_post;
	}

	/**
	 * Reads a text input given either as a string or as an object with a `raw` key.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $request         The request parameters.
	 * @param string       $key             The input key holding the text.
	 * @param bool         $allow_empty_raw Whether an empty `raw` value counts as provided.
	 * @return string|null The text, or null when the input does not provide it.
	 */
	private function get_text_input( array $request, string $key, bool $allow_empty_raw ): ?string {
		$value = $request[ $key ] ?? null;

		if ( is_string( $value ) ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		if ( ! is_array( $value ) || ! isset( $value['raw'] ) || ! is_string( $value['raw'] ) ) {
			return null;
		}

		if ( ! $allow_empty_raw && empty( $value['raw'] ) ) {
			return null;
		}

		return $value['raw'];
	}

	/**
	 * Determines validity and normalizes the given status parameter.
	 *
	 * @since x.x.x
	 *
	 * @param string        $post_status Post status.
	 * @param \WP_Post_Type $post_type   Post type.
	 * @return string|\WP_Error Post status or WP_Error if lacking the proper permission.
	 */
	private function handle_status_param( string $post_status, \WP_Post_Type $post_type ) {

		switch ( $post_status ) {
			case 'draft':
			case 'pending':
				break;
			case 'private':
				if ( ! current_user_can( $post_type->cap->publish_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
					return new WP_Error(
						'media_cannot_publish',
						__( 'Sorry, you are not allowed to create private posts in this post type.', 'ai' ),
						array( 'status' => rest_authorization_required_code() )
					);
				}
				break;
			case 'publish':
			case 'future':
				if ( ! current_user_can( $post_type->cap->publish_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
					return new WP_Error(
						'media_cannot_publish',
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
	 * Returns the input properties shared by the write abilities, keyed by field name.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, array<string, mixed>> Write property definitions.
	 */
	private function get_write_properties(): array {
		// A text field is a string, or an object whose `raw` key holds the text.
		$text_field = static function ( string $description ): array {
			return array(
				'type'        => array( 'string', 'object' ),
				'properties'  => array( 'raw' => array( 'type' => 'string' ) ),
				'required'    => array( 'raw' ),
				'description' => $description,
			);
		};

		return array(
			'title'       => $text_field( __( 'The title for the attachment, as a string or as an object with a `raw` key.', 'ai' ) ),
			'caption'     => $text_field( __( 'The attachment caption, as a string or as an object with a `raw` key.', 'ai' ) ),
			'description' => $text_field( __( 'The attachment description, as a string or as an object with a `raw` key.', 'ai' ) ),
			'alt_text'    => array(
				'type'        => 'string',
				'description' => __( 'Alternative text to display when attachment is not displayed.', 'ai' ),
			),
			'post'        => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'The ID for the associated post of the attachment, or 0 to detach it. Revisions and attachments cannot be parents.', 'ai' ),
			),
			'author'      => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'The ID for the author of the attachment; 0 is ignored. Assigning another user requires the capability to edit their posts.', 'ai' ),
			),
			'status'      => array(
				'type'        => 'string',
				'enum'        => array_keys( get_post_stati( array( 'internal' => false ) ) ),
				'description' => __( 'A named status for the attachment. Attachments are stored as `inherit` unless the status is `private`. Asking for private, publish, or future requires the capability to publish posts.', 'ai' ),
			),
			'slug'        => array(
				'type'        => 'string',
				'description' => __( 'An alphanumeric identifier for the attachment. Sanitized like a title, and adjusted when another post has it.', 'ai' ),
			),
			'date'        => array(
				'type'        => array( 'string', 'null' ),
				'format'      => 'date-time',
				'description' => __( 'The date the attachment was published, in ISO 8601 format with a timezone offset. Pass null to date it now.', 'ai' ),
			),
			'date_gmt'    => array(
				'type'        => array( 'string', 'null' ),
				'format'      => 'date-time',
				'description' => __( 'The date the attachment was published, as GMT ending in `Z`. Pass null to date it now.', 'ai' ),
			),
		);
	}

	/**
	 * Builds the input schema for the `core/media-upload` ability.
	 *
	 * The file comes either as base64 data with a file name, or as a URL to download an
	 * image from. The two modes are modeled as a `oneOf`, and neither accepts the other's
	 * properties, so a request matches one. A URL upload takes no attachment fields other
	 * than the parent post, because the downloaded image provides them.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_upload_input_schema(): array {
		$properties = $this->get_write_properties();

		$properties['post']['description'] = __( 'The ID of the post to attach the attachment to. Requires the capability to edit that post; revisions and attachments cannot be parents.', 'ai' );

		return array(
			'type'  => 'object',
			'oneOf' => array(
				array(
					'title'                => __( 'Upload a file from base64 data', 'ai' ),
					'required'             => array( 'data', 'filename' ),
					'additionalProperties' => false,
					'properties'           => array_merge(
						array(
							'data'     => array(
								'type'        => 'string',
								'description' => __( 'The file contents, base64 encoded.', 'ai' ),
							),
							'filename' => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => __( 'The name to store the file under, sanitized and made unique. Its extension must be a file type the site allows.', 'ai' ),
							),
						),
						$properties,
						array( 'fields' => $this->get_write_fields_schema() )
					),
				),
				array(
					'title'                => __( 'Download an image from a URL', 'ai' ),
					'required'             => array( 'url' ),
					'additionalProperties' => false,
					'properties'           => array(
						'url'    => array(
							'type'        => 'string',
							'description' => __( 'The HTTP or HTTPS URL of a publicly reachable image to download. The file name and image type come from the URL path.', 'ai' ),
						),
						'post'   => $properties['post'],
						'fields' => $this->get_write_fields_schema(),
					),
				),
			),
		);
	}

	/**
	 * Builds the input schema for the `core/media-update` ability.
	 *
	 * The status is not restricted by an enum: an attachment may keep its current status,
	 * such as `inherit`, so the status is validated against the attachment during execution.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_update_input_schema(): array {
		$properties = $this->get_write_properties();

		$properties['status'] = array(
			'type'        => 'string',
			'description' => sprintf(
				/* translators: %s: Comma-separated list of post statuses. */
				__( 'A named status for the attachment: one of %s, or its current status. Attachments are stored as `inherit` unless the status is `private`. Asking for private, publish, or future requires the capability to publish posts.', 'ai' ),
				implode( ', ', array_keys( get_post_stati( array( 'internal' => false ) ) ) )
			),
		);

		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'additionalProperties' => false,
			'properties'           => array_merge(
				array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Unique identifier for the attachment.', 'ai' ),
					),
				),
				$properties,
				array( 'fields' => $this->get_write_fields_schema() )
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
	private function get_write_fields_schema(): array {
		return array(
			'type'        => 'array',
			'uniqueItems' => true,
			'items'       => array(
				'type' => 'string',
				'enum' => array_keys( $this->get_item_schema() ),
			),
			'description' => __( 'Limit the returned attachment to these fields. If omitted, a lean set of common fields is returned. The ID is always included.', 'ai' ),
		);
	}

	/**
	 * Builds the output schema of a written attachment.
	 *
	 * A written attachment is returned in the edit context, so its raw fields do not depend
	 * on edit access.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The attachment JSON Schema.
	 */
	private function get_write_output_schema(): array {
		$properties = $this->get_item_schema();

		$properties['title_raw']['description']       = __( 'Title for the attachment, as it exists in the database.', 'ai' );
		$properties['description_raw']['description'] = __( 'Description for the attachment, as it exists in the database.', 'ai' );
		$properties['caption_raw']['description']     = __( 'Caption for the attachment, as it exists in the database.', 'ai' );

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
		);
	}

	/**
	 * Builds the input schema for the `core/media-delete` ability.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	private function get_delete_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'additionalProperties' => false,
			'properties'           => array(
				'id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Unique identifier for the attachment.', 'ai' ),
				),
				'force'  => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to bypass the trash and delete the attachment and its files permanently. Defaults to false, which moves the attachment to the trash; that is an error unless the site enables the media trash.', 'ai' ),
				),
				'fields' => $this->get_write_fields_schema(),
			),
		);
	}

	/**
	 * Builds the output schema for the `core/media-delete` ability.
	 *
	 * Trashing returns the trashed attachment directly; a forced deletion returns a `deleted`
	 * flag with the deleted attachment under `previous`.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	private function get_delete_output_schema(): array {
		$item_schema = $this->get_write_output_schema();

		return array(
			'type'  => 'object',
			'oneOf' => array(
				$item_schema,
				array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'deleted', 'previous' ),
					'properties'           => array(
						'deleted'  => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the attachment was permanently deleted.', 'ai' ),
						),
						'previous' => $item_schema,
					),
				),
			),
		);
	}
}

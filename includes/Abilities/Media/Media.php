<?php
/**
 * The `core/media-query` WordPress Ability.
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
	 * Registers the read-only `core/media-query` ability.
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
			// A callback that throws would otherwise leave these filters on for the rest of the request.
			remove_filter( 'protected_title_format', array( $this, 'protected_title_format' ) );
			remove_filter( 'private_title_format', array( $this, 'protected_title_format' ) );
			remove_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );

			if ( $previous_post instanceof WP_Post ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous global post.
				$GLOBALS['post'] = $previous_post;
				setup_postdata( $previous_post );
			} else {
				wp_reset_postdata();
				// Assigned rather than unset, so a `global $post` bound before the call is reset too.
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous global post.
				$GLOBALS['post'] = $previous_post;
			}
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
				'description' => __( 'Current page of the collection. Defaults to 1. Requesting a page beyond the last one is an error, unless nothing matches.', 'ai' ),
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
}

<?php
/**
 * Canonical WordPress abilities: shared base for the media write abilities.
 *
 * Ported from WordPress/ai PR #1104 (https://github.com/WordPress/ai/pull/1104) by @jorgefilipecosta.
 *
 * The helpers shared with this package's `wp/media-query` port (attachment lookup,
 * permission checks, field projection, the response date formatting, and the file-size /
 * file-name accessors) are extracted verbatim from Media_Query.php with `private`
 * relaxed to `protected` so the write ability subclasses reuse them; the global-post
 * restoration the upstream PR refactored into a helper is ported as written. The write
 * logic itself (upload from base64 data or a URL sideload, update, delete) is a faithful
 * port of the PR: upstream names the abilities `core/media-*`; this package ships them
 * under the experimental tier's `wp/` prefix (see NAME-LIFECYCLE.md), with the `default`
 * text domain and registration owned by the package's Registrar.
 *
 * @package WordPress\Abilities
 */
declare( strict_types = 1 );

namespace WordPress\Abilities\Media;

use stdClass;
use WP_Error;
use WP_Post;
use WP_Post_Type;

use WordPress\Abilities\Abstract_Core_Ability;

/**
 * Shared base for the `wp/media-upload`, `wp/media-update`, and `wp/media-delete` abilities.
 *
 * A written attachment is answered through the same field projection as `wp/media-query`, in
 * the edit context: the returned item's raw fields do not depend on edit access, because the
 * abilities' permission callbacks already require it.
 *
 * @package WordPress\Abilities
 */
abstract class Abstract_Media_Write extends Abstract_Core_Ability {

	/**
	 * The ability category used for media abilities.
	 *
	 * @var string
	 */
	protected const CATEGORY = 'media';

	/**
	 * Default fields returned when the caller does not request a field subset.
	 *
	 * @var list<string>
	 */
	protected const DEFAULT_FIELDS = array(
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
	 * No request-level capability floor beyond the permission callbacks' checks.
	 *
	 * The source PR registers these abilities without `required_capabilities`; the permission
	 * callbacks own authorization, as the base class documents.
	 *
	 * @return list<string> The empty capability floor.
	 */
	protected function required_capabilities(): array {
		return array();
	}

	/**
	 * Restores the global post and removes the filters added while preparing items.
	 *
	 * Runs in a `finally` block: a callback that throws would otherwise leave them changed
	 * for the rest of the request.
	 *
	 * @param mixed $previous_post The global post before the ability ran.
	 */
	protected function restore_request_state( $previous_post ): void {
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

	// -- The helpers below are extracted verbatim from this package's Media_Query
	// -- port of WordPress/ai PR #1098, with `private` relaxed to `protected` so
	// -- the write abilities reuse them. They are the field projection that lets
	// -- a written attachment be read back unchanged through `wp/media-query`'s
	// -- contract.

	/**
	 * Parses a list input.
	 *
	 * GET requests deliver lists as CSV strings; POST requests deliver arrays.
	 * Anything else is treated as an empty list.
	 *
	 * @param mixed $value The raw `fields` input.
	 * @return list<string> The parsed field list.
	 */
	protected function parse_list( $value ): array {
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
	 * @param array<string, mixed> $request The request parameters.
	 * @return list<string> The field names.
	 */
	protected function get_fields_for_response( array $request ): array {
		$fields = is_array( $request['fields'] ) && array() !== $request['fields'] ? $request['fields'] : self::DEFAULT_FIELDS;

		// Always persist 'id'.
		$fields[] = 'id';

		return array_values( array_filter( $fields, 'is_string' ) );
	}

	/**
	 * Gets an attachment by ID, validating attachments and the ID bound.
	 *
	 * @param mixed $id The requested ID.
	 * @return \WP_Post|\WP_Error Attachment object if the ID is valid, WP_Error otherwise.
	 */
	protected function get_post( $id ) {
		$error = new WP_Error(
			'media_post_invalid_id',
			__( 'Invalid post ID.' ),
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
	 * Checks if a given post type can be shown.
	 *
	 * @param string|\WP_Post_Type $post_type Post type.
	 * @return bool Whether the post type can be shown.
	 */
	protected function check_is_post_type_allowed( $post_type ): bool {
		if ( is_string( $post_type ) ) {
			$post_type = get_post_type_object( $post_type );
		}

		return ! empty( $post_type ) && ! empty( $post_type->show_in_rest );
	}

	/**
	 * Checks if a post can be edited.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool Whether the post can be edited.
	 */
	protected function check_update_permission( $post ): bool {
		$post_type = get_post_type_object( $post->post_type );

		if ( ! $this->check_is_post_type_allowed( $post_type ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Converts a valid post date (or date GMT) to the form used for the response.
	 *
	 * @param string $date_gmt GMT for the post.
	 * @param string $date     The date for the post.
	 * @return string|null The prepared date, or null when the date should be reset.
	 */
	protected function prepare_date_response( $date_gmt, $date = null ) {
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
	 * Gets the attachment's original file name.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null Attachment file name, or null if not available.
	 */
	protected function get_attachment_filename( int $attachment_id ): ?string {
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
	 * @param int $attachment_id Attachment ID.
	 * @return int|null Attachment file size in bytes, or null if not available.
	 */
	protected function get_attachment_filesize( int $attachment_id ): ?int {
		$meta = wp_get_attachment_metadata( $attachment_id );

		// @phpstan-ignore booleanAnd.rightAlwaysTrue (stored filesize metadata can be any scalar, e.g. a numeric string; core's docblock shape is narrower than reality.)
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
	 * Overwrites the default protected and private title format.
	 *
	 * By default, WordPress will show password protected or private posts with a title of
	 * "Protected: %s" or "Private: %s". The status is reported in a machine-readable
	 * format, so the prefix is removed.
	 *
	 * @return string Title format.
	 */
	public function protected_title_format(): string {
		return '%s';
	}

	/**
	 * The attachment item field schema, shared by `wp/media-query` and the write
	 * abilities (the write output uses it with edit-context descriptions).
	 *
	 * @return array<string, array<string, mixed>> Field definitions keyed by field name.
	 */
	protected function get_item_schema(): array {
		return array(
			'id'                   => array(
				'type'        => 'integer',
				'description' => __( 'Unique identifier for the attachment.' ),
			),
			'date'                 => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( "The date the attachment was published, in the site's timezone." ),
			),
			'date_gmt'             => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'The date the attachment was published, as GMT.' ),
			),
			'modified'             => array(
				'type'        => 'string',
				'description' => __( "The date the attachment was last modified, in the site's timezone." ),
			),
			'modified_gmt'         => array(
				'type'        => 'string',
				'description' => __( 'The date the attachment was last modified, as GMT.' ),
			),
			'slug'                 => array(
				'type'        => 'string',
				'description' => __( 'An alphanumeric identifier for the attachment.' ),
			),
			'status'               => array(
				'type'        => 'string',
				'description' => __( 'A named status for the attachment.' ),
			),
			'link'                 => array(
				'type'        => 'string',
				'description' => __( 'URL to the attachment.' ),
			),
			'title_raw'            => array(
				'type'        => 'string',
				'description' => __( 'Title for the attachment, as it exists in the database. Requires edit access.' ),
			),
			'title_rendered'       => array(
				'type'        => 'string',
				'description' => __( 'HTML title for the attachment, transformed for display.' ),
			),
			'author'               => array(
				'type'        => 'integer',
				'description' => __( 'The ID for the author of the attachment.' ),
			),
			'description_raw'      => array(
				'type'        => 'string',
				'description' => __( 'Description for the attachment, as it exists in the database. Requires edit access.' ),
			),
			'description_rendered' => array(
				'type'        => 'string',
				'description' => __( 'HTML description for the attachment, transformed for display.' ),
			),
			'caption_raw'          => array(
				'type'        => 'string',
				'description' => __( 'Caption for the attachment, as it exists in the database. Requires edit access.' ),
			),
			'caption_rendered'     => array(
				'type'        => 'string',
				'description' => __( 'HTML caption for the attachment, transformed for display.' ),
			),
			'alt_text'             => array(
				'type'        => 'string',
				'description' => __( 'Alternative text to display when attachment is not displayed.' ),
			),
			'media_type'           => array(
				'type'        => 'string',
				'enum'        => array( 'image', 'file' ),
				'description' => __( 'Attachment type.' ),
			),
			'mime_type'            => array(
				'type'        => 'string',
				'description' => __( 'The attachment MIME type.' ),
			),
			'media_details'        => array(
				'type'        => 'object',
				'description' => __( 'Details about the media file, specific to its type, such as image dimensions and sizes.' ),
			),
			'post'                 => array(
				'type'        => array( 'integer', 'null' ),
				'description' => __( 'The ID for the associated post of the attachment. Null when the attachment is not attached to a post.' ),
			),
			'source_url'           => array(
				'type'        => 'string',
				'description' => __( 'URL to the original attachment file.' ),
			),
			'filename'             => array(
				'type'        => array( 'string', 'null' ),
				'description' => __( 'Original attachment file name.' ),
			),
			'filesize'             => array(
				'type'        => array( 'integer', 'null' ),
				'description' => __( 'Attachment file size in bytes.' ),
			),
		);
	}

	/**
	 * Prepares a single attachment for the response.
	 *
	 * Includes only the requested fields; `id` is always included. A written
	 * attachment requests fields in the edit context, so raw fields do not depend
	 * on edit access.
	 *
	 * @param \WP_Post             $item    Attachment object.
	 * @param array<string, mixed> $request The request parameters.
	 * @return array<string, mixed> The attachment data.
	 */
	protected function prepare_item_for_response( $item, array $request ): array {
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
					// @phpstan-ignore isset.offset (attachments can carry a size entry without a mime-type, despite core's docblock shape.)
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
	 * Permission callback for the `wp/media-upload` ability.
	 *
	 * Requires an authenticated user who can create attachments and upload files. The author
	 * and the parent post are checked during execution, where the error can name the refused
	 * field.
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
	 * Executes the `wp/media-upload` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::create_item_permissions_check()}
	 * first. The global post, which preparing the response replaces, is restored afterwards.
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
	 * Permission callback for the `wp/media-update` ability.
	 *
	 * Requires an authenticated user who can edit the attachment; a missing attachment is
	 * denied like one the user cannot edit. The rest of the input is checked during
	 * execution, where the error can name the refused field.
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
	 * Executes the `wp/media-update` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::update_item_permissions_check()}
	 * first. The global post, which preparing the response replaces, is restored afterwards.
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
	 * Permission callback for the `wp/media-delete` ability.
	 *
	 * Requires an authenticated user who can delete the attachment; a missing attachment is
	 * denied like one the user cannot delete.
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
	 * Executes the `wp/media-delete` ability.
	 *
	 * {@see WP_Ability::execute()} always runs {@see self::delete_item_permissions_check()}
	 * first. The global post, which preparing the response replaces, is restored afterwards.
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
	 * @param array<mixed> $request The request parameters.
	 * @return \WP_Post|\WP_Error Attachment object if the ID is valid, WP_Error otherwise.
	 */
	protected function get_requested_post( array $request ) {
		return $this->get_post( isset( $request['id'] ) && is_scalar( $request['id'] ) ? $request['id'] : 0 );
	}

	/**
	 * Creates a single attachment.
	 *
	 * The URL, the author, and the parent post are checked first, in this callback rather than
	 * in the permission callback, because the Abilities API replaces any error a permission
	 * callback returns with a generic one.
	 *
	 * @param array<mixed> $request The request parameters.
	 * @return array<string, mixed>|\WP_Error The attachment data, or an error.
	 */
	protected function create_item( array $request ) {
		/*
		 * Reject URLs that are not safe to request server-side. wp_http_validate_url()
		 * enforces an HTTP(S) scheme and blocks private, local, and otherwise
		 * disallowed hosts, guarding the sideload against SSRF.
		 */
		if ( isset( $request['url'] ) && false === wp_http_validate_url( (string) $request['url'] ) ) {
			return new WP_Error(
				'media_invalid_param',
				__( 'Invalid URL. Provide a valid, publicly reachable HTTP or HTTPS image URL.' ),
				array( 'status' => 400 )
			);
		}

		/** @var \WP_Post_Type $post_type Built-in post types cannot be unregistered. */
		$post_type = get_post_type_object( 'attachment' );

		if ( ! empty( $request['author'] ) && get_current_user_id() !== (int) $request['author'] && ! current_user_can( $post_type->cap->edit_others_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
			return new WP_Error(
				'media_cannot_edit_others',
				__( 'Sorry, you are not allowed to create posts as this user.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Attaching media to a post requires ability to edit said post.
		if ( ! empty( $request['post'] ) && ! current_user_can( 'edit_post', (int) $request['post'] ) ) {
			return new WP_Error(
				'media_cannot_edit',
				__( 'Sorry, you are not allowed to upload media to this post.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! empty( $request['post'] ) && in_array( get_post_type( (int) $request['post'] ), array( 'revision', 'attachment' ), true ) ) {
			return new WP_Error(
				'media_invalid_param',
				__( 'Invalid parent type.' ),
				array( 'status' => 400 )
			);
		}

		// When a URL is supplied instead of file data, sideload the remote image on the server.
		if ( ! empty( $request['url'] ) ) {
			return $this->create_item_from_url( $request );
		}

		// Check the attachment fields before the file is stored, so a refused upload stores nothing.
		$prepared = $this->prepare_item_for_database( $request, null );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
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

		/** @var \WP_Post $attachment The attachment was just inserted. */
		$attachment = get_post( $attachment_id );

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
	 * @param array<mixed> $request The request parameters.
	 * @return array<string, mixed>|\WP_Error The attachment data, or an error.
	 */
	protected function create_item_from_url( array $request ) {
		// Sideloading downloads and stores a file, so require the upload capability.
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'media_cannot_create',
				__( 'Sorry, you are not allowed to upload media on this site.' ),
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
				__( 'Could not determine a filename from the provided URL.' ),
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
				__( 'The provided URL does not point to a supported image file.' ),
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
		 * `limit_response_size` stops writing the response once the limit is
		 * passed, so an oversized remote file is never written to disk in full.
		 * One byte over the ceiling is enough to fail the size check below.
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
				sprintf( __( 'This file is too big. Files must be less than %s KB in size.' ), number_format( $max_size / KB_IN_BYTES ) ),
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

		/** @var \WP_Post $attachment The attachment was just inserted. */
		$attachment = get_post( $attachment_id );

		return $this->prepare_item_for_response( $attachment, $request );
	}

	/**
	 * Inserts the attachment post in the database. Does not update the attachment meta.
	 *
	 * @param array<mixed> $request The request parameters.
	 * @return array{attachment_id: int, file: string}|\WP_Error The attachment ID and file path, or an error.
	 */
	protected function insert_attachment( array $request ) {
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
				__( 'The data is not a valid base64 encoded string.' ),
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

		// The image meta is filterable, so its values are read as strings.
		if ( ! empty( $image_meta ) ) {
			if ( empty( $request['title'] ) && trim( (string) $image_meta['title'] ) && ! is_numeric( sanitize_title( (string) $image_meta['title'] ) ) ) {
				$request['title'] = $image_meta['title'];
			}

			if ( empty( $request['caption'] ) && trim( (string) $image_meta['caption'] ) ) {
				$request['caption'] = $image_meta['caption'];
			}

			if ( empty( $request['alt'] ) && trim( (string) $image_meta['alt'] ) ) {
				$alt = (string) $image_meta['alt'];
			}
		}

		$attachment = $this->prepare_item_for_database( $request, null );

		if ( is_wp_error( $attachment ) ) {
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
	 * @param string      $data     Supplied file data.
	 * @param string      $filename The file name.
	 * @param string|null $time     Optional. Time formatted in 'yyyy/mm'. Default null.
	 * @return array<string, mixed>|\WP_Error Data from wp_handle_sideload(): the `file` path, its `url`, and its MIME `type`.
	 */
	protected function upload_from_data( string $data, string $filename, ?string $time = null ) {
		if ( empty( $data ) ) {
			return new WP_Error(
				'media_upload_no_data',
				__( 'No data supplied.' ),
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
				__( 'Could not open file handle.' ),
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
	 * @param array<string, mixed> $file The `$_FILES`-style array for a given file.
	 * @return true|\WP_Error True if can upload, error for errors.
	 */
	protected function check_upload_size( array $file ) {
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
				sprintf( __( 'Not enough space to upload. %s KB needed.' ), number_format( ( $file_size - $space_left ) / KB_IN_BYTES ) ),
				array( 'status' => 400 )
			);
		}

		if ( $file_size > KB_IN_BYTES * get_site_option( 'fileupload_maxk', 1500 ) ) {
			return new WP_Error(
				'media_upload_file_too_big',
				/* translators: %s: Maximum allowed file size in kilobytes. */
				sprintf( __( 'This file is too big. Files must be less than %s KB in size.' ), get_site_option( 'fileupload_maxk', 1500 ) ),
				array( 'status' => 400 )
			);
		}

		// Include multisite admin functions to get access to upload_is_user_over_quota().
		require_once ABSPATH . 'wp-admin/includes/ms.php';

		if ( upload_is_user_over_quota( false ) ) {
			return new WP_Error(
				'media_upload_user_quota_exceeded',
				__( 'You have used your space quota. Please delete files before uploading.' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Updates a single attachment.
	 *
	 * The status, the author, and the parent are checked first, in this callback rather than
	 * in the permission callback, because the Abilities API replaces any error a permission
	 * callback returns with a generic one.
	 *
	 * @param array<mixed> $request The request parameters.
	 * @return array<string, mixed>|\WP_Error The attachment data, or an error.
	 */
	protected function update_item( array $request ) {
		$valid_check = $this->get_requested_post( $request );
		if ( is_wp_error( $valid_check ) ) {
			return $valid_check;
		}

		// Keeping the current status is valid, even an internal one such as `inherit`.
		if ( isset( $request['status'] ) && $valid_check->post_status !== $request['status'] ) {
			$status_check = rest_validate_value_from_schema(
				$request['status'],
				array(
					'type' => 'string',
					'enum' => array_keys( get_post_stati( array( 'internal' => false ) ) ),
				),
				'status'
			);

			if ( is_wp_error( $status_check ) ) {
				return new WP_Error( 'media_invalid_param', $status_check->get_error_message(), array( 'status' => 400 ) );
			}
		}

		/** @var \WP_Post_Type $post_type Built-in post types cannot be unregistered. */
		$post_type = get_post_type_object( 'attachment' );

		if ( ! empty( $request['author'] ) && get_current_user_id() !== (int) $request['author'] && ! current_user_can( $post_type->cap->edit_others_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
			return new WP_Error(
				'media_cannot_edit_others',
				__( 'Sorry, you are not allowed to update posts as this user.' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! empty( $request['post'] ) && in_array( get_post_type( (int) $request['post'] ), array( 'revision', 'attachment' ), true ) ) {
			return new WP_Error(
				'media_invalid_param',
				__( 'Invalid parent type.' ),
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

		/** @var \WP_Post $attachment The attachment was just updated. */
		$attachment = get_post( $post_id );

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
	 * @param array<mixed> $request The request parameters.
	 * @return array<string, mixed>|\WP_Error The trashed attachment, a `deleted`/`previous` pair, or an error.
	 */
	protected function delete_item( array $request ) {
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
				'media_user_cannot_delete_post',
				__( 'Sorry, you are not allowed to delete this post.' ),
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
					__( 'The post does not support trashing. Set `force` to true to delete it permanently.' ),
					array( 'status' => 501 )
				);
			}

			// Otherwise, only trash if we haven't already.
			if ( 'trash' === $post->post_status ) {
				return new WP_Error(
					'media_already_trashed',
					__( 'The post has already been deleted.' ),
					array( 'status' => 410 )
				);
			}

			/*
			 * (Note that internally this falls through to `wp_delete_post()`
			 * if the Trash is disabled.)
			 */
			$result = wp_trash_post( $id );

			/** @var \WP_Post $post A trashed attachment still exists. */
			$post     = get_post( $id );
			$response = $this->prepare_item_for_response( $post, $request );
		}

		if ( ! $result ) {
			return new WP_Error(
				'media_cannot_delete',
				__( 'The post cannot be deleted.' ),
				array( 'status' => 500 )
			);
		}

		return $response;
	}

	/**
	 * Checks if a post can be deleted.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool Whether the post can be deleted.
	 */
	protected function check_delete_permission( $post ): bool {
		$post_type = get_post_type_object( $post->post_type );

		if ( ! $this->check_is_post_type_allowed( $post_type ) ) {
			return false;
		}

		return current_user_can( 'delete_post', $post->ID );
	}

	/**
	 * Prepares a single attachment for create or update.
	 *
	 * @param array<mixed>  $request       The request parameters.
	 * @param \WP_Post|null $existing_post The attachment being updated, or null when creating.
	 * @return \stdClass|\WP_Error Post object or WP_Error.
	 */
	protected function prepare_item_for_database( array $request, ?WP_Post $existing_post ) {
		$prepared_post  = new stdClass();
		$current_status = '';

		// Post ID.
		if ( $existing_post instanceof WP_Post ) {
			$prepared_post->ID = $existing_post->ID;
			$current_status    = $existing_post->post_status;
		}

		// Post title.
		if ( isset( $request['title'] ) ) {
			if ( is_string( $request['title'] ) ) {
				$prepared_post->post_title = $request['title'];
			} elseif ( ! empty( $request['title']['raw'] ) ) {
				$prepared_post->post_title = $request['title']['raw'];
			}
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
						__( 'Invalid author ID.' ),
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
		if ( isset( $request['caption'] ) ) {
			if ( is_string( $request['caption'] ) ) {
				$prepared_post->post_excerpt = $request['caption'];
			} elseif ( isset( $request['caption']['raw'] ) ) {
				$prepared_post->post_excerpt = $request['caption']['raw'];
			}
		}

		// Attachment description (post_content internally).
		if ( isset( $request['description'] ) ) {
			if ( is_string( $request['description'] ) ) {
				$prepared_post->post_content = $request['description'];
			} elseif ( isset( $request['description']['raw'] ) ) {
				$prepared_post->post_content = $request['description']['raw'];
			}
		}

		if ( isset( $request['post'] ) ) {
			$prepared_post->post_parent = (int) $request['post'];
		}

		return $prepared_post;
	}

	/**
	 * Determines validity and normalizes the given status parameter.
	 *
	 * @param string        $post_status Post status.
	 * @param \WP_Post_Type $post_type   Post type.
	 * @return string|\WP_Error Post status or WP_Error if lacking the proper permission.
	 */
	protected function handle_status_param( string $post_status, \WP_Post_Type $post_type ) {

		switch ( $post_status ) {
			case 'draft':
			case 'pending':
				break;
			case 'private':
				if ( ! current_user_can( $post_type->cap->publish_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
					return new WP_Error(
						'media_cannot_publish',
						__( 'Sorry, you are not allowed to create private posts in this post type.' ),
						array( 'status' => rest_authorization_required_code() )
					);
				}
				break;
			case 'publish':
			case 'future':
				if ( ! current_user_can( $post_type->cap->publish_posts ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is resolved from the post type's capability object.
					return new WP_Error(
						'media_cannot_publish',
						__( 'Sorry, you are not allowed to publish posts in this post type.' ),
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
	 * @return array<string, array<string, mixed>> Write property definitions.
	 */
	protected function get_write_properties(): array {
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
			'title'       => $text_field( __( 'The title for the attachment, as a string or as an object with a `raw` key.' ) ),
			'caption'     => $text_field( __( 'The attachment caption, as a string or as an object with a `raw` key.' ) ),
			'description' => $text_field( __( 'The attachment description, as a string or as an object with a `raw` key.' ) ),
			'alt_text'    => array(
				'type'        => 'string',
				'description' => __( 'Alternative text to display when attachment is not displayed.' ),
			),
			'post'        => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'The ID for the associated post of the attachment, or 0 to detach it. Revisions and attachments cannot be parents.' ),
			),
			'author'      => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'The ID for the author of the attachment; 0 is ignored. Assigning another user requires the capability to edit their posts.' ),
			),
			'status'      => array(
				'type'        => 'string',
				'enum'        => array_keys( get_post_stati( array( 'internal' => false ) ) ),
				'description' => __( 'A named status for the attachment. Attachments are stored as `inherit` unless the status is `private`. Asking for private, publish, or future requires the capability to publish posts.' ),
			),
			'slug'        => array(
				'type'        => 'string',
				'description' => __( 'An alphanumeric identifier for the attachment. Sanitized like a title, and adjusted when another post has it.' ),
			),
			'date'        => array(
				'type'        => array( 'string', 'null' ),
				'format'      => 'date-time',
				'description' => __( 'The date the attachment was published, in ISO 8601 format with a timezone offset. Pass null to date it now.' ),
			),
			'date_gmt'    => array(
				'type'        => array( 'string', 'null' ),
				'format'      => 'date-time',
				'description' => __( 'The date the attachment was published, as GMT ending in `Z`. Pass null to date it now.' ),
			),
		);
	}

	/**
	 * Builds the input schema for the `wp/media-upload` ability.
	 *
	 * The file comes either as base64 data with a file name, or as a URL to download an
	 * image from. The two modes are modeled as a `oneOf`, and neither accepts the other's
	 * properties, so a request matches one. A URL upload takes no attachment fields other
	 * than the parent post, because the downloaded image provides them.
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function get_upload_input_schema(): array {
		$properties = $this->get_write_properties();

		$properties['post']['description'] = __( 'The ID of the post to attach the attachment to. Requires the capability to edit that post; revisions and attachments cannot be parents.' );

		return array(
			'type'  => 'object',
			'oneOf' => array(
				array(
					'title'                => __( 'Upload a file from base64 data' ),
					'required'             => array( 'data', 'filename' ),
					'additionalProperties' => false,
					'properties'           => array_merge(
						array(
							'data'     => array(
								'type'        => 'string',
								'description' => __( 'The file contents, base64 encoded.' ),
							),
							'filename' => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => __( 'The name to store the file under, sanitized and made unique. Its extension must be a file type the site allows.' ),
							),
						),
						$properties,
						array( 'fields' => $this->get_write_fields_schema() )
					),
				),
				array(
					'title'                => __( 'Download an image from a URL' ),
					'required'             => array( 'url' ),
					'additionalProperties' => false,
					'properties'           => array(
						'url'    => array(
							'type'        => 'string',
							'description' => __( 'The HTTP or HTTPS URL of a publicly reachable image to download. The file name and image type come from the URL path.' ),
						),
						'post'   => $properties['post'],
						'fields' => $this->get_write_fields_schema(),
					),
				),
			),
		);
	}

	/**
	 * Builds the input schema for the `wp/media-update` ability.
	 *
	 * The status is not restricted by an enum: an attachment may keep its current status,
	 * such as `inherit`, so the status is validated against the attachment during execution.
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function get_update_input_schema(): array {
		$properties = $this->get_write_properties();

		$properties['status'] = array(
			'type'        => 'string',
			'description' => sprintf(
				/* translators: %s: Comma-separated list of post statuses. */
				__( 'A named status for the attachment: one of %s, or its current status. Attachments are stored as `inherit` unless the status is `private`. Asking for private, publish, or future requires the capability to publish posts.' ),
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
						'description' => __( 'Unique identifier for the attachment.' ),
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
	 * @return array<string, mixed> The `fields` JSON Schema.
	 */
	protected function get_write_fields_schema(): array {
		return array(
			'type'        => 'array',
			'uniqueItems' => true,
			'items'       => array(
				'type' => 'string',
				'enum' => array_keys( $this->get_item_schema() ),
			),
			'description' => __( 'Limit the returned attachment to these fields. If omitted, a lean set of common fields is returned. The ID is always included.' ),
		);
	}

	/**
	 * Builds the output schema of a written attachment.
	 *
	 * A written attachment is returned in the edit context, so its raw fields do not depend
	 * on edit access.
	 *
	 * @return array<string, mixed> The attachment JSON Schema.
	 */
	protected function get_write_output_schema(): array {
		$properties = $this->get_item_schema();

		$properties['title_raw']['description']       = __( 'Title for the attachment, as it exists in the database.' );
		$properties['description_raw']['description'] = __( 'Description for the attachment, as it exists in the database.' );
		$properties['caption_raw']['description']     = __( 'Caption for the attachment, as it exists in the database.' );

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
		);
	}

	/**
	 * Builds the input schema for the `wp/media-delete` ability.
	 *
	 * @return array<string, mixed> The input JSON Schema.
	 */
	protected function get_delete_input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id' ),
			'additionalProperties' => false,
			'properties'           => array(
				'id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Unique identifier for the attachment.' ),
				),
				'force'  => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to bypass the trash and delete the attachment and its files permanently. Defaults to false, which moves the attachment to the trash; that is an error unless the site enables the media trash.' ),
				),
				'fields' => $this->get_write_fields_schema(),
			),
		);
	}

	/**
	 * Builds the output schema for the `wp/media-delete` ability.
	 *
	 * Trashing returns the trashed attachment directly; a forced deletion returns a `deleted`
	 * flag with the deleted attachment under `previous`.
	 *
	 * @return array<string, mixed> The output JSON Schema.
	 */
	protected function get_delete_output_schema(): array {
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
							'description' => __( 'Whether the attachment was permanently deleted.' ),
						),
						'previous' => $item_schema,
					),
				),
			),
		);
	}
}

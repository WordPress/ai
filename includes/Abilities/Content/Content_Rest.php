<?php
/**
 * The REST-backed implementation of the `core/read-content` ability.
 *
 * @package WordPress\AI
 *
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content;

use WP_Error;
use WP_Post;
use WP_Post_Type;
use WordPress\AI\Abilities\Rest\Rest_Backend;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class - Content_Rest
 *
 * Reads posts through the REST posts endpoint of their post type instead of running
 * `WP_Query` and formatting the post object directly.
 *
 * The mapping covers three things:
 *
 *   - Field names. The ability flattens the REST sub-objects, so `title.rendered` becomes
 *     `title_rendered`, `content.raw` becomes `content_raw`, and `type` becomes `post_type`.
 *   - Dates. REST returns them without a timezone offset; the ability returns full ISO 8601.
 *   - The author. REST returns the author ID; the ability returns the ID with the name.
 *
 * The `edit` context is requested whenever the caller can edit the post, matching the
 * ability: raw fields are edit-context fields, and password-protected posts render their
 * real content for an editor.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since 1.3.0
 */
final class Content_Rest {

	/**
	 * Ability fields that map to a REST sub-object, keyed by the REST field they come from.
	 *
	 * @since 1.3.0
	 * @var array<string, array<string, string>>
	 */
	private const SUB_OBJECT_FIELDS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'title'   => array(
			'title_raw'      => 'raw',
			'title_rendered' => 'rendered',
		),
		'excerpt' => array(
			'excerpt_raw'       => 'raw',
			'excerpt_rendered'  => 'rendered',
			'excerpt_protected' => 'protected',
		),
		'content' => array(
			'content_raw'       => 'raw',
			'content_rendered'  => 'rendered',
			'content_protected' => 'protected',
		),
	);

	/**
	 * Ability fields that map to a plain REST field, keyed by ability field name.
	 *
	 * @since 1.3.0
	 * @var array<string, string>
	 */
	private const PLAIN_FIELDS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'id'           => 'id',
		'post_type'    => 'type',
		'status'       => 'status',
		'date'         => 'date',
		'date_gmt'     => 'date_gmt',
		'modified'     => 'modified',
		'modified_gmt' => 'modified_gmt',
		'slug'         => 'slug',
		'link'         => 'link',
		'author'       => 'author',
		'parent'       => 'parent',
	);

	/**
	 * Reads a single post through the REST API.
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_Post     $post   The post to read.
	 * @param list<string> $fields The requested field names.
	 * @return array<string, mixed>|\stdClass|\WP_Error The formatted post data, or a WP_Error on failure.
	 */
	public function get_post( WP_Post $post, array $fields ) {
		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object instanceof WP_Post_Type ) {
			return $this->not_found_error();
		}

		$restore_post_type = $this->prepare_post_type( $post_type_object );
		$restore_context   = $this->capture_post_context();

		try {
			$response = Rest_Backend::get(
				$this->route( $post_type_object ) . '/' . (int) $post->ID,
				array(
					'context' => current_user_can( 'edit_post', $post->ID ) ? 'edit' : 'view',
					'_fields' => $this->rest_fields( $fields ),
				)
			);
		} finally {
			$restore_context();
			$restore_post_type();
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->format_post( Rest_Backend::data( $response ), $fields );
	}

	/**
	 * Reads a set of posts through the REST API.
	 *
	 * Takes the `WP_Query` arguments the ability prepared and maps them to the collection
	 * parameters of the posts endpoint.
	 *
	 * @since 1.3.0
	 *
	 * @param string               $post_type  The post type to query.
	 * @param array<string, mixed> $query_args The prepared `WP_Query` arguments.
	 * @param list<string>         $fields     The requested field names.
	 * @return array{posts: list<array<string, mixed>|\stdClass>, total: int, total_pages: int}|\WP_Error The query data, or a WP_Error on failure.
	 */
	public function query_posts( string $post_type, array $query_args, array $fields ) {
		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object instanceof WP_Post_Type ) {
			return $this->not_found_error();
		}

		$params = array(
			'context'  => 'editable' === ( $query_args['perm'] ?? '' ) ? 'edit' : 'view',
			'status'   => $query_args['post_status'],
			'per_page' => $query_args['posts_per_page'],
			'page'     => $query_args['paged'],
			'_fields'  => $this->rest_fields( $fields ),
		);

		// The REST parameters for author and parent are lists, unlike the query arguments.
		if ( isset( $query_args['post__in'] ) ) {
			$params['include'] = $query_args['post__in'];
		}
		if ( isset( $query_args['author'] ) ) {
			$params['author'] = array( $query_args['author'] );
		}
		if ( isset( $query_args['post_parent'] ) ) {
			$params['parent'] = array( $query_args['post_parent'] );
		}

		/*
		 * The endpoint has no parameters for the read permission and the cache priming the
		 * ability decides on, so they are carried over to the query the endpoint builds,
		 * for this request only.
		 */
		$carry_query_args = static function ( array $args ) use ( $query_args ): array {
			$args['perm']                   = $query_args['perm'];
			$args['update_post_meta_cache'] = $query_args['update_post_meta_cache'];
			$args['update_post_term_cache'] = $query_args['update_post_term_cache'];

			return $args;
		};
		add_filter( "rest_{$post_type}_query", $carry_query_args );

		$restore_post_type = $this->prepare_post_type( $post_type_object );
		$restore_context   = $this->capture_post_context();

		try {
			$response = Rest_Backend::get( $this->route( $post_type_object ), $params );
		} finally {
			remove_filter( "rest_{$post_type}_query", $carry_query_args );
			$restore_context();
			$restore_post_type();
		}

		if ( is_wp_error( $response ) ) {
			// The endpoint reports the same out-of-range page the ability reports, under
			// its own error code.
			if ( 'rest_post_invalid_page_number' === $response->get_error_code() ) {
				return new WP_Error(
					'content_invalid_page_number',
					__( 'The page number requested is larger than the number of pages available.', 'ai' ),
					array( 'status' => 400 )
				);
			}

			return $response;
		}

		$posts = array();
		foreach ( Rest_Backend::data( $response ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$posts[] = $this->format_post( $item, $fields );
		}

		return array(
			'posts'       => $posts,
			'total'       => Rest_Backend::pagination_header( $response, 'X-WP-Total' ),
			'total_pages' => Rest_Backend::pagination_header( $response, 'X-WP-TotalPages' ),
		);
	}

	/**
	 * Maps a REST post response to the ability output shape.
	 *
	 * A field the post type does not support is absent from the REST response, so it is
	 * absent here too. An empty projection is returned as an object so it serializes as `{}`.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $data   The REST response data for one post.
	 * @param list<string>         $fields The requested field names.
	 * @return array<string, mixed>|\stdClass The formatted post data.
	 */
	private function format_post( array $data, array $fields ) {
		$requested = array_flip( $fields );
		$result    = array();

		foreach ( self::PLAIN_FIELDS as $field => $rest_field ) {
			if ( ! isset( $requested[ $field ] ) || ! array_key_exists( $rest_field, $data ) ) {
				continue;
			}

			$result[ $field ] = $data[ $rest_field ];
		}

		foreach ( array( 'id', 'parent' ) as $field ) {
			if ( ! isset( $result[ $field ] ) ) {
				continue;
			}

			$result[ $field ] = (int) $result[ $field ];
		}
		foreach ( array( 'post_type', 'status', 'slug', 'link' ) as $field ) {
			if ( ! isset( $result[ $field ] ) ) {
				continue;
			}

			$result[ $field ] = (string) $result[ $field ];
		}

		// REST reports dates without a timezone offset; the ability reports full ISO 8601.
		foreach ( array( 'date', 'modified' ) as $field ) {
			if ( array_key_exists( $field, $result ) ) {
				$result[ $field ] = $this->to_iso_8601( $result[ $field ], wp_timezone() );
			}
			if ( ! array_key_exists( $field . '_gmt', $result ) ) {
				continue;
			}

			$gmt = $this->to_iso_8601( $result[ $field . '_gmt' ], new \DateTimeZone( 'UTC' ) );

			/*
			 * REST reports no GMT date when the stored column is null rather than the
			 * zero date. The ability derives it from the local date in that case, so
			 * read the stored dates back for the fallback.
			 */
			$result[ $field . '_gmt' ] = '' === $gmt ? $this->gmt_from_stored_date( $data, $field ) : $gmt;
		}

		// REST reports the author ID alone; the ability reports the ID with the name.
		if ( array_key_exists( 'author', $result ) ) {
			$author           = get_userdata( (int) $result['author'] );
			$result['author'] = array(
				'id'   => (int) $result['author'],
				'name' => $author ? $author->display_name : '',
			);
		}

		foreach ( self::SUB_OBJECT_FIELDS as $rest_field => $mapped_fields ) {
			if ( ! isset( $data[ $rest_field ] ) || ! is_array( $data[ $rest_field ] ) ) {
				continue;
			}

			foreach ( $mapped_fields as $field => $key ) {
				if ( ! isset( $requested[ $field ] ) || ! array_key_exists( $key, $data[ $rest_field ] ) ) {
					continue;
				}

				$value            = $data[ $rest_field ][ $key ];
				$result[ $field ] = 'protected' === $key ? (bool) $value : (string) $value;
			}
		}

		return array() === $result ? (object) array() : $this->in_output_order( $result );
	}

	/**
	 * Sorts the mapped fields into the order the ability documents them in.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $result The mapped post data.
	 * @return array<string, mixed> The post data in output order.
	 */
	private function in_output_order( array $result ): array {
		$order = array(
			'id',
			'post_type',
			'status',
			'date',
			'date_gmt',
			'modified',
			'modified_gmt',
			'slug',
			'link',
			'title_raw',
			'title_rendered',
			'excerpt_raw',
			'excerpt_rendered',
			'excerpt_protected',
			'content_raw',
			'content_rendered',
			'content_protected',
			'author',
			'parent',
		);

		$ordered = array();
		foreach ( $order as $field ) {
			if ( ! array_key_exists( $field, $result ) ) {
				continue;
			}

			$ordered[ $field ] = $result[ $field ];
		}

		return $ordered;
	}

	/**
	 * Derives a GMT date from the post's stored local date.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $data  The REST response data for one post.
	 * @param string               $field Either `date` or `modified`.
	 * @return string The ISO 8601 date, or an empty string when it cannot be resolved.
	 */
	private function gmt_from_stored_date( array $data, string $field ): string {
		$post = isset( $data['id'] ) ? get_post( (int) $data['id'] ) : null;
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$local = 'modified' === $field ? $post->post_modified : $post->post_date;
		if ( ! is_string( $local ) || '' === $local || '0000-00-00 00:00:00' === $local ) {
			return '';
		}

		$timestamp = strtotime( get_gmt_from_date( $local ) . ' UTC' );

		return false === $timestamp ? '' : gmdate( 'c', $timestamp );
	}

	/**
	 * Remembers the global post context so it can be put back after the request.
	 *
	 * The posts endpoint sets the global post while it renders each item and leaves it
	 * there. The ability restores whatever context it found, so filters that run after it
	 * still see the post they were looking at.
	 *
	 * @since 1.3.0
	 *
	 * @return callable(): void A callback that restores the previous global post context.
	 */
	private function capture_post_context(): callable {
		$previous_post = $GLOBALS['post'] ?? null;

		return static function () use ( $previous_post ): void {
			if ( $previous_post instanceof WP_Post ) {
				$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the previous global post context.
				setup_postdata( $previous_post );

				return;
			}

			unset( $GLOBALS['post'] );
			wp_reset_postdata();
		};
	}

	/**
	 * Formats a REST date as ISO 8601 with a timezone offset.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed         $value    The REST date value.
	 * @param \DateTimeZone $timezone The timezone the value is expressed in.
	 * @return string The ISO 8601 date, or an empty string when it cannot be resolved.
	 */
	private function to_iso_8601( $value, \DateTimeZone $timezone ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		$datetime = date_create_immutable( $value, $timezone );

		return $datetime ? $datetime->format( 'c' ) : '';
	}

	/**
	 * Maps the requested ability fields to the REST fields that carry them.
	 *
	 * @since 1.3.0
	 *
	 * @param list<string> $fields The requested field names.
	 * @return list<string> The REST field names to request.
	 */
	private function rest_fields( array $fields ): array {
		$rest_fields = array();

		foreach ( $fields as $field ) {
			if ( isset( self::PLAIN_FIELDS[ $field ] ) ) {
				$rest_fields[] = self::PLAIN_FIELDS[ $field ];
				continue;
			}

			foreach ( self::SUB_OBJECT_FIELDS as $rest_field => $mapped_fields ) {
				if ( ! isset( $mapped_fields[ $field ] ) ) {
					continue;
				}

				$rest_fields[] = $rest_field;
			}
		}

		// `id` keeps the response shaped as a map even when nothing else is requested.
		$rest_fields[] = 'id';

		return array_values( array_unique( $rest_fields ) );
	}

	/**
	 * Returns the REST route for a post type's posts endpoint.
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @return string The route, for example `/wp/v2/posts`.
	 */
	private function route( WP_Post_Type $post_type_object ): string {
		$namespace = ! empty( $post_type_object->rest_namespace ) && is_string( $post_type_object->rest_namespace )
			? $post_type_object->rest_namespace
			: 'wp/v2';
		$base      = ! empty( $post_type_object->rest_base ) && is_string( $post_type_object->rest_base )
			? $post_type_object->rest_base
			: $post_type_object->name;

		return '/' . $namespace . '/' . $base;
	}

	/**
	 * Makes sure a post type can be read through the REST API.
	 *
	 * A post type can be exposed to abilities with `show_in_abilities` without being exposed
	 * to REST, in which case it has no route and the posts controller refuses to serve it.
	 * Turn the flag on for the length of the request, and drop the built REST server so the
	 * next request builds a fresh one. Rebuilding runs `rest_api_init`, where WordPress
	 * registers a route for every post type exposed to REST, including this one.
	 *
	 * @since 1.3.0
	 *
	 * @param \WP_Post_Type $post_type_object The post type object.
	 * @return callable(): void A callback that restores the flag and the previous server.
	 */
	private function prepare_post_type( WP_Post_Type $post_type_object ): callable {
		if ( ! empty( $post_type_object->show_in_rest ) ) {
			return static function (): void {};
		}

		$previous_flag   = $post_type_object->show_in_rest;
		$previous_server = $GLOBALS['wp_rest_server'] ?? null;

		$post_type_object->show_in_rest = true;
		unset( $GLOBALS['wp_rest_server'] );

		return static function () use ( $post_type_object, $previous_flag, $previous_server ): void {
			$post_type_object->show_in_rest = $previous_flag;

			if ( null === $previous_server ) {
				return;
			}

			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restores the WordPress REST server that was replaced above.
			$GLOBALS['wp_rest_server'] = $previous_server;
		};
	}

	/**
	 * Builds the uniform not-found error.
	 *
	 * @since 1.3.0
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

<?php
/**
 * The REST-backed implementation of the content write abilities.
 *
 * @package WordPress\AI
 *
 * @since x.x.x
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
 * Class - Content_Write_Rest
 *
 * Writes posts through the REST posts endpoint of their post type. Unlike the read
 * abilities, these have no second implementation to compare against: the endpoint is the
 * only way they write, so every capability check, sanitization and side effect stays with
 * the posts controller.
 *
 * The written post is reported in the shape `core/content-query` returns, by reading it
 * back through {@see Content_Rest}. That keeps one field mapping for both directions.
 *
 * @internal This class should not be used outside the plugin and there is no guarantee of backwards compatibility.
 *
 * @since x.x.x
 */
final class Content_Write_Rest {

	use Post_Type_Route;

	/**
	 * Ability input fields that map to a REST parameter of the same name.
	 *
	 * The read abilities flatten the REST sub-objects, so they report `title_raw`. A write
	 * sets the raw value, which the endpoint takes as plain `title`.
	 *
	 * @since x.x.x
	 * @var list<string>
	 */
	private const WRITABLE_FIELDS = array( // phpcs:ignore SlevomatCodingStandard.Classes.DisallowMultiConstantDefinition -- This is used as an array const.
		'title',
		'content',
		'excerpt',
		'status',
		'slug',
		'date',
		'date_gmt',
		'author',
		'parent',
		'password',
		'menu_order',
		'comment_status',
		'ping_status',
		'sticky',
		'template',
		'format',
	);

	/**
	 * Creates a post through the REST API.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post_Type        $post_type_object The post type to create in.
	 * @param array<string, mixed> $input            The ability input.
	 * @param list<string>         $fields           The fields to report on the created post.
	 * @return array<string, mixed>|\stdClass|\WP_Error The created post, or a WP_Error on failure.
	 */
	public function create_post( WP_Post_Type $post_type_object, array $input, array $fields ) {
		$response = $this->request(
			$post_type_object,
			static function ( string $route, array $params ) {
				return Rest_Backend::post( $route, $params );
			},
			$this->route( $post_type_object ),
			$this->to_rest_params( $input )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->read_back( $response, $fields );
	}

	/**
	 * Updates a post through the REST API.
	 *
	 * Only the fields present in the input are sent, so an update leaves every field it
	 * does not name alone rather than resetting it to a default.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post             $post   The post to update.
	 * @param array<string, mixed> $input  The ability input.
	 * @param list<string>         $fields The fields to report on the updated post.
	 * @return array<string, mixed>|\stdClass|\WP_Error The updated post, or a WP_Error on failure.
	 */
	public function update_post( WP_Post $post, array $input, array $fields ) {
		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object instanceof WP_Post_Type ) {
			return $this->not_found_error();
		}

		$response = $this->request(
			$post_type_object,
			static function ( string $route, array $params ) {
				return Rest_Backend::post( $route, $params );
			},
			$this->route( $post_type_object ) . '/' . (int) $post->ID,
			$this->to_rest_params( $input )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->read_back( $response, $fields );
	}

	/**
	 * Deletes a post through the REST API.
	 *
	 * The endpoint answers the two cases differently: forcing returns a `deleted` envelope
	 * carrying the post as it was, while trashing returns the trashed post itself. Both are
	 * reported the same way here, with `deleted` telling them apart.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post     $post   The post to delete.
	 * @param bool         $force  Whether to delete permanently instead of trashing.
	 * @param list<string> $fields The fields to report on the deleted post.
	 * @return array{deleted: bool, post: array<string, mixed>|\stdClass}|\WP_Error The outcome, or a WP_Error on failure.
	 */
	public function delete_post( WP_Post $post, bool $force, array $fields ) {
		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object instanceof WP_Post_Type ) {
			return $this->not_found_error();
		}

		/*
		 * A forced delete leaves nothing to read afterwards, so the post is read while it
		 * is still there. Trashing keeps the row, and it is read back after the request so
		 * the reported status is the one the delete left behind.
		 */
		$previous = $force ? ( new Content_Rest() )->get_post( $post, $fields ) : null;
		if ( is_wp_error( $previous ) ) {
			return $previous;
		}

		$response = $this->request(
			$post_type_object,
			static function ( string $route, array $params ) {
				return Rest_Backend::delete( $route, $params );
			},
			$this->route( $post_type_object ) . '/' . (int) $post->ID,
			$force ? array( 'force' => true ) : array()
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( null !== $previous ) {
			return array(
				'deleted' => true,
				'post'    => $previous,
			);
		}

		$trashed = $this->read_back( $response, $fields );
		if ( is_wp_error( $trashed ) ) {
			return $trashed;
		}

		return array(
			'deleted' => false,
			'post'    => $trashed,
		);
	}

	/**
	 * Runs a request against a post type's posts endpoint and returns its data.
	 *
	 * Wraps the call in the post type exposure and global post context handling every
	 * content request needs, so a failure still restores both.
	 *
	 * @since x.x.x
	 *
	 * @param \WP_Post_Type        $post_type_object The post type being addressed.
	 * @param callable             $dispatch         Receives the route and parameters, returns the response.
	 * @param string               $route            The REST route.
	 * @param array<string, mixed> $params           Request parameters.
	 * @return array<mixed>|\WP_Error The response data, or the error the endpoint returned.
	 */
	private function request( WP_Post_Type $post_type_object, callable $dispatch, string $route, array $params ) {
		$restore_post_type = $this->prepare_post_type( $post_type_object );
		$restore_context   = $this->capture_post_context();

		try {
			$response = $dispatch( $route, $params );
		} finally {
			$restore_context();
			$restore_post_type();
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return Rest_Backend::data( $response );
	}

	/**
	 * Reports a written post in the shape the read ability returns.
	 *
	 * The write response already carries the post, but reading it back through
	 * {@see Content_Rest} keeps one field mapping instead of two that can drift.
	 *
	 * @since x.x.x
	 *
	 * @param array<mixed> $data   The write response data.
	 * @param list<string> $fields The fields to report.
	 * @return array<string, mixed>|\stdClass|\WP_Error The post data, or a WP_Error on failure.
	 */
	private function read_back( array $data, array $fields ) {
		$post = isset( $data['id'] ) ? get_post( (int) $data['id'] ) : null;

		// The endpoint answers a write with the written post, so a response without a
		// readable ID is one the mapping cannot read.
		if ( ! $post instanceof WP_Post ) {
			return Rest_Backend::unexpected_response_error();
		}

		return ( new Content_Rest() )->get_post( $post, $fields );
	}

	/**
	 * Maps the ability input to REST request parameters.
	 *
	 * Fields absent from the input stay absent, which is what makes a partial update
	 * partial. `post_type` is not sent: it is carried by the route.
	 *
	 * @since x.x.x
	 *
	 * @param array<string, mixed> $input The ability input.
	 * @return array<string, mixed> The REST request parameters.
	 */
	private function to_rest_params( array $input ): array {
		$params = array();

		foreach ( self::WRITABLE_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			$params[ $field ] = $input[ $field ];
		}

		return $params;
	}

	/**
	 * Builds the uniform not-found error.
	 *
	 * @since x.x.x
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

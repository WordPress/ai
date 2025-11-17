<?php
/**
 * Utility WordPress Abilities implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Utilities;

use WP_Error;

/**
 * Utility WordPress Abilities.
 *
 * @since 0.1.0
 */
class Utilities {

	/**
	 * Register any needed hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.1.0
	 */
	public function register_abilities(): void {
		$this->register_get_post_details_ability();
		$this->register_get_terms_ability();
	}

	/**
	 * Registers the get-post-details ability.
	 *
	 * @since 0.1.0
	 */
	private function register_get_post_details_ability(): void {
		wp_register_ability(
			'ai/get-post-details',
			array(
				'label'               => esc_html__( 'Get post details', 'ai' ),
				'description'         => esc_html__( 'Get the details of a post based on the post ID. Optionally limit the details to specific fields.', 'ai' ),
				'category'            => 'ai-experiments',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => esc_html__( 'The ID of the post to get the details of.', 'ai' ),
						),
						'fields' => array(
							'type'        => 'array',
							'description' => esc_html__( 'The fields to get the details of.', 'ai' ),
							'items'       => array(
								'type' => 'string',
							),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'details' => array(
							'type'        => 'array',
							'description' => esc_html__( 'An array of post details.', 'ai' ),
						),
					),
				),
				'execute_callback'    => static function ( array $input ) {
					$post_id = absint( $input['post_id'] );
					$post    = self::get_post_object( $post_id );

					// If the post doesn't exist, return an error.
					if ( is_wp_error( $post ) ) {
						return $post;
					}

					// Get the author display name.
					$author = get_user_by( 'ID', $post->post_author );
					if ( $author ) {
						$author_name = $author->display_name;
					} else {
						$author_name = '';
					}

					// Return the post details.
					return array(
						'content' => $post->post_content,
						'title'   => $post->post_title,
						'slug'    => $post->post_name,
						'author'  => $author_name,
						'type'    => $post->post_type,
						'excerpt' => $post->post_excerpt,
					);
				},
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Registers the get-terms ability.
	 *
	 * @since 0.1.0
	 */
	private function register_get_terms_ability(): void {
		wp_register_ability(
			'ai/get-terms',
			array(
				'label'               => esc_html__( 'Get the post terms', 'ai' ),
				'description'         => esc_html__( 'Get the terms of a post based on the post ID and optionally filter by taxonomy.', 'ai' ),
				'category'            => 'ai-experiments',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array(
							'type'        => 'integer',
							'description' => esc_html__( 'The ID of the post to get the terms of.', 'ai' ),
						),
						'taxonomy' => array(
							'type'        => 'string',
							'description' => esc_html__( 'The taxonomy to filter the terms by.', 'ai' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'terms' => array(
							'type'        => 'array',
							'description' => esc_html__( 'An array of WP_Term objects assigned to the post.', 'ai' ),
						),
					),
				),
				'execute_callback'    => static function ( array $input ) {
					$post_id  = absint( $input['post_id'] );
					$post     = self::get_post_object( $post_id );

					if ( is_wp_error( $post ) ) {
						return $post;
					}

					// See if we have a specific taxonomy to get terms for.
					$taxonomy = $input['taxonomy'] ?? '';

					if ( $taxonomy ) {
						$taxonomies = array( $taxonomy );
					} else {
						$taxonomies = get_object_taxonomies( $post->post_type );
					}

					$terms = wp_get_object_terms( $post_id, $taxonomies );

					if ( is_wp_error( $terms ) ) {
						return new WP_Error(
							'get_terms_error',
							/* translators: %1$s: Taxonomy. %2$s: Error message. */
							sprintf( esc_html__( 'Error getting terms for taxonomy %1$s: %2$s', 'ai' ), $taxonomy, $terms->get_error_message() )
						);
					}

					return $terms;
				},
				'permission_callback' => array( $this, 'permission_callback' ),
			),
		);
	}

	/**
	 * The default permission callback abilities can use.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $args The input arguments to the ability.
	 * @return bool|\WP_Error True or false depending on whether the user has permission; WP_Error if the post doesn't exist.
	 */
	public function permission_callback( array $args ) {
		$post_id = absint( $args['post_id'] );
		$post    = self::get_post_object( $post_id );

		// Ensure the post exists.
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// Return true if the user has permission to read the post.
		return current_user_can( 'read_post', $post_id );
	}

	/**
	 * Gets the post object.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id The ID of the post to get the object of.
	 * @return \WP_Post|\WP_Error The post object or WP_Error if the post doesn't exist.
	 */
	private static function get_post_object( int $post_id ) {
		$post = get_post( $post_id );

		// If the post doesn't exist, return an error.
		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				esc_html__( 'Post not found.', 'ai' )
			);
		}

		return $post;
	}
}

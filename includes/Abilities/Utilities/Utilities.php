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
		$this->register_get_content_ability();
		$this->register_get_title_ability();
		$this->register_get_name_ability();
		$this->register_get_author_ability();
		$this->register_get_type_ability();
		$this->register_get_excerpt_ability();
		$this->register_get_terms_ability();
	}

	/**
	 * Registers the get-content ability.
	 *
	 * @since 0.1.0
	 */
	private function register_get_content_ability(): void {
		wp_register_ability(
			'ai/get-content',
			array(
				'label'               => esc_html__( 'Get post content', 'ai' ),
				'description'         => esc_html__( 'Get the content of a post based on the post ID.', 'ai' ),
				'category'            => 'ai-experiments',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => esc_html__( 'The ID of the post to get the content of.', 'ai' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema' => array(
					'type'        => 'string',
					'description' => esc_html__( 'The content of the post.', 'ai' ),
				),
				'execute_callback'    => static function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post    = get_post( $post_id );

					return $post->post_content;
				},
				'permission_callback' => static function ( $args ) {
					$post_id = absint( $args['post_id'] );
					$post    = get_post( $post_id );

					// If the post doesn't exist, return an error.
					if ( ! $post ) {
						return new WP_Error(
							'post_not_found',
							esc_html__( 'Post not found.', 'ai' )
						);
					}

					// Return true if the user has permission to read the post.
					return current_user_can( 'read_post', $post_id );
				},
			),
		);
	}

	/**
	 * Registers the get-title ability.
	 *
	 * @since 0.1.0
	 */
	private function register_get_title_ability(): void {
		wp_register_ability(
			'ai/get-title',
			array(
				'label'               => esc_html__( 'Get the post title', 'ai' ),
				'description'         => esc_html__( 'Get the title of a post based on the post ID.', 'ai' ),
				'category'            => 'ai-experiments',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => esc_html__( 'The ID of the post to get the title of.', 'ai' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema' => array(
					'type'        => 'string',
					'description' => esc_html__( 'The title of the post.', 'ai' ),
				),
				'execute_callback'    => static function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post    = get_post( $post_id );

					return $post->post_title;
				},
				'permission_callback' => static function ( $args ) {
					$post_id = absint( $args['post_id'] );
					$post    = get_post( $post_id );

					// If the post doesn't exist, return an error.
					if ( ! $post ) {
						return new WP_Error(
							'post_not_found',
							esc_html__( 'Post not found.', 'ai' )
						);
					}

					// Return true if the user has permission to read the post.
					return current_user_can( 'read_post', $post_id );
				},
			),
		);
	}

	/**
	 * Registers the get-name ability.
	 *
	 * @since 0.1.0
	 */
	private function register_get_name_ability(): void {
		wp_register_ability(
			'ai/get-name',
			array(
				'label'               => esc_html__( 'Get the post name', 'ai' ),
				'description'         => esc_html__( 'Get the name of a post based on the post ID.', 'ai' ),
				'category'            => 'ai-experiments',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => esc_html__( 'The ID of the post to get the name of.', 'ai' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema' => array(
					'type'        => 'string',
					'description' => esc_html__( 'The name of the post.', 'ai' ),
				),
				'execute_callback'    => static function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post    = get_post( $post_id );

					return $post->post_name;
				},
				'permission_callback' => static function ( $args ) {
					$post_id = absint( $args['post_id'] );
					$post    = get_post( $post_id );

					// If the post doesn't exist, return an error.
					if ( ! $post ) {
						return new WP_Error(
							'post_not_found',
							esc_html__( 'Post not found.', 'ai' )
						);
					}

					// Return true if the user has permission to read the post.
					return current_user_can( 'read_post', $post_id );
				},
			),
		);
	}

	/**
	 * Registers the get-author ability.
	 *
	 * @since 0.1.0
	 */
	private function register_get_author_ability(): void {
		wp_register_ability(
			'ai/get-author',
			array(
				'label'               => esc_html__( 'Get the post author', 'ai' ),
				'description'         => esc_html__( 'Get the display name of the author of a post based on the post ID.', 'ai' ),
				'category'            => 'ai-experiments',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => esc_html__( 'The ID of the post to get the author of.', 'ai' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema' => array(
					'type'        => 'string',
					'description' => esc_html__( 'The display name of the author of the post.', 'ai' ),
				),
				'execute_callback'    => static function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post    = get_post( $post_id );
					$author  = get_user_by( 'ID', $post->post_author );

					return $author ? $author->display_name : '';
				},
				'permission_callback' => static function ( $args ) {
					$post_id = absint( $args['post_id'] );
					$post    = get_post( $post_id );

					// If the post doesn't exist, return an error.
					if ( ! $post ) {
						return new WP_Error(
							'post_not_found',
							esc_html__( 'Post not found.', 'ai' )
						);
					}

					// Return true if the user has permission to read the post.
					return current_user_can( 'read_post', $post_id );
				},
			),
		);
	}

	/**
	 * Registers the get-type ability.
	 *
	 * @since 0.1.0
	 */
	private function register_get_type_ability(): void {
		wp_register_ability(
			'ai/get-type',
			array(
				'label'               => esc_html__( 'Get the post type', 'ai' ),
				'description'         => esc_html__( 'Get the type of a post based on the post ID.', 'ai' ),
				'category'            => 'ai-experiments',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => esc_html__( 'The ID of the post to get the type of.', 'ai' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema' => array(
					'type'        => 'string',
					'description' => esc_html__( 'The post type of the post.', 'ai' ),
				),
				'execute_callback'    => static function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post    = get_post( $post_id );

					return $post->post_type;
				},
				'permission_callback' => static function ( $args ) {
					$post_id = absint( $args['post_id'] );
					$post    = get_post( $post_id );

					// If the post doesn't exist, return an error.
					if ( ! $post ) {
						return new WP_Error(
							'post_not_found',
							esc_html__( 'Post not found.', 'ai' )
						);
					}

					// Return true if the user has permission to read the post.
					return current_user_can( 'read_post', $post_id );
				},
			),
		);
	}

	/**
	 * Registers the get-excerpt ability.
	 *
	 * @since 0.1.0
	 */
	private function register_get_excerpt_ability(): void {
		wp_register_ability(
			'ai/get-excerpt',
			array(
				'label'               => esc_html__( 'Get the post excerpt', 'ai' ),
				'description'         => esc_html__( 'Get the excerpt of a post based on the post ID.', 'ai' ),
				'category'            => 'ai-experiments',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => esc_html__( 'The ID of the post to get the excerpt of.', 'ai' ),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema' => array(
					'type'        => 'string',
					'description' => esc_html__( 'The excerpt of the post.', 'ai' ),
				),
				'execute_callback'    => static function ( $input ) {
					$post_id = absint( $input['post_id'] );
					$post    = get_post( $post_id );

					return $post->post_excerpt;
				},
				'permission_callback' => static function ( $args ) {
					$post_id = absint( $args['post_id'] );
					$post    = get_post( $post_id );

					// If the post doesn't exist, return an error.
					if ( ! $post ) {
						return new WP_Error(
							'post_not_found',
							esc_html__( 'Post not found.', 'ai' )
						);
					}

					// Return true if the user has permission to read the post.
					return current_user_can( 'read_post', $post_id );
				},
			),
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
						'post_id' => array(
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
				'output_schema' => array(
					'type'        => 'object',
					'properties' => array(
						'terms' => array(
							'type'        => 'array',
							'description' => esc_html__( 'An array of WP_Term objects assigned to the post.', 'ai' ),
						),
					),
				),
				'execute_callback'    => static function ( $input ) {
					$post_id  = absint( $input['post_id'] );
					$post     = get_post( $post_id );
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
							/* translators: %s: Taxonomy. %s: Error message. */
							sprintf( esc_html__( 'Error getting terms for taxonomy %s: %s', 'ai' ), $taxonomy, $terms->get_error_message() )
						);
					}

					return $terms;
				},
				'permission_callback' => static function ( $args ) {
					$post_id = absint( $args['post_id'] );
					$post    = get_post( $post_id );

					// If the post doesn't exist, return an error.
					if ( ! $post ) {
						return new WP_Error(
							'post_not_found',
							esc_html__( 'Post not found.', 'ai' )
						);
					}

					// Return true if the user has permission to read the post.
					return current_user_can( 'read_post', $post_id );
				},
			),
		);
	}
}

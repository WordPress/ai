<?php
/**
 * Post-related WordPress Abilities.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Utilities;

use WP_Error;

/**
 * Post utility WordPress Abilities.
 *
 * @since 0.1.0
 */
class Posts {

	/**
	 * The fields that we support.
	 *
	 * @since 0.1.0
	 * @var array<string>
	 */
	private static array $post_details_fields = array( 'content', 'title', 'slug', 'author', 'type', 'excerpt' );

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
				'description'         => esc_html__( 'Get the details of a post based on the post ID. Optionally, limit the details to specific fields.', 'ai' ),
				'category'            => AI_EXPERIMENTS_DEFAULT_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => esc_html__( 'The ID of the post to get the details of.', 'ai' ),
						),
						'fields'  => array(
							'type'        => 'array',
							'description' => esc_html__( 'The fields to get the details of. Will default to all fields if not provided.', 'ai' ),
							'items'       => array(
								'type' => 'string',
								'enum' => self::$post_details_fields,
							),
						),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array(
					'type'        => 'object',
					'description' => esc_html__( 'The details of the post.', 'ai' ),
					'properties'  => array(
						'content' => array(
							'type'        => 'string',
							'description' => esc_html__( 'The content of the post.', 'ai' ),
						),
						'title'   => array(
							'type'        => 'string',
							'description' => esc_html__( 'The title of the post.', 'ai' ),
						),
						'slug'    => array(
							'type'        => 'string',
							'description' => esc_html__( 'The slug of the post.', 'ai' ),
						),
						'author'  => array(
							'type'        => 'string',
							'description' => esc_html__( 'The author of the post.', 'ai' ),
						),
						'type'    => array(
							'type'        => 'string',
							'description' => esc_html__( 'The type of the post.', 'ai' ),
						),
						'excerpt' => array(
							'type'        => 'string',
							'description' => esc_html__( 'The excerpt of the post.', 'ai' ),
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

					// See if we have specific fields to get or default to all fields.
					$fields = isset( $input['fields'] ) && ! empty( $input['fields'] ) ? (array) $input['fields'] : self::$post_details_fields;

					$details = array();

					if ( in_array( 'content', $fields, true ) ) {
						$details['content'] = $post->post_content;
					}

					if ( in_array( 'title', $fields, true ) ) {
						$details['title'] = $post->post_title;
					}

					if ( in_array( 'slug', $fields, true ) ) {
						$details['slug'] = $post->post_name;
					}

					if ( in_array( 'author', $fields, true ) ) {
						// Get the author display name.
						$author = get_user_by( 'ID', $post->post_author );
						if ( $author ) {
							$details['author'] = $author->display_name;
						} else {
							$details['author'] = '';
						}
					}

					if ( in_array( 'type', $fields, true ) ) {
						$details['type'] = $post->post_type;
					}

					if ( in_array( 'excerpt', $fields, true ) ) {
						$details['excerpt'] = $post->post_excerpt;
					}

					// Return the post details.
					return $details;
				},
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'tool',
					),
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
			'ai/get-post-terms',
			array(
				'label'               => esc_html__( 'Get the post terms', 'ai' ),
				'description'         => esc_html__( 'Get the terms of a post based on the post ID and optionally filter by taxonomy.', 'ai' ),
				'category'            => AI_EXPERIMENTS_DEFAULT_ABILITY_CATEGORY,
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
					'type'        => 'object',
					'description' => esc_html__( 'An array of WP_Term objects assigned to the post.', 'ai' ),
					'properties'  => array(
						'type'  => 'array',
						'items' => array(
							'type'  => 'array',
							'items' => array(
								'term_id'          => array(
									'type'        => 'integer',
									'description' => esc_html__( 'The ID of the term.', 'ai' ),
								),
								'name'             => array(
									'type'        => 'string',
									'description' => esc_html__( 'The name of the term.', 'ai' ),
								),
								'slug'             => array(
									'type'        => 'string',
									'description' => esc_html__( 'The slug of the term.', 'ai' ),
								),
								'term_group'       => array(
									'type'        => 'integer',
									'description' => esc_html__( 'The group ID of the term.', 'ai' ),
								),
								'term_taxonomy_id' => array(
									'type'        => 'integer',
									'description' => esc_html__( 'The taxonomy ID of the term.', 'ai' ),
								),
								'taxonomy'         => array(
									'type'        => 'string',
									'description' => esc_html__( 'The taxonomy name of the term.', 'ai' ),
								),
								'description'      => array(
									'type'        => 'string',
									'description' => esc_html__( 'The description of the term.', 'ai' ),
								),
								'parent'           => array(
									'type'        => 'integer',
									'description' => esc_html__( 'The parent ID of the term.', 'ai' ),
								),
								'count'            => array(
									'type'        => 'integer',
									'description' => esc_html__( 'How many times the term is used.', 'ai' ),
								),
								'filter'           => array(
									'type'        => 'string',
									'description' => esc_html__( 'How the term should be filtered.', 'ai' ),
								),
							),
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
						// If a taxonomy is provided, ensure it exists.
						$taxonomy = get_taxonomy( $taxonomy );
						if ( ! $taxonomy ) {
							return new WP_Error(
								'taxonomy_not_found',
								esc_html__( 'Taxonomy not found.', 'ai' )
							);
						}
						$taxonomies = array( $taxonomy );
					} else {
						$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
					}

					// Remove any taxonomies that are not allowed.
					$allowed_taxonomies = array();
					foreach ( $taxonomies as $taxonomy ) {
						// If the taxonomy is not allowed in REST endpoints, skip it.
						if ( empty( $taxonomy->show_in_rest ) ) {
							continue;
						}

						// If the requested post isn't associated with this taxonomy, skip it.
						if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy->name ) ) {
							continue;
						}

						$allowed_taxonomies[] = $taxonomy->name;
					}

					$terms = wp_get_object_terms( $post_id, $allowed_taxonomies );

					if ( is_wp_error( $terms ) ) {
						return new WP_Error(
							'get_terms_error',
							/* translators: %1$s: Error message. */
							sprintf( esc_html__( 'Error getting terms: %1$s', 'ai' ), $terms->get_error_message() )
						);
					}

					return $terms;
				},
				'permission_callback' => array( $this, 'permission_callback' ),
				'meta'                => array(
					'mcp' => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
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

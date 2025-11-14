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
				'meta' => array(
					'show_in_rest' => true,
				),
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
				'meta' => array(
					'show_in_rest' => true,
				),
			),
		);
	}
}

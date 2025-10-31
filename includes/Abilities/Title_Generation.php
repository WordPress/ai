<?php
/**
 * Title generation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Abilities;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;

/**
 * Title generation WordPress Ability.
 *
 * @since 0.1.0
 */
class Title_Generation extends Abstract_Ability {

	/**
	 * The Feature class that the ability belongs to.
	 *
	 * @since 0.1.0
	 * @var \WordPress\AI\Features\Title_Generation\Title_Generation
	 */
	protected $feature;

	/**
	 * Returns the input schema of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Content to generate title suggestions for.', 'ai' ),
				),
				'post_id' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'Content from this post will be used to generate title suggestions. This overrides the content parameter if both are provided.', 'ai' ),
				),
				'n'       => array(
					'type'              => 'integer',
					'minimum'           => 1,
					'maximum'           => 10,
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'Number of titles to generate', 'ai' ),
				),
			),
		);
	}

	/**
	 * Returns the output schema of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'titles' => array(
					'type'        => 'array',
					'description' => esc_html__( 'Generated title suggestions.', 'ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
			),
		);
	}

	/**
	 * Executes the ability with the given input arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return mixed|\WP_Error The result of the ability execution, or a WP_Error on failure.
	 */
	protected function execute_callback( $input ) {
		// Default arguments.
		$args = wp_parse_args(
			$input,
			array(
				'content' => null,
				'post_id' => null,
				'n'       => 1,
			),
		);

		// If a post ID is provided, ensure the post exists before using its' content.
		if ( $args['post_id'] ) {
			$post = get_post( $args['post_id'] );

			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), absint( $args['post_id'] ) )
				);
			}

			$args['content'] = $post->post_content;
		}

		// If we have no content, return an error.
		if ( ! $args['content'] ) {
			return new WP_Error(
				'content_not_provided',
				esc_html__( 'Content is required to generate title suggestions.', 'ai' )
			);
		}

		// Generate the titles.
		$result = $this->feature->generate_titles( $args['content'], $args['n'] );

		// If we have an error, return it.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Return the titles in the format the Ability expects.
		return [ 'titles' => $result ];
	}

	/**
	 * Returns the permission callback of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $args The input arguments to the ability.
	 * @return bool|\WP_Error True if the user has permission, WP_Error otherwise.
	 */
	protected function permission_callback( $args ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to generate titles.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * Returns the meta of the ability.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The meta of the ability.
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}
}

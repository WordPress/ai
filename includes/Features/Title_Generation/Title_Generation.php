<?php
/**
 * Title generation feature implementation.
 *
 * @package WordPress\AI
 */

namespace WordPress\AI\Features\Title_Generation;

use WordPress\AI\Abstracts\Abstract_Feature;
use WP_REST_Server;

/**
 * Title generation feature.
 *
 * @since 0.1.0
 */
class Title_Generation extends Abstract_Feature {

	/**
	 * Loads feature metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return array{id: string, label: string, description: string} Feature metadata.
	 */
	protected function load_feature_metadata(): array {
		return array(
			'id'          => 'title-generation',
			'label'       => __( 'Title Generation', 'ai' ),
			'description' => __( 'Generates title suggestions from content.', 'ai' ),
		);
	}

	/**
	 * Registers the feature hooks.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Registers the categories.
	 *
	 * TODO: If we want to use the same category for all abilities
	 * in this plugin, this should be moved out of this class into
	 * it's own category registration class.
	 *
	 * @since 0.1.0
	 */
	public function register_categories(): void {
		wp_register_ability_category(
			'ai-experiments',
			array(
				'label'       => __( 'AI Experiments', 'ai' ),
				'description' => __( 'Various AI experiment features.', 'ai' ),
			),
		);
	}

	/**
	 * Registers any needed abilities.
	 *
	 * @since 0.1.0
	 */
	public function register_abilities(): void {
		wp_register_ability(
			'ai/title-generation', // TODO: add a method to build this slug from the feature ID.
			array(
				'label'               => $this->get_label(),
				'description'         => $this->get_description(),
				'category'            => 'ai-experiments', // TODO: add a method to get the category slug.
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'content' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Content to generate title suggestions for.', 'ai' ),
						),
					),
					'required'   => [
						'content',
					],
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'titles' => array(
							'type'        => 'array',
							'description' => __( 'Generated title suggestions.', 'ai' ),
							'items'       => array(
								'type' => 'string',
							),
						),
					),
				),
				'execute_callback'    => array( $this, 'title_generation_callback' ),
				'permission_callback' => array( $this, 'title_generation_permission_callback' ),
				'meta'                => array(
					'show_in_rest' => true,
				),
			),
		);
	}

	/**
	 * Registers the title generation REST API route.
	 *
	 * @since 0.1.0
	 */
	public function register_rest_route(): void {
		register_rest_route(
			'ai/v1',
			'title-generation',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'title_generation_callback' ),
				'permission_callback' => array( $this, 'title_generation_permission_callback' ),
				'args'                => [
					'content' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
						'description'       => esc_html__( 'Content to generate title suggestions for.', 'ai' ),
					],
				],
			)
		);
	}

	/**
	 * Callback for the title generation REST endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input The request data.
	 * @return array<string, mixed>
	 */
	public function title_generation_callback( array $input ): array {
		$args = wp_parse_args(
			$input,
			[
				'content' => null,
			]
		);

		return array(
			'feature_id'  => $this->get_id(),
			'label'       => $this->get_label(),
			'description' => $this->get_description(),
			'enabled'     => $this->is_enabled(),
			'content'     => $args['content'],
			'message'     => __( 'Title generation feature is active!', 'ai' ),
		);
	}

	/**
	 * Permission check for the REST endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function title_generation_permission_callback(): bool {
		return current_user_can( 'manage_options' );
	}
}

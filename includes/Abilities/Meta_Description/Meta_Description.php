<?php
/**
 * Meta description generation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Meta_Description;

use WP_Error;
use WP_Post;
use WP_Post_Type;
use WordPress\AI\Abstracts\Abstract_Ability;
use function WordPress\AI\get_post_context;
use function WordPress\AI\get_preferred_models_for_text_generation;
use function WordPress\AI\normalize_content;

/**
 * Meta description generation WordPress Ability.
 *
 * @since x.x.x
 */
class Meta_Description extends Abstract_Ability {

	/**
	 * Default number of suggestions to generate.
	 *
	 * @since x.x.x
	 * @var int
	 */
	public const DEFAULT_CANDIDATE_COUNT = 3;

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Post content to generate a meta description for.', 'ai' ),
				),
				'title'   => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'The post title, used to avoid duplication in the generated description.', 'ai' ),
				),
				'post_id' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'The post ID to generate a meta description for. If provided without content, the post content will be used.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function output_schema(): array {
		return array(
			'type'        => 'object',
			'description' => esc_html__( 'Generated meta description suggestions.', 'ai' ),
			'properties'  => array(
				'descriptions' => array(
					'type'        => 'array',
					'description' => esc_html__( 'Array of meta description suggestions.', 'ai' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'text'            => array(
								'type'        => 'string',
								'description' => esc_html__( 'The meta description text.', 'ai' ),
							),
							'character_count' => array(
								'type'        => 'integer',
								'description' => esc_html__( 'The character count of the description.', 'ai' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function execute_callback( $input ) {
		$args = wp_parse_args(
			$input,
			array(
				'content' => null,
				'title'   => null,
				'post_id' => null,
			),
		);

		$content = '';
		$title   = $args['title'] ?? '';
		$context = '';

		// If a post ID is provided, fetch content and context from the post.
		if ( $args['post_id'] ) {
			$post = get_post( (int) $args['post_id'] );

			if ( ! $post instanceof WP_Post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), absint( $args['post_id'] ) )
				);
			}

			$post_context = get_post_context( $post->ID );
			$content      = $post_context['content'] ?? '';
			unset( $post_context['content'] );
			$context = $post_context;

			// Use the post title if none was provided.
			if ( empty( $title ) && ! empty( $post->post_title ) ) {
				$title = $post->post_title;
			}
		}

		// Prefer explicitly provided content over post content.
		if ( $args['content'] ) {
			$content = normalize_content( $args['content'] );
		}

		if ( empty( $content ) ) {
			return new WP_Error(
				'content_not_provided',
				esc_html__( 'Content is required to generate a meta description.', 'ai' )
			);
		}

		$descriptions = $this->generate_descriptions( $content, $title, $context );
		if ( is_wp_error( $descriptions ) ) {
			return $descriptions;
		}

		if ( empty( $descriptions ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No meta description suggestions were generated.', 'ai' )
			);
		}

		return array( 'descriptions' => $descriptions );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function permission_callback( $args ) {
		$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;

		if ( $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), $post_id )
				);
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to generate meta descriptions for this post.', 'ai' )
				);
			}

			$post_type_obj = get_post_type_object( $post->post_type );
			if ( ! $post_type_obj instanceof WP_Post_Type || empty( $post_type_obj->show_in_rest ) ) {
				return false;
			}
		} elseif ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to generate meta descriptions.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Generate meta description suggestions from the given content.
	 *
	 * @since x.x.x
	 *
	 * @param string                       $content The content to generate descriptions from.
	 * @param string                       $title   The post title.
	 * @param string|array<string, string> $context Additional context to use.
	 * @return array<int, array{text: string, character_count: int}>|\WP_Error The generated descriptions, or a WP_Error.
	 */
	protected function generate_descriptions( string $content, string $title, $context ) {
		// Convert the context to a string if it's an array.
		if ( is_array( $context ) ) {
			$context = implode(
				"\n",
				array_map(
					static function ( $key, $value ) {
						return sprintf(
							'%s: %s',
							ucwords( str_replace( '_', ' ', $key ) ),
							$value
						);
					},
					array_keys( $context ),
					$context
				)
			);
		}

		$prompt = '<content>' . $content . '</content>';

		if ( ! empty( $title ) ) {
			$prompt .= "\n\n<title>" . $title . '</title>';
		}

		if ( ! empty( $context ) ) {
			$prompt .= "\n\n<additional-context>" . $context . '</additional-context>';
		}

		/**
		 * Filters the prompt content sent to the AI model for meta description generation.
		 *
		 * Allows developers to modify or augment the content before it is sent to the model.
		 *
		 * @since x.x.x
		 *
		 * @param string $prompt  The assembled prompt including content, title, and context tags.
		 * @param string $content The normalized post content.
		 * @param string $title   The post title.
		 */
		$prompt = (string) apply_filters( 'wpai_meta_description_prompt', $prompt, $content, $title );

		/**
		 * Filters the number of meta description candidates to generate.
		 *
		 * @since x.x.x
		 *
		 * @param int $candidate_count The number of candidates to request from the AI model.
		 */
		$candidate_count = (int) apply_filters( 'wpai_meta_description_candidate_count', self::DEFAULT_CANDIDATE_COUNT );

		/**
		 * Filters the temperature for the result of the meta description generation.
		 *
		 * @since x.x.x
		 *
		 * @param float $result_temperature The temperature for the result of the meta description generation.
		 */
		$result_temperature = (float) apply_filters( 'wpai_meta_description_result_temperature', 0.7 );

		$results = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction() )
			->using_temperature( $result_temperature )
			->using_candidate_count( $candidate_count )
			->using_model_preference( ...get_preferred_models_for_text_generation() )
			->generate_texts();

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		if ( ! is_array( $results ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No meta description suggestions were generated.', 'ai' )
			);
		}

		$descriptions = array();

		foreach ( $results as $result ) {
			if ( ! is_string( $result ) || empty( trim( $result ) ) ) {
				continue;
			}

			$text = sanitize_text_field( trim( $result, ' "\'' ) );

			$descriptions[] = array(
				'text'            => $text,
				'character_count' => mb_strlen( $text ),
			);
		}

		return $descriptions;
	}
}

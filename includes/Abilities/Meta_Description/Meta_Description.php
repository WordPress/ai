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
 * @since 0.7.0
 */
class Meta_Description extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.7.0
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content' => array(
					'type'        => 'string',
					'description' => esc_html__( 'Post content to generate a meta description for.', 'ai' ),
				),
				'title'   => array(
					'type'        => 'string',
					'description' => esc_html__( 'The post title, used to avoid duplication in the generated description.', 'ai' ),
				),
				'post_id' => array(
					'type'        => 'integer',
					'description' => esc_html__( 'The post ID to generate a meta description for. If provided without content, the post content will be used.', 'ai' ),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.7.0
	 */
	protected function output_schema(): array {
		return array(
			'type'        => 'object',
			'description' => esc_html__( 'Generated meta description suggestion.', 'ai' ),
			'properties'  => array(
				'description' => array(
					'type'        => 'object',
					'description' => esc_html__( 'The meta description suggestion.', 'ai' ),
					'properties'  => array(
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
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.7.0
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
			unset( $post_context['title'] );

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

		$description = $this->generate_description( $content, $title, $context );
		if ( is_wp_error( $description ) ) {
			return $description;
		}

		if ( empty( $description ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No meta description suggestion was generated.', 'ai' )
			);
		}

		return array( 'description' => $description );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.7.0
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
	 * @since 0.7.0
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Generate a meta description suggestion from the given content.
	 *
	 * @since 0.7.0
	 *
	 * @param string                       $content The content to generate a description from.
	 * @param string                       $title   The post title.
	 * @param string|array<string, string> $context Additional context to use.
	 * @return array{text: string, character_count: int}|\WP_Error The generated description, or a WP_Error.
	 */
	protected function generate_description( string $content, string $title, $context ) {
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

		$prompt  = '<title>' . $title . '</title>';
		$prompt .= '<content>' . $content . '</content>';

		if ( ! empty( $context ) ) {
			$prompt .= "\n\n<additional-context>" . $context . '</additional-context>';
		} else {
			$prompt .= "\n\n<additional-context></additional-context>";
		}

		/**
		 * Filters the prompt content sent to the AI model for meta description generation.
		 *
		 * Allows developers to modify or augment the content before it is sent to the model.
		 *
		 * @since 0.7.0
		 *
		 * @param string $prompt  The assembled prompt including content, title, and context tags.
		 * @param string $content The normalized post content.
		 * @param string $title   The post title.
		 */
		$prompt = (string) apply_filters( 'wpai_meta_description_prompt', $prompt, $content, $title );

		$builder = $this->get_prompt_builder( $prompt );

		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_string( $result ) || empty( trim( $result ) ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No meta description suggestion was generated.', 'ai' )
			);
		}

		$text = sanitize_text_field( trim( $result, ' "\'' ) );

		return array(
			'text'            => $text,
			'character_count' => mb_strlen( $text ),
		);
	}

	/**
	 * Returns a prompt builder for meta description generation.
	 *
	 * @since 0.7.0
	 *
	 * @param string $prompt The prompt to build.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error if there isn't a model that supports text generation.
	 */
	private function get_prompt_builder( string $prompt ) {
		/**
		 * Filters the temperature for the result of the meta description generation.
		 *
		 * @since 0.7.0
		 *
		 * @param float $result_temperature The temperature for the result of the meta description generation.
		 */
		$result_temperature = (float) apply_filters( 'wpai_meta_description_result_temperature', 0.7 );

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction() )
			->using_temperature( $result_temperature )
			->using_model_preference( ...get_preferred_models_for_text_generation() );

		// Return a more specific error if there isn't a model that supports text generation.
		if ( ! $builder->is_supported_for_text_generation() ) {
			return new WP_Error(
				'unsupported_model',
				esc_html__( 'Meta description generation failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
			);
		}

		return $builder;
	}
}

<?php
/**
 * Slug generation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Slug_Generation;

use WP_Error;
use WP_Post;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Slug_Generation\Slug_Generation as Slug_Generation_Experiment;

use function WordPress\AI\get_post_context;
use function WordPress\AI\normalize_content;

/**
 * Slug generation WordPress Ability.
 *
 * @since x.x.x
 */
class Slug_Generation extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'title'                 => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Title to generate slug suggestions for.', 'ai' ),
				),
				'content'               => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Content to generate slug suggestions for.', 'ai' ),
				),
				'context'               => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Additional context or post ID.', 'ai' ),
				),
				'number_of_suggestions' => array(
					'type'              => 'integer',
					'minimum'           => 1,
					'maximum'           => 10,
					'sanitize_callback' => 'absint',
					'default'           => 3,
					'description'       => esc_html__( 'Number of slug suggestions to return.', 'ai' ),
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
			'type'       => 'object',
			'properties' => array(
				'slugs' => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'description' => esc_html__( 'Generated slug suggestions.', 'ai' ),
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
				'title'                 => null,
				'content'               => null,
				'context'               => null,
				'number_of_suggestions' => 3,
			)
		);

		$post = null;
		if ( is_numeric( $args['context'] ) ) {
			$post_id = (int) $args['context'];
			$post    = get_post( $post_id );

			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), $post_id )
				);
			}

			// Fetch the post context when a numeric post ID is provided.
			$context      = get_post_context( $post->ID );
			$post_content = $context['content'] ?? '';
			$post_title   = $post->post_title;
			unset( $context['content'], $context['title'] );

			// Override with explicitly passed title or content if available.
			if ( $args['title'] ) {
				$post_title = sanitize_text_field( $args['title'] );
			}
			if ( $args['content'] ) {
				$post_content = normalize_content( $args['content'] );
			}
		} else {
			$post_content = normalize_content( $args['content'] ?? '' );
			$post_title   = sanitize_text_field( $args['title'] ?? '' );
			$context      = $args['context'] ?? '';
		}

		if ( empty( $post_title ) && empty( $post_content ) ) {
			return new WP_Error(
				'insufficient_data',
				esc_html__( 'Post title or content is required to generate slug suggestions.', 'ai' )
			);
		}

		// Build the prompt input with structured XML tags for title, content, and context.
		$prompt_input = '';
		if ( ! empty( $post_title ) ) {
			$prompt_input .= "<title>{$post_title}</title>\n\n";
		}
		if ( ! empty( $post_content ) ) {
			$prompt_input .= "<content>{$post_content}</content>";
		}
		if ( ! empty( $context ) ) {
			if ( is_array( $context ) ) {
				$context_lines = array();
				foreach ( $context as $key => $value ) {
					if ( is_array( $value ) ) {
						$value = implode( ', ', $value );
					}
					if ( is_string( $key ) && ! is_numeric( $key ) ) {
						$context_lines[] = "{$key}: {$value}";
					} else {
						$context_lines[] = (string) $value;
					}
				}
				$context = implode( "\n", $context_lines );
			}
			$prompt_input .= "\n\n<additional-context>{$context}</additional-context>";
		}

		$number_of_suggestions = min( max( (int) $args['number_of_suggestions'], 1 ), 10 );

		// Generate the slug suggestions from the AI model.
		$suggestions = $this->generate_slugs( $prompt_input, $context, $number_of_suggestions );

		if ( is_wp_error( $suggestions ) ) {
			return $suggestions;
		}

		if ( empty( $suggestions ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No slug suggestion was generated.', 'ai' )
			);
		}

		// Sanitize the suggestions into valid, unique WordPress slugs.
		$clean_slugs = array();
		foreach ( $suggestions as $suggestion ) {
			$clean_slug = sanitize_title( str_replace( '_', '-', $suggestion ) );
			if ( empty( $clean_slug ) ) {
				continue;
			}

			// Drop repeats from the model before spending a uniqueness lookup on them.
			if ( in_array( $clean_slug, $clean_slugs, true ) ) {
				continue;
			}

			$clean_slugs[] = $clean_slug;
		}

		$slugs = array();
		foreach ( $clean_slugs as $clean_slug ) {
			$slug = $post instanceof WP_Post ? $this->make_slug_unique( $clean_slug, $post ) : $clean_slug;

			if ( empty( $slug ) ) {
				continue;
			}

			$slugs[] = $slug;
		}

		$slugs = array_slice( array_values( array_unique( $slugs ) ), 0, $number_of_suggestions );

		if ( empty( $slugs ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No slug suggestion was generated.', 'ai' )
			);
		}

		return array(
			'slugs' => $slugs,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since x.x.x
	 */
	protected function permission_callback( $args ) {
		$post_id = isset( $args['context'] ) && is_numeric( $args['context'] ) ? absint( $args['context'] ) : null;

		if ( $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), $post_id )
				);
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to generate slugs for this post.', 'ai' )
				);
			}

			$post_type = get_post_type( $post_id );
			if ( ! $post_type ) {
				return false;
			}

			$post_type_obj = get_post_type_object( $post_type );
			if ( ! $post_type_obj || empty( $post_type_obj->show_in_rest ) ) {
				return false;
			}
		} elseif ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to generate slugs.', 'ai' )
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
	 * Makes a slug unique against existing content for the given post.
	 *
	 * @since x.x.x
	 *
	 * @param string   $slug The sanitized slug to make unique.
	 * @param \WP_Post $post The post the slug is being generated for.
	 * @return string The unique slug.
	 */
	private function make_slug_unique( string $slug, WP_Post $post ): string {
		$post_status = in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true )
			? 'publish'
			: $post->post_status;

		return wp_unique_post_slug(
			$slug,
			$post->ID,
			$post_status,
			$post->post_type,
			(int) $post->post_parent
		);
	}

	/**
	 * Generates slug suggestions from the prompt.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt                The prompt.
	 * @param mixed  $context               The context.
	 * @param int    $number_of_suggestions The number of suggestions.
	 * @return list<string>|\WP_Error The generated suggestions, or WP_Error.
	 */
	protected function generate_slugs( string $prompt, $context, int $number_of_suggestions ) {
		$prompt         = $this->filter_prompt( $prompt, $context );
		$prompt_builder = $this->get_prompt_builder( $prompt, $number_of_suggestions );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		$response = $prompt_builder->generate_text();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_slugs( (string) $response );
	}

	/**
	 * Decodes the structured slug response returned by the model.
	 *
	 * @since x.x.x
	 *
	 * @param string $response The raw JSON response.
	 * @return list<string>|\WP_Error The suggested slugs, or WP_Error if the response could not be parsed.
	 */
	private function parse_slugs( string $response ) {
		$decoded = json_decode( $response, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['slugs'] ) || ! is_array( $decoded['slugs'] ) ) {
			return new WP_Error(
				'invalid_response',
				esc_html__( 'Could not parse the AI response as slug suggestions.', 'ai' )
			);
		}

		return array_values( array_filter( $decoded['slugs'], 'is_string' ) );
	}

	/**
	 * Returns the JSON schema the model must respond with.
	 *
	 * @since x.x.x
	 *
	 * @return array<string, mixed> The response schema.
	 */
	protected function slugs_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'slugs' => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
					),
				),
			),
			'required'             => array( 'slugs' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Gets a prompt builder for generating slugs.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt                The prompt.
	 * @param int    $number_of_suggestions The number of suggestions.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or WP_Error.
	 */
	private function get_prompt_builder( string $prompt, int $number_of_suggestions ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction( null, array( 'number_of_suggestions' => $number_of_suggestions ) ) )
			->as_json_response( $this->slugs_schema() );

		$prompt_builder = $this->filter_prompt_builder( $prompt_builder, Slug_Generation_Experiment::class, array(), $prompt );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Slug generation failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}
}

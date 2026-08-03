<?php
/**
 * Slug generation WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Slug_Generation;

use WP_Error;
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

		$post_id = null;
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
			unset( $context['content'] );

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
				$context = implode( "\n", $context );
			}
			$prompt_input .= "\n\n<additional-context>{$context}</additional-context>";
		}

		$number_of_suggestions = (int) apply_filters( 'wpai_slug_generation_number_of_suggestions', (int) $args['number_of_suggestions'] );

		// Generate the raw slug suggestion text from the AI model.
		$result = $this->generate_slugs( $prompt_input, $context, $number_of_suggestions );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No slug suggestion was generated.', 'ai' )
			);
		}

		// Parse the output lines into clean, sanitized WordPress slugs.
		$lines = explode( "\n", $result );
		$slugs = array();
		foreach ( $lines as $line ) {
			$line = trim( $line, " \t\n\r\0\x0B\"'" );
			if ( empty( $line ) ) {
				continue;
			}

			$slugs[] = sanitize_title( $line );
		}

		$slugs = array_slice( array_unique( array_filter( $slugs ) ), 0, $number_of_suggestions );

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
	 * Generates slug suggestions from the prompt.
	 *
	 * @since x.x.x
	 *
	 * @param string $prompt                The prompt.
	 * @param mixed  $context               The context.
	 * @param int    $number_of_suggestions The number of suggestions.
	 * @return string|\WP_Error The generated suggestions, or WP_Error.
	 */
	protected function generate_slugs( string $prompt, $context, int $number_of_suggestions ) {
		$prompt         = $this->filter_prompt( $prompt, $context );
		$prompt_builder = $this->get_prompt_builder( $prompt, $number_of_suggestions );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		return $prompt_builder->generate_text();
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
			->using_temperature( 0.5 );

		$prompt_builder = $this->filter_prompt_builder( $prompt_builder, Slug_Generation_Experiment::class, array(), $prompt );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Slug generation failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}
}

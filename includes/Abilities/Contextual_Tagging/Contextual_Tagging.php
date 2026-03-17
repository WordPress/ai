<?php
/**
 * Contextual tagging WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Contextual_Tagging;

use WP_Error;
use WordPress\AI\Abstracts\Abstract_Ability;

use function WordPress\AI\get_post_context;
use function WordPress\AI\get_preferred_models_for_text_generation;
use function WordPress\AI\normalize_content;

/**
 * Contextual tagging WordPress Ability.
 *
 * Generates taxonomy term suggestions based on post content analysis.
 *
 * @since 0.6.0
 */
class Contextual_Tagging extends Abstract_Ability {

	/**
	 * The default number of suggestions to generate.
	 *
	 * @since 0.6.0
	 *
	 * @var int
	 */
	protected const SUGGESTIONS_DEFAULT = 5;

	/**
	 * Returns the input schema of the ability.
	 *
	 * @since 0.6.0
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content'         => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => esc_html__( 'Content to generate taxonomy suggestions for.', 'ai' ),
				),
				'post_id'         => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'Content from this post will be used to generate taxonomy suggestions. This overrides the content parameter if both are provided.', 'ai' ),
				),
				'taxonomy'        => array(
					'type'              => 'string',
					'default'           => 'post_tag',
					'sanitize_callback' => 'sanitize_key',
					'description'       => esc_html__( 'The taxonomy to generate suggestions for (e.g., post_tag, category).', 'ai' ),
				),
				'strategy'        => array(
					'type'              => 'string',
					'default'           => 'existing_only',
					'sanitize_callback' => 'sanitize_key',
					'description'       => esc_html__( 'The suggestion strategy: existing_only or allow_new.', 'ai' ),
				),
				'max_suggestions' => array(
					'type'              => 'integer',
					'minimum'           => 1,
					'maximum'           => 10,
					'default'           => self::SUGGESTIONS_DEFAULT,
					'sanitize_callback' => 'absint',
					'description'       => esc_html__( 'Maximum number of suggestions to generate.', 'ai' ),
				),
			),
		);
	}

	/**
	 * Returns the output schema of the ability.
	 *
	 * @since 0.6.0
	 *
	 * @return array<string, mixed> The output schema of the ability.
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'suggestions' => array(
					'type'        => 'array',
					'description' => esc_html__( 'Generated taxonomy term suggestions.', 'ai' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'term'       => array(
								'type'        => 'string',
								'description' => esc_html__( 'The suggested term name.', 'ai' ),
							),
							'confidence' => array(
								'type'        => 'number',
								'description' => esc_html__( 'Confidence score between 0 and 1.', 'ai' ),
							),
							'is_new'     => array(
								'type'        => 'boolean',
								'description' => esc_html__( 'Whether this is a new term or an existing one.', 'ai' ),
							),
							'parent'     => array(
								'type'        => 'string',
								'description' => esc_html__( 'Parent term name for hierarchical taxonomies.', 'ai' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Executes the ability with the given input arguments.
	 *
	 * @since 0.6.0
	 *
	 * @param mixed $input The input arguments to the ability.
	 * @return array{suggestions: array<array{term: string, confidence: float, is_new: bool, parent?: string}>}|\WP_Error The result of the ability execution, or a WP_Error on failure.
	 */
	protected function execute_callback( $input ) {
		// Default arguments.
		$args = wp_parse_args(
			$input,
			array(
				'content'         => null,
				'post_id'         => null,
				'taxonomy'        => 'post_tag',
				'strategy'        => 'existing_only',
				'max_suggestions' => self::SUGGESTIONS_DEFAULT,
			),
		);

		// Validate taxonomy.
		if ( ! taxonomy_exists( $args['taxonomy'] ) ) {
			return new WP_Error(
				'invalid_taxonomy',
				/* translators: %s: Taxonomy name. */
				sprintf( esc_html__( 'Taxonomy "%s" does not exist.', 'ai' ), sanitize_key( $args['taxonomy'] ) )
			);
		}

		// If a post ID is provided, ensure the post exists before using its content.
		if ( $args['post_id'] ) {
			$post = get_post( (int) $args['post_id'] );

			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), absint( $args['post_id'] ) )
				);
			}

			// Get the post context.
			$context = get_post_context( (int) $args['post_id'] );

			// Default to the passed in content if it exists.
			if ( $args['content'] ) {
				$context['content'] = normalize_content( $args['content'] );
			}
		} else {
			$context = array(
				'content' => normalize_content( $args['content'] ?? '' ),
			);
		}

		// If we have no content, return an error.
		if ( empty( $context['content'] ) ) {
			return new WP_Error(
				'content_not_provided',
				esc_html__( 'Content is required to generate taxonomy suggestions.', 'ai' )
			);
		}

		// Generate the suggestions.
		$result = $this->generate_suggestions(
			$context,
			$args['taxonomy'],
			$args['strategy'],
			(int) $args['max_suggestions']
		);

		// If we have an error, return it.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// If we have no results, return an error.
		if ( empty( $result ) ) {
			return new WP_Error(
				'no_results',
				esc_html__( 'No taxonomy suggestions were generated.', 'ai' )
			);
		}

		return array(
			'suggestions' => $result,
		);
	}

	/**
	 * Returns the permission callback of the ability.
	 *
	 * @since 0.6.0
	 *
	 * @param mixed $args The input arguments to the ability.
	 * @return bool|\WP_Error True if the user has permission, WP_Error otherwise.
	 */
	protected function permission_callback( $args ) {
		$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : null;

		if ( $post_id ) {
			$post = get_post( $args['post_id'] );

			// Ensure the post exists.
			if ( ! $post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), absint( $args['post_id'] ) )
				);
			}

			// Ensure the user has permission to edit this particular post.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to generate taxonomy suggestions for this post.', 'ai' )
				);
			}

			// Ensure the post type is allowed in REST endpoints.
			$post_type = get_post_type( $post_id );

			if ( ! $post_type ) {
				return false;
			}

			$post_type_obj = get_post_type_object( $post_type );

			if ( ! $post_type_obj || empty( $post_type_obj->show_in_rest ) ) {
				return false;
			}
		} elseif ( ! current_user_can( 'edit_posts' ) ) {
			// Ensure the user has permission to edit posts in general.
			return new WP_Error(
				'insufficient_capabilities',
				esc_html__( 'You do not have permission to generate taxonomy suggestions.', 'ai' )
			);
		}

		return true;
	}

	/**
	 * Returns the meta of the ability.
	 *
	 * @since 0.6.0
	 *
	 * @return array<string, mixed> The meta of the ability.
	 */
	protected function meta(): array {
		return array(
			'show_in_rest' => true,
		);
	}

	/**
	 * Generates taxonomy term suggestions from the given content.
	 *
	 * @since 0.6.0
	 *
	 * @param string|array<string, string> $context         The context to generate suggestions from.
	 * @param string                       $taxonomy        The taxonomy to suggest terms for.
	 * @param string                       $strategy        The suggestion strategy.
	 * @param int                          $max_suggestions The maximum number of suggestions.
	 * @return array<array{term: string, confidence: float, is_new: bool, parent?: string}>|\WP_Error The generated suggestions, or a WP_Error if there was an error.
	 */
	protected function generate_suggestions( $context, string $taxonomy, string $strategy, int $max_suggestions ) {
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

		// Fetch existing terms for the taxonomy.
		$existing_terms = $this->get_existing_terms( $taxonomy );

		// Build strategy instruction.
		$strategy_instruction = $this->build_strategy_instruction( $strategy );

		// Build existing terms instruction.
		$existing_terms_instruction = $this->build_existing_terms_instruction( $existing_terms, $strategy );

		// Get the taxonomy label for the prompt.
		$taxonomy_label = $this->get_taxonomy_label( $taxonomy );

		// Generate the suggestions using the AI client.
		$result = wp_ai_client_prompt( '"""' . $context . '"""' )
			->using_system_instruction(
				$this->get_system_instruction(
					null,
					array(
						'strategy'        => $strategy_instruction,
						'max_suggestions' => $max_suggestions,
						'taxonomy'        => $taxonomy_label,
						'existing_terms'  => $existing_terms_instruction,
					)
				)
			)
			->using_temperature( 0.5 )
			->using_model_preference( ...get_preferred_models_for_text_generation() )
			->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Parse the JSON response.
		return $this->parse_suggestions( $result, $existing_terms, $max_suggestions );
	}

	/**
	 * Gets existing terms for a taxonomy.
	 *
	 * @since 0.6.0
	 *
	 * @param string $taxonomy The taxonomy to get terms for.
	 * @return array<string> List of existing term names.
	 */
	protected function get_existing_terms( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'names',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return (array) $terms;
	}

	/**
	 * Builds the strategy instruction for the system prompt.
	 *
	 * @since 0.6.0
	 *
	 * @param string $strategy The suggestion strategy.
	 * @return string The strategy instruction text.
	 */
	protected function build_strategy_instruction( string $strategy ): string {
		if ( 'existing_only' === $strategy ) {
			return '- IMPORTANT: Only suggest terms that already exist on the site. Set "is_new" to false for all suggestions. Do not invent new terms.';
		}

		return '- You may suggest new terms if no good existing match exists. Set "is_new" to true for new terms and false for existing terms. Prefer existing terms when possible.';
	}

	/**
	 * Builds the existing terms instruction for the system prompt.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string> $existing_terms The existing terms.
	 * @param string        $strategy       The suggestion strategy.
	 * @return string The existing terms instruction text.
	 */
	protected function build_existing_terms_instruction( array $existing_terms, string $strategy ): string {
		if ( empty( $existing_terms ) ) {
			if ( 'existing_only' === $strategy ) {
				return '- No existing terms are available. Return an empty array.';
			}

			return '- No existing terms are available. You may suggest new terms.';
		}

		return sprintf(
			"- Existing terms on the site: %s\n- Prioritize selecting from these existing terms.",
			implode( ', ', $existing_terms )
		);
	}

	/**
	 * Gets a human-readable label for the taxonomy.
	 *
	 * @since 0.6.0
	 *
	 * @param string $taxonomy The taxonomy slug.
	 * @return string The taxonomy label.
	 */
	protected function get_taxonomy_label( string $taxonomy ): string {
		$taxonomy_obj = get_taxonomy( $taxonomy );

		if ( $taxonomy_obj ) {
			return strtolower( $taxonomy_obj->labels->name );
		}

		return $taxonomy;
	}

	/**
	 * Parses the AI response into structured suggestions.
	 *
	 * @since 0.6.0
	 *
	 * @param string        $response        The raw AI response.
	 * @param array<string> $existing_terms  List of existing term names.
	 * @param int           $max_suggestions The maximum number of suggestions.
	 * @return array<array{term: string, confidence: float, is_new: bool, parent?: string}>|\WP_Error Parsed suggestions or error.
	 */
	protected function parse_suggestions( string $response, array $existing_terms, int $max_suggestions ) {
		// Strip any markdown code fences the model may have included.
		$response = trim( $response );
		$response = preg_replace( '/^```(?:json)?\s*/i', '', $response ) ?? $response;
		$response = preg_replace( '/\s*```$/', '', $response ) ?? $response;
		$response = trim( $response );

		$decoded = json_decode( $response, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'invalid_response',
				esc_html__( 'Could not parse AI response as valid suggestions.', 'ai' )
			);
		}

		$existing_terms_lower = array_map( 'strtolower', $existing_terms );
		$suggestions          = array();

		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) || empty( $item['term'] ) ) {
				continue;
			}

			$term       = sanitize_text_field( trim( $item['term'] ) );
			$confidence = isset( $item['confidence'] ) ? (float) $item['confidence'] : 0.5;
			$is_new     = ! in_array( strtolower( $term ), $existing_terms_lower, true );

			$suggestion = array(
				'term'       => $term,
				'confidence' => max( 0.0, min( 1.0, $confidence ) ),
				'is_new'     => $is_new,
			);

			if ( ! empty( $item['parent'] ) ) {
				$suggestion['parent'] = sanitize_text_field( trim( $item['parent'] ) );
			}

			$suggestions[] = $suggestion;
		}

		// Sort by confidence descending.
		usort(
			$suggestions,
			static function ( $a, $b ) {
				return $b['confidence'] <=> $a['confidence'];
			}
		);

		// Limit to max suggestions.
		return array_slice( $suggestions, 0, $max_suggestions );
	}
}

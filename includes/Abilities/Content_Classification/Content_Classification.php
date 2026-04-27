<?php
/**
 * Content classification WordPress Ability implementation.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI\Abilities\Content_Classification;

use WP_Error;
use WP_Post;
use WP_Post_Type;
use WordPress\AI\Abstracts\Abstract_Ability;
use WordPress\AI\Experiments\Content_Classification\Content_Classification as Content_Classification_Experiment;

use function WordPress\AI\get_post_context;
use function WordPress\AI\get_preferred_models_for_text_generation;
use function WordPress\AI\normalize_content;

/**
 * Content classification WordPress Ability.
 *
 * Generates taxonomy term suggestions based on post content analysis.
 *
 * @since 0.7.0
 */
class Content_Classification extends Abstract_Ability {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.8.0
	 */
	protected function guideline_categories(): array {
		return array( 'site', 'copy' );
	}

	/**
	 * Returns the input schema of the ability.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, mixed> The input schema of the ability.
	 */
	protected function input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content'         => array(
					'type'        => 'string',
					'description' => esc_html__( 'Content to generate taxonomy suggestions for.', 'ai' ),
				),
				'post_id'         => array(
					'type'        => 'integer',
					'description' => esc_html__( 'Content from this post will be used to generate taxonomy suggestions. This overrides the content parameter if both are provided.', 'ai' ),
				),
				'taxonomy'        => array(
					'type'        => 'string',
					'default'     => 'post_tag',
					'description' => esc_html__( 'The taxonomy to generate suggestions for (e.g., post_tag, category).', 'ai' ),
				),
				'strategy'        => array(
					'type'        => 'string',
					'default'     => Content_Classification_Experiment::STRATEGY_EXISTING_ONLY,
					'description' => esc_html__( 'The suggestion strategy: existing_only or allow_new.', 'ai' ),
				),
				'max_suggestions' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 10,
					'default'     => Content_Classification_Experiment::DEFAULT_MAX_SUGGESTIONS,
					'description' => esc_html__( 'Maximum number of suggestions to generate.', 'ai' ),
				),
			),
		);
	}

	/**
	 * Returns the output schema of the ability.
	 *
	 * @since 0.7.0
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
	 * @since 0.7.0
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
				'strategy'        => Content_Classification_Experiment::STRATEGY_EXISTING_ONLY,
				'max_suggestions' => (int) Content_Classification_Experiment::DEFAULT_MAX_SUGGESTIONS,
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

		$assigned_terms = array();

		// If a post ID is provided, ensure the post exists before using its content.
		if ( $args['post_id'] ) {
			$post = get_post( (int) $args['post_id'] );

			if ( ! $post instanceof WP_Post ) {
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

			// Get terms already assigned to this post for the taxonomy.
			$assigned = wp_get_object_terms( (int) $args['post_id'], $args['taxonomy'], array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $assigned ) ) {
				$assigned_terms = (array) $assigned;
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
			(int) $args['max_suggestions'],
			$assigned_terms
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
	 * @since 0.7.0
	 *
	 * @param mixed $args The input arguments to the ability.
	 * @return bool|\WP_Error True if the user has permission, WP_Error otherwise.
	 */
	protected function permission_callback( $args ) {
		$post_id = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : null;

		if ( $post_id ) {
			$post = get_post( $post_id );

			// Ensure the post exists.
			if ( ! $post instanceof WP_Post ) {
				return new WP_Error(
					'post_not_found',
					/* translators: %d: Post ID. */
					sprintf( esc_html__( 'Post with ID %d not found.', 'ai' ), $post_id )
				);
			}

			// Ensure the user has permission to edit this particular post.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'insufficient_capabilities',
					esc_html__( 'You do not have permission to generate taxonomy suggestions for this post.', 'ai' )
				);
			}

			$post_type_obj = get_post_type_object( $post->post_type );
			if ( ! $post_type_obj instanceof WP_Post_Type || empty( $post_type_obj->show_in_rest ) ) {
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
	 * @since 0.7.0
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
	 * The LLM generates suggestions based purely on content analysis
	 * and the currently assigned terms. Post-processing then matches
	 * suggestions against existing terms and applies the strategy.
	 *
	 * @since 0.7.0
	 *
	 * @param string|array<string, string> $context         The context to generate suggestions from.
	 * @param string                       $taxonomy        The taxonomy to suggest terms for.
	 * @param string                       $strategy        The suggestion strategy.
	 * @param int                          $max_suggestions The maximum number of suggestions.
	 * @param array<string>                $assigned_terms  Terms already assigned to the post.
	 * @return array<array{term: string, confidence: float, is_new: bool, parent?: string}>|\WP_Error The generated suggestions, or a WP_Error if there was an error.
	 */
	protected function generate_suggestions( $context, string $taxonomy, string $strategy, int $max_suggestions, array $assigned_terms = array() ) {
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

		// When using existing_only strategy, send the top terms to the LLM
		// so it can select from actual terms rather than guessing.
		$available_terms = array();
		if ( Content_Classification_Experiment::STRATEGY_EXISTING_ONLY === $strategy ) {
			$available_terms = $this->get_top_terms( $taxonomy );
		}

		// Piece together the various prompt parts.
		$prompt_parts = array();

		$prompt_parts[] = '<taxonomy>' . $taxonomy . '</taxonomy>';
		$prompt_parts[] = '<content>' . $context . '</content>';

		// If we have currently assigned terms, add them to the prompt to avoid redundant suggestions.
		if ( ! empty( $assigned_terms ) ) {
			$prompt_parts[] = '<assigned-terms>' . implode( ', ', $assigned_terms ) . '</assigned-terms>';
		}

		// If we're using the existing_only strategy, add the top 100 terms to the prompt.
		if ( ! empty( $available_terms ) ) {
			$prompt_parts[] = '<available-terms>' . implode( ', ', $available_terms ) . '</available-terms>';
		}

		$prompt = implode( "\n", $prompt_parts );

		/**
		 * Filters the prompt string before it is sent to the AI model for taxonomy suggestion generation.
		 *
		 * Allows developers to modify, augment, or replace the prompt that the AI analyzes
		 * when generating taxonomy term suggestions.
		 *
		 * @since 0.7.0
		 *
		 * @param string                       $prompt          The prompt string to be sent to the AI model.
		 * @param string|array<string, string> $context         The context to generate suggestions from.
		 * @param string                       $taxonomy        The taxonomy slug being suggested for (e.g., 'post_tag', 'category').
		 * @param array<string>                $assigned_terms  Terms already assigned to the post.
		 * @param array<string>                $available_terms Available terms to suggest from.
		 */
		$prompt = (string) apply_filters( 'wpai_content_classification_prompt', $prompt, $context, $taxonomy, $assigned_terms, $available_terms );

		$prompt_builder = $this->get_prompt_builder( $prompt );

		if ( is_wp_error( $prompt_builder ) ) {
			return $prompt_builder;
		}

		// Generate the suggestions using the AI client with structured output.
		$result = $prompt_builder->generate_text();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Parse, match against existing terms, filter, and limit.
		$suggestions = $this->parse_suggestions( $result, $strategy, $assigned_terms, $taxonomy, $max_suggestions );

		if ( is_wp_error( $suggestions ) ) {
			return $suggestions;
		}

		/**
		 * Filters the parsed taxonomy suggestions before they are returned to the client.
		 *
		 * Allows developers to modify, reorder, add, or remove suggestions after the AI
		 * has generated them and they have been parsed into structured data.
		 *
		 * Each suggestion is an associative array with the keys:
		 * - 'term'       (string) The suggested term name.
		 * - 'confidence' (float)  Confidence score between 0 and 1.
		 * - 'is_new'     (bool)   Whether the term is new or already exists on the site.
		 * - 'parent'     (string) Optional. Parent term name for hierarchical taxonomies.
		 *
		 * @since 0.7.0
		 *
		 * @param array<array{term: string, confidence: float, is_new: bool, parent?: string}> $suggestions    The parsed suggestions.
		 * @param string                                                                       $taxonomy       The taxonomy slug (e.g., 'post_tag', 'category').
		 * @param string                                                                       $strategy       The suggestion strategy ('existing_only' or 'allow_new').
		 */
		return (array) apply_filters( 'wpai_content_classification_suggestions', $suggestions, $taxonomy, $strategy );
	}

	/**
	 * Get the prompt builder for generating taxonomy term suggestions.
	 *
	 * @since 0.7.0
	 *
	 * @param string $prompt The prompt to use for generating taxonomy term suggestions.
	 * @return \WP_AI_Client_Prompt_Builder|\WP_Error The prompt builder, or a WP_Error on failure.
	 */
	private function get_prompt_builder( string $prompt ) {
		$prompt_builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->get_system_instruction() )
			->using_temperature( 0.5 )
			->using_model_preference( ...get_preferred_models_for_text_generation() )
			->as_json_response( $this->suggestions_schema() );

		return $this->ensure_text_generation_supported(
			$prompt_builder,
			esc_html__( 'Term generation failed. Please ensure you have a connected provider that supports text generation.', 'ai' )
		);
	}

	/**
	 * Returns the JSON schema for structured output from the AI model.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string, mixed> The JSON schema for structured output.
	 */
	protected function suggestions_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'suggestions' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'term'       => array( 'type' => 'string' ),
							'confidence' => array( 'type' => 'number' ),
						),
						'required'             => array( 'term', 'confidence' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'suggestions' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Parses the AI response into structured suggestions.
	 *
	 * Matches LLM suggestions against existing terms (case-insensitive),
	 * filters out assigned terms, applies the strategy, sorts by confidence,
	 * and limits to the requested number of suggestions.
	 *
	 * @since 0.7.0
	 *
	 * @param string        $response        The raw AI response.
	 * @param string        $strategy        The suggestion strategy ('existing_only' or 'allow_new').
	 * @param array<string> $assigned_terms  Terms already assigned to the post.
	 * @param string        $taxonomy        The taxonomy to suggest terms for.
	 * @param int           $max_suggestions The maximum number of suggestions to return.
	 * @return array<array{term: string, confidence: float, is_new: bool, parent?: string}>|\WP_Error Parsed suggestions or error.
	 */
	private function parse_suggestions( string $response, string $strategy, array $assigned_terms, string $taxonomy, int $max_suggestions ) {
		$decoded = json_decode( $response, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['suggestions'] ) || ! is_array( $decoded['suggestions'] ) ) {
			return new WP_Error(
				'invalid_response',
				esc_html__( 'Could not parse AI response as valid suggestions.', 'ai' )
			);
		}

		// Only fetch existing terms when we need them for post-processing (existing_only strategy).
		$existing_terms = Content_Classification_Experiment::STRATEGY_EXISTING_ONLY === $strategy
			? $this->get_existing_terms( $taxonomy )
			: array();

		// Build a lowercase → original name lookup for existing terms.
		// We don't use slugs here because the LLM may generate terms that don't match the taxonomy slug.
		if ( ! empty( $existing_terms ) ) {
			$existing_terms = array_combine( array_map( 'strtolower', $existing_terms ), $existing_terms );
		}

		// Build a lowercase set of assigned terms for filtering.
		$assigned_terms = array_map( 'strtolower', $assigned_terms );
		$suggestions    = array();
		foreach ( $decoded['suggestions'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['term'] ) ) {
				continue;
			}

			$term       = sanitize_text_field( trim( $item['term'] ) );
			$term_lower = strtolower( $term );
			$is_new     = ! isset( $existing_terms[ $term_lower ] );
			$confidence = isset( $item['confidence'] ) ? (float) $item['confidence'] : 0.5;

			// Skip terms already assigned to the post.
			// The agent should avoid suggesting these, but just in case we'll check here as well.
			if ( in_array( $term_lower, $assigned_terms, true ) ) {
				continue;
			}

			// For existing_only strategy, skip terms that don't exist.
			if ( Content_Classification_Experiment::STRATEGY_EXISTING_ONLY === $strategy && $is_new ) {
				continue;
			}

			// Use the original capitalized name for existing terms.
			if ( ! $is_new ) {
				$term = $existing_terms[ $term_lower ];
			}

			$suggestion = array(
				'term'       => $term,
				'confidence' => max( 0.0, min( 1.0, $confidence ) ),
				'is_new'     => $is_new,
			);

			// Only preserve parent for hierarchical taxonomies, and strip it
			// when the AI returns the taxonomy slug itself as the parent.
			if (
				! empty( $item['parent'] )
				&& is_taxonomy_hierarchical( $taxonomy )
				&& strtolower( trim( $item['parent'] ) ) !== strtolower( $taxonomy )
			) {
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

	/**
	 * Gets existing terms for a taxonomy.
	 *
	 * @since 0.7.0
	 *
	 * @param string $taxonomy The taxonomy to get terms for.
	 * @return array<string> List of existing term names.
	 */
	private function get_existing_terms( string $taxonomy ): array {
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
	 * Gets the top terms for a taxonomy, ordered by usage count.
	 *
	 * Used to provide the LLM with a set of existing terms to select from
	 * when using the existing_only strategy, improving match quality.
	 *
	 * @since 0.7.0
	 *
	 * @param string $taxonomy The taxonomy to get terms for.
	 * @param int    $limit    Maximum number of terms to return.
	 * @return array<string> List of term names ordered by count descending.
	 */
	private function get_top_terms( string $taxonomy, int $limit = 100 ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'names',
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => $limit,
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		return (array) $terms;
	}
}

<?php
/**
 * Helper functions for the AI plugin.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI;

use WordPress\AI\Services\AI_Service;

/**
 * Normalizes the content by cleaning it and removing unwanted HTML tags.
 *
 * @since 0.1.0
 *
 * @param string $content The content to normalize.
 * @return string The normalized content.
 */
function normalize_content( string $content ): string {
	/**
	 * Hook to filter content before cleaning it.
	 *
	 * @since 0.1.0
	 * @hook ai_pre_normalize_content
	 *
	 * @param string $post_content The post content.
	 *
	 * @return string The filtered Post content.
	 */
	$content = (string) apply_filters( 'ai_pre_normalize_content', $content );

	// Strip HTML entities.
	$content = preg_replace( '/&#?[a-z0-9]{2,8};/i', '', $content );

	// Replace HTML linebreaks with newlines.
	$content = preg_replace( '#<br\s?/?>#', "\n\n", (string) $content );

	// Strip all HTML tags.
	$content = wp_strip_all_tags( (string) $content );

	// Remove unrendered shortcode tags.
	$content = preg_replace( '#\[.+\](.+)\[/.+\]#', '$1', $content );

	/**
	 * Filters the normalized content to allow for additional cleanup.
	 *
	 * @since 0.1.0
	 * @hook ai_normalize_content
	 *
	 * @param string $content The normalized content.
	 *
	 * @return string The filtered normalized content.
	 */
	$content = (string) apply_filters( 'ai_normalize_content', (string) $content );

	return trim( $content );
}

/**
 * Returns the context for the given post ID.
 *
 * @since 0.1.0
 *
 * @param int $post_id The ID of the post to get the context for.
 * @return array<string, string> The context for the given post ID.
 */
function get_post_context( int $post_id ): array {
	$context = array();

	// Get the post details using the get-post-details ability.
	$details_ability = wp_get_ability( 'ai/get-post-details' );
	if ( $details_ability ) {
		$details = $details_ability->execute( array( 'post_id' => $post_id ) );

		if ( is_array( $details ) ) {
			$context = array_merge( $context, $details );

			if ( isset( $context['content'] ) ) {
				$context['content'] = normalize_content( (string) apply_filters( 'the_content', $context['content'] ) );
			}

			if ( isset( $context['title'] ) ) {
				$context['current_title'] = $context['title'];
				unset( $context['title'] );
			}

			if ( isset( $context['type'] ) ) {
				$context['content_type'] = $context['type'];
				unset( $context['type'] );
			}
		}
	}

	// Get the post terms using the get-terms ability.
	$terms_ability = wp_get_ability( 'ai/get-post-terms' );
	if ( $terms_ability ) {
		$terms = $terms_ability->execute( array( 'post_id' => $post_id ) );

		if ( $terms && ! is_wp_error( $terms ) ) {
			$grouped_terms = array();

			foreach ( $terms as $term ) {
				$grouped_terms[ $term->taxonomy ][] = $term->name;
			}

			$context = array_merge(
				$context,
				array_map(
					static fn( array $term_names ): string => implode( ', ', $term_names ),
					$grouped_terms
				)
			);
		}
	}

	return $context;
}

/**
 * Returns the preferred models for text generation.
 *
 * @since 0.1.0
 *
 * @return array<int, array{string, string}> The preferred models for text generation.
 */
function get_preferred_models_for_text_generation(): array {
	$preferred_models = array(
		array(
			'anthropic',
			'claude-haiku-4-5',
		),
		array(
			'google',
			'gemini-2.5-flash',
		),
		array(
			'openai',
			'gpt-4o-mini',
		),
		array(
			'openai',
			'gpt-4.1',
		),
	);

	/**
	 * Filters the preferred models for text generation.
	 *
	 * @since 0.1.0
	 * @hook ai_preferred_models_for_text_generation
	 *
	 * @param array<int, array{string, string}> $preferred_models The preferred models for text generation.
	 * @return array<int, array{string, string}> The filtered preferred models.
	 */
	return (array) apply_filters( 'ai_preferred_models_for_text_generation', $preferred_models );
}

/**
 * Gets the AI Service instance.
 *
 * Provides a convenient way to access the AI Service for performing AI operations.
 *
 * Example usage:
 * ```php
 * $service = WordPress\AI\get_ai_service();
 *
 * // Check if text generation is supported before generating
 * $builder = $service->create_prompt( 'Summarize this article...' );
 * if ( ! $builder->is_supported_for_text_generation() ) {
 *     return new WP_Error( 'ai_unsupported', 'No AI provider supports text generation.' );
 * }
 * $text = $builder->generate_text();
 *
 * // With options array
 * $text = $service->create_prompt( 'Translate to French: Hello', array(
 *     'system_instruction' => 'You are a translator.',
 *     'temperature'        => 0.3,
 * ) )->generate_text();
 *
 * // Chain additional SDK methods
 * $titles = $service->create_prompt( 'Generate titles for: My blog post' )
 *     ->using_candidate_count( 5 )
 *     ->generate_texts();
 * ```
 *
 * @since 0.1.0
 *
 * @return \WordPress\AI\Services\AI_Service The AI Service instance.
 */
function get_ai_service(): AI_Service {
	return AI_Service::get_instance();
}

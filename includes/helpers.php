<?php
/**
 * Helper functions for the AI plugin.
 *
 * @package WordPress\AI
 */

declare( strict_types=1 );

namespace WordPress\AI;

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

	// Get the post content using the get-content ability.
	$content_ability = wp_get_ability( 'ai/get-content' );
	$content         = $content_ability->execute( array( 'post_id' => $post_id ) );

	if ( $content && ! is_wp_error( $content ) ) {
		$context['content'] = normalize_content( (string) apply_filters( 'the_content', $content ) );
	}

	// Get the post title using the get-title ability.
	$title_ability = wp_get_ability( 'ai/get-title' );
	$title         = $title_ability->execute( array( 'post_id' => $post_id ) );

	if ( $title && ! is_wp_error( $title ) ) {
		$context['current_title'] = $title;
	}

	/**
	 * TODO: Might be interesting to add simple Abilities for the following,
	 * just as a way to demonstrate a different approach to registering Abilities,
	 * how to call Abilities via PHP and how multiple Abilities can be used together.
	 *
	 * Example: Get post content Ability; get post author Ability; get post terms Ability.
	 */

	if ( $post->post_name ) {
		$context['slug'] = $post->post_name;
	}

	$author = get_user_by( 'ID', $post->post_author );
	if ( $author ) {
		$context['author'] = $author->display_name;
	}

	if ( $post->post_type ) {
		$context['content_type'] = $post->post_type;
	}

	if ( $post->post_excerpt ) {
		$context['excerpt'] = $post->post_excerpt;
	}

	$categories = get_the_terms( $post_id, 'category' );
	if ( $categories && ! is_wp_error( $categories ) ) {
		$context['categories'] = implode( ', ', wp_list_pluck( $categories, 'name' ) );
	}

	$tags = get_the_terms( $post_id, 'post_tag' );
	if ( $tags && ! is_wp_error( $tags ) ) {
		$context['tags'] = implode( ', ', wp_list_pluck( $tags, 'name' ) );
	}

	return $context;
}

/**
 * Returns the preferred models.
 *
 * @since 0.1.0
 *
 * @return array<int, array{string, string}> The preferred models.
 */
function get_preferred_models(): array {
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
	 * Filters the preferred models.
	 *
	 * @since 0.1.0
	 * @hook ai_preferred_models
	 *
	 * @param array<int, array{string, string}> $preferred_models The preferred models.
	 * @return array<int, array{string, string}> The filtered preferred models.
	 */
	return (array) apply_filters( 'ai_preferred_models', $preferred_models );
}

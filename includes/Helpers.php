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
	$post    = get_post( $post_id );
	$context = array();

	// If the post doesn't exist, return early.
	if ( ! $post ) {
		return $context;
	}

	if ( $post->post_content ) {
		$context['content'] = normalize_content( (string) apply_filters( 'the_content', $post->post_content ) );
	}

	if ( $post->post_title ) {
		$context['current_title'] = $post->post_title;
	}

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

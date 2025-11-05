<?php
/**
 * Helper functions for the AI plugin.
 *
 * @package WordPress\AI
 */

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
	$content = apply_filters( 'ai_normalize_content', $content );

	return trim( (string) $content );
}

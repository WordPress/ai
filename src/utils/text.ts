/**
 * Collection of text utilities.
 */

/**
 * WordPress dependencies
 */
import { cleanForSlug } from '@wordpress/url';

/**
 * Trims a string to a given length, truncating at word boundaries.
 *
 * @param {string} text   The text to trim.
 * @param {number} length The maximum length of the text.
 * @return {string} The trimmed text.
 */
export function trimText( text: string, length: number = 80 ): string {
	if ( text.length <= length ) {
		return text;
	}

	// Try to truncate at word boundary
	const truncated = text.substring( 0, length );
	const lastSpace = truncated.lastIndexOf( ' ' );

	// Use word boundary if it's not too short (at least 50% of length)
	return lastSpace > length * 0.5
		? truncated.substring( 0, lastSpace )
		: truncated;
}

/**
 * Builds a filename-safe slug from free-form text.
 *
 * @param {string} text   The text to slugify.
 * @param {number} length The maximum length of the slug.
 * @return {string} The slugified text, or an empty string.
 */
export function slugifyForFilename(
	text: string,
	length: number = 75
): string {
	const slug = cleanForSlug( text );

	if ( slug.length <= length ) {
		return slug;
	}

	const truncated = slug.substring( 0, length );
	const lastHyphen = truncated.lastIndexOf( '-' );

	// Prefer a hyphen boundary when it's not too short (at least 50% of length).
	return lastHyphen > length * 0.5
		? truncated.substring( 0, lastHyphen )
		: truncated;
}

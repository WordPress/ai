/**
 * Shared word count utilities.
 *
 * Provides a standardized way to count content length across all features,
 * respecting the user's locale for word/character-based counting.
 */

/**
 * WordPress dependencies
 */
import { count as wordCount } from '@wordpress/wordcount';

/**
 * Counts the content length in characters excluding spaces.
 *
 * @param {string} content The content to count.
 *
 * @return {number} The content count in characters excluding spaces.
 */
export function getContentCount( content: string ): number {
	return wordCount( content, 'characters_excluding_spaces' );
}

/**
 * Checks if the content meets the minimum length requirement.
 *
 * @param {string} content  The content to check.
 * @param {number} minCount The minimum count required.
 *
 * @return {boolean} Whether the content meets the minimum length.
 */
export function hasMinimumContent(
	content: string,
	minCount: number
): boolean {
	return getContentCount( content ) >= minCount;
}

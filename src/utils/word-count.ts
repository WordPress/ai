/**
 * Shared word count utilities.
 *
 * Provides a standardized way to count content length across all features,
 * respecting the user's locale for word/character-based counting.
 */

/**
 * WordPress dependencies
 */
import { _x, sprintf } from '@wordpress/i18n';
import { count as wordCount, type Strategy } from '@wordpress/wordcount';

/**
 * Returns the word count type based on the user's locale.
 *
 * Uses the default (core) text domain so the word count type stays consistent
 * with WordPress core's behavior.
 *
 * @return {Strategy} The word count strategy.
 */
export function getWordCountType(): Strategy {
	/*
	 * translators: If your word count is based on single characters (e.g. East Asian characters),
	 * enter 'characters_excluding_spaces' or 'characters_including_spaces'. Otherwise, enter 'words'.
	 * Do not translate into your own language.
	 */
	// eslint-disable-next-line @wordpress/i18n-text-domain
	return _x( 'words', 'Word count type. Do not translate!' ) as Strategy;
}

/**
 * Counts the content length using the locale-appropriate strategy.
 *
 * @param {string} content The content to count.
 *
 * @return {number} The content count (words or characters based on locale).
 */
export function getContentCount( content: string ): number {
	return wordCount( content, getWordCountType() );
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

/**
 * Formats a minimum-length tooltip label for AI feature buttons.
 *
 * Picks between a characters-based and a words-based message depending on
 * the active word-count strategy, then interpolates the minimum count.
 *
 * @param {string} characterMessage Already-translated message for character-count locales. Must contain one %d placeholder.
 * @param {string} wordMessage      Already-translated message for word-count locales. Must contain one %d placeholder.
 * @param {number} minCount         The minimum count to interpolate into the message.
 *
 * @return {string} The formatted label.
 */
export function formatMinLengthLabel(
	characterMessage: string,
	wordMessage: string,
	minCount: number
): string {
	// eslint-disable-next-line @wordpress/valid-sprintf
	return sprintf(
		getWordCountType() !== 'words' ? characterMessage : wordMessage,
		minCount
	);
}

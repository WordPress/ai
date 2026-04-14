/**
 * Utility functions for text.
 */

/**
 * Internal dependencies
 */
import { LEADING_WHITESPACE_REGEX, WHITESPACE_REGEX } from '../constants';

/**
 * Converts HTML to plain text.
 *
 * @param {string} value The HTML to convert.
 * @return {string} The plain text.
 */
export const htmlToPlainText = ( value?: string ): string => {
	if ( ! value ) {
		return '';
	}

	const temp = document.createElement( 'div' );
	temp.innerHTML = value;

	return ( temp.textContent || temp.innerText || '' ).replaceAll(
		'\u00A0',
		' '
	);
};

/**
 * Determines if a context should trigger a type-ahead suggestion.
 *
 * @param {string} preceding The preceding text.
 * @return {boolean} Whether the context should trigger a type-ahead suggestion.
 */
export const shouldTriggerFromContext = ( preceding: string ): boolean => {
	const trimmed = preceding.trimEnd();

	if ( ! trimmed ) {
		return false;
	}

	const lastChar = trimmed.slice( -1 );
	if ( [ '.', '?', '!', ':' ].includes( lastChar ) ) {
		return true;
	}

	const lower = trimmed.toLowerCase();
	return lower.endsWith( 'such as' ) || lower.endsWith( 'for example' );
};

/**
 * Splits a suggestion into a word or sentence.
 *
 * @param {string} suggestion The suggestion to split.
 * @param {string} mode       The mode to split the suggestion into.
 * @return {Object} The split suggestion.
 */
export const splitSuggestion = (
	suggestion: string,
	mode: 'word' | 'sentence' | 'all'
): { apply: string; remainder: string } => {
	if ( mode === 'all' ) {
		return { apply: suggestion, remainder: '' };
	}

	if ( mode === 'word' ) {
		const match = suggestion.match( /^\s*\S+\s*/ );
		const chunk = match ? match[ 0 ] : suggestion;

		return {
			apply: chunk,
			remainder: suggestion.slice( chunk.length ),
		};
	}

	const sentenceMatch = suggestion.match( /^(.*?[\.!?](?:\s|$))/ );
	const sentence = sentenceMatch ? sentenceMatch[ 0 ] : suggestion;

	return {
		apply: sentence,
		remainder: suggestion.slice( sentence.length ),
	};
};

/**
 * Adds a leading space to a text if needed.
 *
 * @param {string} text          The text to add a leading space to.
 * @param {string} precedingText The preceding text.
 * @return {string} The text with a leading space.
 */
export const addLeadingSpaceIfNeeded = (
	text: string,
	precedingText: string
): string => {
	if ( ! text || ! precedingText ) {
		return text;
	}

	const lastChar = precedingText.slice( -1 );
	if ( ! lastChar || WHITESPACE_REGEX.test( lastChar ) ) {
		return text;
	}

	if ( LEADING_WHITESPACE_REGEX.test( text ) ) {
		return text;
	}

	return ` ${ text }`;
};

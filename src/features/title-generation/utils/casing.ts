/**
 * Casing transformation utilities for title generation.
 *
 * @package WordPress\AI
 */

/**
 * Converts a title to sentence case (first letter capitalized, rest lowercase).
 *
 * @param {string} title - The title to transform.
 * @return {string} The title in sentence case.
 */
export function toSentenceCase( title: string ): string {
	if ( ! title || title.trim().length === 0 ) {
		return title;
	}

	const trimmed = title.trim();
	return trimmed.charAt( 0 ).toUpperCase() + trimmed.slice( 1 ).toLowerCase();
}

/**
 * Converts a title to title case (major words capitalized).
 *
 * Articles, prepositions, and conjunctions are lowercase unless they're the first word.
 *
 * @param {string} title - The title to transform.
 * @return {string} The title in title case.
 */
export function toTitleCase( title: string ): string {
	if ( ! title || title.trim().length === 0 ) {
		return title;
	}

	// Words that should remain lowercase (unless they're the first word)
	const lowercaseWords = [
		'a',
		'an',
		'the',
		'and',
		'but',
		'or',
		'nor',
		'for',
		'so',
		'yet',
		'at',
		'by',
		'in',
		'of',
		'on',
		'to',
		'up',
		'as',
		'is',
		'if',
		'it',
		'from',
		'with',
		'into',
		'onto',
		'over',
		'under',
		'after',
		'before',
		'during',
		'through',
		'via',
	];

	const words = title.trim().split( /\s+/ );
	const titleCaseWords = words.map( ( word, index ) => {
		// Remove punctuation for comparison
		const wordLower = word.toLowerCase().replace( /[^\w]/g, '' );

		// Always capitalize first word, or if word is not in lowercase list
		if ( index === 0 || ! lowercaseWords.includes( wordLower ) ) {
			return capitalizeWord( word );
		}

		return word.toLowerCase();
	} );

	return titleCaseWords.join( ' ' );
}

/**
 * Capitalizes the first letter of a word while preserving the rest.
 *
 * @param {string} word - The word to capitalize.
 * @return {string} The capitalized word.
 */
function capitalizeWord( word: string ): string {
	if ( ! word || word.length === 0 ) {
		return word;
	}

	return word.charAt( 0 ).toUpperCase() + word.slice( 1 ).toLowerCase();
}

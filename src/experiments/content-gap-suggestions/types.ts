/**
 * Type definitions for Content Gap Suggestions.
 */

/**
 * A single post topic suggestion returned by the ai/content-gap-suggestions ability.
 */
export interface ContentGapSuggestion {
	title: string;
	outline: string;
}

/**
 * Output shape of the ai/content-gap-suggestions ability.
 */
export interface ContentGapSuggestionsAbilityOutput {
	suggestions: ContentGapSuggestion[];
}

/**
 * Localized data from the PHP side.
 */
export interface ContentGapSuggestionsData {
	enabled: boolean;
	widgetRoot: string;
	postEditBaseUrl: string;
}

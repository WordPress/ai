/**
 * Type definitions for content classification experiment.
 */

/**
 * Input parameters for the ai/content-classification ability.
 */
export interface ContentClassificationAbilityInput {
	content: string;
	post_id: number;
	taxonomy: string;
	strategy: string;
	max_suggestions: number;
	[ key: string ]: string | number | undefined;
}

/**
 * A single taxonomy term suggestion from the AI.
 */
export interface TagSuggestion {
	term: string;
	confidence: number;
	is_new: boolean;
	parent?: string;
}

/**
 * Response from the ai/content-classification ability.
 */
export interface ContentClassificationResponse {
	suggestions: TagSuggestion[];
}

/**
 * Localized data from the PHP side.
 */
export interface ContentClassificationData {
	enabled: boolean;
	strategy: string;
	maxSuggestions: number;
}

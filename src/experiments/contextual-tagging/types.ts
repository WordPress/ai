/**
 * Type definitions for contextual tagging experiment.
 */

/**
 * Input parameters for the ai/contextual-tagging ability.
 */
export interface ContextualTaggingAbilityInput {
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
 * Response from the ai/contextual-tagging ability.
 */
export interface ContextualTaggingResponse {
	suggestions: TagSuggestion[];
}

/**
 * Localized data from the PHP side.
 */
export interface ContextualTaggingData {
	enabled: boolean;
	strategy: string;
	maxSuggestions: number;
}

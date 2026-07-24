/**
 * Type definitions for slug generation.
 */

/**
 * Input parameters for the ai/slug-generation ability.
 */
export interface SlugGenerationAbilityInput {
	title: string;
	content: string;
	context: string;
	number_of_suggestions?: number;
	[ key: string ]: string | number | undefined;
}

/**
 * Response from the ai/slug-generation ability.
 */
export interface GeneratedSlugData {
	slugs: string[];
}

/**
 * Localized data from the PHP side.
 */
export interface SlugGenerationData {
	enabled: boolean;
	minContentLength: number;
	numberOfSuggestions: number;
}

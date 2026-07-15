/**
 * Type definitions for excerpt generation.
 */

/**
 * Input parameters for the ai/excerpt-generation ability.
 */
export interface ExcerptGenerationAbilityInput {
	content: string;
	context: string;
	[ key: string ]: string | undefined;
}

/**
 * Localized data from the PHP side.
 */
export interface ExcerptGenerationData {
	enabled: boolean;
	minContentLength: number;
}

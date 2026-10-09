/**
 * Type definitions for summarization.
 */

/**
 * Input parameters for the ai/summarization ability.
 */
export interface SummarizationAbilityInput {
	content: string;
	context: string;
	[ key: string ]: string | undefined;
}

/**
 * Localized data from the PHP side.
 */
export interface SummarizationData {
	enabled: boolean;
	minContentLength: number;
}

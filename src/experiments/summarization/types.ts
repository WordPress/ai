/**
 * Type definitions for summarization experiment.
 */

/**
 * Input parameters for the ai/summarization ability.
 */
export interface SummarizationAbilityInput {
	content: string;
	context: string;
	[ key: string ]: string | undefined;
}

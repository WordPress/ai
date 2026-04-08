/**
 * Type definitions for title generation.
 */

/**
 * Input parameters for the ai/title-generation ability.
 */
export interface TitleGenerationAbilityInput {
	content: string;
	context: string;
	[ key: string ]: string | number | undefined;
}

/**
 * Response from the ai/title-generation ability.
 */
export interface GeneratedTitleData {
	title: string;
}

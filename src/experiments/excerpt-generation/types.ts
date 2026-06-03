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

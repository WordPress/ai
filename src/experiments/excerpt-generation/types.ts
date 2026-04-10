/**
 * Type definitions for excerpt generation.
 */

/**
 * Input parameters for the ai/excerpt-generation ability.
 */
export interface ExcerptGenerationAbilityInput {
	content: string;
	post_id: number;
	[ key: string ]: string | number | undefined;
}

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
	/**
	 * Whether the editor renders the excerpt with the Entity Fields API
	 * (WordPress 7.2+), in which case the `@ai/excerpt-field` script module
	 * adds the generate buttons instead of the legacy excerpt panel plugins.
	 */
	entityFieldsApi?: boolean;
}

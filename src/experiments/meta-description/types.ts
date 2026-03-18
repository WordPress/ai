/**
 * Type definitions for the meta description experiment.
 */

/**
 * Input parameters for the ai/meta-description ability.
 */
export interface MetaDescriptionAbilityInput {
	content: string;
	title: string;
	post_id: number;
	[ key: string ]: string | number | undefined;
}

/**
 * A single meta description suggestion returned by the ability.
 */
export interface MetaDescriptionSuggestion {
	text: string;
	character_count: number;
}

/**
 * Response from the ai/meta-description ability.
 */
export interface MetaDescriptionAbilityResponse {
	descriptions: MetaDescriptionSuggestion[];
}

/**
 * Localized data available on `window.aiMetaDescriptionData`.
 */
export interface MetaDescriptionData {
	enabled: boolean;
	metaKey: string;
	seoPlugin: string | null;
}

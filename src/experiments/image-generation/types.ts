/**
 * Type definitions for image generation experiment.
 */

/**
 * Input parameters for the ai/image-import ability.
 */
export interface ImageImportAbilityInput {
	data: string;
	filename?: string;
	title?: string;
	description?: string;
	alt_text?: string;
	mime_type?: string;
	meta?: {
		key: string;
		value: string;
	}[];
	[ key: string ]:
		| string
		| number
		| { key: string; value: string }[]
		| undefined;
}

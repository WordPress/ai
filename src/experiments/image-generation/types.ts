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

/**
 * Input parameters for the ai/image-generation ability.
 */
export interface ImageGenerationAbilityInput {
	prompt: string;
	[ key: string ]: string | undefined;
}

/**
 * Input parameters for the ai/image-prompt-generation ability.
 */
export interface ImagePromptGenerationAbilityInput {
	content: string;
	context?: string;
	style?: string;
	[ key: string ]: string | undefined;
}

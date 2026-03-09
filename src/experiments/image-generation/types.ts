/**
 * Type definitions for image generation experiment.
 */

/**
 * Provider metadata from image generation API.
 */
export interface ProviderMetadata {
	id: string;
	name: string;
	type: string;
}

/**
 * Model metadata from image generation API.
 */
export interface ModelMetadata {
	id: string;
	name: string;
}

/**
 * Generated image data (image part of generation result).
 */
export interface GeneratedImage {
	data: string;
	provider_metadata: ProviderMetadata;
	model_metadata: ModelMetadata;
}

/**
 * Result from generateImage / input for uploadImage.
 */
export interface GeneratedImageData {
	image: GeneratedImage;
	prompt: string;
}

/**
 * Result from uploadImage (imported image in media library).
 */
export interface UploadedImage {
	id: number;
	url: string;
	title: string;
}

/**
 * Post context from getContext (title, type, optional content).
 */
export interface PostContext {
	title: string;
	type: string;
	content?: string;
	[ key: string ]: string | undefined;
}

/**
 * Object shape for formatContext (key-value record).
 */
export type ContextRecord = Record< string, string | undefined >;

/**
 * Props for the AILabel component.
 */
export interface AILabelProps {
	label: string;
}

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
	reference?: string;
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

/**
 * Input parameters for the ai/get-post-details ability.
 */
export interface GetPostDetailsAbilityInput {
	post_id: number;
	fields?: string[];
	[ key: string ]: string | number | string[] | undefined;
}

/**
 * Callback type for image generation progress messages.
 */
export type ImageProgressCallback = ( message: string ) => void;

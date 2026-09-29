/**
 * Type definitions for alt text generation.
 */

/**
 * Input parameters for the ai/alt-text-generation ability.
 */
export interface AltTextGenerationAbilityInput {
	attachment_id?: number;
	image_url?: string;
	context?: string;
	image_meta?: string;
	[ key: string ]: string | number | undefined;
}

/**
 * Image block attributes interface.
 */
export interface ImageBlockAttributes {
	id?: number;
	url?: string;
	alt?: string;
	caption?: string | undefined;
	title?: string;
	href?: string | undefined;
	rel?: string | undefined;
	linkClass?: string;
	linkDestination?: string | undefined;
	linkTarget?: string | undefined;
	width?: number;
	height?: number;
	sizeSlug?: string;
	align?: string;
	isDecorative?: boolean | undefined;
}

/**
 * Minimal shape of an attachment entity record as exposed to the
 * Gutenberg Media Editor's DataForm.
 */
export interface MediaEditorAttachment {
	id?: number;
	alt_text?: string;
	source_url?: string;
	mime_type?: string;
	media_type?: string;
}

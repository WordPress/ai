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
	caption?: string;
	title?: string;
	href?: string;
	rel?: string;
	linkClass?: string;
	linkDestination?: string;
	linkTarget?: string;
	width?: number;
	height?: number;
	sizeSlug?: string;
	align?: string;
}

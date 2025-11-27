/**
 * Type definitions for alt text generation experiment.
 */

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

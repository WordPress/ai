/**
 * Type definitions for slug generation.
 */

/**
 * Input parameters for the ai/slug-generation ability.
 */
export interface SlugGenerationAbilityInput {
	title: string;
	content: string;
	context: string;
	number_of_suggestions?: number;
	[ key: string ]: string | number | undefined;
}

/**
 * Response from the ai/slug-generation ability.
 */
export interface GeneratedSlugData {
	slugs: string[];
}

/**
 * The post fields a slug generation request is built from.
 */
export interface SlugSource {
	/**
	 * The post ID, as reported by `getCurrentPostId()`. Null for posts that have
	 * not been saved yet.
	 */
	postId: string | number | null;
	title: string;
	content: string;
}

/**
 * Localized data from the PHP side.
 */
export interface SlugGenerationData {
	enabled: boolean;
	minContentLength: number;
	numberOfSuggestions: number;
}

declare global {
	interface Window {
		/**
		 * Raw localized data. `wp_localize_script()` casts scalars to strings, so these
		 * are read through `getSettings()` rather than used directly.
		 */
		aiSlugGenerationData?: Partial<
			Record< keyof SlugGenerationData, unknown >
		>;
	}
}

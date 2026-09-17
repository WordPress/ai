/**
 * SEO plugin editor adapters for applying and reading the meta description.
 *
 * The active SEO plugin is detected server-side and passed to the client as
 * `window.aiMetaDescriptionData.seoPlugin`. Adapter selection is keyed by that slug.
 *
 * The default adapter writes to core/editor post meta, which works for the fallback meta
 * key (registered for all REST post types) and for any SEO plugin whose meta key is
 * REST-registered for the current post type. Plugin-specific adapters instead target the
 * plugin's own editor store so the value updates the plugin UI and persists on every post
 * type — including pages and CPTs, where a plugin may expose its meta to REST only for the
 * `post` post type.
 */

/**
 * WordPress dependencies
 */
import { dispatch } from '@wordpress/data';
import type { SelectFunction } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { applyFilters } from '@wordpress/hooks';

/**
 * Context available when reading the current description.
 */
export interface ReadContext {
	metaKey: string;
}

/**
 * Context available when applying a description.
 */
export interface ApplyContext extends ReadContext {
	meta: Record< string, string > | undefined;
	editPost: ( edits: { meta: Record< string, string > } ) => unknown;
}

/**
 * Encapsulates how to write and read a meta description for a given SEO plugin.
 */
export interface SeoEditorAdapter {
	/**
	 * Writes the description so the SEO plugin UI updates and the value persists on save.
	 *
	 * @param text Description to apply.
	 * @param ctx  Apply context.
	 */
	apply: ( text: string, ctx: ApplyContext ) => void;

	/**
	 * Reads the currently stored description for display.
	 *
	 * @param select The `useSelect` select function.
	 * @param ctx    Read context.
	 * @return The current description, or an empty string.
	 */
	read: ( select: SelectFunction, ctx: ReadContext ) => string;
}

const YOAST_STORE = 'yoast-seo/editor';

/**
 * Default adapter: stores the description in core/editor post meta.
 */
export const defaultAdapter: SeoEditorAdapter = {
	apply: ( text, { metaKey, meta, editPost } ) => {
		editPost( { meta: { ...meta, [ metaKey ]: text } } );
	},
	read: ( select, { metaKey } ) => {
		const meta = select( editorStore ).getEditedPostAttribute( 'meta' ) as
			| Record< string, string >
			| undefined;
		return meta?.[ metaKey ] ?? '';
	},
};

/**
 * Yoast adapter: writes to the `yoast-seo/editor` store.
 *
 * Yoast exposes `_yoast_wpseo_metadesc` to REST only for the `post` post type, so core/editor
 * meta cannot round-trip on pages or CPTs. Writing to Yoast's own store updates the snippet
 * editor UI and persists via Yoast's metabox save on every post type. The extra `editPost`
 * marks the editor dirty so the Save button enables (and persists via REST on `post`).
 */
export const yoastAdapter: SeoEditorAdapter = {
	apply: ( text, { metaKey, meta, editPost } ) => {
		try {
			const yoast = dispatch( YOAST_STORE ) as unknown as
				| { updateData?: ( data: { description: string } ) => void }
				| undefined;
			yoast?.updateData?.( { description: text } );
		} catch {
			// Yoast store not registered; the editPost below still applies the value.
		}

		// Mark the editor dirty on every post type; also persists via REST on `post`.
		editPost( { meta: { ...meta, [ metaKey ]: text } } );
	},
	read: ( select, { metaKey } ) => {
		try {
			const yoast = select( YOAST_STORE ) as unknown as
				| { getSnippetEditorDescription?: () => string }
				| undefined;
			if ( typeof yoast?.getSnippetEditorDescription === 'function' ) {
				return yoast.getSnippetEditorDescription() ?? '';
			}
		} catch {
			// Fall through to the core/editor meta fallback below.
		}

		return defaultAdapter.read( select, { metaKey } );
	},
};

/**
 * Registry of SEO plugin adapters, keyed by the plugin slug from `SEO_Integration`.
 */
const ADAPTERS: Record< string, SeoEditorAdapter > = {
	'yoast-seo': yoastAdapter,
};

/**
 * Returns the editor adapter for the active SEO plugin, or the default adapter.
 *
 * @param slug The active SEO plugin slug, or null when none is detected.
 * @return The matching adapter.
 */
export function getAdapter( slug: string | null ): SeoEditorAdapter {
	/**
	 * Filters the SEO plugin editor adapters.
	 *
	 * Lets third parties register a store-based adapter for an SEO plugin. Plugins registered
	 * only via the PHP `wpai_meta_description_seo_plugins` filter fall back to the default
	 * (core/editor meta) adapter.
	 *
	 * @param {Record<string, SeoEditorAdapter>} adapters Map of plugin slug to adapter.
	 */
	const adapters = applyFilters(
		'ai.metaDescription.seoAdapters',
		ADAPTERS
	) as Record< string, SeoEditorAdapter >;

	return ( slug && adapters[ slug ] ) || defaultAdapter;
}

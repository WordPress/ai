/**
 * Global window type augmentation for the Internal Links experiment.
 *
 * `wp_localize_script` serialises every PHP value as a JSON string, so
 * numeric fields arrive as strings at runtime even though the PHP side
 * passes integers. Declaring them as `string` here lets TypeScript enforce
 * the necessary parseInt()/Number() coercions at every call site.
 */
declare global {
	interface Window {
		aiInternalLinksData?: {
			enabled: boolean;
			minContentLength: string;
			maxSuggestions: string;
		};
	}
}

export {};

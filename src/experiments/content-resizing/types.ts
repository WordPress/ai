export type ContentResizingAction = 'shorten' | 'expand' | 'rephrase';

/**
 * Input parameters for the ai/content-resizing ability.
 */
export interface ContentResizingAbilityInput {
	post_id: number;
	content: string;
	action: ContentResizingAction;
	[ key: string ]: string | number | undefined;
}

/**
 * Localized data from the PHP side.
 */
export interface ContentResizingData {
	enabled: boolean;
	minContentLength: number;
}

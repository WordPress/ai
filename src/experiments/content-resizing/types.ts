export type ContentResizingAction = 'shorten' | 'expand' | 'rephrase';

export interface ContentResizingAbilityInput {
	content: string;
	action: ContentResizingAction;
}

/**
 * Localized data from the PHP side.
 */
export interface ContentResizingData {
	enabled: boolean;
	minContentLength: number;
}

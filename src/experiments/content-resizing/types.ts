export type ContentResizingAction = 'shorten' | 'expand' | 'rephrase';

export interface ContentResizingAbilityInput {
	content: string;
	action: ContentResizingAction;
}

/**
 * Type definitions for type-ahead.
 */

export type CompletionMode = 'word' | 'sentence' | 'paragraph' | 'smart';

export type TypeAheadSettings = {
	enabled: boolean;
	completionMode: CompletionMode;
	triggerDelay: number;
	confidence: number;
	showHeadings: boolean;
	maxWords: number;
};

/**
 * Localized data from the PHP side.
 *
 * wp_localize_script() casts scalar values to strings, so numeric and boolean
 * fields may arrive as strings until normalized for runtime use.
 */
export type TypeAheadLocalizedData = {
	enabled?: boolean | string;
	completionMode?: CompletionMode;
	triggerDelay?: number | string;
	confidence?: number | string;
	showHeadings?: boolean | string;
	maxWords?: number | string;
};

export type Suggestion = {
	text: string;
	confidence: number;
};

export type CaretData = {
	offset: number;
	rect: DOMRect | null;
	precedingText: string;
	ownerDocument: Document;
};

export type TypeAheadResponse = {
	suggestion?: string;
	confidence?: number;
};

export type TypeAheadAbilityInput = {
	post_id: number;
	block_content: string;
	preceding_text: string;
	following_text: string;
	surrounding_context: string;
	cursor_position: number;
	mode: CompletionMode;
	max_words: number;
	manual_trigger: boolean;
};

declare global {
	interface Window {
		aiTypeAheadData?: TypeAheadLocalizedData;
	}
}

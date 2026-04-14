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
		aiTypeAheadData?: TypeAheadSettings;
	}
}

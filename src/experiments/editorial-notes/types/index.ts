export interface BlockAttributes {
	content?: string;
	value?: string;
	alt?: string;
	caption?: string;
	metadata?: {
		noteId?: number | number[];
		[ key: string ]: unknown;
	};
	[ key: string ]: unknown;
}

export interface Block {
	clientId: string;
	name: string;
	attributes: BlockAttributes;
	innerBlocks: Block[];
}

export interface Suggestion {
	review_type: string;
	text: string;
}

export interface ReviewResult {
	suggestions: Suggestion[];
}

export interface NoteRecord {
	id: number;
	[ key: string ]: unknown;
}

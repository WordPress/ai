/**
 * Types shared by table caption generation controls and requests.
 */
export interface TableCellAttributes {
	content?: unknown;
	tag?: TableCell[ 'tag' ];
	scope?: TableCell[ 'scope' ];
	colspan?: string | number;
	rowspan?: string | number;
}

export interface TableAttributes {
	head?: { cells: TableCellAttributes[] }[];
	body?: { cells: TableCellAttributes[] }[];
	foot?: { cells: TableCellAttributes[] }[];
	caption?: unknown;
}

export interface TableCell {
	content: string;
	tag: 'td' | 'th';
	scope?: 'row' | 'col' | 'rowgroup' | 'colgroup';
	colspan?: number;
	rowspan?: number;
}

export interface TableData {
	head: TableCell[][];
	body: TableCell[][];
	foot: TableCell[][];
}

export interface TableCaptionSettings {
	enabled: boolean;
}

/** Primitives keep useSelect results stable when unrelated editor state changes. */
export interface TableSnapshot {
	postId: number;
	table: string;
	caption: string;
	title: string;
	context: string;
}

export interface CaptionProposal {
	text: string;
	source: TableSnapshot;
}

declare global {
	interface Window {
		aiTableCaptionGenerationData?: Partial< {
			[ Key in keyof TableCaptionSettings ]:
				| TableCaptionSettings[ Key ]
				| string;
		} >;
	}
}

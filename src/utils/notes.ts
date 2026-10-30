/**
 * Shared utilities for Notes-related experiments (Editorial Notes, Editorial Updates).
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Block types that can be reviewed and refined.
 */
export const REVIEWABLE_BLOCK_TYPES = [
	'core/paragraph',
	'core/heading',
	'core/list-item',
	'core/verse',
	'core/image',
	'core/table',
	'core/preformatted',
	'core/pullquote',
];

/** Number of Notes to fetch per page when paginating. */
export const NOTES_PAGE_SIZE = 100;
const CONTEXT_WINDOW_SIZE = 800;
const TRUNCATED_BEFORE_MARKER = '[TRUNCATED BEFORE]';
const TRUNCATED_AFTER_MARKER = '[TRUNCATED AFTER]';

/** A WordPress comment of type "note" as returned by the REST API. */
export interface ExistingNote {
	id: number;
	parent: number;
	content: { rendered: string };
	meta?: {
		wpai_note?: boolean;
		[ key: string ]: unknown;
	};
	[ key: string ]: unknown;
}

/** WordPress comment status used when querying Notes. */
export type NoteStatus = 'hold' | 'approve';

/**
 * Fetches all Notes by status for a given post.
 *
 * @param postId The ID of the post to fetch Notes for.
 * @param status The status of the Notes to fetch.
 * @return An array of Notes.
 */
export async function fetchAllNotesByStatus(
	postId: number,
	status: NoteStatus
): Promise< ExistingNote[] > {
	const notes: ExistingNote[] = [];
	let page = 1;

	while ( true ) {
		try {
			const pageNotes = await apiFetch< ExistingNote[] >( {
				path: `/wp/v2/comments?type=note&status=${ status }&post=${ postId }&per_page=${ NOTES_PAGE_SIZE }&page=${ page }`,
				method: 'GET',
			} );

			notes.push( ...pageNotes );

			if ( pageNotes.length < NOTES_PAGE_SIZE ) {
				return notes;
			}

			page += 1;
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.warn(
				`[AI Notes] Failed to fetch ${ status } Notes page ${ page }:`,
				error
			);
			return notes;
		}
	}
}

/**
 * Returns a bounded context window around a placeholder token.
 *
 * @param content     The full content with placeholder.
 * @param placeholder The placeholder token.
 * @return A truncated content window centered around the placeholder.
 */
export function buildContextWindow(
	content: string,
	placeholder: string
): string {
	const placeholderIndex = content.indexOf( placeholder );

	if (
		placeholderIndex === -1 ||
		content.length <= CONTEXT_WINDOW_SIZE * 2
	) {
		return content;
	}

	const roughStart = Math.max( 0, placeholderIndex - CONTEXT_WINDOW_SIZE );
	const roughEnd = Math.min(
		content.length,
		placeholderIndex + placeholder.length + CONTEXT_WINDOW_SIZE
	);

	const isBoundaryChar = ( char: string ) => /\s/.test( char );

	// Move inward to the nearest word boundary so we don't cut mid-word.
	let start = roughStart;
	if ( start > 0 && ! isBoundaryChar( content.charAt( start - 1 ) ) ) {
		while (
			start < roughEnd &&
			! isBoundaryChar( content.charAt( start ) )
		) {
			start += 1;
		}
	}

	let end = roughEnd;
	if ( end < content.length && ! isBoundaryChar( content.charAt( end ) ) ) {
		while ( end > start && ! isBoundaryChar( content.charAt( end - 1 ) ) ) {
			end -= 1;
		}
	}

	const prefix = start > 0 ? `${ TRUNCATED_BEFORE_MARKER }\n` : '';
	const suffix = end < content.length ? `\n${ TRUNCATED_AFTER_MARKER }` : '';

	return `${ prefix }${ content.slice( start, end ) }${ suffix }`;
}

/**
 * Normalizes block `metadata.noteId` to an array of unique positive Note IDs.
 *
 * @param metadata        The block's metadata attribute.
 * @param metadata.noteId The raw note ID or array of note IDs from the block's metadata.
 * @return An array of Note IDs (may be empty).
 */
export function getNoteIds( metadata?: { noteId?: unknown } ): number[] {
	const raw = metadata?.noteId;
	const ids = ( Array.isArray( raw ) ? raw : [ raw ] )
		.map( Number )
		.filter( ( id ) => Number.isFinite( id ) && id > 0 );
	return [ ...new Set( ids ) ];
}

/**
 * Checks whether block `metadata.noteId` uses the array format written by cores that support multiple Note threads per block.
 *
 * @param metadata        The block's metadata attribute.
 * @param metadata.noteId The raw note ID or array of note IDs from the block's metadata.
 * @return True when `noteId` is an array.
 */
export function hasNoteIdList( metadata?: { noteId?: unknown } ): boolean {
	return Array.isArray( metadata?.noteId );
}

/**
 * Returns block metadata with a Note ID added, keeping the single-ID format unless the block already uses an array.
 *
 * @param metadata The block's metadata attribute.
 * @param noteId   The Note ID to add.
 * @return The updated metadata object.
 */
export function addNoteId(
	metadata: Record< string, unknown > = {},
	noteId: number
): Record< string, unknown > {
	const ids = getNoteIds( metadata );

	if ( ids.includes( noteId ) ) {
		return metadata;
	}

	return {
		...metadata,
		noteId: hasNoteIdList( metadata ) ? [ ...ids, noteId ] : noteId,
	};
}

/**
 * Collects the root and reply texts of the given pending Note threads.
 *
 * @param noteIds         The root Note IDs attached to the block.
 * @param noteContentById Map of Note ID → rendered Note content (pending only).
 * @param pendingNotes    All pending Notes for the post.
 * @return An array of Note texts, each root followed by its replies.
 */
export function collectThreadTexts(
	noteIds: number[],
	noteContentById: Map< number, string >,
	pendingNotes: ExistingNote[]
): string[] {
	const texts: string[] = [];

	for ( const id of noteIds ) {
		const rootText = noteContentById.get( id );
		if ( rootText ) {
			texts.push( rootText );
		}

		// Also collect replies (Notes with parent === id).
		for ( const note of pendingNotes ) {
			if ( note.parent === id ) {
				const replyText = noteContentById.get( note.id );
				if ( replyText ) {
					texts.push( replyText );
				}
			}
		}
	}

	return texts;
}

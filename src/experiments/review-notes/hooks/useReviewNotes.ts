/**
 * Custom hook for AI Review Notes functionality.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as coreStore } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import {
	flattenBlocks,
	getBlockText,
	replaceBlockWithPlaceholder,
} from '../../../utils/blocks';
import { runAbility } from '../../../utils/run-ability';

const REVIEWABLE_BLOCK_TYPES = [
	'core/paragraph',
	'core/heading',
	'core/list-item',
	'core/verse',
	'core/image',
	'core/table',
	'core/preformatted',
	'core/pullquote',
];

const BLOCK_PLACEHOLDER = '[[BLOCK_GOES_HERE]]';

const BATCH_SIZE = 4;
const NOTES_PAGE_SIZE = 100;
const CONTEXT_WINDOW_SIZE = 2000;
const TRUNCATED_BEFORE_MARKER = '[TRUNCATED BEFORE]';
const TRUNCATED_AFTER_MARKER = '[TRUNCATED AFTER]';

interface BlockAttributes {
	content?: string;
	value?: string;
	alt?: string;
	caption?: string;
	metadata?: {
		noteId?: number;
		[ key: string ]: unknown;
	};
	[ key: string ]: unknown;
}

interface Block {
	clientId: string;
	name: string;
	attributes: BlockAttributes;
	innerBlocks: Block[];
}

interface Suggestion {
	review_type: string;
	text: string;
}

interface ReviewResult {
	suggestions: Suggestion[];
}

interface NoteRecord {
	id: number;
	[ key: string ]: unknown;
}

interface ExistingNote {
	id: number;
	parent: number;
	content: { rendered: string };
	[ key: string ]: unknown;
}

type NoteStatus = 'hold' | 'approve';

/**
 * Hook for AI Review Notes functionality.
 *
 * @return Object with review state and the runReview handler.
 */
export function useReviewNotes(): {
	isReviewing: boolean;
	progress: number;
	total: number;
	lastRunCount: number | null;
	runReview: () => Promise< void >;
} {
	const [ isReviewing, setIsReviewing ] = useState< boolean >( false );
	const [ progress, setProgress ] = useState< number >( 0 );
	const [ total, setTotal ] = useState< number >( 0 );
	const [ lastRunCount, setLastRunCount ] = useState< number | null >( null );

	const runReview = async () => {
		setIsReviewing( true );
		setProgress( 0 );
		setTotal( 0 );
		setLastRunCount( null );

		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_review_notes_error'
		);

		try {
			const postId = (
				select( editorStore ) as any
			 ).getCurrentPostId() as number;
			const content = (
				select( editorStore ) as any
			 ).getEditedPostContent() as string;

			// Get all blocks and flatten the tree.
			const allBlocks = (
				select( blockEditorStore ) as any
			 ).getBlocks() as Block[];
			const flatBlocks = flattenBlocks( allBlocks );

			// Filter to reviewable block types.
			const reviewableBlocks = flatBlocks.filter( ( block ) =>
				REVIEWABLE_BLOCK_TYPES.includes( block.name )
			);

			if ( reviewableBlocks.length === 0 ) {
				setLastRunCount( 0 );
				return;
			}

			setTotal( reviewableBlocks.length );

			// Fetch pending and resolved notes for this post in parallel.
			// Pending (hold) notes are used as context to avoid repeating suggestions.
			// Resolved (approve) note IDs are used to skip already-acknowledged blocks.
			const [ pendingNotes, approvedNotes ] = await Promise.all( [
				fetchAllNotesByStatus( postId, 'hold' ),
				fetchAllNotesByStatus( postId, 'approve' ),
			] );
			const resolvedNoteIds = new Set(
				approvedNotes.map( ( n ) => n.id )
			);

			// Build a lookup: noteId → note content text (pending notes only).
			const noteContentById = new Map< number, string >();
			for ( const note of pendingNotes ) {
				noteContentById.set( note.id, note.content?.rendered ?? '' );
			}

			let totalSuggestions = 0;

			// Process blocks in batches.
			for (
				let batchStart = 0;
				batchStart < reviewableBlocks.length;
				batchStart += BATCH_SIZE
			) {
				const batch = reviewableBlocks.slice(
					batchStart,
					batchStart + BATCH_SIZE
				);

				await Promise.all(
					batch.map( async ( block ) => {
						// Look up any existing note thread on this block.
						const existingNoteId =
							block.attributes.metadata?.noteId ?? null;

						// Skip blocks whose note thread has been resolved.
						if (
							existingNoteId &&
							resolvedNoteIds.has( existingNoteId )
						) {
							return;
						}

						const blockText = getBlockText( block );

						if ( blockText.length === 0 ) {
							return;
						}

						// Collect pending note texts for this block's thread as context.
						const existingNoteTexts: string[] = [];
						if ( existingNoteId ) {
							const rootText =
								noteContentById.get( existingNoteId );
							if ( rootText ) {
								existingNoteTexts.push( rootText );
							}

							// Also collect replies (notes with parent === existingNoteId).
							for ( const note of pendingNotes ) {
								if ( note.parent === existingNoteId ) {
									const replyText = noteContentById.get(
										note.id
									);
									if ( replyText ) {
										existingNoteTexts.push( replyText );
									}
								}
							}
						}

						// Replace the block with the placeholder.
						const contentWithPlaceholder =
							block.clientId !== undefined
								? replaceBlockWithPlaceholder(
										content,
										block.clientId,
										BLOCK_PLACEHOLDER
								  )
								: content;

						// Prepare a bounded context around the placeholder.
						const contextWindow = buildContextWindow(
							contentWithPlaceholder,
							BLOCK_PLACEHOLDER
						);
						const context = `What follows is surrounding article content, where the block being reviewed has been replaced with the placeholder ${ BLOCK_PLACEHOLDER }. Use the nearby text to better understand the context of the block within the article. CONTENT: \n\n${ contextWindow }`;

						// Call the review ability.
						const result = await runAbility< ReviewResult >(
							'ai/review-notes',
							{
								block_type: block.name,
								block_content: blockText,
								context,
								post_id: postId,
								existing_notes: existingNoteTexts,
							}
						).catch( () => null );

						if (
							result?.suggestions &&
							result.suggestions.length > 0
						) {
							await createNote(
								block,
								postId,
								result.suggestions,
								existingNoteId
							);
							totalSuggestions += result.suggestions.length;
						}
					} )
				);

				setProgress(
					Math.min( batchStart + BATCH_SIZE, reviewableBlocks.length )
				);
			}

			setLastRunCount( totalSuggestions );

			if ( totalSuggestions > 0 ) {
				(
					dispatch( coreStore ) as any
				 ).invalidateResolutionForStoreSelector( 'getEntityRecords' );
			}
		} catch ( error: any ) {
			( dispatch( noticesStore ) as any ).createErrorNotice(
				error?.message ?? String( error ),
				{
					id: 'ai_review_notes_error',
					isDismissible: true,
				}
			);
		} finally {
			setIsReviewing( false );
		}
	};

	return { isReviewing, progress, total, lastRunCount, runReview };
}

/**
 * Fetches all notes by status for a given post.
 *
 * @param postId The ID of the post to fetch notes for.
 * @param status The status of the notes to fetch.
 * @return An array of notes.
 */
async function fetchAllNotesByStatus(
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
				`[AI Review Notes] Failed to fetch ${ status } notes page ${ page }:`,
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
function buildContextWindow( content: string, placeholder: string ): string {
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
 * Creates a note (or appends a reply) for a reviewed block.
 *
 * When no existing note thread is present, creates a new note and updates
 * the block's metadata with the resulting note ID. When a thread already
 * exists, appends a reply to preserve the conversation history.
 *
 * @param block          The block that received suggestions.
 * @param postId         The current post ID.
 * @param suggestions    The suggestions to include in the note.
 * @param existingNoteId The ID of an existing note thread, or null for a new thread.
 */
async function createNote(
	block: Block,
	postId: number,
	suggestions: Suggestion[],
	existingNoteId: number | null
): Promise< void > {
	const noteContent = suggestions
		.map( ( s ) => `[${ s.review_type.toUpperCase() }] ${ s.text }` )
		.join( '\n\n' );

	const note = ( await ( dispatch( coreStore ) as any ).saveEntityRecord(
		'root',
		'comment',
		{
			post: postId,
			content: noteContent,
			type: 'note',
			status: 'hold',
			parent: existingNoteId ?? 0,
			meta: { ai_note: true },
		}
	) ) as NoteRecord | undefined;

	// Only update block metadata when creating a new thread (not a reply).
	if ( ! existingNoteId && note?.id ) {
		const existingMeta =
			( select( blockEditorStore ) as any ).getBlockAttributes(
				block.clientId
			)?.metadata ?? {};

		( dispatch( blockEditorStore ) as any ).updateBlockAttributes(
			block.clientId,
			{
				metadata: { ...existingMeta, noteId: note.id },
			}
		);
	}
}

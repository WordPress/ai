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
import { __, _n, sprintf } from '@wordpress/i18n';
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
 * Reviews a single block and creates/updates a Note if suggestions are found.
 *
 * @param block           The block to review.
 * @param postId          The current post ID.
 * @param content         The full post content (HTML).
 * @param resolvedNoteIds Set of Note IDs that have been resolved (approved).
 * @param noteContentById Map of Note ID → rendered Note content (pending only).
 * @param pendingNotes    All pending Notes for the post (for reply lookup).
 * @return The number of suggestions created, or 0 if the block was skipped.
 */
async function reviewSingleBlock(
	block: Block,
	postId: number,
	content: string,
	resolvedNoteIds: Set< number >,
	noteContentById: Map< number, string >,
	pendingNotes: ExistingNote[]
): Promise< number > {
	// Look up any existing Note thread on this block.
	const existingNoteId = block.attributes.metadata?.noteId ?? null;

	// Skip blocks whose Note thread has been resolved.
	if ( existingNoteId && resolvedNoteIds.has( existingNoteId ) ) {
		return 0;
	}

	const blockText = getBlockText( block );

	if ( blockText.length === 0 ) {
		return 0;
	}

	// Collect pending Note texts for this block's thread as context.
	const existingNoteTexts: string[] = [];
	if ( existingNoteId ) {
		const rootText = noteContentById.get( existingNoteId );
		if ( rootText ) {
			existingNoteTexts.push( rootText );
		}

		// Also collect replies (Notes with parent === existingNoteId).
		for ( const note of pendingNotes ) {
			if ( note.parent === existingNoteId ) {
				const replyText = noteContentById.get( note.id );
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

	// Call the review Ability.
	const result = await runAbility< ReviewResult >( 'ai/review-notes', {
		block_type: block.name,
		block_content: blockText,
		context,
		post_id: postId,
		existing_notes: existingNoteTexts,
	} ).catch( () => null );

	if ( result?.suggestions && result.suggestions.length > 0 ) {
		await createNote( block, postId, result.suggestions, existingNoteId );
		return result.suggestions.length;
	}

	return 0;
}

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

			// Fetch pending and resolved Notes for this post in parallel.
			// Pending (hold) Notes are used as context to avoid repeating suggestions.
			// Resolved (approve) Note IDs are used to skip already-acknowledged blocks.
			const [ pendingNotes, approvedNotes ] = await Promise.all( [
				fetchAllNotesByStatus( postId, 'hold' ),
				fetchAllNotesByStatus( postId, 'approve' ),
			] );
			const resolvedNoteIds = new Set(
				approvedNotes.map( ( n ) => n.id )
			);

			// Build a lookup: noteId → Note content text (pending Notes only).
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

				const results = await Promise.all(
					batch.map( ( block ) =>
						reviewSingleBlock(
							block,
							postId,
							content,
							resolvedNoteIds,
							noteContentById,
							pendingNotes
						)
					)
				);
				totalSuggestions += results.reduce( ( sum, n ) => sum + n, 0 );

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
 * Hook for reviewing a single block with AI.
 *
 * @return Object with reviewing state and the reviewBlock handler.
 */
export function useReviewBlock(): {
	isReviewing: boolean;
	reviewBlock: ( clientId: string ) => Promise< void >;
} {
	const [ isReviewing, setIsReviewing ] = useState< boolean >( false );

	const reviewBlock = async ( clientId: string ) => {
		setIsReviewing( true );

		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_review_block_error'
		);

		try {
			const block = ( select( blockEditorStore ) as any ).getBlock(
				clientId
			) as Block | null;

			if ( ! block ) {
				return;
			}

			const postId = (
				select( editorStore ) as any
			 ).getCurrentPostId() as number;
			const content = (
				select( editorStore ) as any
			 ).getEditedPostContent() as string;

			// Fetch fresh note state for this invocation.
			const [ pendingNotes, approvedNotes ] = await Promise.all( [
				fetchAllNotesByStatus( postId, 'hold' ),
				fetchAllNotesByStatus( postId, 'approve' ),
			] );
			const resolvedNoteIds = new Set(
				approvedNotes.map( ( n ) => n.id )
			);

			const noteContentById = new Map< number, string >();
			for ( const note of pendingNotes ) {
				noteContentById.set( note.id, note.content?.rendered ?? '' );
			}

			const suggestionCount = await reviewSingleBlock(
				block,
				postId,
				content,
				resolvedNoteIds,
				noteContentById,
				pendingNotes
			);

			if ( suggestionCount > 0 ) {
				(
					dispatch( coreStore ) as any
				 ).invalidateResolutionForStoreSelector( 'getEntityRecords' );
				( dispatch( noticesStore ) as any ).createSuccessNotice(
					sprintf(
						/* translators: %d: number of suggestions added. */
						_n(
							'%d suggestion added.',
							'%d suggestions added.',
							suggestionCount,
							'ai'
						),
						suggestionCount
					),
					{ type: 'snackbar' }
				);
			} else {
				( dispatch( noticesStore ) as any ).createNotice(
					'info',
					__( 'No new suggestions found.', 'ai' ),
					{ type: 'snackbar' }
				);
			}
		} catch ( error: any ) {
			( dispatch( noticesStore ) as any ).createErrorNotice(
				error?.message ?? String( error ),
				{
					id: 'ai_review_block_error',
					isDismissible: true,
				}
			);
		} finally {
			setIsReviewing( false );
		}
	};

	return { isReviewing, reviewBlock };
}

/**
 * Fetches all Notes by status for a given post.
 *
 * @param postId The ID of the post to fetch Notes for.
 * @param status The status of the Notes to fetch.
 * @return An array of Notes.
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
				`[AI Review Notes] Failed to fetch ${ status } Notes page ${ page }:`,
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
 * Creates a Note (or appends a reply) for a reviewed block.
 *
 * When no existing Note thread is present, creates a new Note and updates
 * the block's metadata with the resulting Note ID. When a thread already
 * exists, appends a reply to preserve the conversation history.
 *
 * @param block          The block that received suggestions.
 * @param postId         The current post ID.
 * @param suggestions    The suggestions to include in the Note.
 * @param existingNoteId The ID of an existing Note thread, or null for a new thread.
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

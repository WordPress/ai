/**
 * Custom hook for AI Review Notes functionality.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { flattenBlocks, getBlockText } from '../../../utils/blocks';
import { runAbility } from '../../../utils/run-ability';
import { stripHtml } from '../../../utils/text';

const REVIEWABLE_BLOCK_TYPES = [
	'core/paragraph',
	'core/heading',
	'core/list',
	'core/list-item',
	'core/quote',
	'core/verse',
	'core/image',
	'core/table',
	'core/preformatted',
	'core/pullquote',
];

const MAX_BLOCKS = 25;
const MIN_CONTENT_LENGTH = 20;
const BATCH_SIZE = 4;

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

			// Get all blocks and flatten the tree.
			const allBlocks = (
				select( blockEditorStore ) as any
			 ).getBlocks() as Block[];
			const flatBlocks = flattenBlocks( allBlocks );

			// Filter to reviewable block types with sufficient content.
			const reviewableBlocks = flatBlocks
				.filter(
					( block ) =>
						REVIEWABLE_BLOCK_TYPES.includes( block.name ) &&
						getBlockText( block ).length >= MIN_CONTENT_LENGTH
				)
				.slice( 0, MAX_BLOCKS );

			if ( reviewableBlocks.length === 0 ) {
				setLastRunCount( 0 );
				return;
			}

			setTotal( reviewableBlocks.length );

			// Fetch pending and resolved notes for this post in parallel.
			// Pending (hold) notes are used as context to avoid repeating suggestions.
			// Resolved (approve) note IDs are used to skip already-acknowledged blocks.
			const [ pendingNotes, resolvedNoteIds ] = await Promise.all( [
				apiFetch< ExistingNote[] >( {
					path: `/wp/v2/comments?type=note&status=hold&post=${ postId }&per_page=100`,
					method: 'GET',
				} ).catch( ( error ) => {
					// eslint-disable-next-line no-console
					console.warn(
						'[AI Review Notes] Failed to fetch pending notes:',
						error
					);
					return [] as ExistingNote[];
				} ),
				apiFetch< ExistingNote[] >( {
					path: `/wp/v2/comments?type=note&status=approve&post=${ postId }&per_page=100`,
					method: 'GET',
				} )
					.then( ( notes ) => new Set( notes.map( ( n ) => n.id ) ) )
					.catch( () => new Set< number >() ),
			] );

			// Build a lookup: noteId → note content text (pending notes only).
			const noteContentById = new Map< number, string >();
			for ( const note of pendingNotes ) {
				noteContentById.set(
					note.id,
					stripHtml( note.content?.rendered ?? '' )
				);
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

						// Call the review ability.
						const result = await runAbility< ReviewResult >(
							'ai/review-notes',
							{
								block_type: block.name,
								block_content: blockText,
								context: postId.toString(),
								existing_notes: existingNoteTexts,
								review_types: [
									'accessibility',
									'readability',
									'grammar',
									'seo',
								],
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

	const note = await apiFetch< NoteRecord >( {
		path: '/wp/v2/comments',
		method: 'POST',
		data: {
			post: postId,
			content: noteContent,
			type: 'note',
			status: 'hold',
			parent: existingNoteId ?? 0,
			meta: { ai_note: true },
		},
	} );

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

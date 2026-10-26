/**
 * WordPress dependencies
 */
import { dispatch, select } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Internal dependencies
 */
import type { ExistingNote } from '../../../utils/notes';
import type { Block, Suggestion, ReviewResult, NoteRecord } from '../types';
import { runAbility } from '../../../utils/run-ability';
import {
	addNoteId,
	buildContextWindow,
	getNoteIds,
} from '../../../utils/notes';
import {
	getBlockText,
	replaceBlockWithPlaceholder,
} from '../../../utils/blocks';

const BLOCK_PLACEHOLDER = '[[BLOCK_GOES_HERE]]';

/**
 * Reviews a single block and creates/updates a Note if suggestions are found.
 *
 * @param block           The block to review.
 * @param postId          The current post ID.
 * @param content         The full post content (HTML).
 * @param resolvedNoteIds Set of AI Note IDs that have been resolved (approved).
 * @param noteContentById Map of Note ID → rendered Note content (pending only).
 * @param pendingNotes    All pending Notes for the post (for reply lookup).
 * @return The number of suggestions created, or 0 if the block was skipped.
 */
export async function reviewSingleBlock(
	block: Block,
	postId: number,
	content: string,
	resolvedNoteIds: Set< number >,
	noteContentById: Map< number, string >,
	pendingNotes: ExistingNote[]
): Promise< number > {
	const noteIds = getNoteIds( block.attributes.metadata );

	// Skip blocks whose AI Note threads have all been resolved.
	if (
		noteIds.length &&
		noteIds.some( ( id ) => resolvedNoteIds.has( id ) )
	) {
		return 0;
	}

	const blockText = getBlockText( block );

	if ( blockText.length === 0 ) {
		return 0;
	}

	// Collect pending Note texts for this block's thread as context.
	const existingNoteTexts = collectThreadTexts(
		noteIds,
		noteContentById,
		pendingNotes
	);

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
	const context = buildContextWindow(
		contentWithPlaceholder,
		BLOCK_PLACEHOLDER
	);

	// Call the review Ability.
	const result = await runAbility< ReviewResult >( 'ai/editorial-notes', {
		block_type: block.name,
		block_content: blockText,
		context,
		post_id: postId,
		existing_notes: existingNoteTexts,
	} );

	if ( result?.suggestions && result.suggestions.length > 0 ) {
		await createNote(
			block,
			postId,
			result.suggestions,
			findAiThreadId( noteIds, pendingNotes )
		);
		return result.suggestions.length;
	}

	return 0;
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
export async function createNote(
	block: Block,
	postId: number,
	suggestions: Suggestion[],
	existingNoteId: number | null
): Promise< void > {
	const noteContent = suggestions
		.map( ( s ) => `[${ s.review_type.toUpperCase() }] ${ s.text }` )
		.join( '\n\n' );

	const note = ( await dispatch( coreStore ).saveEntityRecord(
		'root',
		'comment',
		{
			post: postId,
			content: noteContent,
			type: 'note',
			status: 'hold',
			parent: existingNoteId ?? 0,
			meta: { wpai_note: true },
		},
		// Reject on a failed save so the caller reports the error.
		{ throwOnError: true }
	) ) as NoteRecord | undefined;

	// Only update block metadata when creating a new thread (not a reply).
	if ( ! existingNoteId && note?.id ) {
		const existingMeta =
			( select( blockEditorStore ) as any ).getBlockAttributes(
				block.clientId
			)?.metadata ?? {};

		dispatch( blockEditorStore ).updateBlockAttributes( block.clientId, {
			metadata: addNoteId( existingMeta, note.id ),
		} );
	}
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

		// Also collect replies (Notes with parent === existingNoteId).
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

/**
 * Finds the pending AI-created root Note among a block's Note threads.
 *
 * @param noteIds      The root Note IDs attached to the block.
 * @param pendingNotes All pending Notes for the post.
 * @return The AI thread's root Note ID, or null if none exists.
 */
function findAiThreadId(
	noteIds: number[],
	pendingNotes: ExistingNote[]
): number | null {
	return (
		pendingNotes.find(
			( note ) =>
				noteIds.includes( note.id ) &&
				note.parent === 0 &&
				note.meta?.wpai_note
		)?.id ?? null
	);
}

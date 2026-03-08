/**
 * Custom hook for AI Refine from Notes functionality.
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

// Include the same reviewable types to safely get their attributes.
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
const CONTEXT_WINDOW_SIZE = 2000;
const TRUNCATED_BEFORE_MARKER = '[TRUNCATED BEFORE]';
const TRUNCATED_AFTER_MARKER = '[TRUNCATED AFTER]';
const NOTES_PAGE_SIZE = 100;

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

interface ExistingNote {
	id: number;
	parent: number;
	content: { rendered: string };
	[ key: string ]: unknown;
}

/**
 * Fetches all pending Notes for a given post.
 *
 * @param postId The ID of the post to fetch Notes for.
 * @return An array of pending Notes.
 */
async function fetchPendingNotes( postId: number ): Promise< ExistingNote[] > {
	const notes: ExistingNote[] = [];
	let page = 1;

	while ( true ) {
		try {
			const pageNotes = await apiFetch< ExistingNote[] >( {
				path: `/wp/v2/comments?type=note&status=hold&post=${ postId }&per_page=${ NOTES_PAGE_SIZE }&page=${ page }`,
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
				`[AI Refine Notes] Failed to fetch Notes page ${ page }:`,
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
 * Updates a Note status to approved.
 *
 * @param noteId Note ID.
 */
async function resolveNote( noteId: number ): Promise< void > {
	return apiFetch< void >( {
		path: `/wp/v2/comments/${ noteId }`,
		method: 'PUT',
		data: { status: 'approve' },
	} );
}

/**
 * Applies refinements based on notes to blocks in a post.
 *
 * @return Object with refining state and runRefinement handler.
 */
export function useRefineNotes(): {
	isRefining: boolean;
	hasPendingNotes: boolean;
	checkPendingNotes: () => Promise< void >;
	runRefinement: () => Promise< void >;
} {
	const [ isRefining, setIsRefining ] = useState< boolean >( false );
	const [ hasPendingNotes, setHasPendingNotes ] =
		useState< boolean >( false );

	const checkPendingNotes = async () => {
		const postId = (
			select( editorStore ) as any
		 ).getCurrentPostId() as number;

		if ( ! postId ) {
			return;
		}

		try {
			// Find if there is at least one hold note.
			const checkingNotes = await apiFetch< ExistingNote[] >( {
				path: `/wp/v2/comments?type=note&status=hold&post=${ postId }&per_page=1`,
				method: 'GET',
			} );

			setHasPendingNotes( checkingNotes.length > 0 );
		} catch ( e ) {
			setHasPendingNotes( false );
		}
	};

	const runRefinement = async () => {
		setIsRefining( true );

		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_refine_notes_error'
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

			// Fetch pending Notes for this post.
			const pendingNotes = await fetchPendingNotes( postId );

			if ( pendingNotes.length === 0 ) {
				( dispatch( noticesStore ) as any ).createNotice(
					'info',
					__( 'No pending notes found to refine.', 'ai' ),
					{ type: 'snackbar' }
				);
				setHasPendingNotes( false );
				return;
			}

			// Build a lookup: noteId -> Note content text
			const noteContentById = new Map< number, string >();
			for ( const note of pendingNotes ) {
				noteContentById.set( note.id, note.content?.rendered ?? '' );
			}

			let refinedBlocksCount = 0;
			const notesToResolve: number[] = [];

			// Process each reviewable block that has notes.
			for ( const block of flatBlocks ) {
				if ( ! REVIEWABLE_BLOCK_TYPES.includes( block.name ) ) {
					continue;
				}

				const existingNoteId =
					block.attributes.metadata?.noteId ?? null;
				if (
					! existingNoteId ||
					! noteContentById.has( existingNoteId )
				) {
					continue;
				}

				const blockText = getBlockText( block );
				if ( blockText.length === 0 ) {
					continue;
				}

				// Collect notes logic
				const existingNoteTexts: string[] = [];
				const rootText = noteContentById.get( existingNoteId );
				if ( rootText ) {
					existingNoteTexts.push( rootText );
				}

				for ( const note of pendingNotes ) {
					if ( note.parent === existingNoteId ) {
						const replyText = noteContentById.get( note.id );
						if ( replyText ) {
							existingNoteTexts.push( replyText );
						}
					}
				}

				// Replace the block with the placeholder.
				const contentWithPlaceholder = replaceBlockWithPlaceholder(
					content,
					block.clientId,
					BLOCK_PLACEHOLDER
				);

				const contextWindow = buildContextWindow(
					contentWithPlaceholder,
					BLOCK_PLACEHOLDER
				);
				const context = `What follows is surrounding article content, where the block being refined has been replaced with the placeholder ${ BLOCK_PLACEHOLDER }. Use the nearby text to better understand the context of the block within the article. CONTENT: \n\n${ contextWindow }`;

				// Execute refinement
				try {
					const refinedContent = await runAbility< string >(
						'ai/refine-notes',
						{
							block_type: block.name,
							block_content: blockText,
							context,
							post_id: postId,
							notes: existingNoteTexts,
						}
					);

					if ( refinedContent && refinedContent !== blockText ) {
						// Extract content depending on block type
						// We update the content directly with the refined content logic
						// Only paragraph and heading generally use the `content` attribute directly.
						const attributeToUpdate =
							block.name === 'core/image' ? 'alt' : 'content';

						(
							dispatch( blockEditorStore ) as any
						 ).updateBlockAttributes( block.clientId, {
							[ attributeToUpdate ]: refinedContent,
						} );

						refinedBlocksCount++;

						// Add notes for resolution
						notesToResolve.push( existingNoteId );
						for ( const note of pendingNotes ) {
							if ( note.parent === existingNoteId ) {
								notesToResolve.push( note.id );
							}
						}
					}
				} catch ( e ) {
					// Fall through, continue with others
					console.warn(
						`[AI Refine Notes] Failed to refine block ${ block.clientId }`,
						e
					);
				}
			}

			// Resolve applied notes
			if ( notesToResolve.length > 0 ) {
				await Promise.all(
					notesToResolve.map( ( id ) =>
						resolveNote( id ).catch( () => null )
					)
				);

				(
					dispatch( coreStore ) as any
				 ).invalidateResolutionForStoreSelector( 'getEntityRecords' );
			}

			if ( refinedBlocksCount > 0 ) {
				( dispatch( noticesStore ) as any ).createSuccessNotice(
					sprintf(
						/* translators: %d: number of blocks refined. */
						_n(
							'%d block refined with AI.',
							'%d blocks refined with AI.',
							refinedBlocksCount,
							'ai'
						),
						refinedBlocksCount
					),
					{ type: 'snackbar' }
				);

				// Make sure we trigger an autosave or save state so that it is properly versioned
				// Dispatch save to save standard editor content (saving effectively creates a revision boundary)
				( dispatch( editorStore ) as any ).autosave();
			} else {
				( dispatch( noticesStore ) as any ).createNotice(
					'info',
					__(
						'No content changes were needed based on the existing notes.',
						'ai'
					),
					{ type: 'snackbar' }
				);
			}

			// Recheck
			await checkPendingNotes();
		} catch ( error: any ) {
			( dispatch( noticesStore ) as any ).createErrorNotice(
				error?.message ?? String( error ),
				{
					id: 'ai_refine_notes_error',
					isDismissible: true,
				}
			);
		} finally {
			setIsRefining( false );
		}
	};

	return { isRefining, hasPendingNotes, checkPendingNotes, runRefinement };
}

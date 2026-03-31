/**
 * Custom hook for AI Refine from Notes functionality.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select, useSelect } from '@wordpress/data';
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
import {
	REVIEWABLE_BLOCK_TYPES,
	fetchAllNotesByStatus,
	buildContextWindow,
} from '../../../utils/notes';
import { runAbility } from '../../../utils/run-ability';

const BLOCK_PLACEHOLDER = '[[BLOCK_GOES_HERE]]';

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
 * Hook for refining blocks based on existing notes with AI.
 *
 * @return {Object}   Object with refining state and functions.
 * @property {boolean}  isRefining      Whether a refine operation is in progress.
 * @property {number}   progress        The number of blocks processed so far.
 * @property {number}   total           The total number of blocks to process.
 * @property {boolean}  hasPendingNotes Whether there are pending notes to process.
 * @property {Function} runRefinement   Function to trigger the refinement process.
 */
export function useRefineNotes(): {
	isRefining: boolean;
	progress: number;
	total: number;
	hasPendingNotes: boolean;
	runRefinement: () => Promise< void >;
} {
	const [ isRefining, setIsRefining ] = useState< boolean >( false );
	const [ progress, setProgress ] = useState< number >( 0 );
	const [ total, setTotal ] = useState< number >( 0 );

	const postId = useSelect(
		( sel ) => ( sel( editorStore ) as any ).getCurrentPostId() as number,
		[]
	);

	// Reactively derived from the coreStore so the button appears/disappears
	// automatically whenever Review Notes creates notes (via saveEntityRecord +
	// invalidateResolutionForStoreSelector) or Refine Notes resolves them.
	const hasPendingNotes = useSelect(
		( sel ) => {
			if ( ! postId ) {
				return false;
			}
			const notes = ( sel( coreStore ) as any ).getEntityRecords(
				'root',
				'comment',
				{
					type: 'note',
					status: 'hold',
					post: postId,
					per_page: 1,
					_fields: 'id',
				}
			) as Array< { id: number } > | null;
			// null means the fetch is still in flight; treat as false until resolved.
			return notes !== null && notes.length > 0;
		},
		[ postId ]
	);

	const runRefinement = async () => {
		setIsRefining( true );
		setProgress( 0 );
		setTotal( 0 );

		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_refine_notes_error'
		);

		try {
			const content = (
				select( editorStore ) as any
			 ).getEditedPostContent() as string;

			// Get all blocks and flatten the tree.
			const allBlocks = (
				select( blockEditorStore ) as any
			 ).getBlocks() as Block[];
			const flatBlocks = flattenBlocks( allBlocks );

			// Fetch pending Notes for this post.
			const pendingNotes = await fetchAllNotesByStatus( postId, 'hold' );

			if ( pendingNotes.length === 0 ) {
				( dispatch( noticesStore ) as any ).createNotice(
					'info',
					__( 'No pending Notes found to refine.', 'ai' ),
					{ type: 'snackbar' }
				);
				return;
			}

			// Build a lookup: noteId -> Note content text
			const noteContentById = new Map< number, string >();
			for ( const note of pendingNotes ) {
				noteContentById.set( note.id, note.content?.rendered ?? '' );
			}

			// Find which blocks have matching notes
			const refineableBlocks = flatBlocks.filter( ( block ) => {
				if ( ! REVIEWABLE_BLOCK_TYPES.includes( block.name ) ) {
					return false;
				}
				const existingNoteId =
					block.attributes.metadata?.noteId ?? null;
				if (
					! existingNoteId ||
					! noteContentById.has( existingNoteId )
				) {
					return false;
				}
				const blockText = getBlockText( block );
				return blockText.length > 0;
			} );

			if ( refineableBlocks.length === 0 ) {
				( dispatch( noticesStore ) as any ).createNotice(
					'info',
					__( 'No blocks found matching the existing Notes.', 'ai' ),
					{ type: 'snackbar' }
				);
				return;
			}

			setTotal( refineableBlocks.length );

			let refinedBlocksCount = 0;
			let processedBlocksCount = 0;
			let failedBlocksCount = 0;
			const notesToResolve: number[] = [];

			// Process in batches of 4 (similar to Review Notes)
			const BATCH_SIZE = 4;
			for (
				let batchStart = 0;
				batchStart < refineableBlocks.length;
				batchStart += BATCH_SIZE
			) {
				const batch = refineableBlocks.slice(
					batchStart,
					batchStart + BATCH_SIZE
				);

				await Promise.all(
					batch.map( async ( block ) => {
						const existingNoteId = block.attributes.metadata
							?.noteId as number;

						const blockText = getBlockText( block );

						// Collect notes logic
						const existingNoteTexts: string[] = [];
						const rootText = noteContentById.get( existingNoteId );
						if ( rootText ) {
							existingNoteTexts.push( rootText );
						}

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

						// Replace the block with the placeholder.
						const contentWithPlaceholder =
							replaceBlockWithPlaceholder(
								content,
								block.clientId,
								BLOCK_PLACEHOLDER
							);

						const contextWindow = buildContextWindow(
							contentWithPlaceholder,
							BLOCK_PLACEHOLDER
						);
						const refinementContext = `What follows is surrounding article content, where the block being refined has been replaced with the placeholder ${ BLOCK_PLACEHOLDER }. Use the nearby text to better understand the context of the block within the article. CONTENT: \n\n${ contextWindow }`;

						// Execute refinement
						try {
							const refinedContent = await runAbility< string >(
								'ai/refine-notes',
								{
									block_type: block.name,
									block_content: blockText,
									context: refinementContext,
									post_id: postId,
									notes: existingNoteTexts,
								}
							);

							if (
								refinedContent &&
								refinedContent !== blockText
							) {
								// For heading and paragraph it's content, image is alt
								const attributeToUpdate =
									block.name === 'core/image'
										? 'alt'
										: 'content';

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
							// eslint-disable-next-line no-console
							console.warn(
								`[AI Refine Notes] Failed to refine block ${ block.clientId }`,
								e
							);
							failedBlocksCount++;
						} finally {
							processedBlocksCount++;
							setProgress( processedBlocksCount );
						}
					} )
				);
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

			// If every block failed, surface an error notice.
			if ( failedBlocksCount > 0 && refinedBlocksCount === 0 ) {
				( dispatch( noticesStore ) as any ).createErrorNotice(
					__( 'AI refinement failed for all blocks.', 'ai' ),
					{
						id: 'ai_refine_notes_error',
						isDismissible: true,
					}
				);
				return;
			}

			if ( refinedBlocksCount > 0 ) {
				// We trigger autosave to ensure the DB state has the refinements
				// as a distinct revision boundary.
				await ( dispatch( editorStore ) as any ).autosave();

				// Fetch the latest revision ID directly from the REST API.
				// The autosave endpoint only updates the autosave record (not the main
				// post entity), so the editor store's revision data is stale after autosave.
				const { aiRefineNotesData } = window as any;
				const restBase = aiRefineNotesData?.rest_base as
					| string
					| undefined;

				let lastRevisionId: number | null = null;
				try {
					const revisions = await apiFetch< Array< { id: number } > >(
						{
							path: `/wp/v2/${ restBase }/${ postId }/revisions?per_page=1`,
							method: 'GET',
						}
					);
					lastRevisionId = revisions[ 0 ]?.id ?? null;
				} catch ( e ) {
					lastRevisionId = (
						select( editorStore ) as any
					 ).getCurrentPostLastRevisionId() as number | null;
				}

				const adminUrl = aiRefineNotesData?.admin_url as
					| string
					| undefined;

				const noticeActions =
					lastRevisionId && adminUrl
						? [
								{
									label: __( 'Review in Revisions', 'ai' ),
									url: `${ adminUrl }revision.php?revision=${ lastRevisionId }`,
								},
						  ]
						: [];

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
					{
						type: 'snackbar',
						actions: noticeActions,
					}
				);
			} else {
				( dispatch( noticesStore ) as any ).createNotice(
					'info',
					__(
						'No content changes were needed based on the existing Notes.',
						'ai'
					),
					{ type: 'snackbar' }
				);
			}
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

	return {
		isRefining,
		progress,
		total,
		hasPendingNotes,
		runRefinement,
	};
}

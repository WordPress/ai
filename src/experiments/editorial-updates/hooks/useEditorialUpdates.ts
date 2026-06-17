/**
 * Custom hook for Editorial Updates functionality.
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
import { ensureProvider } from '../../../utils/provider-status';
import { runAbility } from '../../../utils/run-ability';

const BLOCK_PLACEHOLDER = '[[BLOCK_GOES_HERE]]';
const NOTICE_ID = 'wpai_editorial_updates_error';

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
export function useEditorialUpdates(): {
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
	// automatically whenever Editorial Notes creates notes (via saveEntityRecord +
	// invalidateResolutionForStoreSelector) or Editorial Updates resolves them.
	const hasPendingNotes = useSelect(
		( sel ) => {
			if ( ! postId ) {
				return false;
			}

			// Collect Note IDs linked from block metadata, then check whether any are
			// still pending. This avoids showing the action for stale Notes left behind
			// after refreshing before the generated Note metadata was explicitly saved.
			const allBlocks = sel( blockEditorStore ).getBlocks() as Block[];
			const linkedNoteIds = Array.from(
				new Set(
					flattenBlocks( allBlocks )
						.filter( ( block ) =>
							REVIEWABLE_BLOCK_TYPES.includes( block.name )
						)
						.map( ( block ) => block.attributes.metadata?.noteId )
						.filter( ( id ) => typeof id === 'number' )
				)
			);

			if ( linkedNoteIds.length === 0 ) {
				return false;
			}

			const notes = ( sel( coreStore ) as any ).getEntityRecords(
				'root',
				'comment',
				{
					type: 'note',
					status: 'hold',
					post: postId,
					include: Array.from( linkedNoteIds ),
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
		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		setIsRefining( true );
		setProgress( 0 );
		setTotal( 0 );

		( dispatch( noticesStore ) as any ).removeNotice( NOTICE_ID );

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
			let firstErrorMessage: string | null = null;
			const notesToResolve: number[] = [];

			// Process in batches of 4 (similar to Editorial Notes)
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

						const refinementContext = buildContextWindow(
							contentWithPlaceholder,
							BLOCK_PLACEHOLDER
						);

						// Execute refinement
						try {
							const refinedContent = await runAbility< string >(
								'ai/editorial-updates',
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
						} catch ( e: any ) {
							// eslint-disable-next-line no-console
							console.warn(
								`[Refine Notes] Failed to refine block ${ block.clientId }`,
								e
							);
							if ( ! firstErrorMessage ) {
								firstErrorMessage = e?.message ?? String( e );
							}
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
					firstErrorMessage ??
						__( 'Refinement failed for all blocks.', 'ai' ),
					{
						id: NOTICE_ID,
						isDismissible: true,
					}
				);
				return;
			}

			if ( refinedBlocksCount > 0 ) {
				// Save the post so refinements are persisted and a revision is
				// created. This keeps the editor state clean — no "unsaved
				// changes" prompt when navigating to the revisions link.
				await ( dispatch( editorStore ) as any ).savePost();
				const { aiEditorialUpdatesData } = window as any;
				const restBase = aiEditorialUpdatesData?.rest_base as
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
				} catch {
					lastRevisionId = (
						select( editorStore ) as any
					 ).getCurrentPostLastRevisionId() as number | null;
				}

				const adminUrl = aiEditorialUpdatesData?.admin_url as
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
					id: NOTICE_ID,
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

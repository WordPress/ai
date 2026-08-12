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
 * Returns the attribute Editorial Updates should use for a block's primary
 * editable text. Most text blocks use `content`, while blocks such as
 * Pullquote use `value` and Image uses `alt`.
 *
 * @param block The block to inspect.
 * @return The editable text attribute.
 */
function getEditableTextAttribute( block: Block ) {
	if ( block.name === 'core/image' ) {
		return 'alt';
	}

	if ( Object.hasOwn( block.attributes, 'value' ) ) {
		return 'value';
	}

	return 'content';
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
 * Navigates to the classic revisions screen.
 *
 * @param adminUrl       Site admin URL, or undefined if unavailable.
 * @param lastRevisionId The revision ID to open.
 */
function navigateToClassicRevisions(
	adminUrl: string | undefined,
	lastRevisionId: number
): void {
	if ( adminUrl ) {
		window.location.href = `${ adminUrl }revision.php?revision=${ lastRevisionId }`;
	}
}

/**
 * Opens WordPress's in-editor visual revisions UI by clicking the
 * "Revisions" button Core already renders in the Document Settings
 * sidebar (`PrivatePostLastRevision` in @wordpress/editor).
 *
 * There is no public JS API for this in WP 7.0 — the underlying store
 * action (`setCurrentRevisionId`) is registered as a private action and
 * is gated behind @wordpress/private-apis, which only allows a hardcoded
 * list of core module names to opt in. Calling it directly from a plugin
 * throws, since the action is never attached to the public dispatch
 * object. Simulating a real click on Core's own rendered button is the
 * supported workaround.
 *
 * Elements are located by stable, locale-independent attributes rather
 * than translated accessible names: the sidebar toggle uses
 * `aria-controls="edit-post:document"`, which comes from the
 * complementary area identifier `edit-post/document` registered by
 * the WordPress editor package for the Document Settings sidebar, and
 * is the same regardless of site language.
 *
 * Falls back to the classic revisions screen if the button can't be
 * found within a short polling window (e.g. markup changes upstream, or
 * the post genuinely has fewer than two revisions so Core doesn't render
 * the button at all).
 *
 * @param root0                Options.
 * @param root0.adminUrl       Site admin URL, used for the classic
 *                             revisions fallback.
 * @param root0.lastRevisionId The revision ID to open.
 */
function openVisualRevisions( {
	adminUrl,
	lastRevisionId,
}: {
	adminUrl: string | undefined;
	lastRevisionId: number;
} ): void {
	const MAX_ATTEMPTS = 20;
	const INTERVAL_MS = 100;

	// The revisions button only exists in the DOM once the Document
	// Settings sidebar is mounted, so open it first if it's collapsed.
	const settingsToggle = document.querySelector< HTMLButtonElement >(
		'button[aria-controls="edit-post:document"]'
	);
	if ( settingsToggle?.getAttribute( 'aria-expanded' ) === 'false' ) {
		settingsToggle.click();
	}

	let attempts = 0;
	const tryClick = () => {
		const revisionsButton = document.querySelector< HTMLButtonElement >(
			'.editor-private-post-last-revision__button, .editor-post-last-revision__title'
		);
		if ( revisionsButton ) {
			revisionsButton.click();
			return;
		}
		attempts++;
		if ( attempts >= MAX_ATTEMPTS ) {
			navigateToClassicRevisions( adminUrl, lastRevisionId );
			return;
		}
		setTimeout( tryClick, INTERVAL_MS );
	};
	tryClick();
}

/**
 * Builds the snackbar action for reviewing changes in the revisions UI.
 *
 * Uses the in-editor visual revisions path (WP 7.0+ default) via
 * openVisualRevisions(). Falls back to the classic revisions screen when
 * visual revisions are disabled, e.g. when classic metaboxes are active
 * on the post.
 *
 * @param root0                        Options.
 * @param root0.lastRevisionId         The revision ID to open.
 * @param root0.adminUrl               Site admin URL, used for the
 *                                     classic revisions fallback.
 * @param root0.disableVisualRevisions Whether Core has disabled visual
 *                                     revisions for this post.
 * @return Snackbar notice actions, or an empty array when neither path
 *         is available.
 */
function getRevisionReviewAction( {
	lastRevisionId,
	adminUrl,
	disableVisualRevisions,
}: {
	lastRevisionId: number;
	adminUrl: string | undefined;
	disableVisualRevisions: boolean;
} ): Array< { label: string; url?: string; onClick?: () => void } > {
	if ( ! disableVisualRevisions ) {
		return [
			{
				label: __( 'Review in Revisions', 'ai' ),
				onClick: () =>
					openVisualRevisions( { adminUrl, lastRevisionId } ),
			},
		];
	}

	if ( adminUrl ) {
		return [
			{
				label: __( 'Review in Revisions', 'ai' ),
				url: `${ adminUrl }revision.php?revision=${ lastRevisionId }`,
			},
		];
	}

	return [];
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
		( sel ) => sel( editorStore ).getCurrentPostId() as number,
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

			const notes = sel( coreStore ).getEntityRecords(
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

		dispatch( noticesStore ).removeNotice( NOTICE_ID );

		try {
			const content = select(
				editorStore
			).getEditedPostContent() as string;

			// Get all blocks and flatten the tree.
			const allBlocks = select( blockEditorStore ).getBlocks() as Block[];
			const flatBlocks = flattenBlocks( allBlocks );

			// Fetch pending Notes for this post.
			const pendingNotes = await fetchAllNotesByStatus( postId, 'hold' );

			if ( pendingNotes.length === 0 ) {
				dispatch( noticesStore ).createNotice(
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
				dispatch( noticesStore ).createNotice(
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
								const attributeToUpdate =
									getEditableTextAttribute( block );

								dispatch(
									blockEditorStore
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

				dispatch( coreStore ).invalidateResolutionForStoreSelector(
					'getEntityRecords'
				);
			}

			// If every block failed, surface an error notice.
			if ( failedBlocksCount > 0 && refinedBlocksCount === 0 ) {
				dispatch( noticesStore ).createErrorNotice(
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
				await dispatch( editorStore ).savePost();
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
					lastRevisionId =
						select( editorStore ).getCurrentPostLastRevisionId();
				}

				const adminUrl = aiEditorialUpdatesData?.admin_url as
					| string
					| undefined;
				const editorSettings = select(
					editorStore
				).getEditorSettings() as {
					disableVisualRevisions?: boolean;
				};
				const disableVisualRevisions =
					!! editorSettings.disableVisualRevisions;

				const noticeActions = lastRevisionId
					? getRevisionReviewAction( {
							lastRevisionId,
							adminUrl,
							disableVisualRevisions,
					  } )
					: [];

				dispatch( noticesStore ).createSuccessNotice(
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
				dispatch( noticesStore ).createNotice(
					'info',
					__(
						'No content changes were needed based on the existing Notes.',
						'ai'
					),
					{ type: 'snackbar' }
				);
			}
		} catch ( error: any ) {
			dispatch( noticesStore ).createErrorNotice(
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

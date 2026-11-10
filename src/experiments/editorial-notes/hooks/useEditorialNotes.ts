/**
 * Custom hook for Editorial Notes functionality.
 */

/**
 * WordPress dependencies
 */
import { dispatch, select, useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as coreStore } from '@wordpress/core-data';
import { store as editPostStore } from '@wordpress/edit-post';
import { store as editorStore } from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import {
	flattenBlocks,
	getPostContentBlockContext,
} from '../../../utils/blocks';
import {
	REVIEWABLE_BLOCK_TYPES,
	fetchAllNotesByStatus,
} from '../../../utils/notes';
import { ensureProvider } from '../../../utils/provider-status';
import { hasMinimumContent } from '../../../utils/character-count';
import { reviewSingleBlock } from '../utils';

const BATCH_SIZE = 4;
const NOTICE_ID = 'ai_editorial_notes_error';
const NOTES_SIDEBAR_ID = 'edit-post/collab-sidebar';
const MINIMUM_CONTENT_COUNT_DEFAULT = 75;

/**
 * Hook for determining whether Editorial Notes should be available.
 *
 * @return Availability state for the Editorial Notes feature.
 */
function useEditorialNotesAvailability(): {
	content: string;
	minContentLength: number;
	isContentTooShort: boolean;
} {
	const content =
		useSelect( ( selectStore ) => {
			return selectStore( editorStore ).getEditedPostContent();
		}, [] ) ?? '';
	const minContentLength: number =
		( window as any ).aiEditorialNotesData?.minContentLength ??
		MINIMUM_CONTENT_COUNT_DEFAULT;

	return {
		content,
		minContentLength,
		isContentTooShort: ! hasMinimumContent( content, minContentLength ),
	};
}

/**
 * Hook for Editorial Notes functionality.
 *
 * @return Object with review state and the runReview handler.
 */
export function useEditorialNotes(): {
	isReviewing: boolean;
	progress: number;
	total: number;
	lastRunCount: number | null;
	isContentTooShort: boolean;
	minContentLength: number;
	runReview: () => Promise< void >;
} {
	const [ isReviewing, setIsReviewing ] = useState< boolean >( false );
	const [ progress, setProgress ] = useState< number >( 0 );
	const [ total, setTotal ] = useState< number >( 0 );
	const [ lastRunCount, setLastRunCount ] = useState< number | null >( null );
	const { content, isContentTooShort, minContentLength } =
		useEditorialNotesAvailability();

	const runReview = async () => {
		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		if ( isContentTooShort ) {
			return;
		}

		setIsReviewing( true );
		setProgress( 0 );
		setTotal( 0 );
		setLastRunCount( null );

		dispatch( noticesStore ).removeNotice( NOTICE_ID );

		try {
			const postId = select( editorStore ).getCurrentPostId() as number;

			// Get all blocks belonging to the post and flatten the tree.
			const { allBlocks, isMissingPostContent } =
				getPostContentBlockContext();

			if ( isMissingPostContent ) {
				throw new Error(
					__(
						'Unable to generate notes: the current template does not contain a post content block.',
						'ai'
					)
				);
			}

			if ( allBlocks.length === 0 ) {
				setLastRunCount( 0 );
				return;
			}

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

			// Only resolved AI threads mark a block as already reviewed.
			const resolvedNoteIds = new Set(
				approvedNotes
					.filter( ( note ) => note.meta?.wpai_note === true )
					.map( ( n ) => n.id )
			);

			// Build a lookup: noteId → Note content text (pending Notes only).
			const noteContentById = new Map< number, string >();
			for ( const note of pendingNotes ) {
				noteContentById.set( note.id, note.content?.rendered ?? '' );
			}

			let totalSuggestions = 0;
			let failedBlocks = 0;
			let firstError: any = null;

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

				// A block that fails must not discard the blocks that succeed.
				const results = await Promise.allSettled(
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

				for ( const result of results ) {
					if ( result.status === 'fulfilled' ) {
						totalSuggestions += result.value;
					} else {
						failedBlocks++;
						firstError = firstError ?? result.reason;
					}
				}

				setProgress(
					Math.min( batchStart + BATCH_SIZE, reviewableBlocks.length )
				);
			}

			// "No new suggestions found." would be wrong when blocks failed.
			setLastRunCount(
				failedBlocks > 0 && totalSuggestions === 0
					? null
					: totalSuggestions
			);

			if ( totalSuggestions > 0 ) {
				dispatch( coreStore ).invalidateResolutionForStoreSelector(
					'getEntityRecords'
				);

				dispatch( noticesStore ).createSuccessNotice(
					sprintf(
						/* translators: %d: number of suggestions added. */
						_n(
							'%d suggestion added. Save to keep changes.',
							'%d suggestions added. Save to keep changes.',
							totalSuggestions,
							'ai'
						),
						totalSuggestions
					),
					{ type: 'snackbar' }
				);
			}

			if ( failedBlocks > 0 ) {
				dispatch( noticesStore ).createErrorNotice(
					sprintf(
						/* translators: 1: number of blocks that could not be reviewed, 2: error message. */
						_n(
							'%1$d block could not be reviewed: %2$s',
							'%1$d blocks could not be reviewed: %2$s',
							failedBlocks,
							'ai'
						),
						failedBlocks,
						firstError?.message ?? String( firstError )
					),
					{
						id: NOTICE_ID,
						isDismissible: true,
					}
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
			setIsReviewing( false );
		}
	};

	return {
		isReviewing,
		progress,
		total,
		lastRunCount,
		isContentTooShort,
		minContentLength,
		runReview,
	};
}

/**
 * Hook for reviewing a single block.
 *
 * @return Object with reviewing state and the reviewBlock handler.
 */
export function useEditorialBlock(): {
	isReviewing: boolean;
	reviewingClientId: string | null;
	isContentTooShort: boolean;
	minContentLength: number;
	reviewBlock: ( clientId: string ) => Promise< void >;
} {
	const [ reviewingClientId, setReviewingClientId ] = useState<
		string | null
	>( null );
	const { content, isContentTooShort, minContentLength } =
		useEditorialNotesAvailability();

	const reviewBlock = async ( clientId: string ) => {
		if ( isContentTooShort ) {
			return;
		}

		setReviewingClientId( clientId );

		dispatch( noticesStore ).removeNotice( 'ai_editorial_block_error' );

		try {
			const block = select( blockEditorStore ).getBlock( clientId );

			if ( ! block ) {
				return;
			}

			const postId = select( editorStore ).getCurrentPostId() as number;

			// Fetch fresh note state for this invocation.
			const [ pendingNotes, approvedNotes ] = await Promise.all( [
				fetchAllNotesByStatus( postId, 'hold' ),
				fetchAllNotesByStatus( postId, 'approve' ),
			] );
			const resolvedNoteIds = new Set(
				approvedNotes
					.filter( ( note ) => note.meta?.wpai_note === true )
					.map( ( n ) => n.id )
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
				dispatch( coreStore ).invalidateResolutionForStoreSelector(
					'getEntityRecords'
				);
				( dispatch( editPostStore ) as any ).openGeneralSidebar?.(
					NOTES_SIDEBAR_ID
				);
				dispatch( noticesStore ).createSuccessNotice(
					sprintf(
						/* translators: %d: number of suggestions added. */
						_n(
							'%d suggestion added. Save to keep changes.',
							'%d suggestions added. Save to keep changes.',
							suggestionCount,
							'ai'
						),
						suggestionCount
					),
					{ type: 'snackbar' }
				);
			} else {
				dispatch( noticesStore ).createNotice(
					'info',
					__( 'No new suggestions found.', 'ai' ),
					{ type: 'snackbar' }
				);
			}
		} catch ( error: any ) {
			dispatch( noticesStore ).createErrorNotice(
				error?.message ?? String( error ),
				{
					id: 'ai_editorial_block_error',
					isDismissible: true,
				}
			);
		} finally {
			setReviewingClientId( null );
		}
	};

	return {
		isReviewing: !! reviewingClientId,
		reviewingClientId,
		isContentTooShort,
		minContentLength,
		reviewBlock,
	};
}

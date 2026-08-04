/**
 * Shared hook for summary generation logic.
 */

/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { dispatch, useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useMemo, useSyncExternalStore } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { generateSummary } from './generate-summary';
import { ensureProvider } from '../../../utils/provider-status';
import { hasMinimumContent } from '../../../utils/character-count';
import type { SummarizationData } from '../types';
import {
	createSummaryBlock,
	createSummaryInnerBlocks,
	findSummaryBlock,
} from '../utils';

const MINIMUM_CONTENT_COUNT_DEFAULT = 250;
const NOTICE_ID = 'ai_summarization_error';

/**
 * Shared summarizing state.
 *
 * The sidebar and the block toolbar each render their own instance of
 * `useSummaryGeneration`, so the in-flight state lives outside React to keep
 * every button in sync. Module scope also means the state is reset correctly
 * even if the button that started the run unmounts before it finishes (e.g.
 * the block toolbar disappearing when the block is deselected).
 */
let isSummarizingGlobal = false;
const listeners = new Set< () => void >();

/**
 * Subscribes to shared summarizing state changes.
 *
 * @param callback Called whenever the shared state changes.
 * @return Function that removes the subscription.
 */
function subscribe( callback: () => void ): () => void {
	listeners.add( callback );
	return () => {
		listeners.delete( callback );
	};
}

/**
 * Returns the current shared summarizing state.
 *
 * @return Whether a summary is currently being generated.
 */
function getSnapshot(): boolean {
	return isSummarizingGlobal;
}

/**
 * Updates the shared summarizing state and notifies every subscriber.
 *
 * @param isSummarizing Whether a summary is currently being generated.
 */
function setIsSummarizing( isSummarizing: boolean ): void {
	isSummarizingGlobal = isSummarizing;
	listeners.forEach( ( listener ) => listener() );
}

const getSettings = (): SummarizationData => {
	const settings = ( window as any ).aiSummarizationData ?? {};

	return {
		enabled: settings.enabled ?? false,
		minContentLength:
			settings.minContentLength ?? MINIMUM_CONTENT_COUNT_DEFAULT,
	};
};

/**
 * Summary generation hook.
 */
export function useSummaryGeneration() {
	const { allBlocks, postId, content, meta } = useSelect( ( select ) => {
		return {
			allBlocks: select( blockEditorStore )[ 'getBlocks' ](), // eslint-disable-line dot-notation
			postId: select( editorStore ).getCurrentPostId(),
			content: select( editorStore ).getEditedPostContent(),
			meta: select( editorStore ).getEditedPostAttribute( 'meta' ),
		};
	}, [] );
	const { editPost } = useDispatch( editorStore );
	const isSummarizing = useSyncExternalStore( subscribe, getSnapshot );

	// Whether the post already contains an AI-generated summary block.
	const hasSummary = useMemo(
		() => Boolean( findSummaryBlock( allBlocks ) ),
		[ allBlocks ]
	);

	/**
	 * Handles the summarization button click.
	 */
	const handleSummarize = async () => {
		// Read the module value rather than the rendered one, which may be stale.
		if ( isSummarizingGlobal ) {
			return;
		}

		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		setIsSummarizing( true );
		dispatch( noticesStore ).removeNotice( NOTICE_ID );

		try {
			const generatedSummary = await generateSummary(
				postId as number,
				content
			);

			// Store the summary in post meta (will require a manual save).
			editPost( {
				meta: {
					...meta,
					wpai_generated_summary: generatedSummary,
				},
			} );

			// Check if an existing Content Summary group block exists.
			const existingSummaryBlock = findSummaryBlock( allBlocks );

			if ( existingSummaryBlock ) {
				const innerBlocks =
					createSummaryInnerBlocks( generatedSummary );
				// Replace inner blocks of the existing group to preserve its attributes.
				dispatch( blockEditorStore ).replaceInnerBlocks(
					existingSummaryBlock.clientId,
					innerBlocks,
					false
				);
			} else {
				// Insert a new summary group block at the top.
				const summaryBlock = createSummaryBlock( generatedSummary );

				dispatch( blockEditorStore ).insertBlock( summaryBlock, 0 );
			}
		} catch ( error: any ) {
			const message =
				typeof error === 'string'
					? error
					: error?.message ??
					  __( 'Failed to generate summary.', 'ai' );
			dispatch( noticesStore ).createErrorNotice( message, {
				id: NOTICE_ID,
				isDismissible: true,
			} );
		} finally {
			setIsSummarizing( false );
		}
	};

	// Minimum content length required for summarization.
	const isContentTooShort = ! hasMinimumContent(
		content || '',
		getSettings().minContentLength
	);

	return {
		isSummarizing,
		hasSummary,
		handleSummarize,
		isContentTooShort,
		minContentLength: getSettings().minContentLength,
	};
}

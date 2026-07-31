/**
 * Shared hook for summary generation logic.
 */

/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { dispatch, useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect, useState, useSyncExternalStore } from '@wordpress/element';
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

let globalIsRegenerating = false;
const listeners = new Set< () => void >();

function subscribe( callback: () => void ): () => void {
	listeners.add( callback );
	return () => {
		listeners.delete( callback );
	};
}

function getSnapshot(): boolean {
	return globalIsRegenerating;
}

function setGlobalIsRegenerating( isSummarizing: boolean ): void {
	globalIsRegenerating = isSummarizing;
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
	const [ isLocalSummarizing, setIsLocalSummarizing ] = useState( false );
	const isGlobalRegenerating = useSyncExternalStore( subscribe, getSnapshot );
	const [ summary, setSummary ] = useState( '' );

	// Check if a summary group block exists and update state accordingly.
	useEffect( () => {
		const summaryGroup = findSummaryBlock( allBlocks );
		setSummary( summaryGroup ? 'exists' : '' );
	}, [ allBlocks ] );

	const hasSummary = Boolean( summary && summary.trim().length > 0 );

	const isSummarizing = hasSummary
		? isGlobalRegenerating
		: isLocalSummarizing;

	/**
	 * Handles the summarization button click.
	 */
	const handleSummarize = async () => {
		const isRegenerate = hasSummary;

		if ( isRegenerate ? globalIsRegenerating : isLocalSummarizing ) {
			return;
		}

		if ( ! ensureProvider( NOTICE_ID ) ) {
			return;
		}

		if ( isRegenerate ) {
			setGlobalIsRegenerating( true );
		} else {
			setIsLocalSummarizing( true );
		}

		dispatch( noticesStore ).removeNotice( NOTICE_ID );

		try {
			const generatedSummary = await generateSummary(
				postId as number,
				content
			);
			setSummary( generatedSummary );

			// Store the summary in post meta (will require a manual save).
			editPost( {
				meta: {
					...meta,
					ai_generated_summary: generatedSummary,
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
			setSummary( '' );
		} finally {
			if ( isRegenerate ) {
				setGlobalIsRegenerating( false );
			} else {
				setIsLocalSummarizing( false );
			}
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
		summary,
		handleSummarize,
		isContentTooShort,
		minContentLength: getSettings().minContentLength,
	};
}

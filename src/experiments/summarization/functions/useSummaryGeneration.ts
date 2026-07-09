/**
 * Shared hook for summary generation logic.
 */

/**
 * WordPress dependencies
 */
import { store as blockEditorStore } from '@wordpress/block-editor';
import { dispatch, useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect, useState } from '@wordpress/element';
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
	} );
	const { editPost } = useDispatch( editorStore );
	const [ isSummarizing, setIsSummarizing ] = useState( false );
	const [ summary, setSummary ] = useState( '' );

	// Check if a summary group block exists and update state accordingly.
	useEffect( () => {
		const summaryGroup = findSummaryBlock( allBlocks );
		setSummary( summaryGroup ? 'exists' : '' );
	}, [ allBlocks ] );

	/**
	 * Handles the summarization button click.
	 */
	const handleSummarize = async () => {
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
				// eslint-disable-next-line dot-notation
				( dispatch( blockEditorStore ) as any )[ 'replaceInnerBlocks' ](
					existingSummaryBlock.clientId,
					innerBlocks,
					false
				);
			} else {
				// Insert a new summary group block at the top.
				const summaryBlock = createSummaryBlock( generatedSummary );
				// eslint-disable-next-line dot-notation
				( dispatch( blockEditorStore ) as any )[ 'insertBlock' ](
					summaryBlock,
					0
				);
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
		hasSummary: summary && summary.trim().length > 0,
		summary,
		handleSummarize,
		isContentTooShort,
		minContentLength: getSettings().minContentLength,
	};
}

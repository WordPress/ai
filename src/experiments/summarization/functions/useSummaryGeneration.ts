/**
 * Shared hook for summary generation logic.
 */

/**
 * WordPress dependencies
 */
import { createBlock, type BlockInstance } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { dispatch, useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect, useState } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { generateSummary } from './generate-summary';

/**
 * Summary generation hook.
 */
export function useSummaryGeneration() {
	const { allBlocks, postId, content, meta } = useSelect( ( select ) => {
		return {
			allBlocks: select( blockEditorStore ).getBlocks(),
			postId: select( editorStore ).getCurrentPostId(),
			content: select( editorStore ).getEditedPostContent(),
			meta: select( editorStore ).getEditedPostAttribute( 'meta' ),
		};
	} );
	const { editPost } = useDispatch( editorStore );
	const [ isSummarizing, setIsSummarizing ] = useState( false );
	const [ summary, setSummary ] = useState( '' );

	// Check if a summary block exists and update state accordingly.
	useEffect( () => {
		const summaryBlock = allBlocks.find(
			( block: BlockInstance ) =>
				block.name === 'core/paragraph' &&
				block.attributes.aiGeneratedSummary === true
		);
		if ( summaryBlock && summaryBlock.attributes.content ) {
			setSummary( summaryBlock.attributes.content );
		}
	}, [ allBlocks ] );

	/**
	 * Handles the summarization button click.
	 */
	const handleSummarize = async () => {
		setIsSummarizing( true );
		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_summarization_error'
		);

		try {
			const generatedSummary = await generateSummary( postId, content );
			setSummary( generatedSummary );

			// Store the summary in post meta (will require a manual save).
			editPost( {
				meta: {
					...meta,
					ai_generated_summary: generatedSummary,
				},
			} );

			// Create the new summary block.
			const summaryBlock = createBlock( 'core/paragraph', {
				content: generatedSummary,
				className: 'ai-summarization-summary',
				aiGeneratedSummary: true,
			} );

			// Check if an existing AI summary block exists.
			const existingSummaryBlock = allBlocks.find(
				( block: BlockInstance ) =>
					block.name === 'core/paragraph' &&
					block.attributes.aiGeneratedSummary === true
			);

			// Replace existing block or insert at top if none exists.
			if ( existingSummaryBlock ) {
				dispatch( blockEditorStore ).replaceBlock(
					existingSummaryBlock.clientId,
					summaryBlock
				);
			} else {
				dispatch( blockEditorStore ).insertBlock( summaryBlock, 0 );
			}
		} catch ( error: any ) {
			( dispatch( noticesStore ) as any ).createErrorNotice( error, {
				id: 'ai_summarization_error',
				isDismissible: true,
			} );
			setSummary( '' );
		} finally {
			setIsSummarizing( false );
		}
	};

	return {
		isSummarizing,
		hasSummary: summary && summary.trim().length > 0,
		summary,
		handleSummarize,
	};
}

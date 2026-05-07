/**
 * Shared hook for summary generation logic.
 */

/**
 * WordPress dependencies
 */
import { createBlock } from '@wordpress/blocks';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { dispatch, useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useEffect, useState } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { generateSummary } from './generate-summary';
import { ensureProvider } from '../../../utils/provider-status';

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

	// Check if a summary block exists and update state accordingly.
	useEffect( () => {
		const summaryBlock = allBlocks.find(
			( block ) =>
				block.name === 'core/paragraph' &&
				block.attributes[ 'aiGeneratedSummary' ] === true // eslint-disable-line dot-notation
		);
		/* eslint-disable dot-notation -- summaryBlock.attributes is a BlockInstance */
		if ( summaryBlock && summaryBlock.attributes[ 'content' ] ) {
			setSummary( String( summaryBlock.attributes[ 'content' ] ) );
		}
	}, [ allBlocks ] );

	/**
	 * Handles the summarization button click.
	 */
	const handleSummarize = async () => {
		if ( ! ensureProvider( 'ai_summarization_error' ) ) {
			return;
		}

		setIsSummarizing( true );
		( dispatch( noticesStore ) as any ).removeNotice(
			'ai_summarization_error'
		);

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

			// Check if an existing AI summary block exists.
			const existingSummaryBlock = allBlocks.find(
				( block ) =>
					block.name === 'core/paragraph' &&
					block.attributes[ 'aiGeneratedSummary' ] === true
			);

			if ( existingSummaryBlock ) {
				// Update only the content of the existing block to preserve styles and other attributes.
				/* eslint-disable dot-notation -- updateBlockAttributes from store index signature */
				( dispatch( blockEditorStore ) as any )[
					'updateBlockAttributes'
				]( existingSummaryBlock.clientId, {
					content: generatedSummary,
				} );
				/* eslint-enable dot-notation */
			} else {
				// Insert a new summary block at the top.
				const summaryBlock = createBlock( 'core/paragraph', {
					content: generatedSummary,
					className: 'ai-summarization-summary',
					aiGeneratedSummary: true,
				} );
				// eslint-disable-next-line dot-notation
				( dispatch( blockEditorStore ) as any )[ 'insertBlock' ](
					summaryBlock,
					0
				);
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

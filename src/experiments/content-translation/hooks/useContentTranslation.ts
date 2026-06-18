/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ensureProvider } from '../../../utils/provider-status';
import { runAbility } from '../../../utils/run-ability';
import { flattenBlocks } from '../../../utils/blocks';
import { hasMinimumContent } from '../../../utils/word-count';
import {
	getErrorMessage,
	getSettings,
	getTranslatableBlock,
	setTranslationLoadingClass,
} from '../utils';
import { TRANSLATION_BATCH_SIZE, TRANSLATION_NOTICE_ID } from '../constants';

type UseContentTranslationReturn = {
	isContentTooShort: boolean;
	isLoading: boolean;
	progress: number;
	total: number;
	minContentLength: number;
	translate: ( languageCode: string ) => Promise< void >;
};

/**
 * Handles the content translation process, including managing loading state, progress, and error handling.
 *
 * @return {UseContentTranslationReturn} An object containing the translation state and functions.
 */
export function useContentTranslation(): UseContentTranslationReturn {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ progress, setProgress ] = useState( 0 );
	const [ total, setTotal ] = useState( 0 );

	const noticeDispatch = useDispatch( noticesStore );
	const blockEditorDispatch = useDispatch( blockEditorStore );

	const { postId, allBlocks, content } = useSelect( ( select ) => {
		return {
			postId: select( editorStore ).getCurrentPostId(),
			allBlocks: select( blockEditorStore ).getBlocks(),
			content: select( editorStore ).getEditedPostContent(),
		};
	}, [] );

	const isContentTooShort = ! hasMinimumContent(
		content || '',
		getSettings().minContentLength
	);

	const translate = async ( languageCode: string ) => {
		if ( ! ensureProvider( TRANSLATION_NOTICE_ID ) ) {
			return;
		}

		if ( isContentTooShort ) {
			return;
		}

		setProgress( 0 );
		setTotal( 0 );

		noticeDispatch.removeNotice( TRANSLATION_NOTICE_ID );

		const translatableBlocks = flattenBlocks( allBlocks )
			.map( ( block ) => getTranslatableBlock( block ) )
			.filter( ( block ) => block !== null );

		if ( translatableBlocks.length === 0 ) {
			noticeDispatch.createErrorNotice(
				__( 'No translatable content found in the post.', 'ai' ),
				{
					id: TRANSLATION_NOTICE_ID,
				}
			);

			return;
		}

		setTotal( translatableBlocks.length );
		setIsLoading( true );
		setTranslationLoadingClass( true );

		try {
			// Process blocks in batches.
			for (
				let batchStart = 0;
				batchStart < translatableBlocks.length;
				batchStart += TRANSLATION_BATCH_SIZE
			) {
				const batch = translatableBlocks.slice(
					batchStart,
					batchStart + TRANSLATION_BATCH_SIZE
				);

				const results = await Promise.all(
					batch.map( ( block ) =>
						runAbility< string >( 'ai/content-translation', {
							content: block.content,
							target_language: languageCode,
							post_id: postId,
						} )
					)
				);

				results.forEach( ( result, index ) => {
					if ( ! result || ! batch[ index ] ) {
						return;
					}

					const { clientId } = batch[ index ];
					blockEditorDispatch.updateBlockAttributes( clientId, {
						content: result,
					} );
				} );

				setProgress(
					Math.min(
						batchStart + TRANSLATION_BATCH_SIZE,
						translatableBlocks.length
					)
				);
			}
		} catch ( error ) {
			noticeDispatch.createErrorNotice( getErrorMessage( error ), {
				id: TRANSLATION_NOTICE_ID,
			} );
		} finally {
			setIsLoading( false );
			setTranslationLoadingClass( false );
		}
	};

	return {
		isLoading,
		isContentTooShort,
		progress,
		total,
		minContentLength: getSettings().minContentLength,
		translate,
	};
}

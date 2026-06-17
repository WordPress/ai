/**
 * WordPress dependencies
 */
import { useCallback, useState } from '@wordpress/element';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { getBlockText } from '../../../utils/blocks';

/**
 * Notice ID for the content translation error notice.
 */
const NOTICE_ID = 'ai_content_translation_error';

type UseContentTranslationReturn = {
	translate: ( languageCode: string ) => Promise< void >;
	canTranslate: boolean;
	isLoading: boolean;
};

/**
 * Hook to handle content translation for a specific block.
 *
 * @param clientId The block client ID.
 * @return An object containing the translate function, canTranslate boolean, and isLoading boolean.
 */
export function useContentTranslation(
	clientId: string
): UseContentTranslationReturn {
	const [ isLoading, setIsLoading ] = useState( false );

	const noticeDispatch = useDispatch( noticesStore );
	const blockEditorDispatch = useDispatch( blockEditorStore );

	const { blockContent, postId } = useSelect(
		( select ) => {
			const block = select( blockEditorStore ).getBlock( clientId );
			return {
				blockContent: block ? getBlockText( block ) : '',
				postId: select( editorStore ).getCurrentPostId() as number,
			};
		},
		[ clientId ]
	);

	const translate = useCallback(
		async ( languageCode: string ) => {
			noticeDispatch.removeNotice( NOTICE_ID );

			setIsLoading( true );

			try {
				const result = await runAbility< string >(
					'ai/content-translation',
					{
						content: blockContent,
						target_language: languageCode,
						post_id: postId,
					}
				);

				blockEditorDispatch.updateBlockAttributes( clientId, {
					content: result,
				} );
			} catch ( error: unknown ) {
				const message =
					error instanceof Error
						? error.message
						: __(
								'An error occured while translating the content.',
								'ai'
						  );

				noticeDispatch.createErrorNotice( message, {
					id: NOTICE_ID,
				} );
			} finally {
				setIsLoading( false );
			}
		},
		[ blockContent, postId, noticeDispatch, blockEditorDispatch, clientId ]
	);

	return {
		isLoading,
		translate,
		canTranslate: blockContent.trim().length > 0,
	};
}

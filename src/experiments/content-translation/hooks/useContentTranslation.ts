/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { select, useDispatch, useSelect } from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { ensureProvider } from '../../../utils/provider-status';
import { flattenBlocks } from '../../../utils/blocks';
import { hasMinimumContent } from '../../../utils/character-count';
import { getErrorMessage } from '../../../utils/errors';
import {
	getSettings,
	getTranslatableBlock,
	setTranslationLoadingClass,
	translateContent,
} from '../utils';
import { TRANSLATION_BATCH_SIZE, TRANSLATION_NOTICE_ID } from '../constants';

type UseContentTranslationReturn = {
	isContentTooShort: boolean;
	isLoading: boolean;
	progress: number;
	total: number;
	minContentLength: number;
	translate: (
		languageCode: string,
		options?: TranslateOptions
	) => Promise< void >;
};

type TranslateOptions = {
	translateTitle?: boolean;
	targetClientIds?: readonly string[] | undefined;
};

// Notice IDs for the content translation process.
const TRANSLATION_NOTICE_ID_TITLE = `${ TRANSLATION_NOTICE_ID }_title`;
const TRANSLATION_NOTICE_ID_CONTENT = `${ TRANSLATION_NOTICE_ID }_content`;

/**
 * Handles the content translation process, including managing loading state, progress, and error handling.
 *
 * @return An object with the translation state and functions.
 */
export function useContentTranslation(): UseContentTranslationReturn {
	const [ isTranslating, setIsTranslating ] = useState( false );
	const [ progress, setProgress ] = useState( 0 );
	const [ total, setTotal ] = useState( 0 );

	const noticeDispatch = useDispatch( noticesStore );
	const blockEditorDispatch = useDispatch( blockEditorStore );
	const editorDispatch = useDispatch( editorStore );

	const { minContentLength } = getSettings();

	const { postId, content } = useSelect( ( sel ) => {
		return {
			postId: sel( editorStore ).getCurrentPostId() as number,
			content: sel( editorStore ).getEditedPostContent(),
		};
	}, [] );

	const isContentTooShort = ! hasMinimumContent(
		content || '',
		minContentLength
	);

	/**
	 * Translates the content of a post.
	 *
	 * @param languageCode            The code of the language to translate the post to.
	 * @param options                 The options for the translation.
	 * @param options.translateTitle  Whether to translate the post title. Defaults to false.
	 * @param options.targetClientIds Optional client IDs used to restrict translation.
	 *                                When undefined, all eligible blocks are considered.
	 *                                When empty, no blocks are considered.
	 *                                When provided, only blocks with matching client IDs are considered.
	 * @return A promise that resolves when the translation is complete.
	 */
	const translate = async (
		languageCode: string,
		options?: TranslateOptions
	) => {
		const { translateTitle = false, targetClientIds = undefined } =
			options || {};

		// Remove any existing error notices.
		noticeDispatch.removeNotice( TRANSLATION_NOTICE_ID );

		if ( ! ensureProvider( TRANSLATION_NOTICE_ID ) ) {
			return;
		}

		if ( isContentTooShort ) {
			return;
		}

		setIsTranslating( true );

		try {
			if ( translateTitle ) {
				// Translate the title independently so failures can be reported and retried
				// without affecting block translation.
				await translatePostTitle( languageCode );
			}

			setTranslationLoadingClass( 'BLOCKS', true );
			await translateBlocksContent( languageCode, targetClientIds );
		} catch ( error ) {
			noticeDispatch.createErrorNotice( getErrorMessage( error ), {
				id: TRANSLATION_NOTICE_ID_CONTENT,
			} );
		} finally {
			setIsTranslating( false );
			setTranslationLoadingClass( 'BLOCKS', false );
			setProgress( 0 );
			setTotal( 0 );
		}
	};

	/**
	 * Translates and updates the title of a post.
	 *
	 * @param languageCode The code of the language to translate the post to.
	 * @return A promise that resolves when the translation and updates are complete.
	 */
	const translatePostTitle = async ( languageCode: string ) => {
		// Remove any existing warning notices for title translation.
		noticeDispatch.removeNotice( TRANSLATION_NOTICE_ID_TITLE );

		const title = select( editorStore ).getEditedPostAttribute( 'title' );

		if ( typeof title !== 'string' ) {
			return;
		}

		if ( title.trim().length === 0 ) {
			noticeDispatch.createWarningNotice(
				__( 'Cannot translate an empty post title.', 'ai' ),
				{
					id: TRANSLATION_NOTICE_ID_TITLE,
				}
			);

			return;
		}

		// The ability enforces the same minimum, so check it here to warn with a
		// clear reason instead of surfacing a generic request failure.
		if ( ! hasMinimumContent( title, minContentLength ) ) {
			noticeDispatch.createWarningNotice(
				sprintf(
					/* translators: %d: minimum number of characters required for translation. */
					__(
						'The post title is too short to translate. A minimum of %d characters is required.',
						'ai'
					),
					minContentLength
				),
				{
					id: TRANSLATION_NOTICE_ID_TITLE,
				}
			);

			return;
		}

		try {
			setTranslationLoadingClass( 'TITLE', true );

			const translatedTitle = await translateContent(
				title,
				languageCode,
				postId
			);

			editorDispatch.editPost( {
				title: translatedTitle,
			} );
		} catch ( error ) {
			noticeDispatch.createWarningNotice( getErrorMessage( error ), {
				id: TRANSLATION_NOTICE_ID_TITLE,
				actions: [
					{
						label: __( 'Retry title translation', 'ai' ),
						onClick: () => {
							if ( ! ensureProvider( TRANSLATION_NOTICE_ID ) ) {
								return;
							}

							translatePostTitle( languageCode );
						},
					},
				],
			} );
		} finally {
			setTranslationLoadingClass( 'TITLE', false );
		}
	};

	/**
	 * Translates and updates the content of the blocks in the post.
	 *
	 * @param languageCode    The code of the language to translate the post to.
	 * @param targetClientIds Optional client IDs used to restrict translation.
	 *                        When undefined, all eligible blocks are considered.
	 *                        When empty, no blocks are considered.
	 *                        When provided, only blocks with matching client IDs are considered.
	 * @return A promise that resolves when the translation and updates are complete.
	 */
	const translateBlocksContent = async (
		languageCode: string,
		targetClientIds?: readonly string[]
	) => {
		// If an empty target list is explicitly provided, do not translate any blocks.
		if ( targetClientIds && targetClientIds.length === 0 ) {
			return;
		}

		// Remove any existing block translation notice.
		noticeDispatch.removeNotice( TRANSLATION_NOTICE_ID_CONTENT );

		setProgress( 0 );
		setTotal( 0 );

		const allBlocks = select( blockEditorStore ).getBlocks();

		const supportedBlocks = flattenBlocks( allBlocks )
			.map( ( block ) => getTranslatableBlock( block ) )
			.filter( ( block ) => block !== null );

		// Restrict translation when a non-empty target list is provided.
		// Otherwise, consider every eligible block.
		const targetedBlocks =
			targetClientIds && targetClientIds.length > 0
				? supportedBlocks.filter( ( block ) =>
						targetClientIds.includes( block.clientId )
				  )
				: supportedBlocks;

		// The ability rejects content below the minimum length, so filter those
		// blocks out up front rather than spending a request to be told no. The
		// post-level gate measures the whole post, which can pass while short
		// individual blocks (a "FAQ" heading, say) would not.
		const translatableBlocks = targetedBlocks.filter( ( block ) =>
			hasMinimumContent( block.content, minContentLength )
		);

		const skippedBlocksCount =
			targetedBlocks.length - translatableBlocks.length;

		if ( translatableBlocks.length === 0 ) {
			noticeDispatch.createErrorNotice(
				skippedBlocksCount > 0
					? sprintf(
							/* translators: %d: minimum number of characters required for translation. */
							__(
								'No blocks were long enough to translate. Each block needs at least %d characters.',
								'ai'
							),
							minContentLength
					  )
					: __( 'No translatable content found in the post.', 'ai' ),
				{
					id: TRANSLATION_NOTICE_ID_CONTENT,
				}
			);

			return;
		}

		setTotal( translatableBlocks.length );

		// Count the blocks that were translated and applied, and those that failed.
		let translatedBlocksCount = 0;
		const failedBlockClientIds: string[] = [];

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

			// Use allSettled so failed block translations do not prevent successful
			// translations from being applied, avoiding wasted tokens from discarding
			// the whole batch.
			const results = await Promise.allSettled(
				batch.map( ( block ) =>
					translateContent( block.content, languageCode, postId )
				)
			);

			results.forEach( ( result, index ) => {
				// Promise.allSettled() preserves input order, but TypeScript cannot infer
				// that each result has a corresponding block.
				if ( ! batch[ index ] ) {
					return;
				}

				if ( result.status === 'rejected' ) {
					failedBlockClientIds.push( batch[ index ].clientId );
					return;
				}

				// Treat missing, non-string, or blank translations as failures and skip
				// updating the block.
				if (
					! result.value ||
					typeof result.value !== 'string' ||
					! result.value.trim().length
				) {
					failedBlockClientIds.push( batch[ index ].clientId );
					return;
				}

				const { clientId } = batch[ index ];
				blockEditorDispatch.updateBlockAttributes( clientId, {
					content: result.value,
				} );

				translatedBlocksCount++;
			} );

			// Report blocks actually translated, not blocks attempted, so the
			// progress label never claims work that failed.
			setProgress( translatedBlocksCount );
		}

		const warnings: string[] = [];
		const failedBlocksCount = failedBlockClientIds.length;

		if ( failedBlocksCount > 0 ) {
			warnings.push(
				sprintf(
					/* translators: %d: number of blocks that failed to be translated. */
					_n(
						'Failed to translate %d block.',
						'Failed to translate %d blocks.',
						failedBlocksCount,
						'ai'
					),
					failedBlocksCount
				)
			);
		}

		if ( skippedBlocksCount > 0 ) {
			warnings.push(
				sprintf(
					/* translators: %1$d: number of blocks skipped, %2$d: minimum number of characters required for translation. */
					_n(
						'Skipped %1$d block shorter than the %2$d character minimum.',
						'Skipped %1$d blocks shorter than the %2$d character minimum.',
						skippedBlocksCount,
						'ai'
					),
					skippedBlocksCount,
					minContentLength
				)
			);
		}

		if ( warnings.length > 0 ) {
			noticeDispatch.createWarningNotice( warnings.join( ' ' ), {
				id: TRANSLATION_NOTICE_ID_CONTENT,
				...( failedBlocksCount > 0
					? {
							actions: [
								{
									label: _n(
										'Retry failed block',
										'Retry failed blocks',
										failedBlocksCount,
										'ai'
									),
									onClick: () => {
										translate( languageCode, {
											targetClientIds:
												failedBlockClientIds,
										} );
									},
								},
							],
					  }
					: undefined ),
			} );
		}
	};

	return {
		isLoading: isTranslating,
		isContentTooShort,
		progress,
		total,
		minContentLength,
		translate,
	};
}

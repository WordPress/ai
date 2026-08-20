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

type BlockTranslationTarget =
	| { kind: 'all' }
	| { kind: 'none' }
	| { kind: 'specific'; clientIds: readonly string[] };

type TranslateOptions = {
	translateTitle?: boolean;
	blockTarget?: BlockTranslationTarget;
};

type TranslateTitleResult = {
	notice?: string;
	shouldRetry: boolean;
};

type TranslateBlocksContentResult = {
	notices: string[];
	failedBlockClientIds: string[];
};

const ERRORS_NOTICE_ID = `${ TRANSLATION_NOTICE_ID }_errors`;
const WARNING_NOTICE_ID = `${ TRANSLATION_NOTICE_ID }_warnings`;

/**
 * Handles content translation, including loading state, progress, partial failures, and user-initiated retries.
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
	 * Translates the requested title and block content of a post.
	 *
	 * @param languageCode           The code of the language to translate the post to.
	 * @param options                The options for the translation.
	 * @param options.translateTitle Whether to translate the post title. Defaults to false.
	 * @param options.blockTarget    The block translation scope. Defaults to all eligible blocks;
	 *                               use `none` to skip blocks or `specific` to target client IDs.
	 * @return A promise that resolves when the translation is complete.
	 */
	const translate = async (
		languageCode: string,
		options?: TranslateOptions
	) => {
		const { translateTitle = false, blockTarget = { kind: 'all' } } =
			options || {};

		// Remove any existing notices.
		noticeDispatch.removeNotice( ERRORS_NOTICE_ID );
		noticeDispatch.removeNotice( WARNING_NOTICE_ID );

		if ( ! ensureProvider( ERRORS_NOTICE_ID ) ) {
			return;
		}

		if ( isContentTooShort ) {
			return;
		}

		setIsTranslating( true );

		try {
			let titleResult: TranslateTitleResult = {
				shouldRetry: false,
			};

			let blocksResult: TranslateBlocksContentResult = {
				notices: [],
				failedBlockClientIds: [],
			};

			const errors: string[] = [];

			if ( translateTitle ) {
				setTranslationLoadingClass( 'TITLE', true );

				try {
					titleResult = await translatePostTitle( languageCode );
				} catch ( error ) {
					errors.push( getErrorMessage( error ) );
				} finally {
					setTranslationLoadingClass( 'TITLE', false );
				}
			}

			if ( blockTarget.kind !== 'none' ) {
				setTranslationLoadingClass( 'BLOCKS', true );

				try {
					blocksResult = await translateBlocksContent(
						languageCode,
						blockTarget
					);
				} catch ( error ) {
					errors.push( getErrorMessage( error ) );
				} finally {
					setTranslationLoadingClass( 'BLOCKS', false );
				}
			}

			if ( errors.length > 0 ) {
				noticeDispatch.createErrorNotice( errors.join( ' ' ), {
					id: ERRORS_NOTICE_ID,
				} );
			}

			const warningNotices = [
				titleResult.notice,
				...blocksResult.notices,
			].filter( ( notice ): notice is string => notice !== undefined );

			// Total retryable failures include both block content failures and title failures.
			const retryableFailuresCount =
				blocksResult.failedBlockClientIds.length +
				( titleResult.shouldRetry ? 1 : 0 );

			const retryBlockTarget: BlockTranslationTarget =
				blocksResult.failedBlockClientIds.length === 0
					? { kind: 'none' }
					: {
							kind: 'specific',
							clientIds: blocksResult.failedBlockClientIds,
					  };

			if ( warningNotices.length > 0 ) {
				noticeDispatch.createWarningNotice(
					warningNotices.join( ' ' ),
					{
						id: WARNING_NOTICE_ID,
						...( retryableFailuresCount > 0
							? {
									actions: [
										{
											label: _n(
												'Retry failed translation',
												'Retry failed translations',
												retryableFailuresCount,
												'ai'
											),
											onClick: () => {
												translate( languageCode, {
													translateTitle:
														titleResult.shouldRetry,
													blockTarget:
														retryBlockTarget,
												} );
											},
										},
									],
							  }
							: undefined ),
					}
				);
			}
		} catch ( error ) {
			noticeDispatch.createErrorNotice( getErrorMessage( error ), {
				id: ERRORS_NOTICE_ID,
			} );
		} finally {
			setIsTranslating( false );
			setProgress( 0 );
			setTotal( 0 );
		}
	};

	/**
	 * Translates and updates the title of a post.
	 *
	 * @param languageCode The code of the language to translate the post to.
	 * @return A promise that resolves with the translation result and rejects if
	 *         the title is empty or is too short.
	 */
	const translatePostTitle = async (
		languageCode: string
	): Promise< TranslateTitleResult > => {
		const title = select( editorStore ).getEditedPostAttribute( 'title' );

		if ( typeof title !== 'string' || title.trim().length === 0 ) {
			throw new Error(
				__( 'Cannot translate an empty post title.', 'ai' )
			);
		}

		// The ability enforces the same minimum, so check it here to warn with a
		// clear reason instead of surfacing a generic request failure.
		if ( ! hasMinimumContent( title, minContentLength ) ) {
			throw new Error(
				sprintf(
					/* translators: %d: minimum number of characters required for translation. */
					__(
						'The post title is too short to translate. A minimum of %d characters is required.',
						'ai'
					),
					minContentLength
				)
			);
		}

		try {
			const translatedTitle = await translateContent(
				title,
				languageCode,
				postId
			);

			if (
				! translatedTitle ||
				typeof translatedTitle !== 'string' ||
				! translatedTitle.trim().length
			) {
				return {
					notice: __( 'Failed to translate the post title.', 'ai' ),
					shouldRetry: true,
				};
			}

			editorDispatch.editPost( {
				title: translatedTitle,
			} );

			return { shouldRetry: false };
		} catch ( error ) {
			return {
				notice: getErrorMessage( error ),
				shouldRetry: true,
			};
		}
	};

	/**
	 * Translates and updates the content of the blocks in the post.
	 *
	 * @param languageCode The code of the language to translate the post to.
	 * @param target       The block translation scope: all eligible blocks, no blocks,
	 *                     or only blocks matching specific client IDs.
	 * @return A promise that resolves with notices and failed block client IDs. A `none`
	 *         target resolves without translating; other targets reject when no blocks are eligible.
	 */
	const translateBlocksContent = async (
		languageCode: string,
		target: BlockTranslationTarget
	): Promise< TranslateBlocksContentResult > => {
		const notices: string[] = [];
		const failedBlockClientIds: string[] = [];

		// A `none` target explicitly requests that block translation be skipped.
		if ( target.kind === 'none' ) {
			return {
				notices,
				failedBlockClientIds,
			};
		}

		setProgress( 0 );
		setTotal( 0 );

		const allBlocks = select( blockEditorStore ).getBlocks();

		const supportedBlocks = flattenBlocks( allBlocks )
			.map( ( block ) => getTranslatableBlock( block ) )
			.filter( ( block ) => block !== null );

		// A `specific` target restricts translation to matching client IDs;
		// an `all` target considers every eligible block.
		const targetedBlocks =
			target.kind === 'specific'
				? supportedBlocks.filter( ( block ) =>
						target.clientIds.includes( block.clientId )
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
			throw new Error(
				skippedBlocksCount > 0
					? sprintf(
							/* translators: %d: minimum number of characters required for translation. */
							__(
								'No blocks were long enough to translate. Each block needs at least %d characters.',
								'ai'
							),
							minContentLength
					  )
					: __( 'No translatable content found in the post.', 'ai' )
			);
		}

		setTotal( translatableBlocks.length );

		// Count the blocks that were translated and applied, and those that failed.
		let translatedBlocksCount = 0;

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

		const failedBlocksCount = failedBlockClientIds.length;

		if ( failedBlocksCount > 0 ) {
			notices.push(
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
			notices.push(
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

		return {
			notices,
			failedBlockClientIds,
		};
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

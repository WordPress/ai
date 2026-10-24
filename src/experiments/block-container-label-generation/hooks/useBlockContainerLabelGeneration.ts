/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { useSelect, useDispatch, select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as noticesStore } from '@wordpress/notices';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { hasMinimumContent } from '../../../utils/character-count';
import { ensureProvider } from '../../../utils/provider-status';
import {
	collectLabelCandidates,
	generateLabel,
	getDocumentOutline,
	getSettings,
	hasContainerBlocks as checkHasContainerBlocks,
} from '../utils';
import { BLOCK_CONTAINER_LABEL_GENERATION_NOTICE_ID } from '../constants';
import { getErrorMessage } from '../../../utils/errors';
import type { Block } from '../types';

export type UseBlockContainerLabelGenerationReturn = {
	isLoading: boolean;
	activeClientId: string | null;
	progress: number;
	total: number;
	minContentLength: number;
	isContentTooShort: boolean;
	hasContainerBlocks: boolean;
	generate: () => Promise< void >;
};

export function useBlockContainerLabelGeneration(): UseBlockContainerLabelGenerationReturn {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ activeClientId, setActiveClientId ] = useState< string | null >(
		null
	);
	const [ progress, setProgress ] = useState( 0 );
	const [ total, setTotal ] = useState( 0 );

	const noticeDispatch = useDispatch( noticesStore );
	const blockEditorDispatch = useDispatch( blockEditorStore );
	const editorDispatch = useDispatch( editorStore ) as {
		setIsListViewOpened?: ( open: boolean ) => void;
	};

	const { minContentLength } = getSettings();

	const { postId, content, hasContainerBlocks } = useSelect( ( sel ) => {
		const editor = sel( editorStore );
		const blockEditor = sel( blockEditorStore );
		const blocks = ( blockEditor.getBlocks() ?? [] ) as Block[];

		return {
			postId: editor.getCurrentPostId() as number,
			content: editor.getEditedPostContent(),
			hasContainerBlocks: checkHasContainerBlocks( blocks ),
		};
	}, [] );

	const isContentTooShort = ! hasMinimumContent(
		content || '',
		minContentLength
	);

	const _generateLabelsForCandidates = async (
		candidates: { clientId: string; content: string }[]
	): Promise< void > => {
		setTotal( candidates.length );
		setProgress( 0 );

		const postTitle = (
			select( editorStore ) as {
				getEditedPostAttribute?: ( attr: string ) => unknown;
			}
		 )?.getEditedPostAttribute?.( 'title' ) as string | undefined;

		const allBlocks = ( select( blockEditorStore )?.getBlocks() ??
			[] ) as Block[];

		for ( const candidate of candidates ) {
			try {
				setActiveClientId( candidate.clientId );
				const documentContext = getDocumentOutline(
					allBlocks,
					postTitle,
					candidate.clientId
				);
				const label = (
					await generateLabel(
						candidate.content,
						postId,
						documentContext
					)
				).trim();

				if ( ! label ) {
					continue;
				}

				/*
				 * Retrieve the latest block before applying the result. The user
				 * may have deleted the block or entered a manual label while the
				 * request was running.
				 */
				const latestBlock = select( blockEditorStore ).getBlock(
					candidate.clientId
				) as Block | null;

				if (
					! latestBlock ||
					latestBlock.attributes.metadata?.name?.trim()
				) {
					continue;
				}

				blockEditorDispatch.updateBlockAttributes( candidate.clientId, {
					metadata: {
						...latestBlock.attributes.metadata,
						name: label,
					},
				} );
			} finally {
				setProgress( ( currentProgress ) => currentProgress + 1 );
			}
		}
		setActiveClientId( null );
	};

	const generate = async () => {
		// Remove any existing error notices.
		noticeDispatch.removeNotice(
			BLOCK_CONTAINER_LABEL_GENERATION_NOTICE_ID
		);

		if ( ! ensureProvider( BLOCK_CONTAINER_LABEL_GENERATION_NOTICE_ID ) ) {
			return;
		}

		if ( ! hasContainerBlocks ) {
			noticeDispatch.createErrorNotice(
				__( 'No container blocks found to generate labels for.', 'ai' ),
				{
					id: BLOCK_CONTAINER_LABEL_GENERATION_NOTICE_ID,
				}
			);
			return;
		}

		if ( isContentTooShort ) {
			noticeDispatch.createErrorNotice(
				sprintf(
					/* translators: %d: minimum number of characters required */
					__(
						'Block label generation requires at least %d characters.',
						'ai'
					),
					minContentLength
				),
				{
					id: BLOCK_CONTAINER_LABEL_GENERATION_NOTICE_ID,
				}
			);
			return;
		}

		// Ensure the list view is opened so progress is immediately visible.
		editorDispatch.setIsListViewOpened?.( true );

		setIsLoading( true );

		try {
			// Get all blocks and collect candidates.
			const allBlocks = select( blockEditorStore ).getBlocks() as Block[];
			const { candidates } = collectLabelCandidates(
				allBlocks,
				minContentLength
			);

			if ( candidates.length === 0 ) {
				noticeDispatch.createInfoNotice(
					__(
						'All container blocks already have labels, or do not contain enough content.',
						'ai'
					),
					{
						id: BLOCK_CONTAINER_LABEL_GENERATION_NOTICE_ID,
					}
				);
				return;
			}

			await _generateLabelsForCandidates( candidates );
		} catch ( error ) {
			noticeDispatch.createErrorNotice( getErrorMessage( error ), {
				id: BLOCK_CONTAINER_LABEL_GENERATION_NOTICE_ID,
			} );
		} finally {
			setIsLoading( false );
			setActiveClientId( null );
			setProgress( 0 );
			setTotal( 0 );
		}
	};

	return {
		isLoading,
		activeClientId,
		progress,
		total,
		minContentLength,
		isContentTooShort,
		hasContainerBlocks,
		generate,
	};
}

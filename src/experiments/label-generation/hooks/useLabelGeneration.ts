/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { useSelect, useDispatch, select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { hasMinimumContent } from '../../../utils/character-count';
import { ensureProvider } from '../../../utils/provider-status';
import {
	collectLabelCandidates,
	generateLabel,
	getBlockSubtreeContent,
	getSettings,
} from '../utils';
import { LABEL_GENERATION_NOTICE_ID } from '../constants';
import { getErrorMessage } from '../../../utils/errors';
import type { Block } from '../types';

export type UseLabelGenerationReturn = {
	isLoading: boolean;
	activeClientId: string | null;
	progress: number;
	total: number;
	minContentLength: number;
	isContentTooShort: boolean;
	generate: () => void;
	generateSingleBlock: ( clientId: string ) => Promise< void >;
};

export function useLabelGeneration(): UseLabelGenerationReturn {
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

	const _recursivelyGenerateLabels = async (
		blocks: Block[]
	): Promise< void > => {
		const { candidates } = collectLabelCandidates(
			blocks,
			minContentLength
		);

		setTotal( candidates.length );
		setProgress( 0 );

		for ( const candidate of candidates ) {
			try {
				setActiveClientId( candidate.clientId );
				const label = (
					await generateLabel( candidate.content, postId )
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
		noticeDispatch.removeNotice( LABEL_GENERATION_NOTICE_ID );

		if ( ! ensureProvider( LABEL_GENERATION_NOTICE_ID ) ) {
			return;
		}

		if ( isContentTooShort ) {
			return;
		}

		// Ensure the list view is opened so progress is immediately visible.
		editorDispatch.setIsListViewOpened?.( true );

		setIsLoading( true );

		try {
			// Get all blocks and flatten the tree.
			const allBlocks = select( blockEditorStore ).getBlocks() as Block[];
			await _recursivelyGenerateLabels( allBlocks );
		} catch ( error ) {
			noticeDispatch.createErrorNotice( getErrorMessage( error ), {
				id: LABEL_GENERATION_NOTICE_ID,
			} );
		} finally {
			setIsLoading( false );
			setActiveClientId( null );
			setProgress( 0 );
			setTotal( 0 );
		}
	};

	const generateSingleBlock = async ( clientId: string ) => {
		noticeDispatch.removeNotice( LABEL_GENERATION_NOTICE_ID );

		if ( ! ensureProvider( LABEL_GENERATION_NOTICE_ID ) ) {
			return;
		}

		const targetBlock = select( blockEditorStore ).getBlock(
			clientId
		) as Block | null;

		if ( ! targetBlock ) {
			return;
		}

		const blockContent = getBlockSubtreeContent( targetBlock );
		if ( ! hasMinimumContent( blockContent, minContentLength ) ) {
			return;
		}

		setIsLoading( true );
		setActiveClientId( clientId );

		try {
			const label = (
				await generateLabel( blockContent, postId )
			).trim();

			if ( ! label ) {
				return;
			}

			const latestBlock = select( blockEditorStore ).getBlock(
				clientId
			) as Block | null;

			if ( ! latestBlock ) {
				return;
			}

			blockEditorDispatch.updateBlockAttributes( clientId, {
				metadata: {
					...latestBlock.attributes.metadata,
					name: label,
				},
			} );
		} catch ( error ) {
			noticeDispatch.createErrorNotice( getErrorMessage( error ), {
				id: LABEL_GENERATION_NOTICE_ID,
			} );
		} finally {
			setIsLoading( false );
			setActiveClientId( null );
		}
	};

	return {
		isLoading,
		activeClientId,
		progress,
		total,
		minContentLength,
		isContentTooShort,
		generate,
		generateSingleBlock,
	};
}

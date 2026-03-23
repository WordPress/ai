/**
 * Content resizing toolbar component.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	Flex,
	Modal,
	Spinner,
	ToolbarGroup,
	ToolbarDropdownMenu,
} from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { count } from '@wordpress/wordcount';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import type { ContentResizingAction } from '../types';

const SHORTEN_MIN_WORDS = 5;

/**
 * AI icon: Sparkling stars.
 */
const ICON_AI = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="24"
		height="24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
	>
		<path d="M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5L12 3z" />
		<path d="M18 14l.75 2.25L21 17l-2.25.75L18 20l-.75-2.25L15 17l2.25-.75L18 14z" />
	</svg>
);

/**
 * Shorten icon: two horizontal arrows pointing inward.
 */
const ICON_SHORTEN = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="24"
		height="24"
		fill="currentColor"
	>
		<path d="M3 12h5.5l-2.3-2.3 1-1L11 12.5l-3.8 3.8-1-1L8.5 13H3v-1zm18 0h-5.5l2.3-2.3-1-1L13 12.5l3.8 3.8 1-1L15.5 13H21v-1z" />
	</svg>
);

/**
 * Expand icon: two horizontal arrows pointing outward.
 */
const ICON_EXPAND = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="24"
		height="24"
		fill="currentColor"
	>
		<path d="M10 12H4.5l2.3-2.3-1-1L2 12.5l3.8 3.8 1-1L4.5 13H10v-1zm4 0h5.5l-2.3-2.3 1-1L22 12.5l-3.8 3.8-1-1L19.5 13H14v-1z" />
	</svg>
);

/**
 * Rephrase icon: curved arrow forming a circle.
 */
const ICON_REPHRASE = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="24"
		height="24"
		fill="currentColor"
	>
		<path d="M17.65 6.35A7.96 7.96 0 0012 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08A5.99 5.99 0 0112 18c-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z" />
	</svg>
);

/**
 * Undo icon: arrow curving back to the left.
 */
const ICON_UNDO = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		width="24"
		height="24"
		fill="currentColor"
	>
		<path d="M12.5 8c-2.65 0-5.05 1.04-6.83 2.74L3 8v9h9l-2.83-2.83A7.004 7.004 0 0119 12h2A9.02 9.02 0 0012.5 8z" />
	</svg>
);

/**
 * Content resizing toolbar component.
 *
 * @param props           Component props.
 * @param props.clientId  The block client ID.
 * @param props.blockName The block name.
 */
export default function ContentResizingToolbar( {
	clientId,
}: {
	clientId: string;
	blockName: string;
} ): JSX.Element {
	const [ isLoading, setIsLoading ] = useState( false );
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ suggestedContent, setSuggestedContent ] = useState< string | null >(
		null
	);
	const [ lastAction, setLastAction ] =
		useState< ContentResizingAction | null >( null );

	const { blockContent, originalContent } = useSelect(
		( select ) => {
			// eslint-disable-next-line dot-notation -- getBlock from store index signature
			const block = select( blockEditorStore )[ 'getBlock' ]( clientId );
			return {
				blockContent: ( block?.attributes?.content as string ) ?? '',
				originalContent:
					( block?.attributes?.aiOriginalContent as string ) ?? '',
			};
		},
		[ clientId ]
	);

	const hasOriginalContent = originalContent.length > 0;

	const blockEditorDispatch = useDispatch( blockEditorStore ) as any;
	const noticesDispatch = useDispatch( noticesStore ) as any;

	const handleAction = useCallback(
		async ( action: ContentResizingAction ) => {
			if ( action === 'shorten' ) {
				const wordCount = count( blockContent, 'words', {} );
				// We need at least 5 words to shorten the content.
				if ( wordCount < SHORTEN_MIN_WORDS ) {
					noticesDispatch.createErrorNotice(
						__( 'Text is too short to shorten further.', 'ai' ),
						{
							id: 'ai_content_resizing_error',
							isDismissible: true,
						}
					);
					return;
				}
			}

			setIsLoading( true );
			setLastAction( action );
			setSuggestedContent( null );
			setIsModalOpen( true );

			// Remove any previous error notices.
			noticesDispatch.removeNotice( 'ai_content_resizing_error' );

			try {
				const result = await runAbility< string >(
					'ai/content-resizing',
					{ content: blockContent, action }
				);
				setSuggestedContent( result );
			} catch ( error: unknown ) {
				const message =
					error instanceof Error
						? error.message
						: __(
								'An error occurred while resizing content.',
								'ai'
						  );
				noticesDispatch.createErrorNotice( message, {
					id: 'ai_content_resizing_error',
					isDismissible: true,
				} );
				setIsModalOpen( false );
			} finally {
				setIsLoading( false );
			}
		},
		[ blockContent, noticesDispatch ]
	);

	const handleAccept = useCallback( () => {
		if ( suggestedContent !== null ) {
			// Save the current content as original before replacing,
			// but only if we don't already have an original saved.
			const original = hasOriginalContent
				? originalContent
				: blockContent;

			blockEditorDispatch.updateBlockAttributes( clientId, {
				content: suggestedContent,
				aiOriginalContent: original,
			} );
		}
		setSuggestedContent( null );
		setLastAction( null );
		setIsModalOpen( false );
	}, [
		blockContent,
		blockEditorDispatch,
		clientId,
		hasOriginalContent,
		originalContent,
		suggestedContent,
	] );

	const handleUndo = useCallback( () => {
		if ( hasOriginalContent ) {
			blockEditorDispatch.updateBlockAttributes( clientId, {
				content: originalContent,
				aiOriginalContent: '',
			} );
		}
	}, [ blockEditorDispatch, clientId, hasOriginalContent, originalContent ] );

	const closeModal = useCallback( () => {
		setSuggestedContent( null );
		setLastAction( null );
		setIsModalOpen( false );
	}, [] );

	const handleRetry = useCallback( () => {
		if ( lastAction ) {
			handleAction( lastAction );
		}
	}, [ handleAction, lastAction ] );

	const controls: Array< {
		title: string;
		icon: JSX.Element;
		onClick: () => void;
	} > = [];

	const dropdownLabel = hasOriginalContent
		? __( 'Has AI Content', 'ai' )
		: __( 'AI Content Resize', 'ai' );

	// If we have original content,
	// add the undo control at the beginning of the dropdown.
	if ( hasOriginalContent ) {
		controls.push( {
			title: __( 'Undo AI changes', 'ai' ) as string,
			icon: ICON_UNDO,
			onClick: handleUndo,
		} );
	}

	controls.push(
		{
			title: __( 'Shorten', 'ai' ) as string,
			icon: ICON_SHORTEN,
			onClick: () => handleAction( 'shorten' ),
		},
		{
			title: __( 'Expand', 'ai' ) as string,
			icon: ICON_EXPAND,
			onClick: () => handleAction( 'expand' ),
		},
		{
			title: __( 'Rephrase', 'ai' ) as string,
			icon: ICON_REPHRASE,
			onClick: () => handleAction( 'rephrase' ),
		}
	);

	return (
		<>
			<ToolbarGroup
				className={
					hasOriginalContent
						? 'ai-content-resizing-toolbar--has-changes'
						: ''
				}
			>
				<ToolbarDropdownMenu
					icon={ ICON_AI }
					label={ dropdownLabel }
					controls={ controls }
				/>
			</ToolbarGroup>
			{ isModalOpen && (
				<Modal
					title={ __( 'Suggested replacement', 'ai' ) }
					onRequestClose={ closeModal }
					isFullScreen={ false }
					size="medium"
					className="ai-content-resizing-modal"
				>
					{ isLoading ? (
						<div className="ai-content-resizing-modal__loading">
							<Spinner />
							<p>{ __( 'Generating…', 'ai' ) }</p>
						</div>
					) : (
						<>
							<div
								className="ai-content-resizing-modal__text"
								dangerouslySetInnerHTML={ {
									__html: suggestedContent ?? '',
								} }
							/>
							<Flex
								justify="flex-start"
								gap={ 2 }
								className="ai-content-resizing-modal__actions"
							>
								<Button
									variant="primary"
									onClick={ handleAccept }
								>
									{ __( 'Accept', 'ai' ) }
								</Button>
								<Button
									variant="secondary"
									onClick={ handleRetry }
								>
									{ __( 'Retry', 'ai' ) }
								</Button>
								<Button
									variant="tertiary"
									onClick={ closeModal }
								>
									{ __( 'Discard', 'ai' ) }
								</Button>
							</Flex>
						</>
					) }
				</Modal>
			) }
		</>
	);
}

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

const AI_ICON = (
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

	const blockContent = useSelect(
		( select ) => {
			// eslint-disable-next-line dot-notation -- getBlock from store index signature
			const block = select( blockEditorStore )[ 'getBlock' ]( clientId );
			return ( block?.attributes?.content as string ) ?? '';
		},
		[ clientId ]
	);

	const blockEditorDispatch = useDispatch( blockEditorStore ) as any;
	const noticesDispatch = useDispatch( noticesStore ) as any;

	const handleAction = useCallback(
		async ( action: ContentResizingAction ) => {
			if ( action === 'shorten' ) {
				const wordCount = count( blockContent, 'words', {} );
				// We need at least 5 words to shorten the string.
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

	/**
	 * Handles accepting the suggested content.
	 */
	const handleAccept = useCallback( () => {
		if ( suggestedContent !== null ) {
			blockEditorDispatch.updateBlockAttributes( clientId, {
				content: suggestedContent,
			} );
		}
		setSuggestedContent( null );
		setLastAction( null );
		setIsModalOpen( false );
	}, [ blockEditorDispatch, clientId, suggestedContent ] );

	/**
	 * Handles closing the modal.
	 */
	const closeModal = useCallback( () => {
		setSuggestedContent( null );
		setLastAction( null );
		setIsModalOpen( false );
	}, [] );

	/**
	 * Handles retrying the action.
	 */
	const handleRetry = useCallback( () => {
		if ( lastAction ) {
			handleAction( lastAction );
		}
	}, [ handleAction, lastAction ] );

	return (
		<>
			<ToolbarGroup>
				<ToolbarDropdownMenu
					icon={ AI_ICON }
					label={ __( 'AI Content Resize', 'ai' ) }
					controls={ [
						{
							title: __( 'Shorten', 'ai' ),
							onClick: () => handleAction( 'shorten' ),
						},
						{
							title: __( 'Expand', 'ai' ),
							onClick: () => handleAction( 'expand' ),
						},
						{
							title: __( 'Rephrase', 'ai' ),
							onClick: () => handleAction( 'rephrase' ),
						},
					] }
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

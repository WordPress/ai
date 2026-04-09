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
		viewBox="36 36 184 184"
		width="24"
		height="24"
		fill="currentColor"
	>
		<path d="M193.227 204.475L160.753 120.186V89.1724H173.497V77.0521H82.4936V89.1724H95.2377V120.186L62.7643 204.475C62.2666 205.794 62 207.185 62 208.593C62 214.885 67.1012 220 73.3755 220H182.615C184.02 220 185.406 219.733 186.721 219.234C192.587 216.97 195.502 210.357 193.227 204.475ZM107.324 122.45V89.5288H148.667V122.45L164.823 164.389C161.144 163.445 157.341 162.963 153.466 162.963C142.588 162.963 132.279 166.796 124.085 173.658C118.038 178.724 110.405 181.494 102.525 181.482C96.7129 181.482 91.1318 180.003 86.2084 177.258L107.324 122.45ZM74.4064 207.88L81.8182 188.666C88.1636 191.892 95.2199 193.621 102.543 193.621C113.421 193.621 123.73 189.788 131.924 182.926C137.949 177.9 145.485 175.102 153.484 175.102C159.705 175.102 165.641 176.795 170.831 179.932L181.585 207.88H74.4064Z" />
		<path d="M126.024 95.7743L126.935 98.243C128.129 101.48 128.726 103.099 129.904 104.279C131.081 105.46 132.695 106.059 135.923 107.257L138.385 108.17L135.923 109.084C132.695 110.282 131.081 110.881 129.904 112.061C128.726 113.242 128.129 114.86 126.935 118.098L126.024 120.566L125.113 118.098C123.918 114.86 123.321 113.242 122.144 112.061C120.966 110.881 119.352 110.282 116.124 109.084L113.662 108.17L116.124 107.257C119.352 106.059 120.966 105.46 122.144 104.279C123.321 103.099 123.918 101.48 125.113 98.243L126.024 95.7743Z" />
		<path d="M132.546 130.224L133.769 133.541C135.374 137.89 136.177 140.065 137.759 141.651C139.341 143.238 141.509 144.042 145.846 145.652L149.154 146.879L145.846 148.106C141.509 149.716 139.341 150.52 137.759 152.107C136.177 153.693 135.374 155.868 133.769 160.217L132.546 163.534L131.322 160.217C129.717 155.868 128.915 153.693 127.333 152.107C125.751 150.52 123.582 149.716 119.245 148.106L115.937 146.879L119.245 145.652C123.582 144.042 125.751 143.238 127.333 141.651C128.915 140.065 129.717 137.89 131.322 133.541L132.546 130.224Z" />
		<path d="M127.882 54.0236L128.527 55.7728C129.373 58.0666 129.797 59.2135 130.631 60.0501C131.465 60.8868 132.609 61.3111 134.896 62.1599L136.641 62.8072L134.896 63.4545C132.609 64.3032 131.465 64.7276 130.631 65.5643C129.797 66.4009 129.373 67.5478 128.527 69.8416L127.882 71.5908L127.236 69.8416C126.39 67.5478 125.967 66.4009 125.132 65.5643C124.298 64.7276 123.154 64.3032 120.867 63.4545L119.123 62.8072L120.867 62.1599C123.154 61.3111 124.298 60.8868 125.132 60.0501C125.967 59.2135 126.39 58.0666 127.236 55.7728L127.882 54.0236Z" />
		<path d="M140.622 36L141.083 37.2495C141.688 38.8879 141.99 39.7071 142.586 40.3047C143.182 40.9023 143.999 41.2054 145.633 41.8117L146.879 42.274L145.633 42.7364C143.999 43.3426 143.182 43.6458 142.586 44.2434C141.99 44.841 141.688 45.6602 141.083 47.2986L140.622 48.548L140.161 47.2986C139.557 45.6602 139.254 44.841 138.658 44.2434C138.062 43.6458 137.245 43.3426 135.612 42.7364L134.366 42.274L135.612 41.8117C137.245 41.2054 138.062 40.9023 138.658 40.3047C139.254 39.7071 139.557 38.8879 140.161 37.2495L140.622 36Z" />
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
			/* eslint-disable dot-notation */
			const block = select( blockEditorStore )[ 'getBlock' ]( clientId );
			return {
				blockContent:
					( block?.attributes[ 'content' ] as string ) ?? '',
				originalContent:
					( block?.attributes[ 'aiOriginalContent' ] as string ) ??
					'',
			};
			/* eslint-enable dot-notation */
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
					label={ __( 'Resize Content', 'ai' ) }
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
									{ __( 'Regenerate', 'ai' ) }
								</Button>
							</Flex>
						</>
					) }
				</Modal>
			) }
		</>
	);
}

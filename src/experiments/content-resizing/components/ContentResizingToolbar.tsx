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
import {
	useState,
	useCallback,
	useMemo,
	useRef,
	useLayoutEffect,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { store as editorStore } from '@wordpress/editor';

/**
 * Internal dependencies
 */
import { runAbility } from '../../../utils/run-ability';
import { getBlockText } from '../../../utils/blocks';
import type { ContentResizingAction, ContentResizingData } from '../types';
import { ICON_SHORTEN, ICON_EXPAND, ICON_REPHRASE } from '../icons';
import { ensureProvider } from '../../../utils/provider-status';
import { getContentCount } from '../../../utils/character-count';
import AIIcon from '../../../../routes/ai-home/ai-icon';

const SHORTEN_MIN_CONTENT_LENGTH = 100;
const NOTICE_ID = 'ai_content_resizing_error';

const getSettings = (): ContentResizingData => {
	const settings = ( window as any ).aiContentResizingData ?? {};

	return {
		enabled: settings.enabled ?? false,
		minContentLength:
			settings.minContentLength ?? SHORTEN_MIN_CONTENT_LENGTH,
	};
};

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

	const acceptButtonRef = useRef< HTMLDivElement | null >( null );

	// Keep the modal scrolled to the action buttons when it opens or updates.
	useLayoutEffect( () => {
		if ( ! isModalOpen ) {
			return;
		}

		acceptButtonRef.current?.scrollIntoView( {
			block: 'start',
			behavior: 'auto',
		} );
	}, [ isModalOpen, suggestedContent ] );

	const { blockContent, isResized, postId } = useSelect(
		( select ) => {
			/* eslint-disable dot-notation */
			const block = select( blockEditorStore )[ 'getBlock' ]( clientId );
			return {
				blockContent: block ? getBlockText( block ) : '',
				isResized:
					( block?.attributes[ 'aiResized' ] as boolean ) ?? false,
				postId: select( editorStore )[ 'getCurrentPostId' ]() as number,
			};
			/* eslint-enable dot-notation */
		},
		[ clientId ]
	);

	const blockEditorDispatch = useDispatch( blockEditorStore ) as any;
	const noticesDispatch = useDispatch( noticesStore );

	const handleAction = useCallback(
		async ( action: ContentResizingAction ) => {
			if ( ! ensureProvider( NOTICE_ID ) ) {
				return;
			}

			if ( action === 'shorten' ) {
				const contentCount = getContentCount( blockContent );
				// We need at least the minimum content length to shorten.
				if ( contentCount < getSettings().minContentLength ) {
					noticesDispatch.createErrorNotice(
						__( 'Text is too short to shorten further.', 'ai' ),
						{
							id: NOTICE_ID,
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
			noticesDispatch.removeNotice( NOTICE_ID );

			try {
				const result = await runAbility< string >(
					'ai/content-resizing',
					{ content: blockContent, action, post_id: postId }
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
					id: NOTICE_ID,
					isDismissible: true,
				} );
				setIsModalOpen( false );
			} finally {
				setIsLoading( false );
			}
		},
		[ blockContent, noticesDispatch, postId ]
	);

	const handleAccept = useCallback( () => {
		if ( suggestedContent !== null ) {
			blockEditorDispatch.updateBlockAttributes( clientId, {
				content: suggestedContent,
				aiResized: true,
			} );
		}
		setSuggestedContent( null );
		setLastAction( null );
		setIsModalOpen( false );
	}, [ blockEditorDispatch, clientId, suggestedContent ] );

	const closeModal = useCallback( () => {
		setSuggestedContent( null );
		setLastAction( null );
		setIsModalOpen( false );
	}, [] );

	const handleRetry = useCallback( () => {
		if ( lastAction && ! isLoading ) {
			handleAction( lastAction );
		}
	}, [ handleAction, isLoading, lastAction ] );

	// Calculate the character difference between the original and suggested content.
	const characterDiff = useMemo( () => {
		if ( suggestedContent === null ) {
			return null;
		}

		const delta =
			getContentCount( suggestedContent ) -
			getContentCount( blockContent );

		if ( delta === 0 ) {
			return {
				modifier: 'neutral' as const,
				label: __( 'No change', 'ai' ),
				ariaLabel: __( 'No change in character count', 'ai' ),
			};
		}

		const magnitude = Math.abs( delta );

		if ( delta > 0 ) {
			const label = sprintf(
				/* translators: %d: Number of characters added. */
				_n( '+%d character', '+%d characters', magnitude, 'ai' ),
				magnitude
			);

			const ariaLabel = sprintf(
				/* translators: %d: Number of characters added. */
				_n(
					'%d character added',
					'%d characters added',
					magnitude,
					'ai'
				),
				magnitude
			);

			return {
				modifier: 'positive' as const,
				label,
				ariaLabel,
			};
		}

		const label = sprintf(
			/* translators: %d: Number of characters removed. */
			_n( '−%d character', '−%d characters', magnitude, 'ai' ),
			magnitude
		);

		const ariaLabel = sprintf(
			/* translators: %d: Number of characters removed. */
			_n(
				'%d character removed',
				'%d characters removed',
				magnitude,
				'ai'
			),
			magnitude
		);

		return {
			modifier: 'negative' as const,
			label,
			ariaLabel,
		};
	}, [ blockContent, suggestedContent ] );

	const controls: Array< {
		title: string;
		icon: JSX.Element;
		onClick: () => void;
	} > = [
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
		},
	];

	return (
		<>
			<ToolbarGroup
				className={
					isResized ? 'ai-content-resizing-toolbar--has-changes' : ''
				}
			>
				<ToolbarDropdownMenu
					icon={
						<AIIcon className="ai-content-resizing-toolbar__icon" />
					}
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
					focusOnMount="firstContentElement"
				>
					<section
						className="ai-content-resizing-modal__panel"
						aria-label={ __( 'Original content', 'ai' ) }
					>
						<div className="ai-content-resizing-modal__label">
							<span>{ __( 'Original', 'ai' ) }</span>
						</div>
						<div
							className="ai-content-resizing-modal__text ai-content-resizing-modal__text--original"
							dangerouslySetInnerHTML={ {
								__html: blockContent,
							} }
						/>
					</section>
					<section
						className="ai-content-resizing-modal__panel"
						aria-label={ __( 'Suggested content', 'ai' ) }
					>
						<div className="ai-content-resizing-modal__label">
							<span>{ __( 'Suggested', 'ai' ) }</span>
							{ ! isLoading && characterDiff && (
								<span
									className={ `ai-content-resizing-modal__diff ai-content-resizing-modal__diff--${ characterDiff.modifier }` }
									aria-label={ characterDiff.ariaLabel }
								>
									{ characterDiff.label }
								</span>
							) }
						</div>
						{ isLoading ? (
							<div
								className="ai-content-resizing-modal__text ai-content-resizing-modal__loading"
								role="status"
								aria-live="polite"
							>
								<div className="ai-content-resizing-modal__loading-status">
									<Spinner />
									<span>{ __( 'Generating…', 'ai' ) }</span>
								</div>
								<div
									className="ai-content-resizing-modal__loading-skeleton"
									aria-hidden="true"
								>
									{ Array.from( { length: 3 } ).map(
										( _, index ) => (
											<span
												key={ index }
												className="ai-content-resizing-modal__loading-skeleton-line"
											/>
										)
									) }
								</div>
							</div>
						) : (
							<div
								className="ai-content-resizing-modal__text"
								dangerouslySetInnerHTML={ {
									__html: suggestedContent ?? '',
								} }
							/>
						) }
					</section>
					<Flex
						justify="flex-start"
						gap={ 2 }
						className="ai-content-resizing-modal__actions"
					>
						<Button
							variant="primary"
							onClick={ handleAccept }
							disabled={ isLoading || suggestedContent === null }
							accessibleWhenDisabled
							ref={ acceptButtonRef }
							__next40pxDefaultSize
						>
							{ __( 'Accept', 'ai' ) }
						</Button>
						<Button
							variant="secondary"
							onClick={ handleRetry }
							disabled={ isLoading || lastAction === null }
							isBusy={ isLoading }
							accessibleWhenDisabled
							__next40pxDefaultSize
						>
							{ isLoading
								? __( 'Generating…', 'ai' )
								: __( 'Regenerate', 'ai' ) }
						</Button>
					</Flex>
				</Modal>
			) }
		</>
	);
}

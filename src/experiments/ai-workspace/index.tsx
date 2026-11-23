/**
 * AI Workspace admin screen entry point.
 */

/**
 * Internal dependencies
 */
import './index.scss';

/**
 * WordPress dependencies
 */
import { Page } from '@wordpress/admin-ui';
import { Button, Notice } from '@wordpress/components';
import { Notice as UINotice } from '@wordpress/ui';
import { useInstanceId } from '@wordpress/compose';
import domReady from '@wordpress/dom-ready';
import {
	createRoot,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { sidebar as sidebarIcon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import AiIcon from '../../../routes/ai-home/ai-icon';
import ChatHistory from './components/ChatHistory';
import ContextScope from './components/ContextScope';
import PromptInput from './components/PromptInput';
import Transcript from './components/Transcript';
import { useStickToBottom } from './hooks/useStickToBottom';
import { useTurn, type OpenResult } from './hooks/useTurn';
import { chatFromUrl, urlForChat } from './utils/chat';
import { getSeedNotice, getSeedPrompt, urlWithoutPrompt } from './utils/seed';
import type { Availability, LocalizedData } from './types';

/**
 * Renders the screen chrome shared by every workspace state.
 *
 * @param props          Component props.
 * @param props.actions  Header actions, when the state has any.
 * @param props.children The screen body.
 * @return The rendered frame.
 */
function WorkspaceFrame( {
	actions,
	children,
}: {
	actions?: React.ReactNode;
	children: React.ReactNode;
} ) {
	return (
		<Page
			className="ai-workspace__page"
			visual={ <AiIcon /> }
			title={ __( 'AI Workspace', 'ai' ) }
			subTitle={ __(
				'Ask about the content on this site, plan what to publish next, or draft code and patterns.',
				'ai'
			) }
			actions={ actions }
		>
			{ children }
		</Page>
	);
}

/**
 * Returns the explanatory message for a workspace that cannot operate, worded as on the AI settings screen.
 *
 * @param availability Availability reported by the server.
 * @return The explanation, or null when the workspace is ready.
 */
function getUnavailableMessage(
	availability: Availability
): { status: 'error' | 'warning'; body: string } | null {
	switch ( availability.status ) {
		case 'no-credentials':
			return {
				status: 'error',
				body: __(
					'The AI Workspace requires a valid AI Connector to function properly. Verify you have one or more AI Connectors configured.',
					'ai'
				),
			};
		case 'no-function-calling':
			return {
				status: 'warning',
				body: __(
					"Your configured AI Connectors don't offer a model that supports function calling, which the AI Workspace needs to read your content and propose changes.",
					'ai'
				),
			};
		case 'ready':
		default:
			return null;
	}
}

/**
 * Renders the explanatory state shown when the workspace cannot operate.
 *
 * @param props               Component props.
 * @param props.availability  Availability reported by the server.
 * @param props.connectorsUrl URL of the Connectors screen.
 * @return The unavailable state, or null when the workspace is ready.
 */
function UnavailableState( {
	availability,
	connectorsUrl,
}: {
	availability: Availability;
	connectorsUrl: string;
} ) {
	const message = getUnavailableMessage( availability );

	if ( ! message ) {
		return null;
	}

	// Matches the AI settings screen's notice.
	return (
		<div className="ai-workspace__unavailable">
			<UINotice.Root intent={ message.status }>
				<UINotice.Description>{ message.body }</UINotice.Description>
				{ connectorsUrl && (
					<UINotice.Actions>
						<UINotice.ActionLink href={ connectorsUrl }>
							{ __( 'Manage Connectors', 'ai' ) }
						</UINotice.ActionLink>
					</UINotice.Actions>
				) }
			</UINotice.Root>
		</div>
	);
}

/**
 * Width at and below which the history panel is a drawer (wp-admin's mobile breakpoint).
 */
const DRAWER_QUERY = '(max-width: 782px)';

/**
 * Reports whether the screen is narrow enough for the history drawer.
 *
 * @return Whether it is.
 */
function isNarrow(): boolean {
	return (
		'function' === typeof window.matchMedia &&
		window.matchMedia( DRAWER_QUERY ).matches
	);
}

/**
 * Renders the AI Workspace app shell.
 *
 * @param props      Component props.
 * @param props.data Localized data provided by the server.
 * @return The workspace app shell.
 */
function WorkspaceApp( { data }: { data: LocalizedData } ) {
	const seed = data.seed ?? null;
	const [ prompt, setPrompt ] = useState( () => getSeedPrompt( seed ) );
	const seedNotice = getSeedNotice( seed );
	// A prompt from a link was written by the link's author; flag it until edited or sent.
	const [ fromLink, setFromLink ] = useState(
		() => seed?.status === 'prompt' && '' !== seed.prompt
	);
	const changePrompt = useCallback( ( value: string ): void => {
		setPrompt( value );
		setFromLink( false );
	}, [] );

	useEffect( () => {
		const next = urlWithoutPrompt( window.location.href );

		if ( next !== window.location.href ) {
			window.history.replaceState( null, '', next );
		}
	}, [] );
	const inputRef = useRef< HTMLTextAreaElement | null >( null );
	const hasRun = useRef( false );
	const {
		announcement,
		clear,
		conversationId,
		dismiss,
		entries,
		isOpening,
		followUp,
		isDismissing,
		isRunning,
		isStopping,
		open,
		outcomes,
		retry,
		scope,
		send,
		setScope,
		settled,
		stop,
		summary,
	} = useTurn( data );
	const { ref: transcriptRef, stick: stickToBottom } =
		useStickToBottom< HTMLElement >( entries );

	const [ narrow, setNarrow ] = useState( isNarrow );
	const [ historyOpen, setHistoryOpen ] = useState( false );
	const [ missing, setMissing ] = useState( '' );
	const historyId = useInstanceId( WorkspaceApp, 'ai-workspace-history' );
	const historyToggle = useRef< HTMLButtonElement | null >( null );
	const historyPanel = useRef< HTMLElement | null >( null );

	useEffect( () => {
		if ( 'function' !== typeof window.matchMedia ) {
			return undefined;
		}

		const query = window.matchMedia( DRAWER_QUERY );
		// Crossing the breakpoint resets the panel to that width's default.
		const update = (): void => {
			setNarrow( query.matches );
			setHistoryOpen( false );
		};

		query.addEventListener( 'change', update );

		return () => query.removeEventListener( 'change', update );
	}, [] );

	// A drawer takes focus when it opens, so a keyboard user lands in it.
	useEffect( () => {
		if ( narrow && historyOpen ) {
			historyPanel.current
				?.querySelector< HTMLElement >( 'button, input' )
				?.focus();
		}
	}, [ narrow, historyOpen ] );

	const closeDrawer = useCallback( (): void => {
		setHistoryOpen( false );
		historyToggle.current?.focus();
	}, [] );

	// Escape closes the drawer and hands focus back to its toggle.
	useEffect( () => {
		if ( ! narrow || ! historyOpen ) {
			return undefined;
		}

		const onKeyDown = ( event: KeyboardEvent ): void => {
			if (
				'Escape' === event.key &&
				historyPanel.current?.contains( event.target as Node )
			) {
				event.stopPropagation();
				closeDrawer();
			}
		};

		document.addEventListener( 'keydown', onKeyDown );

		return () => document.removeEventListener( 'keydown', onKeyDown );
	}, [ closeDrawer, historyOpen, narrow ] );

	const openChat = useCallback(
		async ( id: string ): Promise< OpenResult > => {
			setMissing( '' );

			if ( narrow ) {
				closeDrawer();
			}

			// An opened chat starts at its newest turn.
			stickToBottom();

			const result = await open( id );

			if ( 'not-found' === result || 'failed' === result ) {
				clear();
				setMissing(
					'not-found' === result
						? __( 'That space was not found.', 'ai' )
						: __( 'That space could not be opened.', 'ai' )
				);
			}

			return result;
		},
		[ clear, closeDrawer, narrow, open, stickToBottom ]
	);

	const newChat = useCallback( (): void => {
		setMissing( '' );
		stickToBottom();
		clear();

		if ( narrow ) {
			closeDrawer();
		}
	}, [ clear, closeDrawer, narrow, stickToBottom ] );

	// Reopen the chat named in the URL; drop it from the URL only if the chat is gone.
	const loading = useRef( false );
	const opened = useRef( false );

	useEffect( () => {
		if ( loading.current || 'ready' !== data.availability.status ) {
			return;
		}

		loading.current = true;

		const id = chatFromUrl( window.location.search );

		if ( '' === id ) {
			opened.current = true;
			return;
		}

		void openChat( id ).then( ( result ) => {
			opened.current = true;

			if ( 'not-found' === result ) {
				window.history.replaceState(
					null,
					'',
					urlForChat( window.location.href, '' )
				);
			}
		} );
	}, [ data.availability.status, openChat ] );

	// The chat on screen is kept in the URL, so a reload reopens it.
	useEffect( () => {
		if ( ! opened.current ) {
			return;
		}

		const next = urlForChat( window.location.href, conversationId );

		if ( next !== window.location.href ) {
			window.history.replaceState( null, '', next );
		}
	}, [ conversationId ] );

	// Return focus to the input after a turn, since it was disabled while running.
	useEffect( () => {
		if ( isRunning ) {
			hasRun.current = true;
			return;
		}

		// Only after a turn: focus is not stolen when the screen first loads.
		if ( hasRun.current ) {
			inputRef.current?.focus();
		}
	}, [ isRunning ] );

	// After a dismissal, focus the input once it is enabled again.
	const [ dismissFocus, setDismissFocus ] = useState( 0 );
	const focusedDismissal = useRef( 0 );

	useEffect( () => {
		if ( isDismissing || dismissFocus === focusedDismissal.current ) {
			return;
		}

		focusedDismissal.current = dismissFocus;
		inputRef.current?.focus();
	}, [ dismissFocus, isDismissing ] );

	if ( data.availability.status !== 'ready' ) {
		return (
			<WorkspaceFrame>
				<div className="ai-workspace__app">
					<UnavailableState
						availability={ data.availability }
						connectorsUrl={ data.connectorsUrl }
					/>
				</div>
			</WorkspaceFrame>
		);
	}

	return (
		<WorkspaceFrame
			actions={
				<Button
					ref={ historyToggle }
					size="compact"
					variant="tertiary"
					icon={ sidebarIcon }
					aria-expanded={ historyOpen }
					aria-controls={ historyId }
					onClick={ () => setHistoryOpen( ( current ) => ! current ) }
				>
					{ __( 'Spaces', 'ai' ) }
				</Button>
			}
		>
			<div className="ai-workspace__body">
				<aside
					id={ historyId }
					ref={ historyPanel }
					className={
						'ai-workspace__history' +
						( narrow ? ' is-drawer' : '' ) +
						( historyOpen ? ' is-open' : '' )
					}
					aria-label={ __( 'Spaces', 'ai' ) }
					hidden={ ! historyOpen }
				>
					<ChatHistory
						rest={ data.rest }
						currentId={ conversationId }
						refreshKey={ settled }
						onOpen={ ( id ) => {
							void openChat( id );
						} }
						onNew={ newChat }
						onRemoved={ ( id ) => {
							if ( id === conversationId ) {
								clear();
							}
						} }
					/>
				</aside>

				<div
					className={
						'ai-workspace__app' +
						( entries.length > 0 ? ' is-active' : '' )
					}
				>
					{ /* The screen's only live region; updated at sentence boundaries. */ }
					<div
						className="screen-reader-text"
						aria-live="polite"
						aria-atomic="true"
					>
						{ announcement }
					</div>

					{ '' !== missing && (
						<Notice
							status="warning"
							onRemove={ () => setMissing( '' ) }
						>
							{ missing }
						</Notice>
					) }

					{ seedNotice && (
						<Notice
							className="ai-workspace__seed-notice"
							status="warning"
							isDismissible={ false }
						>
							{ seedNotice }
						</Notice>
					) }

					<section
						ref={ transcriptRef }
						className="ai-workspace__transcript"
						aria-label={ __( 'Conversation transcript', 'ai' ) }
						tabIndex={ 0 }
					>
						<Transcript
							entries={ entries }
							onRetry={ retry }
							onSuggest={ changePrompt }
							rest={ data.rest }
							conversationId={ conversationId }
							outcomes={ outcomes }
							onFollowUp={ ( turnScope, forConversation ) => {
								void followUp( turnScope, forConversation );
							} }
							onAnswer={ ( choice, turnScope ) => {
								stickToBottom();
								void send( choice, turnScope );
							} }
							onDismiss={ async ( turnId ) => {
								const result = await dismiss( turnId );

								if ( 'dismissed' === result ) {
									setDismissFocus( ( count ) => count + 1 );
								}

								return result;
							} }
						/>
					</section>

					<form
						className="ai-workspace__composer"
						aria-label={ __( 'Send a message', 'ai' ) }
						onSubmit={ ( event ) => event.preventDefault() }
					>
						{ fromLink && (
							<Notice
								className="ai-workspace__prefill-notice"
								status="info"
								isDismissible={ false }
							>
								{ __(
									'This message was filled in from a link. Check it before you send it.',
									'ai'
								) }
							</Notice>
						) }

						<PromptInput
							value={ prompt }
							onChange={ changePrompt }
							onSubmit={ () => {
								// Sending is a request to see the reply, so follow it even when scrolled up.
								stickToBottom();
								void send( prompt, scope );
								changePrompt( '' );
							} }
							onStop={ () => {
								void stop();
							} }
							// The composer is inert while a chat loads, as while a turn runs.
							isRunning={ isRunning || isOpening }
							isBusy={ isDismissing }
							isStopping={ isStopping || isOpening }
							inputRef={ inputRef }
							scopeControl={
								<ContextScope
									value={ scope }
									onChange={ setScope }
									disabled={ isRunning }
								/>
							}
						/>

						<p className="screen-reader-text">{ summary }</p>
					</form>
				</div>
			</div>
		</WorkspaceFrame>
	);
}

domReady( () => {
	const container = document.getElementById( 'ai-workspace-root' );

	if ( ! container ) {
		return;
	}

	const data = window.aiWorkspace;

	if ( ! data ) {
		return;
	}

	createRoot( container ).render( <WorkspaceApp data={ data } /> );
} );

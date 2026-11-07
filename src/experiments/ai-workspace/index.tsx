/**
 * AI Workspace admin screen entry point.
 *
 * Renders the app shell — a transcript region, a context-scope control and a
 * multi-line prompt input — and drives one conversation through the turn route,
 * or shows an explanatory state when the workspace cannot operate.
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
import { useTurn, type OpenResult } from './hooks/useTurn';
import { chatFromUrl, urlForChat } from './utils/chat';
import { getSeedNotice, getSeedPrompt, urlWithoutPrompt } from './utils/seed';
import type { Availability, LocalizedData } from './types';

/**
 * Renders the screen chrome shared by every workspace state.
 *
 * The workspace takes over the admin screen, so it carries its own identity and
 * its own way back rather than borrowing the surrounding wp-admin furniture.
 * The unavailable states use the same frame: a person who lands here with no
 * credentials should still see where they are.
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
 * Returns the explanatory message for a workspace that cannot operate.
 *
 * @param availability Availability reported by the server.
 * @param settingsUrl  URL of the plugin settings screen.
 * @return The explanation, or null when the workspace is ready.
 */
function getUnavailableMessage(
	availability: Availability,
	settingsUrl: string
): { title: string; body: string; actionUrl: string } | null {
	switch ( availability.status ) {
		case 'no-credentials':
			return {
				title: __( 'No AI credentials configured', 'ai' ),
				body: __(
					'The AI Workspace needs valid AI provider credentials before it can hold a conversation. Add a provider connection to continue.',
					'ai'
				),
				actionUrl: settingsUrl,
			};
		case 'no-function-calling':
			return {
				title: __( 'No compatible model available', 'ai' ),
				body: __(
					'The AI Workspace needs a model that supports function calling so the assistant can read your content and propose changes. None of the configured providers offer one.',
					'ai'
				),
				actionUrl: settingsUrl,
			};
		case 'ready':
		default:
			return null;
	}
}

/**
 * Renders the explanatory state shown when the workspace cannot operate.
 *
 * @param props              Component props.
 * @param props.availability Availability reported by the server.
 * @param props.settingsUrl  URL of the plugin settings screen.
 * @return The unavailable state, or null when the workspace is ready.
 */
function UnavailableState( {
	availability,
	settingsUrl,
}: {
	availability: Availability;
	settingsUrl: string;
} ) {
	const message = getUnavailableMessage( availability, settingsUrl );

	if ( ! message ) {
		return null;
	}

	return (
		<Notice status="warning" isDismissible={ false }>
			<h2>{ message.title }</h2>
			<p>{ message.body }</p>
			<p>
				<a href={ message.actionUrl }>
					{ __( 'Open AI settings', 'ai' ) }
				</a>
			</p>
		</Notice>
	);
}

/**
 * The width at and below which the history panel is a drawer, as wp-admin's own
 * mobile breakpoint.
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
	/*
	 * A handoff opens the composer with a prompt naming the seeded post. It is
	 * prefilled rather than sent: the title in it is author-controlled text, so
	 * the person reads and approves it before any turn is taken.
	 */
	const [ prompt, setPrompt ] = useState( () => getSeedPrompt( seed ) );
	const seedNotice = getSeedNotice( seed );

	// The palette's prompt is in the composer now, so a reload must not bring it back.
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
		entries,
		isOpening,
		followUp,
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

	// The panel starts open as a sidebar, and closed as a drawer.
	const [ narrow, setNarrow ] = useState( isNarrow );
	const [ historyOpen, setHistoryOpen ] = useState( () => ! isNarrow() );
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
			setHistoryOpen( ! query.matches );
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

			const result = await open( id );

			if ( 'not-found' === result || 'failed' === result ) {
				clear();
				setMissing(
					'not-found' === result
						? __( 'That chat was not found.', 'ai' )
						: __( 'That chat could not be opened.', 'ai' )
				);
			}

			return result;
		},
		[ clear, closeDrawer, narrow, open ]
	);

	const newChat = useCallback( (): void => {
		setMissing( '' );
		clear();

		if ( narrow ) {
			closeDrawer();
		}
	}, [ clear, closeDrawer, narrow ] );

	/*
	 * A chat named in the URL reopens when the screen loads. The URL is left
	 * alone until that load settles, so a reload that fails to reach the server
	 * keeps the chat in the address to try again; only a chat that is gone is
	 * dropped from it.
	 */
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

	// Focus returns to the input when a turn finishes, so a keyboard user is
	// never left with focus on a control that has just been disabled.
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

	if ( data.availability.status !== 'ready' ) {
		return (
			<WorkspaceFrame>
				<div className="ai-workspace__app">
					<UnavailableState
						availability={ data.availability }
						settingsUrl={ data.settingsUrl }
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
					{ __( 'Chats', 'ai' ) }
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
					aria-label={ __( 'Chats', 'ai' ) }
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

				<div className="ai-workspace__app">
					{ /*
					 * The one polite live region for this screen. The transcript itself
					 * updates on every streamed chunk and is deliberately not live;
					 * this region is updated at sentence boundaries and on completion.
					 */ }
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
						className="ai-workspace__transcript"
						aria-label={ __( 'Conversation transcript', 'ai' ) }
						tabIndex={ 0 }
					>
						<Transcript
							entries={ entries }
							onRetry={ retry }
							onSuggest={ setPrompt }
							rest={ data.rest }
							conversationId={ conversationId }
							outcomes={ outcomes }
							onFollowUp={ ( turnScope, forConversation ) => {
								void followUp( turnScope, forConversation );
							} }
							onAnswer={ ( choice, turnScope ) => {
								void send( choice, turnScope );
							} }
						/>
					</section>

					<form
						className="ai-workspace__composer"
						aria-label={ __( 'Send a message', 'ai' ) }
						onSubmit={ ( event ) => event.preventDefault() }
					>
						<PromptInput
							value={ prompt }
							onChange={ setPrompt }
							onSubmit={ () => {
								void send( prompt, scope );
								setPrompt( '' );
							} }
							onStop={ () => {
								void stop();
							} }
							// The composer is inert while a chat loads, as while a turn runs.
							isRunning={ isRunning || isOpening }
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

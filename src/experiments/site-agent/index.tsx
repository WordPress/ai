/**
 * Site Agent admin screen entry point.
 */

/**
 * Internal dependencies
 */
import './index.scss';
import ConversationSidebar from './components/ConversationSidebar';
import Markdown from './components/Markdown';
import ProposalCard from './components/ProposalCard';
import {
	ApiError,
	approveProposal,
	archiveConversation,
	declineProposal,
	deleteConversation,
	getConversation,
	listConversations,
	renameConversation,
	sendTurn,
	updateProposal,
} from './api';

/**
 * WordPress dependencies
 */
import {
	Button,
	Modal,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import domReady from '@wordpress/dom-ready';
import {
	createRoot,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type {
	ConversationSummary,
	Entry,
	LocalizedData,
	Proposal,
} from './types';

const SUGGESTIONS = [
	__( 'What version of WordPress and PHP am I running?', 'ai' ),
	__( 'Create a draft post about the history of WordPress.', 'ai' ),
	__(
		'Look at my recent posts and suggest three topics I have not covered yet.',
		'ai'
	),
	__(
		'Change the site tagline to something that fits what I actually write about.',
		'ai'
	),
];

const PROMPT_ID = 'ai-site-agent-prompt';

let entryCounter = 0;

/**
 * Returns a unique entry key for locally appended entries.
 */
function entryId(): string {
	entryCounter += 1;

	return `entry-${ entryCounter }`;
}

/**
 * Explains why the Site Agent cannot operate.
 *
 * @param props              Component props.
 * @param props.availability Availability reported by the server.
 * @param props.settingsUrl  URL of the plugin settings screen.
 */
function UnavailableState( {
	availability,
	settingsUrl,
}: {
	availability: LocalizedData[ 'availability' ];
	settingsUrl: string;
} ): React.JSX.Element {
	const title =
		'no-credentials' === availability.status
			? __( 'No AI credentials configured', 'ai' )
			: __( 'No compatible model available', 'ai' );

	const body =
		'no-credentials' === availability.status
			? __(
					'The Site Agent needs valid AI provider credentials before it can hold a conversation.',
					'ai'
			  )
			: __(
					'The Site Agent needs a model that supports function calling. None of the configured providers offer one.',
					'ai'
			  );

	return (
		<Notice status="warning" isDismissible={ false }>
			<h2>{ title }</h2>
			<p>{ body }</p>
			<p>
				<a href={ settingsUrl }>{ __( 'Open AI settings', 'ai' ) }</a>
			</p>
		</Notice>
	);
}

/**
 * Returns the visible label for a transcript role.
 *
 * @param role The entry role.
 */
function roleLabel( role: Entry[ 'role' ] ): string {
	switch ( role ) {
		case 'user':
			return __( 'You', 'ai' );
		case 'assistant':
			return __( 'Assistant', 'ai' );
	}

	return __( 'Result', 'ai' );
}

/**
 * Renders one transcript entry.
 *
 * @param props       Component props.
 * @param props.entry The entry.
 */
function TranscriptEntry( { entry }: { entry: Entry } ): React.JSX.Element {
	return (
		<div
			className={ `ai-site-agent__entry ai-site-agent__entry--${ entry.role }` }
		>
			<span className="ai-site-agent__entry-role">
				{ roleLabel( entry.role ) }
			</span>
			{ 'user' === entry.role ? (
				<div className="ai-site-agent__entry-text ai-site-agent__entry-text--user">
					{ entry.text }
				</div>
			) : (
				<div
					className={ `ai-site-agent__entry-text ai-site-agent__entry-text--${ entry.role }` }
				>
					<Markdown text={ entry.text } />
				</div>
			) }
		</div>
	);
}

/**
 * Renders the Site Agent application.
 *
 * @param props      Component props.
 * @param props.data Localized data provided by the server.
 */
function SiteAgentApp( { data }: { data: LocalizedData } ): React.JSX.Element {
	const [ entries, setEntries ] = useState< Entry[] >( [] );
	const [ prompt, setPrompt ] = useState( '' );
	const [ conversationId, setConversationId ] = useState( '' );
	const [ proposal, setProposal ] = useState< Proposal | null >( null );
	const [ conversations, setConversations ] = useState<
		ConversationSummary[]
	>( [] );
	const [ isRunning, setIsRunning ] = useState( false );
	const [ isDeciding, setIsDeciding ] = useState( false );
	const [ isLoadingConversation, setIsLoadingConversation ] =
		useState( false );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ sidebarOpen, setSidebarOpen ] = useState( false );
	const [ showArchived, setShowArchived ] = useState( false );
	const [ renaming, setRenaming ] = useState< ConversationSummary | null >(
		null
	);
	const [ renameValue, setRenameValue ] = useState( '' );
	const [ deleting, setDeleting ] = useState< ConversationSummary | null >(
		null
	);
	const inputRef = useRef< HTMLTextAreaElement | null >( null );

	const refreshConversations = useCallback( async () => {
		try {
			const result = await listConversations( data );
			setConversations( result.conversations ?? [] );
		} catch {
			// A history failure should not block the conversation itself.
		}
	}, [ data ] );

	useEffect( () => {
		if ( 'ready' === data.availability.status ) {
			void refreshConversations();
		}
	}, [ data.availability.status, refreshConversations ] );

	const append = useCallback( ( role: Entry[ 'role' ], text: string ) => {
		setEntries( ( current ) => [
			...current,
			{ id: entryId(), role, text },
		] );
	}, [] );

	const send = useCallback(
		async ( value: string ) => {
			const text = value.trim();

			if ( '' === text || isRunning ) {
				return;
			}

			setError( '' );
			setNotice( '' );
			setProposal( null );
			append( 'user', text );
			setPrompt( '' );
			setIsRunning( true );

			try {
				const result = await sendTurn( data, text, conversationId );

				setConversationId( result.conversation_id );

				if ( result.transcript && result.transcript.length > 0 ) {
					setEntries( result.transcript );
				} else {
					if ( result.text ) {
						append( 'assistant', result.text );
					}

					if ( result.proposal && ! result.text ) {
						append(
							'assistant',
							__(
								'I prepared this change. Review the exact values, then approve or edit them. Nothing has been written yet.',
								'ai'
							)
						);
					}
				}

				if ( result.proposal ) {
					setProposal( result.proposal );
				}

				if ( 'max_rounds' === result.status ) {
					setNotice(
						__(
							'The assistant stopped at its round limit. Try a narrower question.',
							'ai'
						)
					);
				}

				void refreshConversations();
			} catch ( caught ) {
				setError(
					caught instanceof ApiError
						? caught.message
						: __( 'The Site Agent request failed.', 'ai' )
				);
			} finally {
				setIsRunning( false );
			}
		},
		[ append, conversationId, data, isRunning, refreshConversations ]
	);

	const approve = useCallback(
		async ( args: Record< string, unknown > ) => {
			if ( ! proposal ) {
				return;
			}

			setError( '' );
			setIsDeciding( true );

			try {
				if (
					JSON.stringify( args ) !== JSON.stringify( proposal.args )
				) {
					await updateProposal(
						data,
						proposal.id,
						conversationId,
						args
					);
				}

				const result = await approveProposal(
					data,
					proposal.id,
					conversationId
				);

				if ( result.error ) {
					setError( result.error );
				}

				if ( result.transcript ) {
					setEntries( result.transcript );
				} else if ( ! result.error ) {
					append( 'system', __( 'Approved and executed.', 'ai' ) );
				}

				setProposal( null );
				void refreshConversations();
			} catch ( caught ) {
				setError(
					caught instanceof ApiError
						? caught.message
						: __( 'The proposal could not be approved.', 'ai' )
				);
			} finally {
				setIsDeciding( false );
			}
		},
		[ append, conversationId, data, proposal, refreshConversations ]
	);

	const decline = useCallback( async () => {
		if ( ! proposal ) {
			return;
		}

		setError( '' );
		setIsDeciding( true );

		try {
			const result = await declineProposal(
				data,
				proposal.id,
				conversationId
			);

			if ( result.transcript ) {
				setEntries( result.transcript );
			} else {
				append(
					'system',
					__( 'Declined. Nothing was changed.', 'ai' )
				);
			}

			setProposal( null );
			void refreshConversations();
		} catch ( caught ) {
			setError(
				caught instanceof ApiError
					? caught.message
					: __( 'The proposal could not be declined.', 'ai' )
			);
		} finally {
			setIsDeciding( false );
		}
	}, [ append, conversationId, data, proposal, refreshConversations ] );

	const startNewTopic = useCallback( () => {
		setEntries( [] );
		setConversationId( '' );
		setProposal( null );
		setError( '' );
		setNotice( '' );
		setPrompt( '' );
		setSidebarOpen( false );
		inputRef.current?.focus();
	}, [] );

	const selectConversation = useCallback(
		async ( id: string ) => {
			if ( isRunning || isDeciding ) {
				return;
			}

			setError( '' );
			setNotice( '' );
			setIsLoadingConversation( true );

			try {
				const conversation = await getConversation( data, id );

				setConversationId( conversation.id );
				setEntries( conversation.transcript ?? [] );
				setProposal( conversation.proposal );
				setSidebarOpen( false );
			} catch ( caught ) {
				setError(
					caught instanceof ApiError
						? caught.message
						: __( 'The conversation could not be opened.', 'ai' )
				);
			} finally {
				setIsLoadingConversation( false );
			}
		},
		[ data, isDeciding, isRunning ]
	);

	const confirmRename = useCallback( async () => {
		if ( ! renaming ) {
			return;
		}

		setError( '' );

		try {
			await renameConversation( data, renaming.id, renameValue );
			setRenaming( null );
			void refreshConversations();
		} catch ( caught ) {
			setError(
				caught instanceof ApiError
					? caught.message
					: __( 'The conversation could not be renamed.', 'ai' )
			);
		}
	}, [ data, refreshConversations, renameValue, renaming ] );

	const changeArchive = useCallback(
		async ( conversation: ConversationSummary, archived: boolean ) => {
			setError( '' );

			try {
				await archiveConversation( data, conversation.id, archived );
				void refreshConversations();
			} catch ( caught ) {
				setError(
					caught instanceof ApiError
						? caught.message
						: __( 'The conversation could not be updated.', 'ai' )
				);
			}
		},
		[ data, refreshConversations ]
	);

	const confirmDelete = useCallback( async () => {
		if ( ! deleting ) {
			return;
		}

		setError( '' );

		try {
			await deleteConversation( data, deleting.id );

			if ( deleting.id === conversationId ) {
				startNewTopic();
			}

			setDeleting( null );
			void refreshConversations();
		} catch ( caught ) {
			setError(
				caught instanceof ApiError
					? caught.message
					: __( 'The conversation could not be deleted.', 'ai' )
			);
		}
	}, [
		conversationId,
		data,
		deleting,
		refreshConversations,
		startNewTopic,
	] );

	const busy = isRunning || isDeciding || isLoadingConversation;

	const submit = useCallback( () => {
		void send( prompt );
	}, [ prompt, send ] );

	const showNewTopic =
		entries.length > 0 || conversations.length > 0 || '' !== conversationId;

	if ( 'ready' !== data.availability.status ) {
		return (
			<div className="ai-site-agent__app">
				<UnavailableState
					availability={ data.availability }
					settingsUrl={ data.settingsUrl }
				/>
			</div>
		);
	}

	return (
		<div className="ai-site-agent__app">
			<div
				className="screen-reader-text"
				aria-live="polite"
				aria-atomic="true"
			>
				{ isRunning ? __( 'The assistant is thinking.', 'ai' ) : '' }
			</div>

			<header className="ai-site-agent__header">
				<div className="ai-site-agent__title">
					<h1>{ __( 'Site Agent', 'ai' ) }</h1>
					<p>
						{ __(
							'Ask about your site or request a change. Nothing changes until you approve the exact values.',
							'ai'
						) }
					</p>
				</div>
				<div className="ai-site-agent__header-actions">
					<span className="ai-site-agent__write-state">
						{ data.canWrite ? (
							__( 'Write proposals are on.', 'ai' )
						) : (
							<>
								{ __( 'Read-only.', 'ai' ) }{ ' ' }
								<a href={ data.settingsUrl }>
									{ __(
										'Enable write proposals in Settings → AI.',
										'ai'
									) }
								</a>
							</>
						) }
					</span>
					<Button
						__next40pxDefaultSize
						className="ai-site-agent__history-toggle"
						variant="tertiary"
						size="compact"
						onClick={ () => setSidebarOpen( true ) }
					>
						{ __( 'History', 'ai' ) }
					</Button>
				</div>
			</header>

			<div className="ai-site-agent__body">
				<ConversationSidebar
					conversations={ conversations }
					activeId={ conversationId }
					showArchived={ showArchived }
					showNewTopic={ showNewTopic }
					open={ sidebarOpen }
					onSelect={ ( id ) => {
						void selectConversation( id );
					} }
					onNewTopic={ startNewTopic }
					onRename={ ( conversation ) => {
						setRenaming( conversation );
						setRenameValue( conversation.title );
					} }
					onArchive={ ( conversation, archived ) => {
						void changeArchive( conversation, archived );
					} }
					onDelete={ setDeleting }
					onShowArchivedChange={ setShowArchived }
					onClose={ () => setSidebarOpen( false ) }
				/>

				{ sidebarOpen && (
					<button
						type="button"
						className="ai-site-agent__sidebar-backdrop"
						aria-label={ __( 'Close conversation history', 'ai' ) }
						onClick={ () => setSidebarOpen( false ) }
					/>
				) }

				<main className="ai-site-agent__main">
					{ error && (
						<Notice
							status="error"
							onRemove={ () => setError( '' ) }
						>
							{ error }
						</Notice>
					) }

					{ notice && (
						<Notice
							status="info"
							onRemove={ () => setNotice( '' ) }
						>
							{ notice }
						</Notice>
					) }

					<section
						className="ai-site-agent__transcript"
						aria-label={ __( 'Conversation transcript', 'ai' ) }
						tabIndex={ 0 }
					>
						{ isLoadingConversation ? (
							<div className="ai-site-agent__loading">
								<Spinner />
								<span>
									{ __( 'Opening conversation…', 'ai' ) }
								</span>
							</div>
						) : (
							<>
								{ 0 === entries.length && (
									<div className="ai-site-agent__empty">
										<p>
											{ __(
												'Ask a question, or ask for a change. Write actions need your approval.',
												'ai'
											) }
										</p>
										<div className="ai-site-agent__suggestions">
											{ SUGGESTIONS.map(
												( suggestion ) => (
													<Button
														__next40pxDefaultSize
														key={ suggestion }
														variant="secondary"
														onClick={ () => {
															setPrompt(
																suggestion
															);
															inputRef.current?.focus();
														} }
													>
														{ suggestion }
													</Button>
												)
											) }
										</div>
									</div>
								) }

								{ entries.map( ( entry ) => (
									<TranscriptEntry
										key={ entry.id }
										entry={ entry }
									/>
								) ) }

								{ proposal && (
									<ProposalCard
										proposal={ proposal }
										busy={ isDeciding }
										onApprove={ ( args ) => {
											void approve( args );
										} }
										onDecline={ () => {
											void decline();
										} }
									/>
								) }

								{ isRunning && (
									<p className="ai-site-agent__working">
										<Spinner />
										{ __( 'Thinking…', 'ai' ) }
									</p>
								) }
							</>
						) }
					</section>

					<div className="ai-site-agent__composer">
						<label
							htmlFor={ PROMPT_ID }
							className="screen-reader-text"
						>
							{ __( 'Message the Site Agent', 'ai' ) }
						</label>
						<textarea
							id={ PROMPT_ID }
							ref={ inputRef }
							value={ prompt }
							onChange={ ( event ) =>
								setPrompt( event.target.value )
							}
							onKeyDown={ ( event ) => {
								if (
									'Enter' === event.key &&
									! event.shiftKey
								) {
									event.preventDefault();
									submit();
								}
							} }
							maxLength={ data.maxMessageLength }
							rows={ 3 }
							placeholder={ __(
								'Ask about your site, or ask for a change…',
								'ai'
							) }
							disabled={ busy }
						/>
						<div className="ai-site-agent__composer-actions">
							<span className="ai-site-agent__hint">
								{ __(
									'Enter to send, Shift+Enter for a new line.',
									'ai'
								) }
							</span>
							<Button
								__next40pxDefaultSize
								variant="primary"
								onClick={ submit }
								disabled={ busy || '' === prompt.trim() }
								isBusy={ isRunning }
							>
								{ __( 'Send', 'ai' ) }
							</Button>
						</div>
					</div>
				</main>
			</div>

			{ renaming && (
				<Modal
					title={ __( 'Rename conversation', 'ai' ) }
					onRequestClose={ () => setRenaming( null ) }
				>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Name', 'ai' ) }
						value={ renameValue }
						onChange={ setRenameValue }
					/>
					<div className="ai-site-agent__modal-actions">
						<Button
							__next40pxDefaultSize
							variant="primary"
							onClick={ () => {
								void confirmRename();
							} }
						>
							{ __( 'Save name', 'ai' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ () => setRenaming( null ) }
						>
							{ __( 'Cancel', 'ai' ) }
						</Button>
					</div>
				</Modal>
			) }

			{ deleting && (
				<Modal
					title={ __( 'Delete conversation?', 'ai' ) }
					onRequestClose={ () => setDeleting( null ) }
				>
					<p>
						{ __(
							'This permanently deletes the conversation and its transcript.',
							'ai'
						) }
					</p>
					<div className="ai-site-agent__modal-actions">
						<Button
							__next40pxDefaultSize
							variant="primary"
							isDestructive
							onClick={ () => {
								void confirmDelete();
							} }
						>
							{ __( 'Delete conversation', 'ai' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ () => setDeleting( null ) }
						>
							{ __( 'Cancel', 'ai' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}

domReady( () => {
	const container = document.getElementById( 'ai-site-agent-root' );

	if ( ! container ) {
		return;
	}

	const data = window.aiSiteAgent;

	if ( ! data ) {
		return;
	}

	createRoot( container ).render( <SiteAgentApp data={ data } /> );
} );

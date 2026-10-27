/**
 * Site Agent admin screen entry point.
 */

/**
 * Internal dependencies
 */
import './index.scss';
import ProposalCard from './components/ProposalCard';
import {
	ApiError,
	approveProposal,
	declineProposal,
	sendTurn,
} from './api';

/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import domReady from '@wordpress/dom-ready';
import {
	createRoot,
	useCallback,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Entry, LocalizedData, Proposal } from './types';

const SUGGESTIONS = [
	__( 'What version of WordPress and PHP am I running?', 'ai' ),
	__( 'Show my latest draft posts.', 'ai' ),
	__( 'Create a draft post titled “Site Agent test”.', 'ai' ),
	__( 'Change the site tagline to “Built with WordPress”.', 'ai' ),
];

let entryCounter = 0;

/**
 * Returns a unique entry key.
 */
function entryId(): string {
	entryCounter += 1;

	return `entry-${ entryCounter }`;
}

/**
 * Formats a value for a transcript note.
 *
 * @param value Value to format.
 */
function formatValue( value: unknown ): string {
	if ( typeof value === 'string' ) {
		return value;
	}

	try {
		const json = JSON.stringify( value, null, 2 );

		return typeof json === 'string' ? json : String( value );
	} catch {
		return String( value );
	}
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
				{ 'user' === entry.role
					? __( 'You', 'ai' )
					: 'assistant' === entry.role
					? __( 'Assistant', 'ai' )
					: __( 'Result', 'ai' ) }
			</span>
			<div className="ai-site-agent__entry-text">{ entry.text }</div>
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
	const [ isRunning, setIsRunning ] = useState( false );
	const [ isDeciding, setIsDeciding ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const inputRef = useRef< HTMLTextAreaElement | null >( null );

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
				const result = await sendTurn(
					data,
					text,
					conversationId
				);

				setConversationId( result.conversation_id );

				if ( result.text ) {
					append( 'assistant', result.text );
				}

				if ( result.proposal ) {
					if ( ! result.text ) {
						append(
							'assistant',
							__(
								'I prepared this change. Review the exact values, then approve or decline. Nothing has been written yet.',
								'ai'
							)
						);
					}

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
		[ append, conversationId, data, isRunning ]
	);

	const approve = useCallback( async () => {
		if ( ! proposal ) {
			return;
		}

		setError( '' );
		setIsDeciding( true );

		try {
			const result = await approveProposal(
				data,
				proposal.id,
				conversationId
			);

			if ( result.error ) {
				setError( result.error );
			} else {
				append(
					'system',
					__(
						'Approved and executed. Result:',
						'ai'
					) + '\n' + formatValue( result.result )
				);
			}

			setProposal( null );
		} catch ( caught ) {
			setError(
				caught instanceof ApiError
					? caught.message
					: __( 'The proposal could not be approved.', 'ai' )
			);
		} finally {
			setIsDeciding( false );
		}
	}, [ append, conversationId, data, proposal ] );

	const decline = useCallback( async () => {
		if ( ! proposal ) {
			return;
		}

		setError( '' );
		setIsDeciding( true );

		try {
			await declineProposal( data, proposal.id, conversationId );
			append( 'system', __( 'Declined. Nothing was changed.', 'ai' ) );
			setProposal( null );
		} catch ( caught ) {
			setError(
				caught instanceof ApiError
					? caught.message
					: __( 'The proposal could not be declined.', 'ai' )
			);
		} finally {
			setIsDeciding( false );
		}
	}, [ append, conversationId, data, proposal ] );

	const reset = useCallback( () => {
		setEntries( [] );
		setConversationId( '' );
		setProposal( null );
		setError( '' );
		setNotice( '' );
		setPrompt( '' );
		inputRef.current?.focus();
	}, [] );

	const busy = isRunning || isDeciding;

	const submit = useCallback( () => {
		void send( prompt );
	}, [ prompt, send ] );

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
				{ isRunning
					? __( 'The assistant is thinking.', 'ai' )
					: '' }
			</div>

			<div className="ai-site-agent__toolbar">
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
							</a>{ ' ' }
							{ __(
								'Turn on Advanced settings to see the option.',
								'ai'
							) }
						</>
					) }
				</span>
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					size="compact"
					disabled={ busy || 0 === entries.length }
					accessibleWhenDisabled
					onClick={ reset }
				>
					{ __( 'New topic', 'ai' ) }
				</Button>
			</div>

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
				{ 0 === entries.length && (
					<div className="ai-site-agent__empty">
						<p>
							{ __(
								'Ask a question, or ask for a change. Write actions need your approval.',
								'ai'
							) }
						</p>
						<div className="ai-site-agent__suggestions">
							{ SUGGESTIONS.map( ( suggestion ) => (
								<Button
									key={ suggestion }
									variant="secondary"
									onClick={ () => {
										setPrompt( suggestion );
										inputRef.current?.focus();
									} }
								>
									{ suggestion }
								</Button>
							) ) }
						</div>
					</div>
				) }

				{ entries.map( ( entry ) => (
					<TranscriptEntry key={ entry.id } entry={ entry } />
				) ) }

				{ proposal && (
					<ProposalCard
						proposal={ proposal }
						busy={ isDeciding }
						onApprove={ () => {
							void approve();
						} }
						onDecline={ () => {
							void decline();
						} }
					/>
				) }

				{ isRunning && (
					<p className="ai-site-agent__working">
						{ __( 'Thinking…', 'ai' ) }
					</p>
				) }
			</section>

			<div className="ai-site-agent__composer">
				<label
					htmlFor="ai-site-agent-prompt"
					className="screen-reader-text"
				>
					{ __( 'Message the Site Agent', 'ai' ) }
				</label>
				<textarea
					id="ai-site-agent-prompt"
					ref={ inputRef }
					value={ prompt }
					onChange={ ( event ) => setPrompt( event.target.value ) }
					onKeyDown={ ( event ) => {
						if ( 'Enter' === event.key && ! event.shiftKey ) {
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
						{ __( 'Enter to send, Shift+Enter for a new line.', 'ai' ) }
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

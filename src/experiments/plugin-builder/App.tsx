import { useState, useEffect, useRef } from '@wordpress/element';
import { usePluginBuilder } from './usePluginBuilder';
import { getChatHistory, getChatById } from './api';
import type { ChatHistory } from './types';

function Spinner() {
	return (
		<svg
			className="apb-spinner-svg"
			viewBox="0 0 24 24"
			fill="none"
			xmlns="http://www.w3.org/2000/svg"
		>
			<circle
				className="opacity-25"
				cx="12"
				cy="12"
				r="10"
				stroke="currentColor"
				strokeWidth="4"
			></circle>
			<path
				className="opacity-75"
				fill="currentColor"
				d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
			></path>
		</svg>
	);
}

function SmallSpinner() {
	return <span className="apb-spinner" />;
}

export default function App() {
	const {
		state,
		messages,
		isProcessing,
		hasSlugConflict,
		sendDescription,
		installPlugin,
		forceInstallPlugin,
		reset,
		logs,
		activeChatId,
		loadChat,
	} = usePluginBuilder();

	const [ input, setInput ] = useState( '' );
	const [ recentChats, setRecentChats ] = useState< ChatHistory[] >( [] );
	const messagesEndRef = useRef< HTMLDivElement >( null );

	const examples = [
		'A dashboard widget showing recent drafts with quick edit links',
		'A plugin that adds reading time to blog posts',
		'A simple contact form with email notifications',
		'A maintenance mode plugin with countdown timer',
	];

	useEffect( () => {
		if ( messagesEndRef.current ) {
			messagesEndRef.current.scrollIntoView( { behavior: 'smooth' } );
		}
	}, [ messages.length ] );

	// On mount, check if there's a chat_id in the URL
	useEffect( () => {
		const urlParams = new URLSearchParams( window.location.search );
		const queryChatId = urlParams.get( 'chat_id' );
		
		if ( queryChatId && messages.length === 0 ) {
			getChatById( parseInt( queryChatId, 10 ) )
				.then( ( chat ) => {
					loadChat( chat );
					
					// Clean up the URL securely
					const newUrl = new URL( window.location.href );
					newUrl.searchParams.delete( 'chat_id' );
					window.history.replaceState( {}, '', newUrl.toString() );
				} )
				.catch( ( err ) => console.error( 'Failed to fetch specific chat', err ) );
		}
	}, [ loadChat, messages.length ] );

	useEffect( () => {
		if ( messages.length === 0 ) {
			getChatHistory()
				.then( ( histories ) => setRecentChats( histories ) )
				.catch( ( err ) => console.error( 'Failed to fetch histories', err ) );
		}
	}, [ messages.length ] );

	const handleSend = () => {
		if ( ! input.trim() || isProcessing ) return;
		sendDescription( input.trim() );
		setInput( '' );
	};

	const handleKeyDown = ( e: React.KeyboardEvent< HTMLTextAreaElement > ) => {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			handleSend();
		}
	};

	return (
		<div className="apb-chat">
			<div className="apb-chat__header">
				<h2>WordPress AI Plugin Builder</h2>
				<div className="apb-chat__header-actions">
					{ messages.length > 0 ? (
						<button className="apb-chat__reset" onClick={ reset }>
							New Chat
						</button>
					) : (
						<div className="apb-chat__status">
							<div className="apb-chat__status-dot"></div>
							Ready
						</div>
					) }
				</div>
			</div>

			<div className="apb-chat__messages">
				{ messages.length === 0 ? (
					<div className="apb-chat__empty">
						<h3 className="apb-chat__empty-title">
							Code WordPress Plugins with AI
						</h3>
						<p className="apb-chat__empty-subtitle">
							Describe the functionality you need.
						</p>

						<div className="apb-chat__examples">
							{ examples.map( ( example, i ) => (
								<button
									key={ i }
									className="apb-chat__example-btn"
									onClick={ () => setInput( example ) }
								>
									{ example }
								</button>
							) ) }
						</div>

						{ recentChats && recentChats.length > 0 && (
							<div className="apb-chat__history" style={ { marginTop: '40px' } }>
								<h4 className="apb-chat__history-title" style={ { fontSize: '14px', marginBottom: '10px' } }>Recent Conversations</h4>
								<ul className="apb-chat__history-list" style={ { listStyle: 'none', padding: 0 } }>
									{ recentChats.map( chat => (
										<li key={ chat.id } style={ { marginBottom: '8px' } }>
											<button
												className="apb-chat__history-btn button button-secondary"
												onClick={ () => loadChat( chat ) }
												style={ { width: '100%', textAlign: 'left', display: 'flex', justifyContent: 'space-between' } }
											>
												<span>{ chat.title || 'Plugin Builder Chat' }</span>
												{ chat.plugin_slug && (
													<span style={ { opacity: 0.6, fontSize: '11px' } }>{ chat.plugin_slug }</span>
												) }
											</button>
										</li>
									) ) }
								</ul>
							</div>
						) }
					</div>
				) : (
					<div className="apb-chat__message-list">
						{ messages.map( ( msg ) => (
							<div
								key={ msg.id }
								className={ `apb-msg apb-msg--${ msg.role }` }
							>
								{ msg.role === 'assistant' && (
									<div className="apb-avatar">🤖</div>
								) }
								<div className="apb-msg__content">
									{ msg.type === 'text' && (
										<div className="apb-bubble">
											<p
												dangerouslySetInnerHTML={ {
													__html: msg.content.replace(
														/\n/g,
														'<br/>'
													),
												} }
											/>
										</div>
									) }
									{ msg.type === 'loading' && (
										<div className="apb-bubble apb-bubble--loading">
											<SmallSpinner /> { msg.content }
										</div>
									) }
									{ msg.type === 'plan' && (
										<div className="apb-bubble apb-bubble--plan">
											<strong>
												Plugin Plan:{ ' ' }
												{ msg.data.plugin_name }
											</strong>
											<p>{ msg.data.description }</p>
											<ul>
												{ msg.data.files.map(
													(
														file: any,
														i: number
													) => (
														<li key={ i }>
															<code>
																{ file.path }
															</code>{ ' ' }
															-{ ' ' }
															{ file.description }
														</li>
													)
												) }
											</ul>
										</div>
									) }
									{ msg.type === 'files' && (
										<div className="apb-bubble apb-bubble--files">
											<strong>
												Generated Files:{ ' ' }
												{ msg.data.length }
											</strong>
											{ !messages.slice(messages.indexOf(msg)).some(m => m.type === 'install' && m.data?.activated) && (
												<div
													className="apb-actions"
													style={ { marginTop: '10px' } }
												>
													<button
														className="button button-primary"
														disabled={ isProcessing || state === 'installing' || state === 'installed' }
														onClick={ () =>
															installPlugin()
														}
													>
														{ messages.slice(0, messages.indexOf(msg)).some(m => m.type === 'install' && m.data?.activated) ? 'Update Plugin Files' : 'Install and Activate Plugin' }
													</button>
												</div>
											) }
										</div>
									) }
									{ msg.type === 'install' && (
										<div className="apb-bubble apb-bubble--success">
											{ msg.data.activated
												? 'Plugin installed and activated successfully!'
												: `Installed, but activation failed: ${ msg.data.error }` }
										</div>
									) }
									{ msg.type === 'error' && (
										<div className="apb-bubble apb-bubble--error">
											{ msg.content }
										</div>
									) }
									{ msg.type === 'analysis' && (
										<div className="apb-bubble apb-bubble--analysis">
											<strong>Suggested Next Steps:</strong>
											<div
												className="apb-actions"
												style={ { marginTop: '10px', display: 'flex', gap: '10px', flexWrap: 'wrap' } }
											>
												{ msg.data?.suggested_commands?.map( ( cmdName: string, i: number ) => {
													const cmdObj = msg.data.all_commands?.find( ( c: any ) => c.name === cmdName );
													if ( ! cmdObj ) return null;

													return (
														<button
															key={ cmdName }
															className={ `button ${ i === 0 ? 'button-primary' : 'button-secondary' }` }
															onClick={ () => {
																if ( typeof cmdObj.callback === 'function' ) {
																	cmdObj.callback( { close: () => {} } );
																}
															} }
														>
															{ cmdObj.label }
														</button>
													);
												} ) }
											</div>
										</div>
									) }
								</div>
							</div>
						) ) }
						<div ref={ messagesEndRef } />
					</div>
				) }

				{ hasSlugConflict && (
					<div className="apb-chat__conflict-actions">
						<button
							className="apb-chat__force-install button button-secondary"
							onClick={ forceInstallPlugin }
						>
							Install Anyway
						</button>
					</div>
				) }
			</div>

			<div className="apb-chat__footer">
				<div className="apb-chat__input-wrapper">
					<textarea
						value={ input }
						onChange={ ( e ) => setInput( e.target.value ) }
						className="apb-chat__input"
						disabled={ isProcessing }
						rows={ 1 }
						onKeyDown={ handleKeyDown }
						placeholder="Describe what plugin you want to build..."
					/>
					<button
						className="apb-chat__send-btn"
						disabled={ isProcessing || ! input.trim() }
						onClick={ handleSend }
						title="Send"
					>
						{ isProcessing ? <Spinner /> : '🚀' }
					</button>
				</div>
				{ logs.length > 0 && (
					<div
						style={ {
							marginTop: '5px',
							fontSize: '11px',
							color: '#666',
							textAlign: 'right',
						} }
					>
						{ logs[ logs.length - 1 ].message }
					</div>
				) }
			</div>
		</div>
	);
}

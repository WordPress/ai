import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { usePluginBuilder } from './usePluginBuilder';
import { AIBrainIcon } from './AIBrainIcon';
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
		messages,
		isProcessing,
		hasSlugConflict,
		isInstalled,
		sendDescription,
		installPlugin,
		forceInstallPlugin,
		downloadPlugin,
		reset,
		logs,
		activeChatId,
		loadChat,
	} = usePluginBuilder();

	const [ input, setInput ] = useState( '' );
	const [ recentChats, setRecentChats ] = useState< ChatHistory[] >( [] );
	const messagesEndRef = useRef< HTMLDivElement >( null );

	const examples = [
		__(
			'A dashboard widget showing recent drafts with quick edit links',
			'ai'
		),
		__( 'A plugin that adds reading time to blog posts', 'ai' ),
		__( 'A simple contact form with email notifications', 'ai' ),
		__( 'A maintenance mode plugin with countdown timer', 'ai' ),
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
				.catch( ( err ) =>
					console.error( 'Failed to fetch specific chat', err )
				);
		}
	}, [ loadChat, messages.length ] );

	useEffect( () => {
		if ( messages.length === 0 ) {
			getChatHistory()
				.then( ( histories ) => setRecentChats( histories ) )
				.catch( ( err ) =>
					console.error( 'Failed to fetch histories', err )
				);
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
				<div className="apb-chat__header-left">
					<h2>🤖 AI-Powered Plugin Builder</h2>
				</div>
				<div className="apb-chat__header-actions">
					{ messages.length > 0 ? (
						<button
							className="apb-chat__reset button button-secondary"
							onClick={ reset }
						>
							✨ { __( 'New Project', 'ai' ) }
						</button>
					) : (
						<div className="apb-chat__status">
							<div className="apb-chat__status-dot"></div>
							{ __( 'Ready', 'ai' ) }
						</div>
					) }
				</div>
			</div>

			<div className="apb-chat__messages">
				{ messages.length === 0 ? (
					<div className="apb-chat__empty">
						<AIBrainIcon />
						<h3 className="apb-chat__empty-title">
							{ __( 'Code WordPress Plugins with AI', 'ai' ) }
						</h3>
						<p className="apb-chat__empty-subtitle">
							{ __(
								'Describe the functionality you need, and watch AI build your plugin in minutes.',
								'ai'
							) }
						</p>

						<div className="apb-chat__examples">
							{ examples.map( ( example, i ) => (
								<button
									key={ i }
									className="apb-chat__example-btn"
									onClick={ () => setInput( example ) }
									title={ example }
									onClick={ () => setInput( example ) }
									title={example}
								>
									{ example }
								</button>
							) ) }
						</div>

						{ recentChats && recentChats.length > 0 && (
							<div
								className="apb-chat__history"
								style={ { marginTop: '40px' } }
							>
								<h4
									className="apb-chat__history-title"
									style={ {
										fontSize: '14px',
										marginBottom: '10px',
									} }
								>
									{ __( 'Recent Conversations', 'ai' ) }
								</h4>
								<ul
									className="apb-chat__history-list"
									style={ { listStyle: 'none', padding: 0 } }
								>
									{ recentChats.map( ( chat ) => (
										<li
											key={ chat.id }
											style={ { marginBottom: '8px' } }
										>
											<button
												className="apb-chat__history-btn button button-secondary"
												onClick={ () =>
													loadChat( chat )
												}
												style={ {
													width: '100%',
													textAlign: 'left',
													display: 'flex',
													justifyContent:
														'space-between',
												} }
											>
												<span>
													{ chat.title ||
														__(
															'Plugin Builder Chat',
															'ai'
														) }
												</span>
												{ chat.plugin_slug && (
													<span
														style={ {
															opacity: 0.6,
															fontSize: '11px',
														} }
													>
														{ chat.plugin_slug }
													</span>
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
						{ messages
							.filter( ( msg ) => {
								if ( msg.type === 'review' ) {
									return (
										msg.data && msg.data.passed === false
									);
								}
								if ( msg.type === 'analysis' ) {
									return (
										msg.data &&
										msg.data.suggested_commands &&
										msg.data.suggested_commands.length > 0
									);
								}
								if ( msg.type === 'text' && ! msg.content ) {
									return false;
								}
								return true;
							} )
							.map( ( msg ) => (
								<div
									key={ msg.id }
									className={ `apb-msg apb-msg--${ msg.role }` }
								>
									{ msg.role === 'assistant' && (
										<div className="apb-avatar">🤖</div>
									) }
									<div className="apb-msg__content">
										{ msg.type === 'text' && (
											<div className="apb-bubble apb-bubble--text">
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
												<div className="apb-bubble__header">
													<span className="apb-bubble__icon">
														📋
													</span>
													<strong>
														{ sprintf(
															/* translators: %s: plugin name */
															__(
																'Plugin Plan: %s',
																'ai'
															),
															msg.data.plugin_name
														) }
													</strong>
												</div>
												<p>{ msg.data.description }</p>
												<ul>
													{ msg.data.files.map(
														(
															file: any,
															i: number
														) => (
															<li key={ i }>
																<code>
																	{
																		file.path
																	}
																</code>{ ' ' }
																-{ ' ' }
																{
																	file.description
																}
															</li>
														)
													) }
												</ul>
											</div>
										) }
										{ msg.type === 'files' && (
											<div className="apb-bubble apb-bubble--files">
												<div className="apb-bubble__header">
													<span className="apb-bubble__icon">
														📁
													</span>
													<strong>
														{ sprintf(
															/* translators: %d: number of files */
															__(
																'Generated Files: %d',
																'ai'
															),
															msg.data.length
														) }
													</strong>
												</div>
												<div
													className="apb-actions"
													style={ {
														marginTop: '10px',
													} }
												>
													{ ! messages
														.slice(
															messages.indexOf(
																msg
															)
														)
														.some(
															( m ) =>
																m.type ===
																	'install' &&
																m.data
																	?.activated
														) && (
														<button
															className="button button-primary"
															disabled={
																isProcessing ||
																state ===
																	'installing' ||
																state ===
																	'installed'
															}
															onClick={ () =>
																installPlugin()
															}
														>
															{ messages
																.slice(
																	0,
																	messages.indexOf(
																		msg
																	)
																)
																.some(
																	( m ) =>
																		m.type ===
																			'install' &&
																		m.data
																			?.activated
																)
																? __(
																		'Update Plugin Files',
																		'ai'
																  )
																: __(
																		'Install and Activate Plugin',
																		'ai'
																  ) }
														</button>
													) }
													<button
														className="button button-secondary"
														onClick={ () =>
															downloadPlugin()
														}
														disabled={
															! isInstalled
														}
														style={ {
															marginLeft: messages
																.slice(
																	messages.indexOf(
																		msg
																	)
																)
																.some(
																	( m ) =>
																		m.type ===
																			'install' &&
																		m.data
																			?.activated
																)
																? '0'
																: '8px',
														} }
														title={
															isInstalled
																? __(
																		'Download plugin as ZIP',
																		'ai'
																  )
																: __(
																		'Install the plugin first to download',
																		'ai'
																  )
														}
													>
														{ __(
															'Download Plugin',
															'ai'
														) }
													</button>
												</div>
											</div>
										) }
										{ msg.type === 'install' && (
											<div className="apb-bubble apb-bubble--success">
												{ ' ' }
												<span className="apb-bubble__icon">
													✅
												</span>{ ' ' }
												{ msg.data.activated
													? __(
															'Plugin installed and activated successfully!',
															'ai'
													  )
													: sprintf(
															/* translators: %s: error message */
															__(
																'Installed, but activation failed: %s',
																'ai'
															),
															msg.data.error
													  ) }
											</div>
										) }
										{ msg.type === 'error' && (
											<div className="apb-bubble apb-bubble--error">
												<span className="apb-bubble__icon">
													❌
												</span>
												{ msg.content }
											</div>
										) }
										{ msg.type === 'review' &&
											msg.data &&
											msg.data.passed === false && (
												<div className="apb-bubble apb-bubble--error">
													<strong>
														{ __(
															'Security Review Failed',
															'ai'
														) }
													</strong>
													<p>
														{
															msg.data
																.review_summary
														}
													</p>
												</div>
											) }
										{ msg.type === 'analysis' && (
											<div className="apb-bubble apb-bubble--analysis">
												<div className="apb-bubble__header">
													<span className="apb-bubble__icon">
														💡
													</span>
													<strong>
														{ __(
															'Suggested Next Steps:',
															'ai'
														) }
													</strong>
												</div>
												<div
													className="apb-actions"
													style={ {
														marginTop: '10px',
														display: 'flex',
														gap: '10px',
														flexWrap: 'wrap',
													} }
												>
													{ msg.data?.suggested_commands?.map(
														(
															cmdName: string,
															i: number
														) => {
															const cmdObj =
																msg.data.all_commands?.find(
																	(
																		c: any
																	) =>
																		c.name ===
																		cmdName
																);
															if ( ! cmdObj )
																return null;

															return (
																<button
																	key={
																		cmdName
																	}
																	className={ `button ${
																		i === 0
																			? 'button-primary'
																			: 'button-secondary'
																	}` }
																	onClick={ () => {
																		if (
																			typeof cmdObj.callback ===
																			'function'
																		) {
																			cmdObj.callback(
																				{
																					close: () => {},
																				}
																			);
																		}
																	} }
																>
																	{
																		cmdObj.label
																	}
																</button>
															);
														}
													) }
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
							{ __( 'Install Anyway', 'ai' ) }
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
						placeholder={ __(
							'Describe what plugin you want to build...',
							'ai'
						) }
					/>
					<button
						className="apb-chat__send-btn"
						disabled={ isProcessing || ! input.trim() }
						onClick={ handleSend }
						title={ __( 'Submit', 'ai' ) }
					>
						{ isProcessing ? (
							<Spinner />
						) : (
							<span
								style={ {
									fontWeight: 'bold',
									fontSize: '1.2em',
								} }
							>
								↑
							</span>
						) }
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
						{ logs[ logs.length - 1 ]?.message }
					</div>
				) }
			</div>
		</div>
	);
}

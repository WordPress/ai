import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { usePluginBuilder } from './usePluginBuilder';
import { runAbility } from '../../utils/run-ability';
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

function EnhanceIcon() {
	return (
		<svg
			xmlns="http://www.w3.org/2000/svg"
			width="18"
			height="18"
			viewBox="0 0 24 24"
			fill="none"
		>
			<path
				d="M21.1704 3.89883C21.767 4.48918 22.0657 4.78452 22.1787 5.12605C22.2781 5.42649 22.2794 5.75086 22.1832 6.05234C22.0738 6.39496 21.7789 6.69341 21.1888 7.2899L8.03706 20.5838C7.87513 20.7475 7.794 20.8296 7.70283 20.8974L7.57681 20.9816C7.53352 21.0074 7.48886 21.0312 7.44313 21.0526L7.27004 21.119C7.20193 21.1413 7.11906 21.1657 7.0086 21.1983L3.95389 22.0993L3.31851 22.2825C2.76364 22.4358 2.43236 22.4926 2.1868 22.4018L2.08226 22.3561C1.88018 22.2552 1.71617 22.0919 1.61439 21.8903L1.5683 21.787C1.44544 21.4603 1.58524 20.9796 1.86444 20.0193L2.75062 16.9724C2.78219 16.8638 2.80514 16.7822 2.82676 16.7153L2.89157 16.5458C2.91223 16.501 2.93508 16.4569 2.96002 16.4144L3.04164 16.2904C3.10735 16.2002 3.18668 16.1186 3.34538 15.9576L8.42932 10.8012L16.5026 2.65679C17.0938 2.06042 17.3897 1.76163 17.7316 1.64863C18.0325 1.54934 18.3582 1.54777 18.6601 1.64416C18.9173 1.72643 19.1492 1.91363 19.501 2.25098L19.8987 2.64043L21.1704 3.89883ZM9.85506 12.2038L4.77044 17.3609C4.743 17.3888 4.72008 17.4115 4.70032 17.4317C4.69219 17.4594 4.68303 17.4911 4.67177 17.5298L3.94938 20.0147L6.44254 19.2807C6.4813 19.2693 6.51333 19.2603 6.54119 19.252C6.56178 19.2313 6.58548 19.2073 6.61407 19.1784L19.7658 5.88452C19.8746 5.77461 19.9646 5.68122 20.0442 5.59949C19.9639 5.51883 19.8719 5.42756 19.7624 5.31923L18.4907 4.06083C18.3807 3.95192 18.288 3.86035 18.2061 3.78058C18.1251 3.86114 18.0333 3.95359 17.9242 4.06356L9.85506 12.2038Z"
				fill="currentColor"
			/>
			<path
				d="M18.0097 9.59118L14.4549 6.05979L15.8649 4.64143L19.4191 8.17213L18.0097 9.59118Z"
				fill="currentColor"
			/>
			<path
				d="M5.6716 1.8758C5.52072 1.49323 4.97928 1.49323 4.8284 1.8758L4.04036 3.87391C3.99429 3.99071 3.90184 4.08316 3.78503 4.12923L1.78693 4.91727C1.40436 5.06815 1.40436 5.60959 1.78693 5.76047L3.78503 6.54851C3.90184 6.59458 3.99429 6.68703 4.04036 6.80384L4.8284 8.80194C4.97928 9.18451 5.52072 9.18451 5.6716 8.80194L6.45964 6.80384C6.50571 6.68704 6.59817 6.59458 6.71497 6.54851L8.71307 5.76047C9.09564 5.60959 9.09564 5.06815 8.71307 4.91727L6.71497 4.12923C6.59817 4.08316 6.50571 3.99071 6.45964 3.87391L5.6716 1.8758Z"
				fill="currentColor"
			/>
		</svg>
	);
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
	const [ isEnhancing, setIsEnhancing ] = useState( false );
	const [ enhanceError, setEnhanceError ] = useState< string | null >( null );
	const [ recentChats, setRecentChats ] = useState< ChatHistory[] >( [] );
	const messagesEndRef = useRef< HTMLDivElement >( null );
	const textareaRef = useRef< HTMLTextAreaElement >( null );

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

	const adjustTextareaHeight = () => {
		const textarea = textareaRef.current;
		if ( textarea ) {
			textarea.style.height = 'auto';
			textarea.style.height = `${ textarea.scrollHeight }px`;
		}
	};

	useEffect( () => {
		adjustTextareaHeight();
	}, [ input ] );

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

	const handleEnhancePrompt = async () => {
		if ( ! input.trim() || isEnhancing || isProcessing ) return;

		setIsEnhancing( true );
		setEnhanceError( null );

		try {
			const enhanced = await runAbility< string >(
				'ai/plugin-prompt-enhancement',
				{ prompt: input.trim() }
			);

			if ( enhanced && typeof enhanced === 'string' ) {
				setInput( enhanced );
			}
		} catch ( error: any ) {
			setEnhanceError(
				error?.message || __( 'Failed to enhance prompt.', 'ai' )
			);
		} finally {
			setIsEnhancing( false );
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
								'Describe the functionality you need.',
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
																isInstalled
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
						ref={ textareaRef }
						value={ input }
						onChange={ ( e ) => setInput( e.target.value ) }
						className="apb-chat__input"
						disabled={ isProcessing || isEnhancing }
						rows={ 1 }
						onKeyDown={ handleKeyDown }
						placeholder={ __(
							'Describe what plugin you want to build...',
							'ai'
						) }
					/>
					<button
						className="apb-chat__send-btn"
						disabled={
							isProcessing || isEnhancing || ! input.trim()
						}
						onClick={ handleSend }
						title={ __( 'Submit', 'ai' ) }
					>
						<span className="dashicons dashicons-arrow-up-alt"></span>
					</button>
					<button
						className="apb-chat__prompt-tip-icon"
						disabled={
							isProcessing || isEnhancing || ! input.trim()
						}
						onClick={ handleEnhancePrompt }
						title={ __( 'Enhance prompt with AI', 'ai' ) }
					>
						<span className="apb-chat__prompt-tip-icon-wrapper">
							{ isEnhancing ? <SmallSpinner /> : <EnhanceIcon /> }
							<div className="apb-chat__prompt-tip-tooltip">
								{ __(
									'Describe what your plugin should do • Mention specific features you need • Include where settings should appear • Click to enhance your prompt with AI',
									'ai'
								) }
							</div>
						</span>
						<span className="apb-chat__prompt-tip-text">
							{ __( 'Enhance with AI', 'ai' ) }
						</span>
					</button>
				</div>
				{ enhanceError && (
					<div className="apb-chat__enhance-error">
						{ enhanceError }
					</div>
				) }
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
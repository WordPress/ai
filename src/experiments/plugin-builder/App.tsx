import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { usePluginBuilder } from './usePluginBuilder';
import { AIBrainIcon } from './AIBrainIcon';

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
	} = usePluginBuilder();

	const [ input, setInput ] = useState( '' );
	const messagesEndRef = useRef< HTMLDivElement >( null );

	const examples = [
		__( 'A dashboard widget showing recent drafts with quick edit links', 'ai' ),
		__( 'A plugin that adds reading time to blog posts', 'ai' ),
		__( 'A simple contact form with email notifications', 'ai' ),
		__( 'A maintenance mode plugin with countdown timer', 'ai' ),
	];

	useEffect( () => {
		if ( messagesEndRef.current ) {
			messagesEndRef.current.scrollIntoView( { behavior: 'smooth' } );
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
					{messages.length > 0 ? (
						<button className="apb-chat__reset button button-secondary" onClick={reset}>
							✨ {__('New Project', 'ai')}
						</button>
					) : (
						<div className="apb-chat__status">
							<div className="apb-chat__status-dot"></div>
							{__('Ready', 'ai')}
						</div>
					)}
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
							{ __( 'Describe the functionality you need, and watch AI build your plugin in minutes.', 'ai' ) }
						</p>

						<div className="apb-chat__examples">
							{ examples.map( ( example, i ) => (
								<button
									key={ i }
									className="apb-chat__example-btn"
									onClick={() => setInput(example)}
									title={example}
									onClick={ () => setInput( example ) }
								>
									{ example }
								</button>
							) ) }
						</div>
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
												<span className="apb-bubble__icon">📋</span>
												<strong>
													{ sprintf(
														/* translators: %s: plugin name */
														__( 'Plugin Plan: %s', 'ai' ),
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
											<div className="apb-bubble__header">
												<span className="apb-bubble__icon">📁</span>
												<strong>
													{ sprintf(
														/* translators: %d: number of files */
														__( 'Generated Files: %d', 'ai' ),
														msg.data.length
													) }
												</strong>
											</div>
											<div
												className="apb-actions"
												style={ { marginTop: '10px' } }
											>
												{ ! isInstalled && (
													<button
														className="button button-primary"
														onClick={ () =>
															installPlugin()
														}
													>
														{ __(
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
													disabled={ ! isInstalled }
													style={ {
														marginLeft: isInstalled
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
													{ __( 'Download Plugin', 'ai' ) }
												</button>
											</div>
										</div>
									) }
									{ msg.type === 'install' && (
										<div className="apb-bubble apb-bubble--success">											<span className="apb-bubble__icon">✅</span>											{ msg.data.activated
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
											<span className="apb-bubble__icon">❌</span>
											{ msg.content }
										</div>
									) }
									{ msg.type === 'analysis' && (
										<div className="apb-bubble apb-bubble--analysis">
											<div className="apb-bubble__header">
												<span className="apb-bubble__icon">💡</span>
												<strong>Suggested Next Steps:</strong>
											</div>
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
						title={ __( 'Send', 'ai' ) }
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
						{ logs[ logs.length - 1 ]?.message }
					</div>
				) }
			</div>
		</div>
	);
}

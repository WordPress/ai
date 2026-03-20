import { useState, useEffect, useRef } from '@wordpress/element';
import { usePluginBuilder } from './usePluginBuilder';

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
														Install and Activate
														Plugin
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
															? 'Download plugin as ZIP'
															: 'Install the plugin first to download'
													}
												>
													Download Plugin
												</button>
											</div>
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

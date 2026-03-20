import { useState, useEffect, useRef } from '@wordpress/element';
import { usePluginBuilder } from './usePluginBuilder';

function Spinner() {
	return (
		<svg className="apb-spinner-svg" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
			<circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
			<path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
		</svg>
	);
}

function SmallSpinner() {
	return (
		<span className="apb-spinner" />
	);
}

export default function App() {
	const {
		messages,
		isProcessing,
		hasSlugConflict,
		sendDescription,
		installPlugin,
		forceInstallPlugin,
		reset,
		logs,
	} = usePluginBuilder();

	const [input, setInput] = useState('');
	const messagesEndRef = useRef<HTMLDivElement>(null);

	const examples = [
		'A dashboard widget showing recent drafts with quick edit links',
		'A plugin that adds reading time to blog posts',
		'A simple contact form with email notifications',
		'A maintenance mode plugin with countdown timer',
	];

	useEffect(() => {
		if (messagesEndRef.current) {
			messagesEndRef.current.scrollIntoView({ behavior: 'smooth' });
		}
	}, [messages.length]);

	const handleSend = () => {
		if (!input.trim() || isProcessing) return;
		sendDescription(input.trim());
		setInput('');
	};

	const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			handleSend();
		}
	};

	return (
		<div className="apb-chat">
			<div className="apb-chat__header">
				<div className="apb-chat__header-left">
					<h2>🤖 WordPress AI Plugin Builder</h2>
				</div>
				<div className="apb-chat__header-actions">
					{messages.length > 0 ? (
						<button className="apb-chat__reset button button-secondary" onClick={reset}>
							✨ New Project
						</button>
					) : (
						<div className="apb-chat__status">
							<div className="apb-chat__status-dot"></div>
							Ready
						</div>
					)}
				</div>
			</div>

			<div className="apb-chat__messages">
				{messages.length === 0 ? (
					<div className="apb-chat__empty">
						<div className="apb-chat__empty-icon">🏗️</div>
						<h3 className="apb-chat__empty-title">Code WordPress Plugins with AI</h3>
						<p className="apb-chat__empty-subtitle">Describe the functionality you need, and watch AI build your plugin in minutes.</p>
						
						<div className="apb-chat__examples">
							{examples.map((example, i) => (
								<button
									key={i}
									className="apb-chat__example-btn"
									onClick={() => setInput(example)}
									title={example}
								>
									→ {example}
								</button>
							))}
						</div>
					</div>
				) : (
					<div className="apb-chat__message-list">
						{messages.map((msg) => (
							<div key={msg.id} className={`apb-msg apb-msg--${msg.role}`}>
								{msg.role === 'assistant' && <div className="apb-avatar">🤖</div>}
								<div className="apb-msg__content">
									{msg.type === 'text' && (
										<div className="apb-bubble">
											<p dangerouslySetInnerHTML={{ __html: msg.content.replace(/\n/g, '<br/>') }} />
										</div>
									)}
									{msg.type === 'loading' && (
										<div className="apb-bubble apb-bubble--loading">
											<SmallSpinner /> {msg.content}
										</div>
									)}
									{msg.type === 'plan' && (
										<div className="apb-bubble apb-bubble--plan">
										<div className="apb-plan-header">
											<strong>📋 Plugin Plan: {msg.data.plugin_name}</strong>
											<span className="apb-plan-badge">{msg.data.complexity}</span>
										</div>
										<p>{msg.data.description}</p>
										<div className="apb-files-section">
											<strong>📁 Files ({msg.data.files.length}):</strong>
											<ul>
												{msg.data.files.map((file: any, i: number) => (
													<li key={i}><code>{file.path}</code> — {file.description}</li>
												))}
											</ul>
										</div>
										</div>
									)}
									{msg.type === 'files' && (
										<div className="apb-bubble apb-bubble--files">
										<strong>✅ Files Generated Successfully</strong>
										<p style={{ marginTop: '8px' }}>Your plugin code is ready to be installed and activated.</p>
										<div style={{ marginTop: '12px' }}>
											<button className="button button-primary" onClick={() => installPlugin()}>
												🚀 Install & Activate Plugin
												</button>
											</div>
										</div>
									)}
									{msg.type === 'install' && (
									<div className={`apb-bubble apb-bubble--${msg.data.activated ? 'success' : 'warning'}`}>
										<strong>{msg.data.activated ? '🎉 Success!' : '⚠️ Partial Success'}</strong>
										<p style={{ marginTop: '6px' }}>
											{msg.data.activated 
												? 'Your plugin has been installed and activated successfully!' 
												: `Installed, but activation encountered an issue: ${msg.data.error}`
											}
										</p>
										</div>
									)}
									{msg.type === 'error' && (
										<div className="apb-bubble apb-bubble--error">
											<strong>❌ Error</strong>
											<p style={{ marginTop: '6px' }}>{msg.content}</p>
										</div>
									)}
								</div>
							</div>
						))}
						<div ref={messagesEndRef} />
					</div>
				)}

				{hasSlugConflict && (
					<div className="apb-chat__conflict-actions">
						<div className="apb-bubble apb-bubble--warning">
							<strong>⚠️ Plugin Slug Conflict</strong>
							<p style={{ marginTop: '6px' }}>A plugin with this slug already exists. You can install this version anyway to overwrite the existing files.</p>
							<button className="button button-primary" style={{ marginTop: '10px' }} onClick={forceInstallPlugin}>
								Proceed with Installation
							</button>
						</div>
					</div>
				)}
			</div>

			<div className="apb-chat__footer">
				<div className="apb-chat__input-wrapper">
					<textarea
						value={input}
						onChange={(e) => setInput(e.target.value)}
						className="apb-chat__input"
						disabled={isProcessing}
						rows={1}
						onKeyDown={handleKeyDown}
						placeholder="Describe what plugin you want to build... (e.g., 'A contact form with email notifications')"
					/>
					<button
						className={`apb-chat__send-btn ${isProcessing ? 'is-loading' : ''}`}
						disabled={isProcessing || !input.trim()}
						onClick={handleSend}
						title={isProcessing ? 'Building plugin...' : 'Send'}
					>
						{isProcessing ? <Spinner /> : '🚀'}
					</button>
				</div>
				{logs.length > 0 && (
					<div className="apb-chat__logs">
						<span className="apb-log-status">
							{logs[logs.length - 1].level === 'error' && '❌'}
							{logs[logs.length - 1].level === 'success' && '✓'}
							{logs[logs.length - 1].level === 'info' && 'ℹ'}
							{logs[logs.length - 1].level === 'warn' && '⚠'}
						</span>
						<span className="apb-log-message">{logs[logs.length - 1].message}</span>
					</div>
				)}
			</div>
		</div>
	);
}

import {useState, useEffect, useRef} from '@wordpress/element';
import {__, sprintf} from '@wordpress/i18n';
import {usePluginBuilder} from './usePluginBuilder';
import {runAbility} from '../../utils/run-ability';

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
	return <span className="apb-spinner"/>;
}

function InfoIcon() {
	return (
		<svg
			xmlns="http://www.w3.org/2000/svg"
			width="14"
			height="14"
			viewBox="0 0 416.979 416.979"
		>
			<path
				fill="#6b7280"
				d="M356.004 61.156c-81.37-81.47-213.377-81.551-294.848-.182-81.47 81.371-81.552 213.379-.181 294.85 81.369 81.47 213.378 81.551 294.849.181 81.469-81.369 81.551-213.379.18-294.849zM237.6 340.786a5.821 5.821 0 0 1-5.822 5.822h-46.576a5.821 5.821 0 0 1-5.822-5.822V167.885a5.821 5.821 0 0 1 5.822-5.822h46.576a5.82 5.82 0 0 1 5.822 5.822v172.901zm-29.11-202.885c-18.618 0-33.766-15.146-33.766-33.765 0-18.617 15.147-33.766 33.766-33.766s33.766 15.148 33.766 33.766c0 18.619-15.149 33.765-33.766 33.765z"
			/>
		</svg>
	);
}

export default function App() {
	const {
		state,
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

	const [input, setInput] = useState('');
	const [isEnhancing, setIsEnhancing] = useState(false);
	const [enhanceError, setEnhanceError] = useState<string | null>(null);
	const messagesEndRef = useRef<HTMLDivElement>(null);
	const textareaRef = useRef<HTMLTextAreaElement>(null);

	const examples = [
		__('A dashboard widget showing recent drafts with quick edit links', 'ai'),
		__('A plugin that adds reading time to blog posts', 'ai'),
		__('A simple contact form with email notifications', 'ai'),
		__('A maintenance mode plugin with countdown timer', 'ai'),
	];

	useEffect(() => {
		if (messagesEndRef.current) {
			messagesEndRef.current.scrollIntoView({behavior: 'smooth'});
		}
	}, [messages.length]);

	const adjustTextareaHeight = () => {
		const textarea = textareaRef.current;
		if (textarea) {
			textarea.style.height = 'auto';
			textarea.style.height = `${textarea.scrollHeight}px`;
		}
	};

	useEffect(() => {
		adjustTextareaHeight();
	}, [input]);

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

	const handleEnhancePrompt = async () => {
		if (!input.trim() || isEnhancing || isProcessing) return;

		setIsEnhancing(true);
		setEnhanceError(null);

		try {
			const enhanced = await runAbility<string>(
				'ai/plugin-prompt-enhancement',
				{prompt: input.trim()}
			);

			if (enhanced && typeof enhanced === 'string') {
				setInput(enhanced);
			}
		} catch (error: any) {
			setEnhanceError(
				error?.message || __('Failed to enhance prompt.', 'ai')
			);
		} finally {
			setIsEnhancing(false);
		}
	};

	return (
		<div className="apb-chat">
			<div className="apb-chat__header">
				<h2>{__('AI-Powered Plugin Builder', 'ai')}</h2>
				<div className="apb-chat__header-actions">
					{messages.length > 0 ? (
						<button className="apb-chat__reset" onClick={reset}>
							{__('New Chat', 'ai')}
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
				{messages.length === 0 ? (
					<div className="apb-chat__empty">
						<h3 className="apb-chat__empty-title">
							{__('Code WordPress Plugins with AI', 'ai')}
						</h3>
						<p className="apb-chat__empty-subtitle">
							{__('Describe the functionality you need.', 'ai')}
						</p>

						<div className="apb-chat__examples">
							{examples.map((example, i) => (
								<button
									key={i}
									className="apb-chat__example-btn"
									onClick={() => setInput(example)}
								>
									{example}
								</button>
							))}
						</div>
					</div>
				) : (
					<div className="apb-chat__message-list">
						{messages.map((msg) => (
							<div
								key={msg.id}
								className={`apb-msg apb-msg--${msg.role}`}
							>
								{msg.role === 'assistant' && (
									<div className="apb-avatar">🤖</div>
								)}
								<div className="apb-msg__content">
									{msg.type === 'text' && (
										<div className="apb-bubble">
											<p
												dangerouslySetInnerHTML={{
													__html: msg.content.replace(
														/\n/g,
														'<br/>'
													),
												}}
											/>
										</div>
									)}
									{msg.type === 'loading' && (
										<div className="apb-bubble apb-bubble--loading">
											<SmallSpinner/> {msg.content}
										</div>
									)}
									{msg.type === 'plan' && (
										<div className="apb-bubble apb-bubble--plan">
											<strong>
												{sprintf(
													/* translators: %s: plugin name */
													__('Plugin Plan: %s', 'ai'),
													msg.data.plugin_name
												)}
											</strong>
											<p>{msg.data.description}</p>
											<ul>
												{msg.data.files.map(
													(
														file: any,
														i: number
													) => (
														<li key={i}>
															<code>
																{file.path}
															</code>{' '}
															-{' '}
															{file.description}
														</li>
													)
												)}
											</ul>
										</div>
									)}
									{msg.type === 'files' && (
										<div className="apb-bubble apb-bubble--files">
											<strong>
												{sprintf(
													/* translators: %d: number of files */
													__('Generated Files: %d', 'ai'),
													msg.data.length
												)}
											</strong>
											<div
												className="apb-actions"
												style={{marginTop: '10px'}}
											>
												{!isInstalled && (
													<button
														className="button button-primary"
														onClick={() =>
															installPlugin()
														}
													>
														{__(
															'Install and Activate Plugin',
															'ai'
														)}
													</button>
												)}
												<button
													className="button button-secondary"
													onClick={() =>
														downloadPlugin()
													}
													disabled={!isInstalled}
													style={{
														marginLeft: isInstalled
															? '0'
															: '8px',
													}}
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
													{__('Download Plugin', 'ai')}
												</button>
											</div>
										</div>
									)}
									{msg.type === 'install' && (
										<div className="apb-bubble apb-bubble--success">
											{msg.data.activated
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
												)}
										</div>
									)}
									{msg.type === 'error' && (
										<div className="apb-bubble apb-bubble--error">
											{msg.content}
										</div>
									)}
									{msg.type === 'analysis' && (
										<div className="apb-bubble apb-bubble--analysis">
											<strong>Suggested Next Steps:</strong>
											<div
												className="apb-actions"
												style={{
													marginTop: '10px',
													display: 'flex',
													gap: '10px',
													flexWrap: 'wrap'
												}}
											>
												{msg.data?.suggested_commands?.map((cmdName: string, i: number) => {
													const cmdObj = msg.data.all_commands?.find((c: any) => c.name === cmdName);
													if (!cmdObj) return null;

													return (
														<button
															key={cmdName}
															className={`button ${i === 0 ? 'button-primary' : 'button-secondary'}`}
															onClick={() => {
																if (typeof cmdObj.callback === 'function') {
																	cmdObj.callback({
																		close: () => {
																		}
																	});
																}
															}}
														>
															{cmdObj.label}
														</button>
													);
												})}
											</div>
										</div>
									)}
								</div>
							</div>
						))}
						<div ref={messagesEndRef}/>
					</div>
				)}

				{hasSlugConflict && (
					<div className="apb-chat__conflict-actions">
						<button
							className="apb-chat__force-install button button-secondary"
							onClick={forceInstallPlugin}
						>
							{__('Install Anyway', 'ai')}
						</button>
					</div>
				)}
			</div>

			<div className="apb-chat__footer">
				<div className="apb-chat__input-wrapper">
					<textarea
						ref={textareaRef}
						value={input}
						onChange={(e) => setInput(e.target.value)}
						className="apb-chat__input"
						disabled={isProcessing || isEnhancing}
						rows={1}
						onKeyDown={handleKeyDown}
						placeholder={__(
							'Describe what plugin you want to build...',
							'ai'
						)}
					/>
					<button
						className="apb-chat__send-btn"
						disabled={isProcessing || isEnhancing || !input.trim()}
						onClick={handleSend}
						title={__('Send', 'ai')}
					>
						{isProcessing ? (
							<Spinner/>
						) : (
							<span className="dashicons dashicons-arrow-up-alt"></span>
						)}
					</button>
					<button
						className="apb-chat__prompt-tip-icon"
						disabled={isProcessing || isEnhancing || !input.trim()}
						onClick={handleEnhancePrompt}
						title={__('Enhance prompt with AI', 'ai')}
					>
						<span className="apb-chat__prompt-tip-icon-wrapper">
							{isEnhancing ? (
								<SmallSpinner/>
							) : (
								<span className="dashicons dashicons-superhero-alt"></span>
							)}
							<div className="apb-chat__prompt-tip-tooltip">
								{__(
									'Describe what your plugin should do • Mention specific features you need • Include where settings should appear • Click to enhance your prompt with AI',
									'ai'
								)}
							</div>
						</span>
						<span className="apb-chat__prompt-tip-text">
							{__('Enhance with AI', 'ai')}
						</span>
					</button>
				</div>
				{enhanceError && (
					<div className="apb-chat__enhance-error">
						{enhanceError}
					</div>
				)}
				{logs.length > 0 && (
					<div
						style={{
							marginTop: '5px',
							fontSize: '11px',
							color: '#666',
							textAlign: 'right',
						}}
					>
						{logs[logs.length - 1].message}
					</div>
				)}
			</div>
		</div>
	);
}

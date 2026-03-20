import { useState, useCallback, useMemo, useRef } from '@wordpress/element';
import {
	BuilderState,
	ChatMessage,
	GeneratedFile,
	LogEntry,
	LogLevel,
	PluginPlan,
	ReviewResult,
	TokenUsageSummary,
	needsSlugConfirmation,
} from './types';
import * as api from './api';
import {
	getSystemPrompt,
	getIntentPrompt,
	getPlannerPrompt,
	getCoderPrompt,
} from './prompts';
import { scanFiles } from './securityScanner';

// Type augmentations for wp.aiClient since there's no types package yet
declare global {
	interface Window {
		wp: any;
	}
}

let messageIdCounter = 0;
let logIdCounter = 0;

function createMessage(
	role: 'user' | 'assistant',
	type: ChatMessage['type'],
	content: string,
	data?: any
): ChatMessage {
	return {
		id: String(++messageIdCounter),
		role,
		type,
		content,
		data,
		timestamp: new Date(),
	};
}

/**
 * Parses JSON with markdown delimiters removed.
 *
 * @param {string} json
 * @return {any} Parsed JSON.
 */
function parseJSON( json: string ): any {
	return JSON.parse(
		json
			.replace( /^```json/, '' )
			.replace( /```\s*$/, '' )
	);
}

export function usePluginBuilder() {
	const [state, setState] = useState<BuilderState>('idle');
	const [messages, setMessages] = useState<ChatMessage[]>([]);
	const [logs, setLogs] = useState<LogEntry[]>([]);
	const [currentPlan, setCurrentPlan] = useState<PluginPlan | null>(null);
	const [currentFiles, setCurrentFiles] = useState<GeneratedFile[]>([]);
	const [currentReview, setCurrentReview] = useState<ReviewResult | null>(null);
	const [currentStep, setCurrentStep] = useState<string>('');
	const [error, setError] = useState<string | null>(null);
	const [tokenUsage, setTokenUsage] = useState<TokenUsageSummary | null>(null);
	const [slugConflictWarnings, setSlugConflictWarnings] = useState<string[]>([]);

	const startTimeRef = useRef<number>(0);

	// Logging
	const log = useCallback((level: LogLevel, message: string, detail?: string) => {
		setLogs((prev) => [
			...prev,
			{
				id: ++logIdCounter,
				timestamp: new Date(),
				level,
				message,
				detail,
			},
		]);
	}, []);

	const elapsed = useCallback(() => {
		if (!startTimeRef.current) return '';
		const secs = Math.round((Date.now() - startTimeRef.current) / 1000);
		return `${secs}s`;
	}, []);

	const addMessage = useCallback((msg: ChatMessage) => {
		setMessages((prev) => [...prev, msg]);
	}, []);

	const updateLastLoading = useCallback((content: string) => {
		setMessages((prev) => {
			const index = [...prev].reverse().findIndex((m) => m.type === 'loading');
			if (index !== -1) {
				const realIndex = prev.length - 1 - index;
				const newMsgs = [...prev];
				newMsgs[realIndex] = { ...newMsgs[realIndex], content };
				return newMsgs;
			}
			return prev;
		});
	}, []);

	const removeLastLoading = useCallback(() => {
		setMessages((prev) => {
			const index = [...prev].reverse().findIndex((m) => m.type === 'loading');
			if (index !== -1) {
				const realIndex = prev.length - 1 - index;
				const newMsgs = [...prev];
				newMsgs.splice(realIndex, 1);
				return newMsgs;
			}
			return prev;
		});
	}, []);

	const handleError = useCallback((message: string) => {
		setState('error');
		setError(message);
		removeLastLoading();
		addMessage(createMessage('assistant', 'error', message));
		log('error', 'Pipeline error', message);
	}, [addMessage, log, removeLastLoading]);

	const updateStep = useCallback((step: string) => {
		setCurrentStep(step);
		updateLastLoading(step);
		log('info', `Status: ${state}`, step);
	}, [state, updateLastLoading, log]);

	// Actions
	const sendDescription = useCallback(async (description: string) => {
		if (!description.trim()) return;
		if (!window.wp?.aiClient?.prompt) {
			handleError('WP AI Client JavaScript API is not available.');
			return;
		}

		const previousPlan = currentPlan;
		const previousFiles = currentFiles.length > 0 ? currentFiles : [];

		setError(null);
		setState('planning');
		startTimeRef.current = Date.now();
		setTokenUsage(null);

		log('info', 'Request sent', description.substring(0, 100));
		addMessage(createMessage('user', 'text', description));
		addMessage(createMessage('assistant', 'loading', 'Analyzing your request...'));

		const aiPrompt = window.wp.aiClient.prompt;

		try {
			// Phase 1: Intent Detection
			updateStep('Detecting intent...');
			const intentText = await aiPrompt(getIntentPrompt(description, previousPlan))
				.usingSystemInstruction(getSystemPrompt('detector'))
				.usingTemperature(0.1)
				.usingMaxTokens(500)
				.asJsonResponse()
				.generateText();

			let intentData;
			try {
				intentData = parseJSON(intentText);
			} catch (e) {
				intentData = { intent: 'plugin_request', confidence: 0.5 };
			}

			if (intentData.intent === 'question' || intentData.intent === 'other') {
				removeLastLoading();
				addMessage(createMessage('assistant', 'text', intentData.response || 'I can help you build plugins.'));
				setState(previousPlan ? 'ready_to_install' : 'idle');
				return;
			}

			if (intentData.intent !== 'modification_request') {
				setCurrentPlan(null);
				setCurrentFiles([]);
				setCurrentReview(null);
				// Reset previous files context for a new request
				previousFiles.length = 0;
			}

			// Phase 2: Planner
			setState('planning');
			updateStep('Generating plugin architecture plan...');
			const maxFiles = 10;
			const plannerText = await aiPrompt(getPlannerPrompt(description, 'simple', maxFiles, previousPlan))
				.usingSystemInstruction(getSystemPrompt('planner'))
				.usingMaxTokens(16384)
				.usingTemperature(0.3)
				.asJsonResponse()
				.generateText();

			let plan: PluginPlan;
			try {
				plan = parseJSON(plannerText);
			} catch (e) {
				handleError('Failed to parse the plugin plan JSON.');
				return;
			}

			setCurrentPlan(plan);
			log('success', `Plan ready: ${plan.plugin_name}`, `${plan.files.length} file(s)`);
			
			// Show plan inline
			removeLastLoading();
			addMessage(createMessage('assistant', 'plan', `Here's the plan for **${plan.plugin_name}**:`, plan));
			addMessage(createMessage('assistant', 'loading', 'Preparing generated files...'));

			// Phase 3: Generator Loop
			setState('coding');
			const newFiles: GeneratedFile[] = [];

			for (const fileInfo of plan.files) {
				updateStep(`Writing ${fileInfo.path}...`);

				const codeText = await aiPrompt(getCoderPrompt(plan, fileInfo, previousFiles.concat(newFiles)))
					.usingSystemInstruction(getSystemPrompt('coder', fileInfo.type))
					.usingTemperature(0.2)
					.usingMaxTokens(32768)
					.generateText();

				// Optional basic cleanup: AI sometimes wraps code in backticks
				let cleanContent = codeText;
				if (cleanContent.startsWith('\`\`\`')) {
					const firstNewlineIndex = cleanContent.indexOf('\\n');
					if (firstNewlineIndex !== -1) {
						cleanContent = cleanContent.substring(firstNewlineIndex + 1);
					}
					if (cleanContent.endsWith('\`\`\`')) {
						cleanContent = cleanContent.substring(0, cleanContent.length - 3);
					}
				}

				newFiles.push({
					...fileInfo,
					content: cleanContent.trim(),
				});
			}

			setCurrentFiles(newFiles);

			// Phase 4: Basic Client-Side Security Scan
			setState('reviewing');
			updateStep('Scanning files for security issues...');
			const scanResult = scanFiles(newFiles);

			const review: ReviewResult = {
				passed: scanResult.passed,
				review_summary: scanResult.passed ? 'No obvious dangerous patterns found.' : 'Dangerous patterns detected in generated code.',
				suggestions: scanResult.issues.map(iss => ({
					action: 'Needs Review',
					file_path: iss.file_path,
					file_type: 'php',
					reason: 'Pattern match',
					description: `Matched dangerous pattern \`${iss.pattern}\` on line ${iss.line}: \`${iss.line_content}\``
				})),
			};
			setCurrentReview(review);

			// Finish
			removeLastLoading();
			addMessage(createMessage('assistant', 'review', '', review));
			addMessage(createMessage('assistant', 'files', "Here's the generated code:", newFiles));

			setState('ready_to_install');
			log('success', `Done in ${elapsed()}`, 'Ready to install');

		} catch (e: any) {
			handleError(e.message || 'Failed during AI generation pipeline.');
		}
	}, [addMessage, currentFiles, currentPlan, handleError, log, removeLastLoading, updateStep, elapsed]);

	const installPlugin = useCallback(async (force: boolean = false) => {
		if (!currentPlan || !currentFiles.length) return;

		setState('installing');
		setSlugConflictWarnings([]);
		addMessage(createMessage('assistant', 'loading', 'Installing plugin...'));
		log('info', `Installing: ${currentPlan.plugin_slug}${force ? ' (forced)' : ''}`);

		try {
			const result = await api.install(currentPlan.plugin_slug, currentFiles, force);
			removeLastLoading();

			if (needsSlugConfirmation(result)) {
				setSlugConflictWarnings(result.warnings);
				setState('ready_to_install');
				addMessage(
					createMessage('assistant', 'text', `**Warning:** ${result.warnings.join(' ')}\n\nClick "Install Anyway" to proceed.`)
				);
				log('warn', 'Slug conflict detected', result.warnings.join('; '));
				return;
			}

			if ('installed' in result && result.installed) {
				setState('installed');
				addMessage(createMessage('assistant', 'install', '', result));
				log('success', result.activated ? 'Plugin installed & activated' : 'Plugin installed (activation failed)', result.error || result.plugin);
			} else if ('error' in result) {
				handleError(result.error);
			}
		} catch (e: any) {
			const msg = e.message || 'Failed to install plugin';
			handleError(msg);
		}
	}, [addMessage, currentFiles, currentPlan, handleError, log, removeLastLoading]);

	const forceInstallPlugin = useCallback(() => {
		void installPlugin(true);
	}, [installPlugin]);

	const reset = useCallback(() => {
		setState('idle');
		setMessages([]);
		setLogs([]);
		setCurrentPlan(null);
		setCurrentFiles([]);
		setCurrentReview(null);
		setCurrentStep('');
		setError(null);
		setTokenUsage(null);
		startTimeRef.current = 0;
		setSlugConflictWarnings([]);
		messageIdCounter = 0;
		logIdCounter = 0;
	}, []);

	const isProcessing = useMemo(() => 
		['planning', 'coding', 'reviewing', 'fixing', 'installing'].includes(state),
	[state]);

	const hasSlugConflict = useMemo(() => slugConflictWarnings.length > 0, [slugConflictWarnings]);

	return {
		state,
		messages,
		logs,
		currentPlan,
		currentFiles,
		currentReview,
		currentStep,
		error,
		tokenUsage,
		isProcessing,
		hasSlugConflict,
		slugConflictWarnings,
		sendDescription,
		installPlugin,
		forceInstallPlugin,
		reset,
	};
}

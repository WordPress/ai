import { useState, useCallback, useMemo, useRef } from '@wordpress/element';
import {
	BuilderState,
	ChatMessage,
	GeneratedFile,
	InstallResponse,
	LogEntry,
	LogLevel,
	PluginPlan,
	ReviewResult,
	StatusResponse,
	TokenUsageSummary,
	isJobResponse,
	needsSlugConfirmation,
} from './types';
import * as api from './api';
import { usePolling } from './usePolling';

let messageIdCounter = 0;
let logIdCounter = 0;

function createMessage(
	role: 'user' | 'assistant',
	type: ChatMessage['type'],
	content: string,
	data?: any,
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

export function usePluginBuilder() {
	const [state, setState] = useState<BuilderState>('idle');
	const [messages, setMessages] = useState<ChatMessage[]>([]);
	const [logs, setLogs] = useState<LogEntry[]>([]);
	const [currentJobId, setCurrentJobId] = useState<string | null>(null);
	const [currentPlan, setCurrentPlan] = useState<PluginPlan | null>(null);
	const [currentFiles, setCurrentFiles] = useState<GeneratedFile[]>([]);
	const [currentReview, setCurrentReview] = useState<ReviewResult | null>(null);
	const [currentStep, setCurrentStep] = useState<string>('');
	const [error, setError] = useState<string | null>(null);
	const [planShown, setPlanShown] = useState<boolean>(false);
	const [tokenUsage, setTokenUsage] = useState<TokenUsageSummary | null>(null);
	const [slugConflictWarnings, setSlugConflictWarnings] = useState<string[]>([]);
	
	const startTimeRef = useRef<number>(0);
	const lastStatusRef = useRef<string>('');

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

	// Messages
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

	// Polling callback
	const pollCallback = useCallback(async (): Promise<boolean> => {
		if (!currentJobId) return true;

		try {
			const status = await api.getStatus(currentJobId);
			return handleStatusUpdate(status);
		} catch (e: any) {
			const msg = e.message || 'Failed to check status';
			log('error', 'Polling failed', msg);
			handleError(msg);
			return true;
		}
	}, [currentJobId, handleError, log]);

	const { start: startPolling, stop: stopPolling } = usePolling(pollCallback, 2000);

	const handleStatusUpdate = useCallback((status: StatusResponse): boolean => {
		setCurrentStep(status.current_step);

		if (status.status !== lastStatusRef.current) {
			const prefix = elapsed() ? `[${elapsed()}]` : '';
			log('info', `${prefix} Status: ${status.status}`, status.current_step);
			lastStatusRef.current = status.status;
		}

		if (status.plan && !planShown) {
			setPlanShown(true);
			setCurrentPlan(status.plan);
			removeLastLoading();
			addMessage(createMessage('assistant', 'plan', `Here's the plan for **${status.plan.plugin_name}**:`, status.plan));
			addMessage(createMessage('assistant', 'loading', status.current_step));
			log('success', `Plan ready: ${status.plan.plugin_name}`, `${status.plan.files.length} file(s)`);
		}

		updateLastLoading(status.current_step);

		if (status.status === 'coding') setState('coding');
		if (status.status === 'reviewing') setState('reviewing');
		if (status.status === 'fixing') setState('fixing');

		if (status.status === 'done') {
			if (status.files?.length) setCurrentFiles(status.files);
			if (status.review) setCurrentReview(status.review);
			if (status.token_usage) setTokenUsage(status.token_usage);

			removeLastLoading();

			if (status.review) {
				addMessage(createMessage('assistant', 'review', '', status.review));
			}
			if (status.files?.length) {
				addMessage(createMessage('assistant', 'files', "Here's the generated code:", status.files));
			}

			setState('ready_to_install');
			log('success', `Done in ${elapsed()}`, status.current_step);

			if (status.token_usage) {
				log('info', `Tokens: ${status.token_usage.total_tokens.toLocaleString()} total`);
			}
			return true; // Stop polling
		}

		if (status.status === 'error') {
			handleError(status.error || 'An unknown error occurred');
			return true; // Stop polling
		}

		return false; // Continue polling
	}, [addMessage, elapsed, handleError, log, planShown, removeLastLoading, updateLastLoading]);

	// Actions
	const sendDescription = useCallback(async (description: string) => {
		if (!description.trim()) return;

		stopPolling();

		const previousPlan = currentPlan;
		const previousFiles = currentFiles.length > 0 ? currentFiles : null;

		setCurrentJobId(null);
		setPlanShown(false);
		setError(null);
		lastStatusRef.current = '';
		setState('planning');
		startTimeRef.current = Date.now();

		log('info', 'Request sent', description.substring(0, 100));
		addMessage(createMessage('user', 'text', description));
		addMessage(createMessage('assistant', 'loading', 'Analyzing your request...'));

		try {
			const resp = await api.generate(description.trim(), 'simple', previousPlan, previousFiles);

			if (!isJobResponse(resp)) {
				removeLastLoading();
				addMessage(createMessage('assistant', 'text', resp.response || 'I can help you build plugins.'));
				if (resp.token_usage) setTokenUsage(resp.token_usage);
				setState(previousPlan ? 'ready_to_install' : 'idle');
				log('info', `Intent: ${resp.type}`, resp.response?.substring(0, 100));
				return;
			}

			setCurrentJobId(resp.job_id);
			log('info', `Job ID: ${resp.job_id}`, `Intent: ${resp.type}`);

			if (resp.type !== 'modification_request') {
				setCurrentPlan(null);
				setCurrentFiles([]);
				setCurrentReview(null);
			}

			// We need a slight timeout to let the currentJobId state update propagate to pollCallback dependencies.
			// Actually usePolling is wrapping the callback, so it will get the latest due to refs or we can just call it in next tick
			setTimeout(() => {
			    startPolling();
			}, 100);
		} catch (e: any) {
			const msg = e.message || 'Failed to start generation';
			handleError(msg);
		}
	}, [addMessage, currentFiles, currentPlan, handleError, log, removeLastLoading, startPolling, stopPolling]);

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
		stopPolling();
		setState('idle');
		setMessages([]);
		setLogs([]);
		setCurrentJobId(null);
		setCurrentPlan(null);
		setCurrentFiles([]);
		setCurrentReview(null);
		setCurrentStep('');
		setPlanShown(false);
		setError(null);
		setTokenUsage(null);
		lastStatusRef.current = '';
		startTimeRef.current = 0;
		setSlugConflictWarnings([]);
		messageIdCounter = 0;
		logIdCounter = 0;
	}, [stopPolling]);

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

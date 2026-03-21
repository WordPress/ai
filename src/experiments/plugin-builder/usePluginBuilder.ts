/* eslint-disable */

import { useState, useCallback, useMemo, useRef } from '@wordpress/element';
import { select, dispatch } from '@wordpress/data';
import { store as commandsStore } from '@wordpress/commands';
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
	AnalysisResponse,
	ChatHistory,
} from './types';
import * as api from './api';
import {
	getSystemPrompt,
	getIntentPrompt,
	getPlannerPrompt,
	getCoderPrompt,
	getAnalyzerPrompt,
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
	type: ChatMessage[ 'type' ],
	content: string,
	data?: any
): ChatMessage {
	return {
		id: String( ++messageIdCounter ) + '-' + Date.now(),
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
		json.replace( /^```json/, '' ).replace( /```\s*$/, '' )
	);
}

function mapChatMessagesToApiMessages( messages: ChatMessage[] ): any[] {
	const raw = messages
		.filter( m => ['text', 'plan', 'review'].includes(m.type) )
		.filter( m => m.content || m.data )
		.map( m => {
			let text = m.content || '';
			if (m.type === 'plan' && m.data) {
				text += '\n\nPlan:\n```json\n' + JSON.stringify({ plugin_name: m.data.plugin_name, description: m.data.description, files: m.data.files.map((f: any) => f.path) }) + '\n```';
			}
			return {
				role: m.role === 'assistant' ? 'model' : m.role,
				text: text
			};
		});

	// The current request (user description) is inside raw.
	// WP AI Client expects the messages array to end with 'user',
	// so we leave it intact.

	const merged: any[] = [];
	for (const msg of raw) {
		if (merged.length > 0 && merged[merged.length - 1].role === msg.role) {
			merged[merged.length - 1].parts[0].text += '\n\n' + msg.text;
		} else {
			merged.push({
				role: msg.role,
				parts: [ { type: 'text', text: msg.text } ]
			});
		}
	}

	// Ensure API history strict requirement: first item MUST be user if array is not empty
	if (merged.length > 0 && merged[0].role === 'model') {
		merged.unshift({
			role: 'user',
			parts: [ { type: 'text', text: 'Hello, please build a WordPress plugin for me.' } ]
		});
	}

	return merged;
}

export function usePluginBuilder() {
	const [ state, setState ] = useState< BuilderState >( 'idle' );
	const [ messages, setMessages ] = useState< ChatMessage[] >( [] );
	const [ logs, setLogs ] = useState< LogEntry[] >( [] );
	const [ currentPlan, setCurrentPlan ] = useState< PluginPlan | null >(
		null
	);
	const [ currentFiles, setCurrentFiles ] = useState< GeneratedFile[] >( [] );
	const [ currentReview, setCurrentReview ] = useState< ReviewResult | null >(
		null
	);
	const [ currentStep, setCurrentStep ] = useState< string >( '' );
	const [ error, setError ] = useState< string | null >( null );
	const [ tokenUsage, setTokenUsage ] = useState< TokenUsageSummary | null >(
		null
	);
	const [ slugConflictWarnings, setSlugConflictWarnings ] = useState<
		string[]
	>( [] );

	const [ activeChatId, _setActiveChatId ] = useState< number | null >( null );
	const activeChatIdRef = useRef< number | null >( null );

	const setActiveChatId = useCallback( ( id: number | null ) => {
		_setActiveChatId( id );
		activeChatIdRef.current = id;
	}, [] );

	const [ chatTitle, _setChatTitle ] = useState< string >( '' );
	const chatTitleRef = useRef< string >( '' );

	const setChatTitle = useCallback( ( title: string ) => {
		_setChatTitle( title );
		chatTitleRef.current = title;
	}, [] );

	const startTimeRef = useRef< number >( 0 );
	const messagesRef = useRef< ChatMessage[] >( [] );

	// Logging
	const log = useCallback(
		( level: LogLevel, message: string, detail?: string ) => {
			setLogs( ( prev ) => [
				...prev,
				{
					id: ++logIdCounter,
					timestamp: new Date(),
					level,
					message,
					detail,
				},
			] );
		},
		[]
	);

	const elapsed = useCallback( () => {
		if ( ! startTimeRef.current ) return '';
		const secs = Math.round( ( Date.now() - startTimeRef.current ) / 1000 );
		return `${ secs }s`;
	}, [] );

	const addMessage = useCallback( ( msg: ChatMessage ) => {
		setMessages( ( prev ) => {
			const next = [ ...prev, msg ];
			messagesRef.current = next;
			return next;
		} );
	}, [] );

	const updateLastLoading = useCallback( ( content: string ) => {
		setMessages( ( prev ) => {
			const index = [ ...prev ]
				.reverse()
				.findIndex( ( m ) => m.type === 'loading' );
			if ( index !== -1 ) {
				const realIndex = prev.length - 1 - index;
				const newMsgs = [ ...prev ];
				newMsgs[ realIndex ] = { ...newMsgs[ realIndex ], content };
				messagesRef.current = newMsgs;
				return newMsgs;
			}
			return prev;
		} );
	}, [] );

	const removeLastLoading = useCallback( () => {
		setMessages( ( prev ) => {
			const index = [ ...prev ]
				.reverse()
				.findIndex( ( m ) => m.type === 'loading' );
			if ( index !== -1 ) {
				const realIndex = prev.length - 1 - index;
				const newMsgs = [ ...prev ];
				newMsgs.splice( realIndex, 1 );
				messagesRef.current = newMsgs;
				return newMsgs;
			}
			return prev;
		} );
	}, [] );

	// Save Chat
	const performChatSave = useCallback( async ( currentSlug?: string ) => {
		const latestMessages = messagesRef.current;
		if ( latestMessages.length === 0 ) return;

		const isNew = !activeChatIdRef.current;
		const currentActiveId = activeChatIdRef.current;

		try {
			let title = chatTitleRef.current;
			if ( isNew ) {
				const planMsg = latestMessages.slice().reverse().find( m => m.type === 'plan' );
				if ( planMsg && planMsg.data?.plugin_name ) {
					title = planMsg.data.plugin_name;
				} else {
					title = 'Plugin Builder Chat';
				}
				setChatTitle( title );
			}
			const result = await api.saveChatHistory( latestMessages, currentSlug, currentActiveId || undefined, title );
			if ( result && result.id ) {
				setActiveChatId( result.id );
			}
		} catch ( e ) {
			console.error( 'Failed to save chat history:', e );
		}
	}, [ setChatTitle, setActiveChatId ] );


	const handleError = useCallback(
		( message: string ) => {
			setState( 'error' );
			setError( message );
			removeLastLoading();
			addMessage( createMessage( 'assistant', 'error', message ) );
			log( 'error', 'Pipeline error', message );
		},
		[ addMessage, log, removeLastLoading ]
	);

	const updateStep = useCallback(
		( step: string ) => {
			setCurrentStep( step );
			updateLastLoading( step );
			log( 'info', `Status: ${ state }`, step );
		},
		[ state, updateLastLoading, log ]
	);

	// Actions
	const sendDescription = useCallback(
		async ( description: string ) => {
			if ( ! description.trim() ) return;
			if ( ! window.wp?.aiClient?.prompt ) {
				handleError( 'WP AI Client JavaScript API is not available.' );
				return;
			}

			const previousPlan = currentPlan;
			const previousFiles = currentFiles.length > 0 ? currentFiles : [];

			setError( null );
			setState( 'planning' );
			startTimeRef.current = Date.now();
			setTokenUsage( null );

			log( 'info', 'Request sent', description.substring( 0, 100 ) );
			addMessage( createMessage( 'user', 'text', description ) );
			addMessage(
				createMessage(
					'assistant',
					'loading',
					'Analyzing your request...'
				)
			);

			try {
				const apiHistory = mapChatMessagesToApiMessages( messagesRef.current );

				// Phase 1: Intent Detection
				updateStep( 'Detecting intent...' );
				const intentPromptBuilder = window.wp.aiClient.prompt(
					getIntentPrompt( description, previousPlan )
				).usingSystemInstruction( getSystemPrompt( 'detector' ) )
					.usingTemperature( 0.1 )
					.usingMaxTokens( 500 )
					.asJsonResponse();
				
				if ( apiHistory.length > 0 ) {
					intentPromptBuilder.withHistory( ...apiHistory );
				}

				const intentText = await intentPromptBuilder.generateText();

				let intentData;
				try {
					intentData = parseJSON( intentText );
				} catch ( e ) {
					intentData = { intent: 'plugin_request', confidence: 0.5 };
				}

				if (
					intentData.intent === 'question' ||
					intentData.intent === 'other'
				) {
					removeLastLoading();
					addMessage(
						createMessage(
							'assistant',
							'text',
							intentData.response ||
								'I can help you build plugins.'
						)
					);
					setState( previousPlan ? 'ready_to_install' : 'idle' );
					void performChatSave( previousPlan?.plugin_slug );
					return;
				}

				if ( intentData.intent !== 'modification_request' ) {
					setCurrentPlan( null );
					setCurrentFiles( [] );
					setCurrentReview( null );
					// Reset previous files context for a new request
					previousFiles.length = 0;
				}

				// Phase 2: Planner
				setState( 'planning' );
				updateStep( 'Generating plugin architecture plan...' );
				const maxFiles = 10;
				const plannerBuilder = window.wp.aiClient.prompt(
					getPlannerPrompt(
						description,
						'simple',
						maxFiles,
						previousPlan
					)
				)
					.usingSystemInstruction( getSystemPrompt( 'planner' ) )
					.usingMaxTokens( 16384 )
					.usingTemperature( 0.3 )
					.asJsonResponse();

				if ( apiHistory.length > 0 ) {
					plannerBuilder.withHistory( ...apiHistory );
				}

				const plannerText = await plannerBuilder.generateText();

				let plan: PluginPlan;
				try {
					plan = parseJSON( plannerText );
				} catch ( e ) {
					handleError( 'Failed to parse the plugin plan JSON.' );
					return;
				}

				setCurrentPlan( plan );
				log(
					'success',
					`Plan ready: ${ plan.plugin_name }`,
					`${ plan.files.length } file(s)`
				);

				// Show plan inline
				removeLastLoading();
				addMessage(
					createMessage(
						'assistant',
						'plan',
						`Here's the plan for **${ plan.plugin_name }**:`,
						plan
					)
				);
				addMessage(
					createMessage(
						'assistant',
						'loading',
						'Preparing generated files...'
					)
				);

				// Phase 3: Generator Loop
				setState( 'coding' );
				const newFiles: GeneratedFile[] = [];

				for ( const fileInfo of plan.files ) {
					updateStep( `Writing ${ fileInfo.path }...` );

					const codeText = await window.wp.aiClient.prompt(
						getCoderPrompt(
							plan,
							fileInfo,
							previousFiles.concat( newFiles )
						)
					)
						.usingSystemInstruction(
							getSystemPrompt( 'coder', fileInfo.type )
						)
						.usingTemperature( 0.2 )
						.usingMaxTokens( 32768 )
						.generateText();

					// Optional basic cleanup: AI sometimes wraps code in backticks
					let cleanContent = codeText.trim();
					cleanContent = cleanContent.replace(
						/^```[a-z]*\s*\n/i,
						''
					);
					cleanContent = cleanContent.replace( /\n```\s*$/i, '' );

					newFiles.push( {
						...fileInfo,
						content: cleanContent.trim(),
					} );
				}

				setCurrentFiles( newFiles );

				// Phase 4: Basic Client-Side Security Scan
				setState( 'reviewing' );
				updateStep( 'Scanning files for security issues...' );
				const scanResult = scanFiles( newFiles );

				const review: ReviewResult = {
					passed: scanResult.passed,
					review_summary: scanResult.passed
						? 'No obvious dangerous patterns found.'
						: 'Dangerous patterns detected in generated code.',
					suggestions: scanResult.issues.map( ( iss ) => ( {
						action: 'Needs Review',
						file_path: iss.file_path,
						file_type: 'php',
						reason: 'Pattern match',
						description: `Matched dangerous pattern \`${ iss.pattern }\` on line ${ iss.line }: \`${ iss.line_content }\``,
					} ) ),
				};
				setCurrentReview( review );

				// Finish
				removeLastLoading();
				addMessage(
					createMessage( 'assistant', 'review', '', review )
				);
				addMessage(
					createMessage(
						'assistant',
						'files',
						"Here's the generated code:",
						newFiles
					)
				);

				setState( 'ready_to_install' );
				log( 'success', `Done in ${ elapsed() }`, 'Ready to install' );
				void performChatSave( plan.plugin_slug );
			} catch ( e: any ) {
				handleError(
					e.message || 'Failed during AI generation pipeline.'
				);
				void performChatSave( currentPlan?.plugin_slug );
			}
		},
		[
			addMessage,
			currentFiles,
			currentPlan,
			handleError,
			log,
			removeLastLoading,
			updateStep,
			elapsed,
			performChatSave
		]
	);

	const installPlugin = useCallback(
		async ( force: boolean = false ) => {
			if ( ! currentPlan || ! currentFiles.length ) return;

			const isUpdate = messagesRef.current.some( m => m.type === 'install' && m.data?.activated );
			const _force = force || isUpdate;

			setState( 'installing' );
			setSlugConflictWarnings( [] );
			addMessage(
				createMessage(
					'assistant',
					'loading',
					isUpdate ? 'Updating plugin files...' : 'Saving and activating plugin...'
				)
			);
			log(
				'info',
				`Saving: ${ currentPlan.plugin_slug }${
					_force ? ' (forced)' : ''
				}`
			);

			try {
				const result = await api.writeFiles(
					currentPlan.plugin_slug,
					currentFiles,
					_force
				);

				if ( needsSlugConfirmation( result ) ) {
					removeLastLoading();
					setSlugConflictWarnings( result.warnings );
					setState( 'ready_to_install' );
					addMessage(
						createMessage(
							'assistant',
							'text',
							`**Warning:** ${ result.warnings.join(
								' '
							) }\n\nClick "Install Anyway" to proceed.`
						)
					);
					log(
						'warn',
						'Slug conflict detected',
						result.warnings.join( '; ' )
					);
					return;
				}

				if ( 'written' in result && result.written ) {
					const pluginFile = result.plugin;

					try {
						if ( !isUpdate ) {
							updateStep( 'Activating plugin...' );
							await api.activatePlugin( pluginFile );
							removeLastLoading();
							setState( 'installed' );
							addMessage(
								createMessage( 'assistant', 'install', '', {
									installed: true,
									activated: true,
									plugin: pluginFile,
								} )
							);
							log(
								'success',
								'Plugin installed & activated',
								pluginFile
							);
						} else {
							removeLastLoading();
							setState( 'idle' );
							addMessage(
								createMessage( 'assistant', 'install', 'Plugin files updated!', {
									installed: true,
									activated: true,
									plugin: pluginFile,
									isUpdate: true,
								} )
							);
							log(
								'success',
								'Plugin files updated locally',
								pluginFile
							);
						}

						// New Analysis Phase
						addMessage(
							createMessage('assistant', 'loading', 'Analyzing next steps...')
						);
						updateStep('Checking plugin features...');

						try {
							const existingCommands = select(commandsStore)
								.getCommands()
								.map((c: any) => ({ name: c.name, label: c.label }));

							const analyzerText = await window.wp.aiClient.prompt(getAnalyzerPrompt(currentFiles, existingCommands))
								.usingSystemInstruction(getSystemPrompt('analyzer'))
								.usingTemperature(0.2)
								.usingMaxTokens(8000)
								.asJsonResponse()
								.generateText();

							const analysis: AnalysisResponse = parseJSON(analyzerText);
							if (analysis.new_commands && analysis.new_commands.length > 0) {
								for (const cmd of analysis.new_commands) {
									dispatch(commandsStore).registerCommand({
										name: cmd.name,
										label: cmd.label,
										callback: ({ close }: { close?: () => void }) => {
											document.location.href = cmd.url;
											if (close) close();
										},
									});
								}
							}

							const updatedCommands = select(commandsStore).getCommands();

							removeLastLoading();
							addMessage(
								createMessage('assistant', 'analysis', '', {
									suggested_commands: analysis.suggested_commands || [],
									all_commands: updatedCommands,
								})
							);
							log('success', 'Analysis complete. Suggested next steps generated.');
						} catch (analysisErr: any) {
							removeLastLoading();
							console.error( 'Analysis failed:', analysisErr );
							addMessage(
								createMessage(
									'assistant',
									'text',
									`**Analysis Error:** ${ analysisErr.message }\n\nCheck browser console for details.`
								)
							);
							log('warn', 'Failed to analyze next steps', analysisErr.message);
						}
					} catch ( activationError: any ) {
						removeLastLoading();
						setState( 'installed' );
						let msg =
							activationError.message ||
							'Failed to activate the plugin.';

						let additionalData =
							activationError.data?.additional_data ||
							activationError.additional_data ||
							'';
						if ( additionalData ) {
							if ( Array.isArray( additionalData ) ) {
								additionalData = additionalData.join( '\n' );
							} else if ( typeof additionalData === 'object' ) {
								additionalData = JSON.stringify(
									additionalData,
									null,
									2
								);
							}
							// The UI will likely render this error string.
							msg += `\n\n**Additional Data:**\n\`\`\`\n${ additionalData }\n\`\`\``;
						}

						addMessage(
							createMessage( 'assistant', 'install', '', {
								installed: true,
								activated: false,
								plugin: pluginFile,
								error: msg,
							} )
						);
						log(
							'warn',
							'Plugin installed (activation failed)',
							msg
						);
					}
				} else if ( 'error' in result ) {
					removeLastLoading();
					handleError( result.error );
				}
			} catch ( e: any ) {
				removeLastLoading();
				const msg = e.message || 'Failed to save plugin files';
				handleError( msg );
			} finally {
				void performChatSave( currentPlan.plugin_slug );
			}
		},
		[
			addMessage,
			currentFiles,
			currentPlan,
			handleError,
			log,
			removeLastLoading,
			updateStep,
			performChatSave,
		]
	);

	const forceInstallPlugin = useCallback( () => {
		void installPlugin( true );
	}, [ installPlugin ] );

	const reset = useCallback( () => {
		setState( 'idle' );
		setMessages( [] );
		messagesRef.current = [];
		setLogs( [] );
		setCurrentPlan( null );
		setCurrentFiles( [] );
		setCurrentReview( null );
		setCurrentStep( '' );
		setError( null );
		setTokenUsage( null );
		startTimeRef.current = 0;
		setSlugConflictWarnings( [] );
		setActiveChatId( null );
		activeChatIdRef.current = null;
		setChatTitle( '' );
		chatTitleRef.current = '';
		messageIdCounter = 0;
		logIdCounter = 0;
	}, [ setActiveChatId, setChatTitle ] );

	const loadChat = useCallback( async ( chat: ChatHistory ) => {
		reset();
		setActiveChatId( chat.id || null );
		activeChatIdRef.current = chat.id || null;
		setChatTitle( chat.title || '' );
		if ( chat.messages && chat.messages.length > 0 ) {
			setMessages( chat.messages );
			messagesRef.current = chat.messages;
			const isInstalledLocally = chat.messages.some( m => m.type === 'install' && m.data?.activated );
			setState( isInstalledLocally ? 'idle' : 'ready_to_install' );
			
			const lastPlan = chat.messages.slice().reverse().find( m => m.type === 'plan' );
			if ( lastPlan && lastPlan.data ) {
				setCurrentPlan( lastPlan.data as PluginPlan );
			}
			
			// If installed, attempt to load physical files from local server.
			if ( chat.plugin_slug && isInstalledLocally ) {
				try {
					const localFilesResponse = await api.getPluginFiles( chat.plugin_slug );
					if ( localFilesResponse && localFilesResponse.files && localFilesResponse.files.length > 0 ) {
						setCurrentFiles( localFilesResponse.files );
					} else {
						const lastFiles = chat.messages.slice().reverse().find( m => m.type === 'files' );
						if ( lastFiles && lastFiles.data ) {
							setCurrentFiles( lastFiles.data as GeneratedFile[] );
						}
					}
				} catch ( e ) {
					console.error( 'Failed to fetch physical files synchronously, falling back to history', e );
					const lastFiles = chat.messages.slice().reverse().find( m => m.type === 'files' );
					if ( lastFiles && lastFiles.data ) {
						setCurrentFiles( lastFiles.data as GeneratedFile[] );
					}
				}
			} else {
				const lastFiles = chat.messages.slice().reverse().find( m => m.type === 'files' );
				if ( lastFiles && lastFiles.data ) {
					setCurrentFiles( lastFiles.data as GeneratedFile[] );
				}
			}

			const lastReview = chat.messages.slice().reverse().find( m => m.type === 'review' );
			if ( lastReview && lastReview.data ) {
				setCurrentReview( lastReview.data as ReviewResult );
			}
		}
	}, [ reset, setActiveChatId, setChatTitle ] );

	const isProcessing = useMemo(
		() =>
			[
				'planning',
				'coding',
				'reviewing',
				'fixing',
				'installing',
			].includes( state ),
		[ state ]
	);

	const hasSlugConflict = useMemo(
		() => slugConflictWarnings.length > 0,
		[ slugConflictWarnings ]
	);

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
		activeChatId,
		sendDescription,
		installPlugin,
		forceInstallPlugin,
		reset,
		loadChat,
	};
}

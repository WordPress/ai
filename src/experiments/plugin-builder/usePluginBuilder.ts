/* eslint-disable */

import { useState, useCallback, useMemo, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
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

	const tokenUsageRef = useRef< TokenUsageSummary >({
    total_tokens: 0,
    total_input_tokens: 0,
    total_output_tokens: 0,
    steps: [],
});

const addTokenUsage = useCallback(
    ( stepName: string, modelName: string, tu: any ) => {
        if ( ! tu ) return;
        const updated = { ...tokenUsageRef.current };
        updated.total_input_tokens += tu.promptTokens || 0;
        updated.total_output_tokens += tu.completionTokens || 0;
        updated.total_tokens += tu.totalTokens || 0;
        const steps = [ ...updated.steps ];
        steps.push( {
            step: stepName,
            model: modelName,
            input_tokens: tu.promptTokens || 0,
            output_tokens: tu.completionTokens || 0,
        } );
        updated.steps = steps;
        tokenUsageRef.current = updated;
        setTokenUsage( { ...updated } );
    },
    []
);

const resetTokenUsage = useCallback(() => {
    const resetVal = {
        total_tokens: 0,
        total_input_tokens: 0,
        total_output_tokens: 0,
        steps: [],
    };
    tokenUsageRef.current = resetVal;
    setTokenUsage(resetVal);
}, []);

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
			log( 'error', __( 'Pipeline error', 'ai' ), message );
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
				handleError(
					__( 'WP AI Client JavaScript API is not available.', 'ai' )
				);
				return;
			}

			const previousPlan = currentPlan;
			const previousFiles = currentFiles.length > 0 ? currentFiles : [];

			setError( null );
			setState( 'planning' );
			startTimeRef.current = Date.now();
			resetTokenUsage();

			log( 'info', __( 'Request sent', 'ai' ), description.substring( 0, 100 ) );
			addMessage( createMessage( 'user', 'text', description ) );
			addMessage(
				createMessage(
					'assistant',
					'loading',
					__( 'Analyzing your request...', 'ai' )
				)
			);

			try {
				const apiHistory = mapChatMessagesToApiMessages( messagesRef.current );

				// Phase 1: Intent Detection
				updateStep( __( 'Detecting intent...', 'ai' ) );
				const intentPromptBuilder = window.wp.aiClient.prompt(
					getIntentPrompt( description, previousPlan )
				).usingSystemInstruction( getSystemPrompt( 'detector' ) )
					.usingTemperature( 0.1 )
					.usingMaxTokens( 500 )
					.asJsonResponse();
				
				if ( apiHistory.length > 0 ) {
					intentPromptBuilder.withHistory( ...apiHistory );
				}

				const intentResult = await intentPromptBuilder.generateTextResult();
				const intentText = intentResult.toText();
				addTokenUsage( 'Intent Detection', intentResult.modelMetadata.name || 'unknown', intentResult.tokenUsage );


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
								__( 'I can help you build plugins.', 'ai' )
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
				updateStep( __( 'Generating plugin architecture plan...', 'ai' ) );
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

				const plannerResult = await plannerBuilder.generateTextResult();
					const plannerText = plannerResult.toText();
					addTokenUsage( 'Planner', plannerResult.modelMetadata.name || 'unknown', plannerResult.tokenUsage );


				let plan: PluginPlan;
				try {
					plan = parseJSON( plannerText );
				} catch ( e ) {
					handleError(
						__( 'Failed to parse the plugin plan JSON.', 'ai' )
					);
					return;
				}

				setCurrentPlan( plan );
				log(
					'success',
					sprintf(
						/* translators: %s: plugin name */
						__( 'Plan ready: %s', 'ai' ),
						plan.plugin_name
					),
					sprintf(
						/* translators: %d: number of files */
						__( '%d file(s)', 'ai' ),
						plan.files.length
					)
				);

				// Show plan inline
				removeLastLoading();
				addMessage(
					createMessage(
						'assistant',
						'plan',
						sprintf(
							/* translators: %s: plugin name */
							__( "Here's the plan for **%s**:", 'ai' ),
							plan.plugin_name
						),
						plan
					)
				);
				addMessage(
					createMessage(
						'assistant',
						'loading',
						__( 'Preparing generated files...', 'ai' )
					)
				);

				// Phase 3: Generator Loop
				setState( 'coding' );
				const newFiles: GeneratedFile[] = [];

				const systemPrompt = `You are an expert autonomous WordPress developer. You have been given a plan to build a plugin.
Your goal is to write all the files necessary according to the plan.
It is HIGHLY recommended to use the \`discover_abilities\` tool right at the beginning before writing any code to gain additional context and guidance on available WP features.
You must use the write_file tool to write each file.
You must use the list_plugins tool to verify the planned plugin slug is NOT already taken. If it is taken, pick a new descriptive slug prefixed with \`apb-\`.
When you are completely finished writing all the code, you MUST call the finish tool and optionally pass the new slug if it changed.
IMPORTANT: You MUST NOT call the finish tool in the same turn alongside other tools. Call it ALONE in a subsequent turn.
Do not stop until you have called finish.`;

				let coderPromptBuilder = window.wp.aiClient.prompt(
					`Please build the plugin according to this plan:\n${JSON.stringify(plan, null, 2)}`
				)
					.usingSystemInstruction( systemPrompt )
					.usingTemperature( 0.2 )
					.usingMaxTokens( 32768 )
					.usingFunctionDeclarations(
						{
							name: 'discover_abilities',
							description: 'Lists available WordPress abilities.',
						},
						{
							name: 'execute_ability',
							description: 'Executes a single WordPress ability.',
							parameters: {
								type: 'object',
								properties: {
									name: { type: 'string', description: 'Name of the ability' },
									input: { type: 'object', description: 'Arguments for the ability' }
								},
								required: ['name', 'input']
							}
						},
						{
							name: 'write_file',
							description: 'Writes a file for the plugin.',
							parameters: {
								type: 'object',
								properties: {
									path: { type: 'string', description: 'Path to the file relative to the plugin root (e.g., plugin-slug.php)' },
									content: { type: 'string', description: 'Full content of the file' }
								},
								required: ['path', 'content']
							}
						},
						{
							name: 'read_file',
							description: 'Reads a previously generated file from the plugin.',
							parameters: {
								type: 'object',
								properties: {
									path: { type: 'string', description: 'Path to the file relative to the plugin root' }
								},
								required: ['path']
							}
						},
						{
								name: 'list_plugins',
								description: 'Lists all currently installed WordPress plugins. Use this to check for slug conflicts.',
							},
							{
								name: 'finish',
								description: 'Call this function ONLY when you have finished writing all files for the plugin.',
								parameters: {
									type: 'object',
									properties: {
										plugin_slug: { type: 'string', description: 'Override the planned slug if a conflict was detected. Must start with apb-' }
									}
								}
							}
					);

				let isFinished = false;
				let turnCount = 0;
				const maxTurns = 10;
				
				while ( ! isFinished && turnCount < maxTurns ) {
					turnCount++;
					updateStep( sprintf( __( 'Agent thinking (Turn %d)...', 'ai' ), turnCount ) );
					
					const result = await coderPromptBuilder.generateResult();
						addTokenUsage( `Generator (Turn ${turnCount})`, result.modelMetadata.name || 'unknown', result.tokenUsage );

						const candidate = result.candidates[0];
						if ( candidate.message && Array.isArray( candidate.message.parts ) ) {
							candidate.message.parts.forEach( ( p: any ) => {
								if ( p.channel === 'thought' && p.type === 'text' && p.text ) {
									addMessage( createMessage( 'assistant', 'thought', p.text ) );
								}
							} );
						}

					if ( candidate.finishReason === 'tool_calls' ) {
						const toolCalls = candidate.message.parts.filter( (p: any) => p.type === 'function_call' );
						toolCalls.sort( ( a: any, b: any ) => {
							if ( a.functionCall.name === 'finish' ) return 1;
							if ( b.functionCall.name === 'finish' ) return -1;
							return 0;
						} );
						const responses: any[] = [];
						
						for ( const part of toolCalls ) {
							const call = part.functionCall;
							const fnName = call.name;
							const args = call.args || {};
							let res: any = null;

							updateStep( sprintf( __( 'Executing tool: %s...', 'ai' ), fnName ) );

							addMessage(
								createMessage(
									'assistant',
									'text',
									sprintf(
										/* translators: 1: tool name, 2: JSON arguments */
										__( '<strong>🛠 Executing tool:</strong> <code>%1$s</code>', 'ai' ),
										fnName
									)
								)
							);

							try {
									if ( fnName === 'list_plugins' ) {
										res = await api.listPlugins();
									} else if ( fnName === 'discover_abilities' ) {
										res = await api.discoverAbilities();
								} else if ( fnName === 'execute_ability' ) {
									res = await api.executeAbility( args.name as string, args.input );
								} else if ( fnName === 'write_file' ) {
									const existingIndex = newFiles.findIndex(f => f.path === args.path);
									if (existingIndex >= 0) {
										newFiles[existingIndex].content = args.content as string;
									} else {
										newFiles.push({ path: args.path as string, content: args.content as string, type: (args.path as string).endsWith('.php') ? 'php' : 'js', description: 'Generated' });
									}
									setCurrentFiles( [...newFiles] );
									res = { success: true };
								} else if ( fnName === 'read_file' ) {
									const file = newFiles.find(f => f.path === args.path);
									if ( file ) {
										res = { content: file.content };
									} else {
										res = { error: 'File not found locally. Ensure you have written it first using write_file.' };
									}
								} else if ( fnName === 'finish' ) {
										const finalSlug = args.plugin_slug && args.plugin_slug.startsWith('apb-') ? args.plugin_slug : plan.plugin_slug;
										const writeRes = await api.writeFiles( finalSlug, newFiles, true );
									
									if ( writeRes.issues && writeRes.issues.length > 0 ) {
										res = { success: false, issues: writeRes.issues, instruction: 'Fix these issues using write_file and call finish again.' };
									} else {
										isFinished = true;
										res = { success: true, message: 'Plugin Generation Complete.' };
									}
								} else {
									res = { error: 'Unknown tool.' };
								}
							} catch ( e: any ) {
								res = { error: e.message || 'Tool execution failed' };
							}
							
							responses.push({
								channel: 'content',
								type: 'function_response',
								functionResponse: { id: call.id, name: fnName, response: res }
							});
						}

						coderPromptBuilder = coderPromptBuilder.withHistory(
							candidate.message,
							{ role: 'user', parts: responses }
						);
					} else {
						isFinished = true;
					}
				}

				setCurrentFiles( newFiles );

				// Phase 4: Basic Client-Side Security Scan
				setState( 'reviewing' );
				updateStep( __( 'Scanning files for security issues...', 'ai' ) );
				const scanResult = scanFiles( newFiles );

				const review: ReviewResult = {
					passed: scanResult.passed,
					review_summary: scanResult.passed
						? __( 'No obvious dangerous patterns found.', 'ai' )
						: __( 'Dangerous patterns detected in generated code.', 'ai' ),
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
						__( "Here's the generated code:", 'ai' ),
						newFiles
					)
				);

				setState( 'ready_to_install' );
				log(
					'success',
					sprintf(
						/* translators: %s: elapsed time */
						__( 'Done in %s', 'ai' ),
						elapsed()
					),
					__( 'Ready to install', 'ai' )
				);
				void performChatSave( plan.plugin_slug );
			} catch ( e: any ) {
				handleError(
					e.message || __( 'Failed during AI generation pipeline.', 'ai' )
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
			const _force = true; // Always force install, as the finish() tool already created the directory, which would otherwise trigger a false conflict.

			setState( 'installing' );
			setSlugConflictWarnings( [] );
			addMessage(
				createMessage(
					'assistant',
					'loading',
					isUpdate ? __( 'Updating plugin files...', 'ai' ) : __( 'Saving and activating plugin...', 'ai' )
				)
			);
			log(
				'info',
				sprintf(
					/* translators: %s: plugin slug */
					__( 'Saving: %s', 'ai' ),
					currentPlan.plugin_slug
				) + ( _force ? sprintf( ' (%s)', __( 'forced', 'ai' ) ) : '' )
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
							sprintf(
								/* translators: %s: warning messages */
								__( '<strong>Warning:</strong> %s\n\nClick "Install Anyway" to proceed.', 'ai' ),
								result.warnings.join( ' ' )
							)
						)
					);
					log(
						'warn',
						__( 'Slug conflict detected', 'ai' ),
						result.warnings.join( '; ' )
					);
					return;
				}

				if ( 'written' in result && result.written ) {
					const pluginFile = result.plugin;

					try {
						if ( !isUpdate ) {
							updateStep( __( 'Activating plugin...', 'ai' ) );
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
								createMessage( 'assistant', 'install', __( 'Plugin files updated!', 'ai' ), {
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
							createMessage( 'assistant', 'loading', __( 'Analyzing next steps...', 'ai' ) )
						);
						updateStep( __( 'Checking plugin features...', 'ai' ) );

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
								createMessage( 'assistant', 'analysis', '', {
									suggested_commands: analysis.suggested_commands || [],
									all_commands: updatedCommands,
								} )
							);
							log( 'success', __( 'Analysis complete. Suggested next steps generated.', 'ai' ) );
						} catch ( analysisErr: any ) {
							removeLastLoading();
							console.error( 'Analysis failed:', analysisErr );
							addMessage(
								createMessage(
									'assistant',
									'text',
									sprintf(
											/* translators: %s: error message */
											__( '<strong>Analysis Error:</strong> %s\n\nCheck browser console for details.', 'ai' ),
											analysisErr.message
										)
								)
							);
							log( 'warn', __( 'Failed to analyze next steps', 'ai' ), analysisErr.message );
						}
					} catch ( activationError: any ) {
						removeLastLoading();
						setState( 'installed' );
						let msg =
							activationError.message ||
							__( 'Failed to activate the plugin.', 'ai' );

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
								msg += `\n\n${ __( 'Additional Data:', 'ai' ) }\n${ additionalData }\n`;
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
							__( 'Plugin installed (activation failed)', 'ai' ),
							msg
						);
					}
				} else if ( 'error' in result ) {
					removeLastLoading();
					handleError( result.error );
				}
			} catch ( e: any ) {
				removeLastLoading();
				const msg = e.message || __( 'Failed to save plugin files', 'ai' );
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

	const downloadPlugin = useCallback( async () => {
		if ( ! currentPlan || state !== 'installed' ) return;

		try {
			await api.downloadPlugin( currentPlan.plugin_slug );
			log( 'success', __( 'Plugin downloaded', 'ai' ), currentPlan.plugin_slug );
		} catch ( e: any ) {
			handleError( e.message || __( 'Failed to download plugin.', 'ai' ) );
		}
	}, [ currentPlan, state, log, handleError ] );

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
		resetTokenUsage();
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

	const isInstalled = useMemo( () => state === 'installed', [ state ] );

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
		isInstalled,
		slugConflictWarnings,
		activeChatId,
		sendDescription,
		installPlugin,
		forceInstallPlugin,
		downloadPlugin,
		reset,
		loadChat,
	};
}

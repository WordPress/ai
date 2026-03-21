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
		id: String( ++messageIdCounter ),
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

	const startTimeRef = useRef< number >( 0 );

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
		setMessages( ( prev ) => [ ...prev, msg ] );
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
				return newMsgs;
			}
			return prev;
		} );
	}, [] );

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
			setTokenUsage( null );

			log(
				'info',
				__( 'Request sent', 'ai' ),
				description.substring( 0, 100 )
			);
			addMessage( createMessage( 'user', 'text', description ) );
			addMessage(
				createMessage(
					'assistant',
					'loading',
					__( 'Analyzing your request...', 'ai' )
				)
			);

			try {
				// Phase 1: Intent Detection
				updateStep( __( 'Detecting intent...', 'ai' ) );
				const intentText = await window.wp.aiClient
					.prompt( getIntentPrompt( description, previousPlan ) )
					.usingSystemInstruction( getSystemPrompt( 'detector' ) )
					.usingTemperature( 0.1 )
					.usingMaxTokens( 500 )
					.asJsonResponse()
					.generateText();

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
				updateStep(
					__( 'Generating plugin architecture plan...', 'ai' )
				);
				const maxFiles = 10;
				const plannerText = await window.wp.aiClient
					.prompt(
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
					.asJsonResponse()
					.generateText();

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

				for ( const fileInfo of plan.files ) {
					updateStep( `Writing ${ fileInfo.path }...` );

					const codeText = await window.wp.aiClient
						.prompt(
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
				updateStep(
					__( 'Scanning files for security issues...', 'ai' )
				);
				const scanResult = scanFiles( newFiles );

				const review: ReviewResult = {
					passed: scanResult.passed,
					review_summary: scanResult.passed
						? __( 'No obvious dangerous patterns found.', 'ai' )
						: __(
								'Dangerous patterns detected in generated code.',
								'ai'
						  ),
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
			} catch ( e: any ) {
				handleError(
					e.message ||
						__( 'Failed during AI generation pipeline.', 'ai' )
				);
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
		]
	);

	const installPlugin = useCallback(
		async ( force: boolean = false ) => {
			if ( ! currentPlan || ! currentFiles.length ) return;

			setState( 'installing' );
			setSlugConflictWarnings( [] );
			addMessage(
				createMessage(
					'assistant',
					'loading',
					__( 'Saving and activating plugin...', 'ai' )
				)
			);
			log(
				'info',
				sprintf(
					/* translators: %s: plugin slug */
					__( 'Saving: %s', 'ai' ),
					currentPlan.plugin_slug
				) + ( force ? sprintf( ' (%s)', __( 'forced', 'ai' ) ) : '' )
			);

			try {
				const result = await api.writeFiles(
					currentPlan.plugin_slug,
					currentFiles,
					force
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
								__(
									'**Warning:** %s\n\nClick "Install Anyway" to proceed.',
									'ai'
								),
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
					updateStep( __( 'Activating plugin...', 'ai' ) );
					const pluginFile = result.plugin;

					try {
						await api.activatePlugin( pluginFile );
						removeLastLoading();
						setState( 'installed' );
						// Reusing 'install' message type for the success output
						addMessage(
							createMessage( 'assistant', 'install', '', {
								installed: true,
								activated: true,
								plugin: pluginFile,
							} )
						);
						log(
							'success',
							__( 'Plugin installed & activated', 'ai' ),
							pluginFile
						);

						// New Analysis Phase
						addMessage(
							createMessage(
								'assistant',
								'loading',
								__( 'Analyzing next steps...', 'ai' )
							)
						);
						updateStep( __( 'Checking plugin features...', 'ai' ) );

						try {
							const existingCommands = select( commandsStore )
								.getCommands()
								.map( ( c: any ) => ( {
									name: c.name,
									label: c.label,
								} ) );

							const analyzerText = await window.wp.aiClient
								.prompt(
									getAnalyzerPrompt(
										currentFiles,
										existingCommands
									)
								)
								.usingSystemInstruction(
									getSystemPrompt( 'analyzer' )
								)
								.usingTemperature( 0.2 )
								.usingMaxTokens( 8000 )
								.asJsonResponse()
								.generateText();

							const analysis: AnalysisResponse =
								parseJSON( analyzerText );
							if (
								analysis.new_commands &&
								analysis.new_commands.length > 0
							) {
								for ( const cmd of analysis.new_commands ) {
									dispatch( commandsStore ).registerCommand( {
										name: cmd.name,
										label: cmd.label,
										callback: ( {
											close,
										}: {
											close?: () => void;
										} ) => {
											document.location.href = cmd.url;
											if ( close ) close();
										},
									} );
								}
							}

							const updatedCommands =
								select( commandsStore ).getCommands();

							removeLastLoading();
							addMessage(
								createMessage( 'assistant', 'analysis', '', {
									suggested_commands:
										analysis.suggested_commands || [],
									all_commands: updatedCommands,
								} )
							);
							log(
								'success',
								__(
									'Analysis complete. Suggested next steps generated.',
									'ai'
								)
							);
						} catch ( analysisErr: any ) {
							removeLastLoading();
							console.error( 'Analysis failed:', analysisErr );
							addMessage(
								createMessage(
									'assistant',
									'text',
									sprintf(
										/* translators: %s: error message */
										__(
											'**Analysis Error:** %s\n\nCheck browser console for details.',
											'ai'
										),
										analysisErr.message
									)
								)
							);
							log(
								'warn',
								__( 'Failed to analyze next steps', 'ai' ),
								analysisErr.message
							);
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
							msg += `\n\n**${ __(
								'Additional Data:',
								'ai'
							) }**\n\`\`\`\n${ additionalData }\n\`\`\``;
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
				const msg =
					e.message || __( 'Failed to save plugin files', 'ai' );
				handleError( msg );
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
		]
	);

	const forceInstallPlugin = useCallback( () => {
		void installPlugin( true );
	}, [ installPlugin ] );

	const downloadPlugin = useCallback( async () => {
		if ( ! currentPlan || state !== 'installed' ) return;

		try {
			await api.downloadPlugin( currentPlan.plugin_slug );
			log(
				'success',
				__( 'Plugin downloaded', 'ai' ),
				currentPlan.plugin_slug
			);
		} catch ( e: any ) {
			handleError(
				e.message || __( 'Failed to download plugin.', 'ai' )
			);
		}
	}, [ currentPlan, state, log, handleError ] );

	const reset = useCallback( () => {
		setState( 'idle' );
		setMessages( [] );
		setLogs( [] );
		setCurrentPlan( null );
		setCurrentFiles( [] );
		setCurrentReview( null );
		setCurrentStep( '' );
		setError( null );
		setTokenUsage( null );
		startTimeRef.current = 0;
		setSlugConflictWarnings( [] );
		messageIdCounter = 0;
		logIdCounter = 0;
	}, [] );

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
		sendDescription,
		installPlugin,
		forceInstallPlugin,
		downloadPlugin,
		reset,
	};
}

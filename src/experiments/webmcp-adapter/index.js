/**
 * WebMCP adapter for WordPress abilities.
 */

( function () {
	'use strict';

	const adapterData = window.aiWebMCPAdapterData || {};

	const DEFAULT_TOOL_NAMES = {
		discover: 'wp-discover-abilities',
		info: 'wp-get-ability-info',
		execute: 'wp-execute-ability',
	};

	const debugState = {
		enabled: false,
		open: true,
		modelContextAvailable: false,
		modelContextSource: '',
		modelContextShimInstalled: false,
		abilitiesApiAvailable: false,
		abilitiesApiSource: '',
		abilitiesApiError: '',
		registrationSucceeded: false,
		registrationError: '',
		registrationAttempts: 0,
		toolNames: [],
		context: null,
	};

	let registeredTools = [];
	let debugPanelElements = null;
	let modelContextShim = null;
	let abilitiesApiPromise = null;

	/**
	 * Returns a plain object when valid, otherwise an empty object.
	 *
	 * @param {unknown} value Candidate value.
	 * @return {Object} Safe object.
	 */
	function asObject( value ) {
		if ( value && typeof value === 'object' && ! Array.isArray( value ) ) {
			return value;
		}
		return {};
	}

	/**
	 * Returns a normalized string array.
	 *
	 * @param {unknown} value Candidate value.
	 * @return {Array<string>} String array.
	 */
	function normalizeStringArray( value ) {
		if ( typeof value === 'string' && value ) {
			return [ value ];
		}

		if ( Array.isArray( value ) ) {
			return value.filter(
				( item ) => typeof item === 'string' && Boolean( item )
			);
		}

		return [];
	}

	/**
	 * Converts unknown errors into a stable string.
	 *
	 * @param {unknown} error Candidate error.
	 * @return {string} Error message.
	 */
	function getErrorMessage( error ) {
		if ( error instanceof Error && error.message ) {
			return error.message;
		}

		return String( error );
	}

	/**
	 * Safely serializes a value for debug output.
	 *
	 * @param {unknown} value Value to serialize.
	 * @return {string} Serialized text.
	 */
	function toDebugText( value ) {
		if ( typeof value === 'string' ) {
			return value;
		}

		try {
			return JSON.stringify( value, null, 2 );
		} catch {
			return String( value );
		}
	}

	/**
	 * Converts any value to WebMCP text content.
	 *
	 * @param {unknown} value Result payload.
	 * @return {{ content: Array<{ type: string, text: string }> }} Text result.
	 */
	function toTextContentResult( value ) {
		return {
			content: [ { type: 'text', text: toDebugText( value ) } ],
		};
	}

	/**
	 * Returns query vars from location.
	 *
	 * @return {Object<string, string>} Query vars.
	 */
	function getQueryVarsFromLocation() {
		const query = {};
		const search = window?.location?.search;

		if ( typeof search !== 'string' || ! search ) {
			return query;
		}

		const params = new URLSearchParams( search );
		params.forEach( ( value, key ) => {
			if ( typeof value === 'string' ) {
				query[ key ] = value;
			}
		} );

		return query;
	}

	/**
	 * Returns current WordPress context for filtering.
	 *
	 * @return {{screen?: string, adminPage?: string, postType?: string, query: Object<string, string>}} Context.
	 */
	function getWordPressWebMcpContext() {
		const localizedContext = asObject( adapterData.wpContext );
		const localizedQuery = asObject( localizedContext.query );
		const query = {
			...localizedQuery,
			...getQueryVarsFromLocation(),
		};

		const queryPostType =
			query.postType || query.post_type || query.post_type_name;

		return {
			screen:
				typeof window.pagenow === 'string'
					? window.pagenow
					: localizedContext.screen,
			adminPage:
				typeof window.adminpage === 'string'
					? window.adminpage
					: localizedContext.adminPage,
			postType:
				typeof window.typenow === 'string'
					? window.typenow
					: queryPostType || localizedContext.postType,
			query,
		};
	}

	/**
	 * Checks if ability context rules match current WP context.
	 *
	 * @param {Object | undefined}                                                                      ability   Ability object.
	 * @param {{screen?: string, adminPage?: string, postType?: string, query: Object<string, string>}} wpContext Current context.
	 * @return {boolean} True if context matches.
	 */
	function doesAbilityMatchWpContext( ability, wpContext ) {
		const contextRules = asObject( ability?.meta?.mcp?.context );

		const allowedScreens = normalizeStringArray( contextRules.screens );
		if (
			allowedScreens.length > 0 &&
			( typeof wpContext.screen !== 'string' ||
				! allowedScreens.includes( wpContext.screen ) )
		) {
			return false;
		}

		const allowedAdminPages = normalizeStringArray(
			contextRules.adminPages
		);
		if (
			allowedAdminPages.length > 0 &&
			( typeof wpContext.adminPage !== 'string' ||
				! allowedAdminPages.includes( wpContext.adminPage ) )
		) {
			return false;
		}

		const allowedPostTypes = normalizeStringArray( contextRules.postTypes );
		if (
			allowedPostTypes.length > 0 &&
			( typeof wpContext.postType !== 'string' ||
				! allowedPostTypes.includes( wpContext.postType ) )
		) {
			return false;
		}

		const queryRules = asObject( contextRules.query );
		const queryKeys = Object.keys( queryRules );

		for ( const key of queryKeys ) {
			const allowedValues = normalizeStringArray( queryRules[ key ] );
			if ( allowedValues.length === 0 ) {
				continue;
			}

			if (
				typeof wpContext.query?.[ key ] !== 'string' ||
				! allowedValues.includes( wpContext.query[ key ] )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns whether ability is marked public for agents.
	 *
	 * @param {Object | undefined} ability Ability object.
	 * @return {boolean} True when public.
	 */
	function isAbilityPublicForAgents( ability ) {
		return Boolean( ability?.meta?.mcp?.public );
	}

	/**
	 * Returns confirmation level for ability execution.
	 *
	 * @param {Object | undefined} ability Ability object.
	 * @return {'none'|'default'|'destructive'} Confirmation level.
	 */
	function getAbilityConfirmationLevel( ability ) {
		if ( ability?.meta?.annotations?.readonly ) {
			return 'none';
		}

		if ( ability?.meta?.annotations?.destructive ) {
			return 'destructive';
		}

		return 'default';
	}

	/**
	 * Applies a filter to ability exposure when hooks are available.
	 *
	 * @param {boolean} defaultValue Default exposure decision.
	 * @param {Object}  ability      Ability object.
	 * @param {Object}  wpContext    WP context object.
	 * @return {boolean} Final decision.
	 */
	function applyAbilityExposureFilter( defaultValue, ability, wpContext ) {
		const hooks = window?.wp?.hooks;
		if ( ! hooks || typeof hooks.applyFilters !== 'function' ) {
			return defaultValue;
		}

		return Boolean(
			hooks.applyFilters(
				'ai.webmcp.isAbilityExposed',
				defaultValue,
				ability,
				wpContext
			)
		);
	}

	/**
	 * Requests execution confirmation for mutating abilities.
	 *
	 * @param {{ability: Object, agent: {requestUserInteraction?: Function}|undefined, confirmationLevel: 'default'|'destructive'}} context Confirmation context.
	 * @return {Promise<boolean>} True when confirmed.
	 */
	async function defaultRequestConfirmation( context ) {
		const { ability, agent, confirmationLevel } = context;

		if ( ! window || typeof window.confirm !== 'function' ) {
			return false;
		}

		if ( typeof agent?.requestUserInteraction !== 'function' ) {
			return false;
		}

		const label = ability.label || ability.name;
		const description = ability.description
			? `\n\n${ ability.description }`
			: '';

		if ( confirmationLevel === 'destructive' ) {
			const destructiveMessage = `Allow destructive ability execution?\n\n${ label }${ description }`;
			const secondPrompt =
				'This ability is marked destructive. Confirm again to continue.';

			return agent.requestUserInteraction( () => {
				// eslint-disable-next-line no-alert
				const firstConfirmation = window.confirm( destructiveMessage );

				if ( ! firstConfirmation ) {
					return false;
				}

				// eslint-disable-next-line no-alert
				return window.confirm( secondPrompt );
			} );
		}

		return agent.requestUserInteraction( () => {
			// eslint-disable-next-line no-alert
			return window.confirm(
				`Allow the agent to run this ability?\n\n${ label }${ description }`
			);
		} );
	}

	/**
	 * Validates and normalizes ability names.
	 *
	 * @param {unknown} value Candidate ability name.
	 * @return {string} Ability name.
	 */
	function parseAbilityName( value ) {
		if ( typeof value !== 'string' || ! value ) {
			throw new Error(
				'Ability name is required and must be a non-empty string.'
			);
		}

		return value;
	}

	/**
	 * Returns merged tool names.
	 *
	 * @return {{discover: string, info: string, execute: string}} Tool names.
	 */
	function getToolNames() {
		return {
			...DEFAULT_TOOL_NAMES,
			...asObject( adapterData.toolNames ),
		};
	}

	/**
	 * Returns true when the value looks like abilities API.
	 *
	 * @param {unknown} value Candidate API value.
	 * @return {boolean} True when shape matches.
	 */
	function isAbilitiesApi( value ) {
		const api = asObject( value );

		return (
			typeof api.getAbilities === 'function' &&
			typeof api.getAbility === 'function' &&
			typeof api.executeAbility === 'function'
		);
	}

	/**
	 * Returns WordPress abilities API from global namespace.
	 *
	 * @return {{getAbilities: Function, getAbility: Function, executeAbility: Function}|null} Abilities API or null.
	 */
	function getGlobalAbilitiesApi() {
		const abilitiesApi = window?.wp?.abilities;

		return isAbilitiesApi( abilitiesApi ) ? abilitiesApi : null;
	}

	/**
	 * Imports a registered WordPress script module from the current page import map.
	 *
	 * @param {string} specifier Module specifier.
	 * @return {Promise<Object>} Imported module namespace object.
	 */
	async function importWordPressScriptModule( specifier ) {
		return import(
			/* webpackIgnore: true */
			specifier
		);
	}

	/**
	 * Resolves abilities API from either window globals or script modules.
	 *
	 * @param {{forceReload?: boolean}} options Loader options.
	 * @return {Promise<{api: {getAbilities: Function, getAbility: Function, executeAbility: Function}|null, source: string, error: string}>} Load result.
	 */
	function resolveAbilitiesApi( options = {} ) {
		const { forceReload = false } = options;

		if ( ! forceReload && abilitiesApiPromise ) {
			return abilitiesApiPromise;
		}

		abilitiesApiPromise = ( async () => {
			const globalApi = getGlobalAbilitiesApi();
			if ( globalApi ) {
				return {
					api: globalApi,
					source: 'window.wp.abilities',
					error: '',
				};
			}

			const errors = [];

			try {
				await importWordPressScriptModule(
					'@wordpress/core-abilities'
				);
			} catch ( error ) {
				errors.push(
					`@wordpress/core-abilities: ${ getErrorMessage( error ) }`
				);
			}

			try {
				const moduleApi = await importWordPressScriptModule(
					'@wordpress/abilities'
				);
				const abilitiesApi = {
					getAbilities: moduleApi?.getAbilities,
					getAbility: moduleApi?.getAbility,
					executeAbility: moduleApi?.executeAbility,
				};

				if ( isAbilitiesApi( abilitiesApi ) ) {
					return {
						api: abilitiesApi,
						source: '@wordpress/abilities (script module)',
						error: '',
					};
				}

				errors.push(
					'@wordpress/abilities did not expose getAbilities/getAbility/executeAbility.'
				);
			} catch ( error ) {
				errors.push(
					`@wordpress/abilities: ${ getErrorMessage( error ) }`
				);
			}

			const globalApiAfterImport = getGlobalAbilitiesApi();
			if ( globalApiAfterImport ) {
				return {
					api: globalApiAfterImport,
					source: 'window.wp.abilities (after module import)',
					error: '',
				};
			}

			return {
				api: null,
				source: '',
				error: errors.join( ' ' ),
			};
		} )();

		return abilitiesApiPromise;
	}

	/**
	 * Returns debug flag config from global runtime overrides.
	 *
	 * Supported forms:
	 * - `window.aiWebMCPDebug = true|false`
	 * - `window.aiWebMCPDebug = { enabled: true|false, open: true|false, shimModelContext: true|false }`
	 *
	 * @return {Object} Debug config.
	 */
	function getDebugFlagConfig() {
		const debugFlag = window.aiWebMCPDebug;

		if ( typeof debugFlag === 'boolean' ) {
			return { enabled: debugFlag };
		}

		return asObject( debugFlag );
	}

	/**
	 * Returns whether debug panel should be enabled.
	 *
	 * @return {boolean} True when enabled.
	 */
	function shouldEnableDebugPanel() {
		const debugFlagConfig = getDebugFlagConfig();

		if ( typeof debugFlagConfig.enabled === 'boolean' ) {
			return debugFlagConfig.enabled;
		}

		return Boolean( adapterData.debugPanelEnabled );
	}

	/**
	 * Returns whether the panel should start open.
	 *
	 * @return {boolean} True when open.
	 */
	function shouldDebugPanelStartOpen() {
		const debugFlagConfig = getDebugFlagConfig();

		if ( typeof debugFlagConfig.open === 'boolean' ) {
			return debugFlagConfig.open;
		}

		return true;
	}

	/**
	 * Returns whether model context shim should auto-install for debugging.
	 *
	 * @return {boolean} True when shim should auto-install.
	 */
	function shouldAutoInstallModelContextShim() {
		const debugFlagConfig = getDebugFlagConfig();
		return Boolean( debugFlagConfig.shimModelContext );
	}

	/**
	 * Returns whether value looks like WebMCP model context.
	 *
	 * @param {unknown} value Candidate model context.
	 * @return {boolean} True when valid.
	 */
	function isModelContext( value ) {
		const context = asObject( value );
		return typeof context.provideContext === 'function';
	}

	/**
	 * Creates a local test model context shim for non-WebMCP browsers.
	 *
	 * @return {{provideContext: Function, getContext: Function, executeTool: Function}} Shim.
	 */
	function createModelContextShim() {
		const shimState = {
			context: {
				tools: [],
			},
		};

		return {
			provideContext( context = {} ) {
				const nextContext = asObject( context );
				shimState.context = {
					...nextContext,
					tools: Array.isArray( nextContext.tools )
						? nextContext.tools
						: [],
				};
			},
			getContext() {
				const currentContext = asObject( shimState.context );
				return {
					...currentContext,
					tools: Array.isArray( currentContext.tools )
						? [ ...currentContext.tools ]
						: [],
				};
			},
			async executeTool( name, input = {}, agent ) {
				if ( typeof name !== 'string' || ! name ) {
					throw new Error(
						'Tool name is required and must be a non-empty string.'
					);
				}

				const tools = Array.isArray( shimState.context.tools )
					? shimState.context.tools
					: [];
				const tool = tools.find(
					( candidate ) => candidate?.name === name
				);

				if ( ! tool || typeof tool.execute !== 'function' ) {
					throw new Error( `Tool not registered: ${ name }` );
				}

				return tool.execute( input, agent );
			},
		};
	}

	/**
	 * Installs a local model context shim for debugging.
	 *
	 * @return {{provideContext: Function, getContext: Function, executeTool: Function}} Shim.
	 */
	function installModelContextShim() {
		if ( ! modelContextShim ) {
			modelContextShim = createModelContextShim();
		}

		try {
			Object.defineProperty( window.navigator, 'modelContext', {
				configurable: true,
				get() {
					return modelContextShim;
				},
			} );
		} catch {
			// Ignore; some browser implementations make navigator properties immutable.
		}

		debugState.modelContextShimInstalled = true;

		return modelContextShim;
	}

	/**
	 * Returns model context from browser or debug shim.
	 *
	 * @return {{provideContext: Function}|null} Model context.
	 */
	function getModelContext() {
		const modelContext = window?.navigator?.modelContext;

		if ( isModelContext( modelContext ) ) {
			debugState.modelContextSource =
				modelContext === modelContextShim
					? 'debug-shim (navigator.modelContext)'
					: 'navigator.modelContext';
			debugState.modelContextShimInstalled =
				modelContext === modelContextShim;
			return modelContext;
		}

		if ( modelContextShim && isModelContext( modelContextShim ) ) {
			debugState.modelContextSource = 'debug-shim';
			debugState.modelContextShimInstalled = true;
			return modelContextShim;
		}

		if ( shouldAutoInstallModelContextShim() ) {
			const shim = installModelContextShim();
			debugState.modelContextSource = 'debug-shim';
			return shim;
		}

		debugState.modelContextSource = '';
		debugState.modelContextShimInstalled = false;
		return null;
	}

	/**
	 * Renders debug status data.
	 */
	function renderDebugPanelStatus() {
		if ( ! debugPanelElements ) {
			return;
		}

		const status = {
			modelContextAvailable: debugState.modelContextAvailable,
			modelContextSource: debugState.modelContextSource || null,
			modelContextShimInstalled: debugState.modelContextShimInstalled,
			abilitiesApiAvailable: debugState.abilitiesApiAvailable,
			abilitiesApiSource: debugState.abilitiesApiSource || null,
			abilitiesApiError: debugState.abilitiesApiError || null,
			registrationSucceeded: debugState.registrationSucceeded,
			registrationError: debugState.registrationError || null,
			registrationAttempts: debugState.registrationAttempts,
			toolNames: debugState.toolNames,
			wpContext: debugState.context,
			flags: {
				optionEnabled: Boolean( adapterData.debugPanelEnabled ),
				jsFlag: window.aiWebMCPDebug,
			},
		};

		debugPanelElements.status.textContent = toDebugText( status );
	}

	/**
	 * Sets debug panel output.
	 *
	 * @param {unknown} value Output value.
	 */
	function setDebugPanelOutput( value ) {
		if ( ! debugPanelElements ) {
			return;
		}

		debugPanelElements.output.textContent = toDebugText( value );
	}

	/**
	 * Returns a registered tool by name.
	 *
	 * @param {string} toolName Registered tool name.
	 * @return {Object} Tool object.
	 */
	function getRegisteredToolByName( toolName ) {
		const tool = registeredTools.find(
			( candidate ) => candidate.name === toolName
		);

		if ( ! tool ) {
			throw new Error( `Tool not registered: ${ toolName }` );
		}

		return tool;
	}

	/**
	 * Resolves a layered key (`discover`, `info`, `execute`) or direct tool name.
	 *
	 * @param {string} toolKeyOrName Layered key or tool name.
	 * @return {string} Tool name.
	 */
	function resolveToolName( toolKeyOrName ) {
		const toolNames = getToolNames();
		const maybeName = toolNames[ toolKeyOrName ] || toolKeyOrName;

		if ( typeof maybeName !== 'string' || ! maybeName ) {
			throw new Error(
				'Tool key/name must resolve to a non-empty string.'
			);
		}

		return maybeName;
	}

	/**
	 * Ensures tools are registered before debug action execution.
	 *
	 * @return {Promise<void>} Resolves when tools are available.
	 */
	async function ensureToolsRegistered() {
		if ( ! debugState.registrationSucceeded ) {
			await registerAbilitiesWebMCPAdapter();
		}

		if ( ! debugState.registrationSucceeded ) {
			throw new Error(
				debugState.registrationError ||
					'WebMCP tools were not registered.'
			);
		}
	}

	/**
	 * Handles debug panel action execution.
	 *
	 * @param {() => Promise<unknown>} action Async action callback.
	 */
	async function runDebugPanelAction( action ) {
		try {
			const result = await action();
			setDebugPanelOutput( result );
		} catch ( error ) {
			setDebugPanelOutput( {
				error: getErrorMessage( error ),
			} );
		}
	}

	/**
	 * Renders panel open/closed state.
	 */
	function renderDebugPanelVisibility() {
		if ( ! debugPanelElements ) {
			return;
		}

		debugPanelElements.body.style.display = debugState.open
			? 'block'
			: 'none';
		debugPanelElements.toggle.textContent = debugState.open
			? 'Collapse'
			: 'Expand';
	}

	/**
	 * Creates the debug panel DOM.
	 */
	function createDebugPanel() {
		if ( debugPanelElements || ! document.body ) {
			return;
		}

		const panel = document.createElement( 'div' );
		panel.id = 'ai-webmcp-debug-panel';
		panel.style.cssText =
			'position:fixed;right:16px;bottom:16px;z-index:999999;width:min(440px,calc(100vw - 32px));max-height:75vh;overflow:auto;background:#111827;color:#f9fafb;border:1px solid #374151;border-radius:8px;box-shadow:0 12px 40px rgba(0,0,0,.35);font:12px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;';

		panel.innerHTML = `
			<div style="padding:10px 12px;border-bottom:1px solid #374151;display:flex;justify-content:space-between;align-items:center;gap:8px;">
				<strong>WebMCP Debug Panel</strong>
				<div style="display:flex;gap:6px;">
					<button type="button" data-ai-webmcp-action="toggle" style="cursor:pointer;border:1px solid #6b7280;background:#1f2937;color:#f9fafb;padding:3px 8px;border-radius:4px;">Collapse</button>
					<button type="button" data-ai-webmcp-action="close" style="cursor:pointer;border:1px solid #6b7280;background:#1f2937;color:#f9fafb;padding:3px 8px;border-radius:4px;">Hide</button>
				</div>
			</div>
			<div data-ai-webmcp-body style="padding:10px 12px;">
				<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
					<button type="button" data-ai-webmcp-action="refresh" style="cursor:pointer;border:1px solid #6b7280;background:#1f2937;color:#f9fafb;padding:4px 8px;border-radius:4px;">Refresh</button>
					<button type="button" data-ai-webmcp-action="register" style="cursor:pointer;border:1px solid #6b7280;background:#1f2937;color:#f9fafb;padding:4px 8px;border-radius:4px;">Retry Register</button>
					<button type="button" data-ai-webmcp-action="install-shim" style="cursor:pointer;border:1px solid #6b7280;background:#1f2937;color:#f9fafb;padding:4px 8px;border-radius:4px;">Install Shim</button>
				</div>
				<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
					<button type="button" data-ai-webmcp-action="discover" style="cursor:pointer;border:1px solid #6b7280;background:#1f2937;color:#f9fafb;padding:4px 8px;border-radius:4px;">Discover</button>
					<button type="button" data-ai-webmcp-action="info-post-details" style="cursor:pointer;border:1px solid #6b7280;background:#1f2937;color:#f9fafb;padding:4px 8px;border-radius:4px;">Info Post Details</button>
					<button type="button" data-ai-webmcp-action="execute-post-details" style="cursor:pointer;border:1px solid #6b7280;background:#1f2937;color:#f9fafb;padding:4px 8px;border-radius:4px;">Run Post Details</button>
				</div>
				<div style="margin-bottom:10px;">
					<div style="font-weight:600;margin-bottom:4px;">Status</div>
					<pre data-ai-webmcp-status style="margin:0;max-height:200px;overflow:auto;background:#030712;border:1px solid #374151;color:#e5e7eb;padding:8px;border-radius:4px;white-space:pre-wrap;word-break:break-word;"></pre>
				</div>
				<div>
					<div style="font-weight:600;margin-bottom:4px;">Last Result</div>
					<pre data-ai-webmcp-output style="margin:0;max-height:220px;overflow:auto;background:#030712;border:1px solid #374151;color:#e5e7eb;padding:8px;border-radius:4px;white-space:pre-wrap;word-break:break-word;">No calls yet.</pre>
				</div>
			</div>
		`;

		document.body.appendChild( panel );

		const body = panel.querySelector( '[data-ai-webmcp-body]' );
		const status = panel.querySelector( '[data-ai-webmcp-status]' );
		const output = panel.querySelector( '[data-ai-webmcp-output]' );
		const toggle = panel.querySelector(
			'[data-ai-webmcp-action="toggle"]'
		);

		if ( ! body || ! status || ! output || ! toggle ) {
			return;
		}

		debugPanelElements = {
			panel,
			body,
			status,
			output,
			toggle,
		};

		panel.addEventListener( 'click', ( event ) => {
			const target = event.target;
			if ( ! target || typeof target.getAttribute !== 'function' ) {
				return;
			}

			const action = target.getAttribute( 'data-ai-webmcp-action' );
			if ( ! action ) {
				return;
			}

			if ( action === 'toggle' ) {
				debugState.open = ! debugState.open;
				renderDebugPanelVisibility();
				return;
			}

			if ( action === 'close' ) {
				window.aiWebMCPDebug = false;
				debugState.enabled = false;
				destroyDebugPanel();
				return;
			}

			if ( action === 'refresh' ) {
				debugState.context = getWordPressWebMcpContext();
				renderDebugPanelStatus();
				setDebugPanelOutput( 'Refreshed context status.' );
				return;
			}

			if ( action === 'register' ) {
				runDebugPanelAction( async () => {
					const isRegistered = await registerAbilitiesWebMCPAdapter( {
						forceReloadAbilities: true,
					} );

					return {
						registrationSucceeded: isRegistered,
						registrationError: debugState.registrationError || null,
					};
				} );
				return;
			}

			if ( action === 'install-shim' ) {
				runDebugPanelAction( async () => {
					installModelContextShim();
					await registerAbilitiesWebMCPAdapter();

					return {
						modelContextSource:
							debugState.modelContextSource || null,
						registrationSucceeded: debugState.registrationSucceeded,
						registrationError: debugState.registrationError || null,
					};
				} );
				return;
			}

			if ( action === 'discover' ) {
				runDebugPanelAction( async () => {
					await ensureToolsRegistered();
					const discoverTool = getRegisteredToolByName(
						resolveToolName( 'discover' )
					);

					return discoverTool.execute();
				} );
				return;
			}

			if ( action === 'info-post-details' ) {
				runDebugPanelAction( async () => {
					await ensureToolsRegistered();
					const infoTool = getRegisteredToolByName(
						resolveToolName( 'info' )
					);

					return infoTool.execute( { name: 'ai/get-post-details' } );
				} );
				return;
			}

			if ( action === 'execute-post-details' ) {
				runDebugPanelAction( async () => {
					await ensureToolsRegistered();
					const executeTool = getRegisteredToolByName(
						resolveToolName( 'execute' )
					);

					return executeTool.execute(
						{
							name: 'ai/get-post-details',
							input: {
								post_id: 1,
								fields: [ 'title', 'slug' ],
							},
						},
						{
							requestUserInteraction: async ( callback ) =>
								callback(),
						}
					);
				} );
			}
		} );

		renderDebugPanelVisibility();
		renderDebugPanelStatus();
	}

	/**
	 * Destroys the debug panel.
	 */
	function destroyDebugPanel() {
		if ( ! debugPanelElements ) {
			return;
		}

		debugPanelElements.panel.remove();
		debugPanelElements = null;
	}

	/**
	 * Synchronizes panel DOM with debug state.
	 */
	function syncDebugPanel() {
		if ( ! debugState.enabled ) {
			destroyDebugPanel();
			return;
		}

		createDebugPanel();
		renderDebugPanelVisibility();
		renderDebugPanelStatus();
	}

	/**
	 * Creates layered WebMCP tools for abilities.
	 *
	 * @param {{getAbilities: Function, getAbility: Function, executeAbility: Function}} abilitiesApi Abilities API.
	 * @return {Array<Object>} Tool definitions.
	 */
	function createAbilitiesWebMcpTools( abilitiesApi ) {
		const toolNames = getToolNames();
		const isAbilityExposed = ( ability, wpContext ) =>
			applyAbilityExposureFilter(
				isAbilityPublicForAgents( ability ) &&
					doesAbilityMatchWpContext( ability, wpContext ),
				ability,
				wpContext
			);

		/**
		 * Resolves ability and enforces exposure rules.
		 *
		 * @param {unknown} nameValue Ability name input.
		 * @param {Object}  wpContext Current WP context.
		 * @return {Object} Ability object.
		 */
		const resolveAbility = ( nameValue, wpContext ) => {
			const name = parseAbilityName( nameValue );
			const ability = abilitiesApi.getAbility( name );

			if ( ! ability ) {
				throw new Error( `Ability not found: ${ name }` );
			}

			if ( ! isAbilityExposed( ability, wpContext ) ) {
				throw new Error(
					`Ability is not exposed to agents: ${ name }`
				);
			}

			return ability;
		};

		return [
			{
				name: toolNames.discover,
				description:
					'List abilities available in this WordPress context for agents.',
				inputSchema: {
					type: 'object',
					properties: {
						category: {
							type: 'string',
							description: 'Optional ability category filter.',
						},
					},
					additionalProperties: false,
				},
				execute: ( rawInput = {} ) => {
					const input = asObject( rawInput );
					const wpContext = getWordPressWebMcpContext();
					const { category } = input;
					const queryArgs =
						typeof category === 'string' && category
							? { category }
							: {};
					const abilities = abilitiesApi.getAbilities( queryArgs );
					const filteredAbilities = abilities.filter( ( ability ) =>
						isAbilityExposed( ability, wpContext )
					);

					return toTextContentResult(
						filteredAbilities.map( ( ability ) => ( {
							name: ability.name,
							label: ability.label,
							description: ability.description,
							category: ability.category,
						} ) )
					);
				},
			},
			{
				name: toolNames.info,
				description: 'Get schema and metadata for an ability by name.',
				inputSchema: {
					type: 'object',
					properties: {
						name: {
							type: 'string',
							description:
								'Ability name in namespace/ability format.',
						},
					},
					required: [ 'name' ],
					additionalProperties: false,
				},
				execute: ( rawInput = {} ) => {
					const input = asObject( rawInput );
					const wpContext = getWordPressWebMcpContext();
					const ability = resolveAbility( input.name, wpContext );

					return toTextContentResult( {
						name: ability.name,
						label: ability.label,
						description: ability.description,
						category: ability.category,
						input_schema: ability.input_schema,
						output_schema: ability.output_schema,
						meta: ability.meta,
					} );
				},
			},
			{
				name: toolNames.execute,
				description:
					'Execute an ability by name with optional JSON input.',
				inputSchema: {
					type: 'object',
					properties: {
						name: {
							type: 'string',
							description:
								'Ability name in namespace/ability format.',
						},
						input: {
							description: 'Ability input payload.',
							type: [
								'object',
								'array',
								'string',
								'number',
								'boolean',
								'null',
							],
						},
					},
					required: [ 'name' ],
					additionalProperties: false,
				},
				execute: async ( rawInput = {}, agent ) => {
					const input = asObject( rawInput );
					const wpContext = getWordPressWebMcpContext();
					const ability = resolveAbility( input.name, wpContext );
					const confirmationLevel =
						getAbilityConfirmationLevel( ability );

					if ( confirmationLevel !== 'none' ) {
						const isConfirmed = await defaultRequestConfirmation( {
							ability,
							agent,
							confirmationLevel,
						} );

						if ( ! isConfirmed ) {
							throw new Error( 'Ability execution canceled.' );
						}
					}

					const result = await abilitiesApi.executeAbility(
						ability.name,
						input.input
					);

					return toTextContentResult( result );
				},
			},
		];
	}

	/**
	 * Registers layered WebMCP tools with navigator.modelContext.
	 *
	 * @param {{forceReloadAbilities?: boolean}} options Adapter options.
	 * @return {Promise<boolean>} True when tools are registered.
	 */
	async function registerAbilitiesWebMCPAdapter( options = {} ) {
		debugState.enabled = shouldEnableDebugPanel();
		debugState.open = shouldDebugPanelStartOpen();
		debugState.context = getWordPressWebMcpContext();
		debugState.registrationAttempts += 1;
		debugState.registrationSucceeded = false;
		debugState.registrationError = '';
		debugState.toolNames = [];
		syncDebugPanel();

		const modelContext = getModelContext();
		debugState.modelContextAvailable = Boolean( modelContext );

		if ( ! debugState.modelContextAvailable ) {
			debugState.registrationError =
				'navigator.modelContext.provideContext is unavailable. Use a WebMCP-enabled browser environment or enable the debug shim.';
			syncDebugPanel();
			return false;
		}

		const abilitiesApiResult = await resolveAbilitiesApi( {
			forceReload: Boolean( options.forceReloadAbilities ),
		} );
		const abilitiesApi = abilitiesApiResult.api;

		debugState.abilitiesApiAvailable = Boolean( abilitiesApi );
		debugState.abilitiesApiSource = abilitiesApiResult.source || '';
		debugState.abilitiesApiError = abilitiesApiResult.error || '';

		if ( ! abilitiesApi ) {
			debugState.registrationError =
				'WordPress abilities API is unavailable. Ensure Gutenberg/core abilities are loaded in this admin page.';
			syncDebugPanel();
			return false;
		}

		const tools = createAbilitiesWebMcpTools( abilitiesApi );
		registeredTools = tools;

		try {
			modelContext.provideContext( { tools } );
		} catch ( error ) {
			debugState.registrationError = `modelContext.provideContext failed: ${ getErrorMessage(
				error
			) }`;
			syncDebugPanel();
			return false;
		}

		debugState.registrationSucceeded = true;
		debugState.registrationError = '';
		debugState.toolNames = tools.map( ( tool ) => tool.name );
		syncDebugPanel();

		return true;
	}

	window.aiWebMCPAdapterDebug = {
		enable() {
			window.aiWebMCPDebug = true;
			debugState.enabled = true;
			syncDebugPanel();
			void registerAbilitiesWebMCPAdapter();
		},
		disable() {
			window.aiWebMCPDebug = false;
			debugState.enabled = false;
			syncDebugPanel();
		},
		async register( options = {} ) {
			return registerAbilitiesWebMCPAdapter( {
				forceReloadAbilities: Boolean(
					asObject( options ).forceReloadAbilities
				),
			} );
		},
		installModelContextShim() {
			installModelContextShim();
			syncDebugPanel();
		},
		async callTool( toolKeyOrName, input = {}, agent ) {
			await ensureToolsRegistered();
			const toolName = resolveToolName( toolKeyOrName );
			const tool = getRegisteredToolByName( toolName );

			return tool.execute( input, agent );
		},
		refresh() {
			debugState.context = getWordPressWebMcpContext();
			renderDebugPanelStatus();
		},
		getState() {
			return {
				...debugState,
				toolNames: [ ...debugState.toolNames ],
			};
		},
	};

	void registerAbilitiesWebMCPAdapter();
} )();

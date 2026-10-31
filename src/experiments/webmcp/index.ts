/**
 * WebMCP bridge.
 *
 * Collects page tools from the registry, runs them through the
 * `wpai.webmcp.tools` filter, and registers each one on
 * `document.modelContext` with one `registerTool` call, up to the per-page
 * cap. The built-in editor tools register once the block editor has initialized.
 *
 * `modelContext` lives on `document` (with `navigator` kept as a fallback
 * for older builds) and, in ChatGPT's browser, is a frozen object that
 * implements only `registerTool`; batch `provideContext` throws. Every
 * registration failure is swallowed so one bad tool does not take the rest
 * down.
 */

/**
 * WordPress dependencies
 */
import { subscribe } from '@wordpress/data';
import domReady from '@wordpress/dom-ready';
import { applyFilters } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { getEditorTools, hasEditor } from './editor-tools';
import type {
	BridgeData,
	ModelContext,
	WebMCPRegistry,
	WebMCPTool,
} from './types';

declare global {
	interface Window {
		aiWebMCP?: BridgeData;
		wpai?: { webmcp?: WebMCPRegistry } & Record< string, unknown >;
	}
	interface Document {
		modelContext?: ModelContext;
	}
	interface Navigator {
		modelContext?: ModelContext;
	}
}

const getModelContext = (): ModelContext | null => {
	if ( document.modelContext ) {
		return document.modelContext;
	}
	if ( navigator.modelContext ) {
		return navigator.modelContext;
	}
	return null;
};

// wp_localize_script turns numbers into strings, so read maxTools back as one.
const data: BridgeData = {
	screen: String( window.aiWebMCP?.screen ?? '' ),
	maxTools: Math.max( 1, Number( window.aiWebMCP?.maxTools ) || 30 ),
};
const custom: WebMCPTool[] = [];
const registeredNames = new Set< string >();

const isTool = ( tool: unknown ): tool is WebMCPTool => {
	const candidate = tool as Partial< WebMCPTool > | null;
	return Boolean(
		candidate &&
			typeof candidate.name === 'string' &&
			candidate.name &&
			typeof candidate.description === 'string' &&
			typeof candidate.execute === 'function'
	);
};

const collect = (): WebMCPTool[] => {
	const tools = [ ...( hasEditor() ? getEditorTools() : [] ), ...custom ];
	const filtered = applyFilters(
		'wpai.webmcp.tools',
		tools,
		data.screen
	) as unknown;
	return ( Array.isArray( filtered ) ? filtered : tools ).filter( isTool );
};

const register = () => {
	const modelContext = getModelContext();
	if ( ! modelContext ) {
		return;
	}

	const tools = collect().filter(
		( tool ) => ! registeredNames.has( tool.name )
	);
	const room = Math.max( 0, data.maxTools - registeredNames.size );
	const dropped = tools.slice( room );
	if ( dropped.length > 0 ) {
		// eslint-disable-next-line no-console
		console.warn(
			`WebMCP: ${ dropped.length } tool(s) not registered, the page cap is ${ data.maxTools }:`,
			dropped.map( ( tool ) => tool.name ).join( ', ' )
		);
	}

	const toRegister = tools.slice( 0, room ).map( ( tool ) => ( {
		...tool,
		inputSchema: tool.inputSchema ?? { type: 'object' },
	} ) );

	if ( typeof modelContext.registerTool === 'function' ) {
		for ( const tool of toRegister ) {
			try {
				const result = modelContext.registerTool( tool ) as
					| { catch?: ( handler: () => void ) => unknown }
					| undefined;
				result?.catch?.( () => {} );
				registeredNames.add( tool.name );
			} catch {
				// One bad tool must not stop the others.
			}
		}
		return;
	}

	if ( typeof modelContext.provideContext === 'function' ) {
		try {
			modelContext.provideContext( { tools: toRegister } );
			toRegister.forEach( ( tool ) => registeredNames.add( tool.name ) );
		} catch {
			// Older polyfills only.
		}
	}
};

const registry: WebMCPRegistry = {
	registerTool: ( tool ) => {
		if ( ! isTool( tool ) ) {
			throw new Error(
				'A WebMCP tool needs a name, a description and an execute function.'
			);
		}
		custom.push( tool );
	},
	getTools: () => collect(),
	refresh: register,
};

window.wpai = window.wpai ?? {};
window.wpai.webmcp = registry;

domReady( () => {
	register();
	if (
		document.body.classList.contains( 'block-editor-page' ) &&
		! hasEditor()
	) {
		// The editor may load its post after DOM ready. Stop listening once
		// it is initialized, so later edits do not register tools again.
		const unsubscribe = subscribe( () => {
			if ( hasEditor() ) {
				unsubscribe();
				register();
			}
		} );
	}
} );

export {};

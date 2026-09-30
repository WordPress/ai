/**
 * WebMCP bridge.
 *
 * Registers the tools the server exposes for this page on
 * `document.modelContext`, one `registerTool` call per tool, and executes
 * them through the experiment's REST route. No WordPress packages are
 * imported on purpose: the script also loads on the front end.
 *
 * Findings this follows, from running a WordPress bridge against ChatGPT's
 * in-app browser: `modelContext` lives on `document` (with `navigator` as a
 * fallback for older builds) and is a frozen object that implements only
 * `registerTool`; batch `provideContext` throws. Tools are registered one at
 * a time and each failure is swallowed so one bad schema does not take the
 * rest down.
 */

interface BridgeData {
	context: string;
	toolsUrl: string;
	executeUrl: string;
	nonceUrl: string;
	restNonce: string;
	nonce: string;
}

interface ToolDefinition {
	name: string;
	description: string;
	inputSchema: Record< string, unknown >;
	annotations?: Record< string, unknown >;
}

interface RegisteredTool extends ToolDefinition {
	execute: ( input: unknown ) => Promise< ToolResult >;
}

interface ToolResult {
	content: Array< { type: 'text'; text: string } >;
}

interface ModelContext {
	registerTool?: ( tool: RegisteredTool ) => unknown;
	provideContext?: ( context: { tools: RegisteredTool[] } ) => unknown;
}

declare global {
	interface Window {
		aiWebMCP?: BridgeData;
	}
	interface Document {
		modelContext?: ModelContext;
	}
	interface Navigator {
		modelContext?: ModelContext;
	}
}

const getModelContext = (): ModelContext | null => {
	if ( typeof document !== 'undefined' && document.modelContext ) {
		return document.modelContext;
	}
	if ( typeof navigator !== 'undefined' && navigator.modelContext ) {
		return navigator.modelContext;
	}
	return null;
};

const toTextResult = ( value: unknown ): ToolResult => ( {
	content: [
		{
			type: 'text',
			text: typeof value === 'string' ? value : JSON.stringify( value ),
		},
	],
} );

const bridge = ( data: BridgeData, modelContext: ModelContext ) => {
	let { nonce, restNonce } = data;

	const headers = ( withToken: boolean ): Record< string, string > => {
		const result: Record< string, string > = {
			'Content-Type': 'application/json',
		};
		if ( restNonce ) {
			// The cookie session's own nonce. Core rejects anything else in
			// this header, which is why the experiment's token has its own.
			result[ 'X-WP-Nonce' ] = restNonce;
		}
		if ( withToken && nonce ) {
			result[ 'X-WPAI-WebMCP-Nonce' ] = nonce;
		}
		return result;
	};

	const refreshNonces = async (): Promise< void > => {
		try {
			const response = await fetch( data.nonceUrl, {
				credentials: 'same-origin',
				headers: headers( false ),
			} );
			if ( ! response.ok ) {
				return;
			}
			const json = await response.json();
			if ( typeof json?.nonce === 'string' ) {
				nonce = json.nonce;
			}
			if ( typeof json?.restNonce === 'string' ) {
				restNonce = json.restNonce;
			}
		} catch {
			// A failed refresh surfaces on the next execution as a 403.
		}
	};

	const loadTools = async (): Promise< ToolDefinition[] > => {
		const url = new URL( data.toolsUrl, window.location.href );
		url.searchParams.set( 'context', data.context );
		const response = await fetch( url.toString(), {
			credentials: 'same-origin',
			headers: headers( false ),
		} );
		if ( ! response.ok ) {
			return [];
		}
		const json = await response.json();
		if ( typeof json?.nonce === 'string' ) {
			nonce = json.nonce;
		}
		if ( typeof json?.restNonce === 'string' ) {
			restNonce = json.restNonce;
		}
		return Array.isArray( json?.tools ) ? json.tools : [];
	};

	const post = ( body: string ): Promise< Response > =>
		fetch( data.executeUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: headers( true ),
			body,
		} );

	const runTool = async (
		name: string,
		input: unknown
	): Promise< ToolResult > => {
		const body = JSON.stringify( {
			tool: name,
			context: data.context,
			input: input && typeof input === 'object' ? input : {},
		} );

		let response = await post( body );
		if ( response.status === 403 ) {
			// Tokens expire while a page stays open. Refresh once, then retry.
			await refreshNonces();
			response = await post( body );
		}

		let json: {
			result?: unknown;
			message?: string;
			code?: string;
		} = {};
		try {
			json = await response.json();
		} catch {
			// A non-JSON body is reported through the status below.
		}

		if ( ! response.ok ) {
			throw new Error(
				json.message ?? `WebMCP: HTTP ${ response.status }`
			);
		}

		return toTextResult( json.result );
	};

	const registerTools = ( tools: ToolDefinition[] ) => {
		const registered = tools
			.filter( ( tool ) => tool && tool.name && tool.description )
			.map(
				( tool ): RegisteredTool => ( {
					name: tool.name,
					description: tool.description,
					inputSchema: tool.inputSchema ?? { type: 'object' },
					...( tool.annotations
						? { annotations: tool.annotations }
						: {} ),
					execute: ( input: unknown ) => runTool( tool.name, input ),
				} )
			);

		if ( registered.length === 0 ) {
			return;
		}

		if ( typeof modelContext.registerTool === 'function' ) {
			for ( const tool of registered ) {
				try {
					const result = modelContext.registerTool( tool ) as
						| { catch?: ( handler: () => void ) => unknown }
						| undefined;
					result?.catch?.( () => {} );
				} catch {
					// One bad tool must not stop the others.
				}
			}
			return;
		}

		if ( typeof modelContext.provideContext === 'function' ) {
			try {
				modelContext.provideContext( { tools: registered } );
			} catch {
				// Older polyfills only; nothing to do when this throws.
			}
		}
	};

	loadTools()
		.then( registerTools )
		.catch( () => {} );
};

const data = window.aiWebMCP;
const modelContext = getModelContext();

if ( data && modelContext ) {
	bridge( data, modelContext );
}

export {};

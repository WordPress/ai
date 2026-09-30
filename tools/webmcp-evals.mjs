/**
 * Runs the WebMCP tool-selection evals against an OpenAI-compatible chat
 * endpoint and reports the prompts for which the model picked the wrong tool.
 *
 *   WEBMCP_EVAL_ENDPOINT=https://api.openai.com/v1/chat/completions \
 *   WEBMCP_EVAL_API_KEY=... WEBMCP_EVAL_MODEL=gpt-4o-mini \
 *   node tools/webmcp-evals.mjs
 *
 * The tool list comes from the bridge's own definitions, so a change to a
 * description is judged by whether a model still chooses the right tool.
 */
/* eslint-disable no-console */

/**
 * External dependencies
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname( fileURLToPath( import.meta.url ) );
const evals = JSON.parse(
	readFileSync(
		path.join( here, '../src/experiments/webmcp/evals.json' ),
		'utf8'
	)
);
const { EDITOR_TOOL_DEFINITIONS } = await import(
	path.join( here, '../src/experiments/webmcp/editor-tool-definitions.mjs' )
);

const {
	WEBMCP_EVAL_ENDPOINT: endpoint,
	WEBMCP_EVAL_API_KEY: apiKey,
	WEBMCP_EVAL_MODEL: model,
} = process.env;
if ( ! endpoint || ! apiKey || ! model ) {
	console.error(
		'Set WEBMCP_EVAL_ENDPOINT, WEBMCP_EVAL_API_KEY and WEBMCP_EVAL_MODEL.'
	);
	process.exit( 2 );
}

const tools = EDITOR_TOOL_DEFINITIONS.map(
	/** @param {{ name: string, description: string, inputSchema: object }} tool */
	( tool ) => ( {
		type: 'function',
		function: {
			name: tool.name,
			description: tool.description,
			parameters: tool.inputSchema,
		},
	} )
);

let failures = 0;
for ( const { prompt, expect } of evals.cases ) {
	const response = await fetch( endpoint, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Authorization: `Bearer ${ apiKey }`,
		},
		body: JSON.stringify( {
			model,
			messages: [
				{
					role: 'system',
					content:
						'You are working inside the WordPress block editor on the current page. Use the tools to act on the page.',
				},
				{ role: 'user', content: prompt },
			],
			tools,
			tool_choice: 'required',
		} ),
	} );
	const json = await response.json();
	const picked =
		json?.choices?.[ 0 ]?.message?.tool_calls?.[ 0 ]?.function?.name ??
		'(none)';
	const ok = picked === expect;
	if ( ! ok ) {
		failures++;
	}
	console.log(
		`${ ok ? 'ok  ' : 'FAIL' } ${ expect.padEnd(
			32
		) } got ${ picked.padEnd( 32 ) } ${ prompt }`
	);
}
console.log(
	`\n${ evals.cases.length - failures }/${
		evals.cases.length
	} picked the expected tool.`
);
process.exit( failures ? 1 : 0 );

/**
 * Coverage for the AI Workspace post-markup renderer, `renderHtml()`.
 *
 * The update card shows a post's proposed content through `renderHtml()`, so
 * it is the boundary between markup an approver approves and markup they see.
 * It needs `DOMParser`, which Node does not have, so the module and the one it
 * imports are transpiled with TypeScript and run in a blank browser page.
 * Nothing here needs the WordPress site; the page is `about:blank` with the
 * modules added as an inline script.
 */

/**
 * External dependencies
 */
import { readFileSync } from 'fs';
import path from 'path';
import ts from 'typescript';
import { expect, test, type Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import type {
	BlockNode,
	InlineNode,
} from '../../../../src/experiments/ai-workspace/utils/render-markdown';

const ALLOWED_HOST = 'example.test';

const GLOBAL_NAME = 'AiWorkspaceRenderHtml';

/**
 * The modules the renderer needs, by the specifier `render-html.ts` imports
 * them with. `render-markdown.ts` imports nothing.
 */
const MODULES: Record< string, string > = {
	'./render-markdown': 'render-markdown.ts',
	'./render-html': 'render-html.ts',
};

/**
 * Transpiles the renderer into a script that sets one global.
 *
 * Each module becomes a CommonJS function behind a small `require`, so the
 * script needs no bundler and runs as plain browser JavaScript.
 *
 * @return The script source.
 */
function bundle(): string {
	const directory = path.resolve(
		__dirname,
		'../../../../src/experiments/ai-workspace/utils'
	);

	const definitions = Object.entries( MODULES )
		.map( ( [ specifier, file ] ) => {
			const { outputText } = ts.transpileModule(
				readFileSync( path.join( directory, file ), 'utf8' ),
				{
					compilerOptions: {
						module: ts.ModuleKind.CommonJS,
						target: ts.ScriptTarget.ES2020,
					},
				}
			);

			return `${ JSON.stringify(
				specifier
			) }: function ( exports, require, module ) {\n${ outputText }\n}`;
		} )
		.join( ',\n' );

	return `( function () {
	var definitions = { ${ definitions } };
	var cache = {};
	function load( specifier ) {
		if ( ! cache[ specifier ] ) {
			var module = { exports: {} };
			cache[ specifier ] = module;
			definitions[ specifier ]( module.exports, load, module );
		}
		return cache[ specifier ].exports;
	}
	window[ ${ JSON.stringify( GLOBAL_NAME ) } ] = load( './render-html' );
} )();`;
}

/**
 * Runs `renderHtml()` in the page.
 *
 * @param page The page with the bundle loaded.
 * @param html The markup.
 * @return The block nodes.
 */
async function render( page: Page, html: string ): Promise< BlockNode[] > {
	return page.evaluate(
		( [ markup, host, name ] ) =>
			(
				window as unknown as Record<
					string,
					{
						renderHtml: (
							source: string,
							options: { allowedHost: string }
						) => BlockNode[];
					}
				>
			 )[ name ]!.renderHtml( markup, { allowedHost: host } ),
		[ html, ALLOWED_HOST, GLOBAL_NAME ] as const
	);
}

/**
 * Runs `changedBlockSettings()` in the page.
 *
 * @param page     The page with the bundle loaded.
 * @param current  The current markup.
 * @param proposed The proposed markup.
 * @return The names of the blocks whose settings differ.
 */
async function changedSettings(
	page: Page,
	current: string,
	proposed: string
): Promise< string[] > {
	return page.evaluate(
		( [ before, after, name ] ) =>
			(
				window as unknown as Record<
					string,
					{
						changedBlockSettings: (
							current: string,
							proposed: string
						) => string[];
					}
				>
			 )[ name ]!.changedBlockSettings( before, after ),
		[ current, proposed, GLOBAL_NAME ] as const
	);
}

/**
 * Collects every inline node in a tree, depth first.
 *
 * @param blocks The block nodes.
 * @return The inline nodes.
 */
function inlineNodes( blocks: BlockNode[] ): InlineNode[] {
	const collected: InlineNode[] = [];

	const walkInline = ( nodes: InlineNode[] ): void => {
		nodes.forEach( ( node ) => {
			collected.push( node );

			if ( 'strong' === node.type || 'emphasis' === node.type ) {
				walkInline( node.children );
			}
		} );
	};

	const walkBlocks = ( nodes: BlockNode[] ): void => {
		nodes.forEach( ( node ) => {
			if ( 'blockquote' === node.type ) {
				walkBlocks( node.children );
				return;
			}

			if ( 'list' === node.type ) {
				node.items.forEach( walkInline );
				return;
			}

			if ( 'codeBlock' === node.type ) {
				return;
			}

			walkInline( node.children );
		} );
	};

	walkBlocks( blocks );

	return collected;
}

/**
 * Returns the withheld-markup descriptions in a tree.
 *
 * @param blocks The block nodes.
 * @return The withheld nodes.
 */
function withheld(
	blocks: BlockNode[]
): Extract< InlineNode, { type: 'withheld' } >[] {
	return inlineNodes( blocks ).filter(
		( node ): node is Extract< InlineNode, { type: 'withheld' } > =>
			'withheld' === node.type
	);
}

/**
 * Returns the concatenated text of every text node in a tree.
 *
 * @param blocks The block nodes.
 * @return The text.
 */
function textOf( blocks: BlockNode[] ): string {
	return inlineNodes( blocks )
		.map( ( node ) => ( 'text' === node.type ? node.value : '' ) )
		.join( '' );
}

test.describe( 'AI Workspace post markup renderer', () => {
	let script = '';
	const requested: string[] = [];

	test.beforeAll( () => {
		script = bundle();
	} );

	test.beforeEach( async ( { page } ) => {
		requested.length = 0;
		page.on( 'request', ( request ) => requested.push( request.url() ) );

		await page.goto( 'about:blank' );
		await page.addScriptTag( { content: script } );
	} );

	test.afterEach( () => {
		// Nothing the renderer reads may make the browser fetch anything.
		expect(
			requested.filter( ( url ) => url.startsWith( 'http' ) )
		).toEqual( [] );
	} );

	test( 'scripts and styles produce nothing and never run', async ( {
		page,
	} ) => {
		const blocks = await render(
			page,
			'<p>Kept</p><script>window.__ranScript = true;</script><style>body { display: none; }</style>'
		);

		expect( textOf( blocks ) ).toBe( 'Kept' );
		expect( withheld( blocks ) ).toEqual( [] );
		expect(
			await page.evaluate(
				() =>
					( window as unknown as { __ranScript?: boolean } )
						.__ranScript
			)
		).toBeUndefined();
	} );

	test( 'javascript: and off-host links are inert', async ( { page } ) => {
		const blocks = await render(
			page,
			'<p><a href="javascript:alert(1)">run</a> <a href="https://evil.test/x">off</a> <a href="https://example.test/ok">on</a></p>'
		);

		const links = inlineNodes( blocks ).filter(
			( node ) => 'link' === node.type
		);

		expect( links ).toEqual( [
			{
				type: 'link',
				label: 'run',
				destination: 'javascript:alert(1)',
				inert: true,
			},
			{
				type: 'link',
				label: 'off',
				destination: 'https://evil.test/x',
				inert: true,
			},
			{
				type: 'link',
				label: 'on',
				destination: 'https://example.test/ok',
				inert: false,
			},
		] );
	} );

	test( 'describes self-closing blocks, not the delimiters of blocks with content', async ( {
		page,
	} ) => {
		const blocks = await render(
			page,
			[
				'<!-- wp:rss {"feedURL":"https://evil.test/feed"} /-->',
				'<!-- wp:paragraph -->',
				'<p>Body</p>',
				'<!-- /wp:paragraph -->',
				'<!-- wp:acme/widget {"id":7} /-->',
				'<!-- an ordinary comment -->',
			].join( '\n' )
		);

		expect( withheld( blocks ) ).toEqual( [
			{
				type: 'withheld',
				kind: 'block',
				name: 'core/rss',
				detail: '{"feedURL":"https://evil.test/feed"}',
			},
			{
				type: 'withheld',
				kind: 'block',
				name: 'acme/widget',
				detail: '{"id":7}',
			},
		] );
		expect( textOf( blocks ) ).toContain( 'Body' );
	} );

	test( 'describes block delimiters after a stray closing body tag', async ( {
		page,
	} ) => {
		const blocks = await render(
			page,
			'<p>Body</p></body></html><!-- wp:latest-posts /-->'
		);

		expect( withheld( blocks ) ).toEqual( [
			{
				type: 'withheld',
				kind: 'block',
				name: 'core/latest-posts',
				detail: '',
			},
		] );
	} );

	test( 'truncates long block attributes', async ( { page } ) => {
		const long = 'x'.repeat( 500 );
		const blocks = await render(
			page,
			`<!-- wp:rss {"feedURL":"${ long }"} /-->`
		);

		const [ node ] = withheld( blocks );

		expect( node?.detail.length ).toBeLessThan( 200 );
		expect( node?.detail.endsWith( '…' ) ).toBe( true );
	} );

	test( 'describes video and audio, with their sources, without playing them', async ( {
		page,
	} ) => {
		const blocks = await render(
			page,
			[
				'<video src="https://evil.test/v.mp4" autoplay muted></video>',
				'<audio autoplay><source src="https://evil.test/a.mp3" type="audio/mpeg"></audio>',
			].join( '' )
		);

		expect( withheld( blocks ) ).toEqual( [
			{
				type: 'withheld',
				kind: 'media',
				name: 'video',
				detail: 'src=https://evil.test/v.mp4, autoplay, muted',
			},
			{
				type: 'withheld',
				kind: 'media',
				name: 'audio',
				detail: 'autoplay',
			},
			{
				type: 'withheld',
				kind: 'media',
				name: 'source',
				detail: 'src=https://evil.test/a.mp3, type=audio/mpeg',
			},
		] );
	} );

	test( 'describes buttons and textareas with what they show', async ( {
		page,
	} ) => {
		const blocks = await render(
			page,
			'<p><button type="submit">Pay now</button><textarea name="note">Prefilled</textarea></p>'
		);

		expect( withheld( blocks ) ).toEqual( [
			{
				type: 'withheld',
				kind: 'control',
				name: 'button',
				detail: "'Pay now', type=submit",
			},
			{
				type: 'withheld',
				kind: 'control',
				name: 'textarea',
				detail: "'Prefilled', name=note",
			},
		] );
	} );

	test( 'describes embeds by address', async ( { page } ) => {
		const blocks = await render(
			page,
			'<iframe src="https://evil.test/frame"></iframe><object data="https://evil.test/o.swf"></object>'
		);

		expect( withheld( blocks ) ).toEqual( [
			{
				type: 'withheld',
				kind: 'embed',
				name: 'iframe',
				detail: 'src=https://evil.test/frame',
			},
			{
				type: 'withheld',
				kind: 'embed',
				name: 'object',
				detail: 'data=https://evil.test/o.swf',
			},
		] );
	} );

	test( 'describes images and never fetches them', async ( { page } ) => {
		const blocks = await render(
			page,
			'<p><img src="https://evil.test/pixel.png?leak=1" alt="a pixel"></p>'
		);

		expect( inlineNodes( blocks ) ).toEqual( [
			{
				type: 'image',
				alt: 'a pixel',
				destination: 'https://evil.test/pixel.png?leak=1',
			},
		] );
	} );

	test( 'describes svg and math by the text they draw, and canvas by name', async ( {
		page,
	} ) => {
		const blocks = await render(
			page,
			[
				'<p><svg viewBox="0 0 10 10"><text>Logo</text></svg>',
				' and <math><mi>x</mi><mo>=</mo><mn>2</mn></math>',
				'<canvas width="300"></canvas></p>',
			].join( '' )
		);

		expect( withheld( blocks ) ).toEqual( [
			{ type: 'withheld', kind: 'embed', name: 'svg', detail: "'Logo'" },
			{ type: 'withheld', kind: 'embed', name: 'math', detail: "'x=2'" },
			{ type: 'withheld', kind: 'embed', name: 'canvas', detail: '' },
		] );
	} );

	test( 'describes selects and inputs with what they hold', async ( {
		page,
	} ) => {
		const blocks = await render(
			page,
			'<p><select name="size"><option>Small</option><option>Large</option></select><input type="text" name="q" value="hi" placeholder="Search"></p>'
		);

		expect( withheld( blocks ) ).toEqual( [
			{
				type: 'withheld',
				kind: 'control',
				name: 'select',
				detail: "'Small / Large', name=size",
			},
			{
				type: 'withheld',
				kind: 'control',
				name: 'input',
				detail: 'type=text, name=q, value=hi, placeholder=Search',
			},
		] );
	} );

	test( 'describes a form by where it sends, and the controls inside it', async ( {
		page,
	} ) => {
		const blocks = await render(
			page,
			'<form action="https://evil.test/collect" method="post"><input type="password" name="pw"><button>Sign in</button></form>'
		);

		expect( withheld( blocks ) ).toEqual( [
			{
				type: 'withheld',
				kind: 'control',
				name: 'form',
				detail: 'action=https://evil.test/collect, method=post',
			},
			{
				type: 'withheld',
				kind: 'control',
				name: 'input',
				detail: 'type=password, name=pw',
			},
			{
				type: 'withheld',
				kind: 'control',
				name: 'button',
				detail: "'Sign in'",
			},
		] );
	} );

	test( 'describes embed elements and media tracks by address', async ( {
		page,
	} ) => {
		const blocks = await render(
			page,
			[
				'<embed src="https://evil.test/e.swf" type="application/x-shockwave-flash">',
				'<video controls><track src="https://evil.test/t.vtt" kind="captions" srclang="en"></video>',
			].join( '' )
		);

		expect( withheld( blocks ) ).toEqual( [
			{
				type: 'withheld',
				kind: 'embed',
				name: 'embed',
				detail: 'src=https://evil.test/e.swf, type=application/x-shockwave-flash',
			},
			{
				type: 'withheld',
				kind: 'media',
				name: 'video',
				detail: 'controls',
			},
			{
				type: 'withheld',
				kind: 'media',
				name: 'track',
				detail: 'src=https://evil.test/t.vtt, kind=captions, srclang=en',
			},
		] );
	} );

	test( 'names a wrapping block whose settings alone change', async ( {
		page,
	} ) => {
		const loop = ( postType: string ) =>
			[
				`<!-- wp:query {"queryId":1,"query":{"perPage":3,"postType":"${ postType }"}} -->`,
				'<div class="wp-block-query"><!-- wp:paragraph -->',
				'<p>Hi</p>',
				'<!-- /wp:paragraph --></div>',
				'<!-- /wp:query -->',
			].join( '\n' );

		const current = loop( 'post' );
		const proposed = loop( 'page' );

		// Both sides render the same, so only the notice tells them apart.
		expect( await render( page, proposed ) ).toEqual(
			await render( page, current )
		);
		expect( await changedSettings( page, current, proposed ) ).toEqual( [
			'core/query',
		] );
	} );

	test( 'names blocks whose bindings or self-closing settings change', async ( {
		page,
	} ) => {
		const bound = ( key: string, id: number ) =>
			`<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"${ key }"}}}}} --><p>x</p><!-- /wp:paragraph --><!-- wp:acme/box {"id":${ id }} /-->`;

		expect(
			await changedSettings( page, bound( 'a', 1 ), bound( 'b', 2 ) )
		).toEqual( [ 'core/paragraph', 'acme/box' ] );
	} );

	test( 'names no block when settings only move or a plain block is added', async ( {
		page,
	} ) => {
		expect(
			await changedSettings(
				page,
				'<!-- wp:group {"layout":{"type":"constrained"},"tagName":"main"} --><!-- /wp:group -->',
				'<!-- wp:group {"tagName":"main","layout":{"type":"constrained"}} --><!-- /wp:group --><!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->'
			)
		).toEqual( [] );
	} );
} );

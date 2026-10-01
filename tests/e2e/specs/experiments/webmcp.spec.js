/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );

/**
 * Stands in for an agent browser: a frozen `document.modelContext` that only
 * implements `registerTool`, which is the dialect ChatGPT's browser speaks.
 * Registered tools are kept on `window.__webmcpTools` so the test can call
 * them the way an agent would.
 */
const modelContextShim = () => {
	const tools = [];
	window.__webmcpTools = tools;
	Object.defineProperty( document, 'modelContext', {
		configurable: true,
		value: Object.freeze( {
			registerTool( tool ) {
				tools.push( tool );
				return Promise.resolve();
			},
		} ),
	} );
};

const callTool = ( page, name, input ) =>
	page.evaluate(
		async ( [ toolName, toolInput ] ) => {
			const tool = window.__webmcpTools.find(
				( t ) => t.name === toolName
			);
			if ( ! tool ) {
				throw new Error( `Tool ${ toolName } is not registered.` );
			}
			const result = await tool.execute( toolInput );
			return JSON.parse( result.content[ 0 ].text );
		},
		[ name, input ]
	);

test.describe( 'WebMCP experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
		await enableExperiment( admin, page, 'WebMCP' );
		await page.addInitScript( modelContextShim );
	} );

	test( 'registers the editor tools on a post and each one changes the page', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { postType: 'post', title: 'Before' } );

		await page.waitForFunction(
			() => ( window.__webmcpTools || [] ).length > 0
		);
		const names = await page.evaluate( () =>
			window.__webmcpTools.map( ( t ) => t.name )
		);
		expect( names ).toEqual(
			expect.arrayContaining( [
				'editor-get-document',
				'editor-set-title',
				'editor-insert-block',
				'editor-update-block-text',
				'editor-save',
				'editor-publish',
			] )
		);

		// The title changes in front of the person.
		await callTool( page, 'editor-set-title', {
			title: 'Autumn opening hours',
		} );
		await expect(
			editor.canvas.locator( '.editor-post-title__input' )
		).toHaveText( 'Autumn opening hours' );

		// A paragraph appears in the canvas.
		const inserted = await callTool( page, 'editor-insert-block', {
			blockName: 'core/paragraph',
			attributes: { content: 'We close at 5pm on Fridays.' },
		} );
		expect( inserted.clientId ).toBeTruthy();
		await expect(
			editor.canvas.getByRole( 'document', { name: /Paragraph/ } )
		).toContainText( 'We close at 5pm on Fridays.' );

		// The outline names that block, and editing it by clientId updates the canvas.
		const doc = await callTool( page, 'editor-get-document', {} );
		expect( doc.title ).toBe( 'Autumn opening hours' );
		expect(
			doc.blocks.some( ( b ) => b.clientId === inserted.clientId )
		).toBe( true );

		await callTool( page, 'editor-update-block-text', {
			clientId: inserted.clientId,
			content: 'We open at 9.',
		} );
		await expect(
			editor.canvas.getByRole( 'document', { name: /Paragraph/ } )
		).toContainText( 'We open at 9.' );

		// Save keeps it a draft; the status is reported back.
		const saved = await callTool( page, 'editor-save', {} );
		expect( saved.status ).toBe( 'draft' );
	} );

	test( 'structural tools move, duplicate, transform, nest and undo on the page', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { postType: 'post', title: 'Structure' } );
		await page.waitForFunction(
			() => ( window.__webmcpTools || [] ).length > 0
		);

		const first = await callTool( page, 'editor-insert-block', {
			attributes: { content: 'First' },
		} );
		const second = await callTool( page, 'editor-insert-block', {
			attributes: { content: 'Second' },
		} );
		const order = async () =>
			( await callTool( page, 'editor-get-document', {} ) ).blocks.map(
				( b ) => b.text
			);
		expect( await order() ).toEqual( [ 'First', 'Second' ] );

		// Move: the first paragraph ends up after the second.
		await callTool( page, 'editor-move-block', {
			clientId: first.clientId,
			afterClientId: second.clientId,
		} );
		expect( await order() ).toEqual( [ 'Second', 'First' ] );

		// Undo is one step per tool call and puts the order back.
		await callTool( page, 'editor-undo', {} );
		expect( await order() ).toEqual( [ 'First', 'Second' ] );

		// Duplicate: a copy appears right after the original.
		const copy = await callTool( page, 'editor-duplicate-block', {
			clientId: first.clientId,
		} );
		expect( copy.clientIds ).toHaveLength( 1 );
		expect( await order() ).toEqual( [ 'First', 'First', 'Second' ] );

		// Transform: the paragraph becomes a heading, as the editor's own menu would do it.
		const transformed = await callTool( page, 'editor-transform-block', {
			clientId: second.clientId,
			blockName: 'core/heading',
		} );
		expect( transformed.name ).toBe( 'core/heading' );
		await expect(
			editor.canvas.getByRole( 'document', { name: /Heading/ } )
		).toContainText( 'Second' );

		// Discovery: a block type's attributes, and what a position allows.
		const heading = await callTool( page, 'editor-get-block-types', {
			name: 'core/heading',
		} );
		expect( heading.attributes ).toHaveProperty( 'level' );
		const allowed = await callTool( page, 'editor-get-block-types', {
			search: 'group',
		} );
		expect(
			allowed.blockTypes.some( ( b ) => b.name === 'core/group' )
		).toBe( true );

		// Nesting: a group, then a paragraph inserted into it.
		const group = await callTool( page, 'editor-insert-block', {
			blockName: 'core/group',
		} );
		const child = await callTool( page, 'editor-insert-block', {
			parentClientId: group.clientId,
			attributes: { content: 'Inside the group' },
		} );
		const doc = await callTool( page, 'editor-get-document', {} );
		const nested = doc.blocks.find(
			( b ) => b.clientId === child.clientId
		);
		expect( nested.depth ).toBe( 1 );

		// A block the editor does not allow at a position is refused, not forced.
		const refusal = await page.evaluate( async ( parentId ) => {
			const tool = window.__webmcpTools.find(
				( t ) => t.name === 'editor-insert-block'
			);
			try {
				await tool.execute( {
					blockName: 'core/list-item',
					parentClientId: parentId,
				} );
				return 'inserted';
			} catch ( error ) {
				return error.message;
			}
		}, group.clientId );
		expect( refusal ).toContain( 'cannot be inserted here' );
	} );

	test( 'does not load the bridge outside the editor', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'index.php' );
		const registered = await page.evaluate(
			() => ( window.__webmcpTools || [] ).length
		);
		expect( registered ).toBe( 0 );
	} );
} );

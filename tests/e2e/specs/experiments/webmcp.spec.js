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

	test( 'refuses editing, moving and removing locked blocks without changing the document', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Locked blocks' } );
		await page.waitForFunction( () => window.__webmcpTools.length > 0 );
		const locked = await callTool( page, 'editor-insert-block', {
			attributes: {
				content: 'Keep this text',
				lock: { edit: true, move: true, remove: true },
			},
		} );
		const after = await callTool( page, 'editor-insert-block', {
			attributes: { content: 'After' },
		} );
		const before = await callTool( page, 'editor-get-document', {} );
		for ( const [ name, input, message ] of [
			[
				'editor-update-block-text',
				{ content: 'Changed' },
				'locked against editing',
			],
			[
				'editor-update-block-attributes',
				{ attributes: { content: 'Changed', lock: {} } },
				'locked against editing',
			],
			[
				'editor-move-block',
				{ afterClientId: after.clientId },
				'locked against moving',
			],
			[ 'editor-remove-block', {}, 'locked against removal' ],
		] ) {
			await expect(
				callTool( page, name, { clientId: locked.clientId, ...input } )
			).rejects.toThrow( message );
			expect( await callTool( page, 'editor-get-document', {} ) ).toEqual(
				before
			);
		}
	} );

	test( 'moving a block after itself changes nothing and adds no undo step', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( { title: 'No-op move' } );
		await page.waitForFunction( () => window.__webmcpTools.length > 0 );
		const first = await callTool( page, 'editor-insert-block', {
			attributes: { content: 'First' },
		} );
		await callTool( page, 'editor-insert-block', {
			attributes: { content: 'Second' },
		} );
		const before = await callTool( page, 'editor-get-document', {} );
		expect(
			await callTool( page, 'editor-move-block', {
				clientId: first.clientId,
				afterClientId: first.clientId,
			} )
		).toMatchObject( { moved: false, message: 'Nothing to move.' } );
		expect( await callTool( page, 'editor-get-document', {} ) ).toEqual(
			before
		);
		await callTool( page, 'editor-undo', {} );
		await expect
			.poll( async () =>
				(
					await callTool( page, 'editor-get-document', {} )
				).blocks.map( ( block ) => block.text )
			)
			.toEqual( [ 'First' ] );
	} );

	test( 'refuses moving a container into itself or a descendant, including after a nested block', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Nested moves' } );
		await page.waitForFunction( () => window.__webmcpTools.length > 0 );
		const group = await callTool( page, 'editor-insert-block', {
			blockName: 'core/group',
		} );
		const child = await callTool( page, 'editor-insert-block', {
			blockName: 'core/group',
			parentClientId: group.clientId,
		} );
		const grandchild = await callTool( page, 'editor-insert-block', {
			blockName: 'core/group',
			parentClientId: child.clientId,
		} );
		const before = await callTool( page, 'editor-get-document', {} );
		for ( const input of [
			{ parentClientId: group.clientId },
			{ parentClientId: child.clientId },
			{ parentClientId: grandchild.clientId },
			{ afterClientId: child.clientId },
			{ afterClientId: grandchild.clientId },
		] ) {
			await expect(
				callTool( page, 'editor-move-block', {
					clientId: group.clientId,
					...input,
				} )
			).rejects.toThrow( 'cannot be moved into itself' );
			expect( await callTool( page, 'editor-get-document', {} ) ).toEqual(
				before
			);
		}
	} );

	test( 'refuses the text shortcut for quote and pullquote without changing their content', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Quote attributes' } );
		await page.waitForFunction( () => window.__webmcpTools.length > 0 );
		for ( const blockName of [ 'core/quote', 'core/pullquote' ] ) {
			const block = await callTool( page, 'editor-insert-block', {
				blockName,
			} );
			const before = await callTool( page, 'editor-get-document', {} );
			await expect(
				callTool( page, 'editor-update-block-text', {
					clientId: block.clientId,
					content: 'Wrong attribute',
				} )
			).rejects.toThrow( 'does not use a content attribute' );
			expect( await callTool( page, 'editor-get-document', {} ) ).toEqual(
				before
			);
		}
	} );

	test( 'reports failed saves and publishes instead of returning success', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Save failure' } );
		await page.waitForFunction( () => window.__webmcpTools.length > 0 );
		const saved = await callTool( page, 'editor-save', {} );
		expect( saved.saving ).toBe( false );
		await page.route(
			/(?:\/|%2F)wp(?:\/|%2F)v2(?:\/|%2F)posts(?:\/|%2F)\d+/i,
			async ( route ) => {
				if ( route.request().method() !== 'POST' ) {
					await route.continue();
					return;
				}
				await route.fulfill( {
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify( {
						code: 'webmcp_test_failure',
						message: 'Save refused for testing',
						data: { status: 500 },
					} ),
				} );
			}
		);
		await callTool( page, 'editor-set-title', { title: 'Unsaved change' } );
		for ( const name of [ 'editor-save', 'editor-publish' ] ) {
			await expect( callTool( page, name, {} ) ).rejects.toThrow(
				'could not be saved'
			);
			expect(
				await page.evaluate( () => ( {
					failed: window.wp.data
						.select( 'core/editor' )
						.didPostSaveRequestFail(),
					saving: window.wp.data
						.select( 'core/editor' )
						.isSavingPost(),
					status: window.wp.data
						.select( 'core/editor' )
						.getCurrentPostAttribute( 'status' ),
					editedStatus: window.wp.data
						.select( 'core/editor' )
						.getEditedPostAttribute( 'status' ),
				} ) )
			).toEqual( {
				failed: true,
				saving: false,
				status: 'draft',
				editedStatus: 'draft',
			} );
		}
	} );

	test( 'refuses to publish a post that cannot be saved, without changing its status', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost();
		await page.waitForFunction( () => window.__webmcpTools.length > 0 );
		await expect( callTool( page, 'editor-publish', {} ) ).rejects.toThrow(
			'cannot be saved yet'
		);
		expect(
			await page.evaluate( () =>
				window.wp.data
					.select( 'core/editor' )
					.getEditedPostAttribute( 'status' )
			)
		).toBe( 'auto-draft' );
	} );

	test( 'refuses lock changes, unsupported or locked transforms and duplicates that create nothing', async ( {
		admin,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Strict structure' } );
		await page.waitForFunction( () => window.__webmcpTools.length > 0 );
		const open = await callTool( page, 'editor-insert-block', {
			attributes: { content: 'Open' },
		} );
		const pinned = await callTool( page, 'editor-insert-block', {
			attributes: { content: 'Pinned', lock: { remove: true } },
		} );
		const more = await callTool( page, 'editor-insert-block', {
			blockName: 'core/more',
		} );
		const before = await callTool( page, 'editor-get-document', {} );
		for ( const [ name, input, message ] of [
			[
				'editor-update-block-attributes',
				{ clientId: pinned.clientId, attributes: { lock: {} } },
				'lock attribute cannot be changed',
			],
			[
				'editor-transform-block',
				{ clientId: open.clientId, blockName: 'core/image' },
				'cannot be transformed into core/image',
			],
			[
				'editor-transform-block',
				{ clientId: pinned.clientId, blockName: 'core/heading' },
				'locked against removal',
			],
			[
				'editor-duplicate-block',
				{ clientId: more.clientId },
				'was not duplicated',
			],
		] ) {
			await expect( callTool( page, name, input ) ).rejects.toThrow(
				message
			);
			expect( await callTool( page, 'editor-get-document', {} ) ).toEqual(
				before
			);
		}
	} );

	test( 'lists no tools in the classic editor even though the editor store scripts are loaded', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'post-new.php',
			'post_type=ai_e2e_classic'
		);
		await expect( page.locator( '#postdivrich' ) ).toBeVisible();
		const result = await page.evaluate( () => ( {
			hasEditorSelector:
				typeof window.wp.data.select( 'core/editor' )
					.getCurrentPostId === 'function',
			listed: window.wpai.webmcp.getTools().map( ( tool ) => tool.name ),
			registered: window.__webmcpTools.map( ( tool ) => tool.name ),
		} ) );
		expect( result ).toEqual( {
			hasEditorSelector: true,
			listed: [],
			registered: [],
		} );
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

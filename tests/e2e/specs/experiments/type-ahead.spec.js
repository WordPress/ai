/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	enableExperiment,
} = require( '../../utils/helpers' );

test.describe( 'Type-ahead Text Experiment', () => {
	test( 'Can enable the type-ahead text experiment', async ( {
		admin,
		page,
	} ) => {
		// Enable the Type-ahead Text Experiment.
		await enableExperiment( admin, page, 'Type-ahead Text' );
	} );

	test( 'Can use the Type-ahead Text Experiment', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Type-ahead Text Experiment.
		await enableExperiment( admin, page, 'Type-ahead Text' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Type-ahead Text Experiment',
			content:
				'This is some test content for the Type-ahead Text Experiment.',
		} );

		// Add a block.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content: 'This paragraph needs more text.',
			},
		} );

		// Click into the block.
		await editor.canvas
			.getByRole( 'document', { name: 'Block: Paragraph' } )
			.last()
			.click();

		// Ensure the type-ahead text is visible and has the correct text.
		await expect( editor.canvas.getByRole( 'status' ) ).toHaveText(
			'This is a test suggestion.'
		);

		// Accept the type-ahead text.
		await page.keyboard.press( 'Tab' );

		// Ensure the type-ahead text is removed.
		await expect( editor.canvas.getByRole( 'status' ) ).toHaveText( '' );

		// Ensure the block content is updated.
		await expect(
			editor.canvas
				.getByRole( 'document', { name: 'Block: Paragraph' } )
				.last()
		).toHaveText(
			'This paragraph needs more text. This is a test suggestion.'
		);

		// Save the post.
		await editor.saveDraft();
	} );

	test( 'Can request a suggestion with the access + G shortcut', async ( {
		admin,
		editor,
		page,
		pageUtils,
	} ) => {
		await enableExperiment( admin, page, 'Type-ahead Text' );
		await admin.createNewPost();
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: 'This paragraph needs more text.' },
		} );

		// Put the caret at the start, where suggestions only come from the shortcut.
		await editor.canvas
			.getByRole( 'document', { name: 'Block: Paragraph' } )
			.click( { position: { x: 5, y: 5 } } );

		const status = editor.canvas.getByRole( 'status' );
		await expect( status ).toHaveText( '' );

		// Ctrl + Option + G on macOS, Shift + Alt + G elsewhere.
		await pageUtils.pressKeys( 'access+g' );

		await expect( status ).toHaveText( /This is a test suggestion\./ );

		// The shortcut is listed in the Keyboard shortcuts modal.
		await pageUtils.pressKeys( 'access+h' );
		await expect(
			page
				.getByRole( 'dialog', { name: 'Keyboard shortcuts' } )
				.getByText( 'Ask for a type-ahead suggestion.' )
		).toBeVisible();
	} );

	test( 'Sends one request for a paragraph nested in other blocks', async ( {
		admin,
		editor,
		page,
		pageUtils,
	} ) => {
		await enableExperiment( admin, page, 'Type-ahead Text' );

		let requests = 0;
		await page.route(
			/\/wp-abilities\/v1\/abilities\/ai\/type-ahead\/run/,
			( route ) => {
				requests++;
				return route.fulfill( {
					json: {
						suggestion: 'This is a test suggestion.',
						confidence: 1,
					},
				} );
			}
		);

		await admin.createNewPost();
		await editor.insertBlock( {
			name: 'core/group',
			innerBlocks: [
				{
					name: 'core/columns',
					innerBlocks: [
						{
							name: 'core/column',
							innerBlocks: [
								{
									name: 'core/paragraph',
									attributes: {
										content:
											'This paragraph needs more text.',
									},
								},
							],
						},
					],
				},
			],
		} );

		// Put the caret at the start, where suggestions only come from the shortcut.
		await editor.canvas
			.getByRole( 'document', { name: 'Block: Paragraph' } )
			.click( { position: { x: 5, y: 5 } } );

		await pageUtils.pressKeys( 'access+g' );

		await expect( editor.canvas.getByRole( 'status' ) ).toHaveText(
			/This is a test suggestion\./
		);
		expect( requests ).toBe( 1 );
	} );

	test( 'Ensure the Type-ahead Text Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Disable the Type-ahead Text Experiment.
		await disableExperiment( admin, page, 'Type-ahead Text' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Type-ahead Text Experiment Disabled',
			content:
				'This is some test content for the Type-ahead Text Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Add a block.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content: 'This paragraph needs more text.',
			},
		} );

		// Click into the block.
		await editor.canvas
			.getByRole( 'document', { name: 'Block: Paragraph' } )
			.last()
			.click();

		// Ensure the type-ahead text is not visible.
		await expect( editor.canvas.getByRole( 'status' ) ).toBeHidden();
	} );
} );

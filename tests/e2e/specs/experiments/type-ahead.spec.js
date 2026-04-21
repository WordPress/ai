/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	disableExperiments,
	enableExperiment,
	enableExperiments,
} = require( '../../utils/helpers' );

test.describe( 'Type-ahead Text Experiment', () => {
	test( 'Can enable the type-ahead text experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Type-ahead Text Experiment.
		await enableExperiment( admin, page, 'Type-ahead Text' );
	} );

	test( 'Can use the Type-ahead Text Experiment', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

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
		await editor.canvas.locator( '.wp-block-paragraph' ).click();

		// Ensure the type-ahead text is visible and has the correct text.
		await expect(
			editor.canvas.locator( '.ai-type-ahead-overlay' )
		).toBeVisible();
		await expect(
			editor.canvas.locator( '.ai-type-ahead-overlay' )
		).toHaveText( 'This is a test suggestion.' );

		// Accept the type-ahead text.
		await page.keyboard.press( 'Tab' );

		// Ensure the type-ahead text is removed.
		await expect(
			editor.canvas.locator( '.ai-type-ahead-overlay' )
		).toBeHidden();
		await expect(
			editor.canvas.locator( '.ai-type-ahead-inline-ghost' )
		).toBeHidden();

		// Ensure the block content is updated.
		await expect(
			editor.canvas.locator( '.wp-block-paragraph' )
		).toHaveText(
			'This paragraph needs more text. This is a test suggestion.',
		);

		// Save the post.
		await editor.saveDraft();
	} );

	test( 'Ensure the Type-ahead Text Experiment UI is not visible when Experiments are globally disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Type-ahead Text Experiment.
		await enableExperiment( admin, page, 'Type-ahead Text' );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Type-ahead Text Experiment Globally Disabled',
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
		await editor.canvas.locator( '.wp-block-paragraph' ).click();

		// Ensure the type-ahead text is not visible.
		await expect(
			editor.canvas.locator( '.ai-type-ahead-overlay' )
		).toBeHidden();
		await expect(
			editor.canvas.locator( '.ai-type-ahead-inline-ghost' )
		).toBeHidden();
	} );

	test( 'Ensure the Type-ahead Text Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

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
		await editor.canvas.locator( '.wp-block-paragraph' ).click();

		// Ensure the type-ahead text is not visible.
		await expect(
			editor.canvas.locator( '.ai-type-ahead-overlay' )
		).toBeHidden();
		await expect(
			editor.canvas.locator( '.ai-type-ahead-inline-ghost' )
		).toBeHidden();
	} );
} );

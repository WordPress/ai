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

test.describe( 'Title Generation Experiment', () => {
	test( 'Can enable the title generation experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Title Generation Experiment.
		await enableExperiment( admin, page, 'Title Generation' );
	} );

	test( 'Can use the Title Generation Experiment with a post with no title', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Title Generation Experiment.
		await enableExperiment( admin, page, 'Title Generation' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: '',
			content:
				'This is some test content for the Title Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Click into the title field.
		await editor.canvas.locator( '.editor-post-title__input' ).click();

		// Ensure the title toolbar is visible with "Generate" label.
		await expect(
			editor.canvas.locator( '.ai-title-toolbar-container', {
				hasText: 'Generate',
			} )
		).toBeVisible();

		// Click the Generate button.
		await editor.canvas
			.locator( '.ai-title-toolbar-container button' )
			.click();

		// Ensure the title is updated directly (no modal).
		await expect(
			editor.canvas.locator( '.editor-post-title__input' )
		).toHaveText(
			'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure',
			{ timeout: 10000 }
		);

		// Save the post.
		await editor.saveDraft();
	} );

	test( 'Can use the Title Generation Experiment with a post with a title', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Title Generation Experiment.
		await enableExperiment( admin, page, 'Title Generation' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Title Generation',
			content:
				'This is some test content for the Title Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Click into the title field.
		await editor.canvas.locator( '.editor-post-title__input' ).click();

		// Ensure the title toolbar is visible with "Re-generate" label.
		await expect(
			editor.canvas.locator( '.ai-title-toolbar-container', {
				hasText: 'Re-generate',
			} )
		).toBeVisible();

		// Click the Re-generate button.
		await editor.canvas
			.locator( '.ai-title-toolbar-container button' )
			.click();

		// Ensure the title is updated directly (no modal).
		await expect(
			editor.canvas.locator( '.editor-post-title__input' )
		).toHaveText(
			'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure',
			{ timeout: 10000 }
		);

		// Save the post.
		await editor.saveDraft();
	} );

	test( 'Ensure the Title Generation Experiment UI is not visible when Experiments are globally disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Title Generation Experiment.
		await enableExperiment( admin, page, 'Title Generation' );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Title Generation Experiment Globally Disabled',
			content:
				'This is some test content for the Title Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Click into the title field.
		await editor.canvas.locator( '.editor-post-title__input' ).click();

		// Ensure the title toolbar is not there.
		await expect(
			editor.canvas.locator( '.ai-title-toolbar-container' )
		).not.toBeVisible();
	} );

	test( 'Ensure the Title Generation Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Title Generation Experiment.
		await disableExperiment( admin, page, 'Title Generation' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Title Generation Experiment Disabled',
			content:
				'This is some test content for the Title Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Click into the title field.
		await editor.canvas.locator( '.editor-post-title__input' ).click();

		// Ensure the title toolbar is not there.
		await expect(
			editor.canvas.locator( '.ai-title-toolbar-container' )
		).not.toBeVisible();
	} );
} );

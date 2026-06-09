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

const LONG_CONTENT =
	'Artificial intelligence is rapidly changing how content is created, edited, and published across the web today. Writers increasingly rely on automated tools to draft outlines, summarize research, and suggest improvements to their work. These systems analyze large amounts of text and surface patterns that would take a human many hours to find on their own. As the technology matures, editors are learning to combine their own judgment with machine generated suggestions to produce stronger results. This paragraph exists only to provide enough words for the title generation experiment to run, because the feature now requires a reasonable amount of content before it will offer to generate a brand new title for the post.';

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
			content: LONG_CONTENT,
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

		// Ensure the title modal is visible.
		await expect(
			page.locator( '.ai-title-generation-modal' )
		).toBeVisible();

		// Ensure the generated title textarea is visible.
		await expect(
			page.locator( '.ai-title-generation-modal textarea' )
		).toBeVisible();

		// Click Insert to apply the generated title.
		await page
			.locator( '.ai-title-generation-modal' )
			.getByRole( 'button', { name: 'Insert' } )
			.click();

		// Ensure the title modal is closed.
		await expect(
			page.locator( '.ai-title-generation-modal' )
		).not.toBeVisible();

		// Ensure the title is updated.
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
			content: LONG_CONTENT,
		} );

		// Save the post.
		await editor.saveDraft();

		// Click into the title field.
		await editor.canvas.locator( '.editor-post-title__input' ).click();

		// Ensure the title toolbar is visible with "Regenerate" label.
		await expect(
			editor.canvas.locator( '.ai-title-toolbar-container', {
				hasText: 'Regenerate',
			} )
		).toBeVisible();

		// Click the Regenerate button.
		await editor.canvas
			.locator( '.ai-title-toolbar-container button' )
			.click();

		// Ensure the title modal is visible.
		await expect(
			page.locator( '.ai-title-generation-modal' )
		).toBeVisible();

		// Ensure the generated title textarea is visible.
		await expect(
			page.locator( '.ai-title-generation-modal textarea' )
		).toBeVisible();

		// Click Insert to apply the generated title.
		await page
			.locator( '.ai-title-generation-modal' )
			.getByRole( 'button', { name: 'Insert' } )
			.click();

		// Ensure the title modal is closed.
		await expect(
			page.locator( '.ai-title-generation-modal' )
		).not.toBeVisible();

		// Ensure the title is updated.
		await expect(
			editor.canvas.locator( '.editor-post-title__input' )
		).toHaveText(
			'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure',
			{ timeout: 10000 }
		);

		// Save the post.
		await editor.saveDraft();
	} );

	test( 'Generate button is disabled when there is not enough content', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Title Generation Experiment.
		await enableExperiment( admin, page, 'Title Generation' );

		// Create a new post with content well below the minimum length.
		await admin.createNewPost( {
			postType: 'post',
			title: '',
			content: 'Too short.',
		} );

		// Click into the title field to reveal the toolbar.
		await editor.canvas.locator( '.editor-post-title__input' ).click();

		// The toolbar is visible with the "Generate" label.
		await expect(
			editor.canvas.locator( '.ai-title-toolbar-container', {
				hasText: 'Generate',
			} )
		).toBeVisible();

		await expect(
			editor.canvas.locator( '.ai-title-toolbar-container button' )
		).toHaveAttribute( 'aria-disabled', 'true' );
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

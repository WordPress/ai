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
		await editor.canvas
			.getByRole( 'textbox', { name: 'Add Title' } )
			.click();

		const generateTitleToolbar = editor.canvas.getByRole( 'toolbar', {
			name: 'Generate title toolbar',
		} );

		// Ensure the title toolbar is visible with "Generate" label.
		await expect(
			generateTitleToolbar.filter( { hasText: 'Generate' } )
		).toBeVisible();

		// Click the Generate button.
		await generateTitleToolbar
			.getByRole( 'button', { name: 'Generate' } )
			.click();

		const modal = page.getByRole( 'dialog', {
			name: 'Title suggestion',
		} );

		// Ensure the title modal is visible.
		await expect( modal ).toBeVisible();

		// Ensure the generated title textarea is visible.
		await expect(
			page.getByRole( 'textbox', { name: 'Generated title' } )
		).toBeVisible();

		// Click Insert to apply the generated title.
		await modal.getByRole( 'button', { name: 'Insert' } ).click();

		// Ensure the title modal is closed.
		await expect( modal ).not.toBeVisible();

		// Ensure the title is updated.
		await expect(
			editor.canvas.getByRole( 'textbox', { name: 'Add Title' } )
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
		await editor.canvas
			.getByRole( 'textbox', { name: 'Add Title' } )
			.click();

		const generateTitleToolbar = editor.canvas.getByRole( 'toolbar', {
			name: 'Generate title toolbar',
		} );

		// Ensure the title toolbar is visible.
		await expect( generateTitleToolbar ).toBeVisible();

		// Click the Regenerate button.
		await generateTitleToolbar
			.getByRole( 'button', { name: 'Regenerate' } )
			.click();

		const modal = page.getByRole( 'dialog', { name: 'Title suggestion' } );

		// Ensure the title modal is visible.
		await expect( modal ).toBeVisible();

		// Ensure the generated title textarea is visible.
		await expect(
			page.getByRole( 'textbox', { name: 'Generated title' } )
		).toBeVisible();

		// Click Insert to apply the generated title.
		await modal.getByRole( 'button', { name: 'Insert' } ).click();

		// Ensure the title modal is closed.
		await expect( modal ).not.toBeVisible();

		// Ensure the title is updated.
		await expect(
			editor.canvas.getByRole( 'textbox', { name: 'Add Title' } )
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

		// Save the post.
		await editor.saveDraft();

		// Click into the title field to reveal the toolbar.
		await editor.canvas
			.getByRole( 'textbox', { name: 'Add Title' } )
			.click();

		const generateTitleToolbar = editor.canvas.getByRole( 'toolbar', {
			name: 'Generate title toolbar',
		} );

		// The toolbar is visible with the "Generate" label.
		await expect( generateTitleToolbar ).toBeVisible();

		await expect(
			generateTitleToolbar
				.getByRole( 'button' )
				.filter( { hasText: 'Generate' } )
		).toBeDisabled();
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
		await editor.canvas
			.getByRole( 'textbox', { name: 'Add Title' } )
			.click();

		// Ensure the title toolbar is not there.
		await expect(
			editor.canvas.getByRole( 'toolbar', {
				name: 'Generate title toolbar',
			} )
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
		await editor.canvas
			.getByRole( 'textbox', { name: 'Add Title' } )
			.click();

		// Ensure the title toolbar is not there.
		await expect(
			editor.canvas.getByRole( 'toolbar', {
				name: 'Generate title toolbar',
			} )
		).not.toBeVisible();
	} );
} );

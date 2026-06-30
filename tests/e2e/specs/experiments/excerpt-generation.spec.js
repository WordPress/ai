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

// Long enough (>250 characters) to satisfy the excerpt generation minimum content length.
const LONG_CONTENT =
	'Artificial intelligence is rapidly changing how content is created, edited, and published across the web today. Writers increasingly rely on automated tools to draft outlines, summarize research, and suggest improvements to their work. These systems analyze large amounts of text and surface patterns that would take a human many hours to find on their own. As the technology matures, editors are learning to combine their own judgment with machine generated suggestions to produce stronger results. This paragraph exists only to provide enough characters for the excerpt generation experiment to run, because the feature now requires a reasonable amount of content before it will offer to generate a brand new excerpt for the post.';

test.describe( 'Excerpt Generation Experiment', () => {
	test( 'Can enable the excerpt generation experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );
	} );

	test( 'Can use the Excerpt Generation Experiment', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt Generation Experiment',
			content: LONG_CONTENT,
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the generate excerpt inline button exists.
		await expect(
			page.locator(
				'.editor-post-excerpt__dropdown .ai-excerpt-inline-wrapper .ai-excerpt-inline-button'
			)
		).toBeVisible();

		// Click the Add excerpt button.
		await page
			.locator( '.editor-post-excerpt__dropdown button' )
			.first()
			.click();

		// Ensure the generate excerpt button shows in the modal.
		await expect(
			page.locator( '.ai-excerpt-generation button' )
		).toBeVisible();

		// Click the generate excerpt button.
		await page.locator( '.ai-excerpt-generation button' ).click();

		// Ensure the excerpt is updated.
		await expect(
			page.locator(
				'.editor-post-excerpt .editor-post-excerpt__textarea textarea'
			)
		).toHaveValue(
			'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure'
		);

		// Ensure the excerpt button text is updated.
		await expect(
			page.locator( '.ai-excerpt-generation button' )
		).toHaveText( 'Regenerate excerpt' );

		// Delete the excerpt.
		await page
			.locator(
				'.editor-post-excerpt .editor-post-excerpt__textarea textarea'
			)
			.fill( '' );

		// Close the modal.
		await page
			.locator(
				'.editor-post-excerpt__dropdown__content .block-editor-inspector-popover-header button'
			)
			.click();

		// Click the generate excerpt inline button.
		await page
			.locator(
				'.editor-post-excerpt__dropdown .ai-excerpt-inline-wrapper .ai-excerpt-inline-button'
			)
			.click();

		// Ensure the excerpt is updated.
		const excerptDropdownLocator = page.locator(
			'.editor-post-excerpt__dropdown'
		);
		const excerptParentLocator = page
			.locator(
				'.editor-post-panel__section .components-h-stack .components-h-stack'
			)
			.filter( { has: excerptDropdownLocator } );

		await expect(
			excerptParentLocator.locator( '.components-text' ).first()
		).toHaveText(
			'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure'
		);

		// Save the post.
		await editor.saveDraft();
	} );

	test( 'Generate excerpt button is disabled when there is not enough content', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// Create a new post with content well below the minimum length.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt Generation Minimum Length',
			content: 'Too short.',
		} );

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		const inlineButton = page.locator(
			'.editor-post-excerpt__dropdown .ai-excerpt-inline-wrapper .ai-excerpt-inline-button'
		);
		await expect( inlineButton ).toBeVisible();
		await expect( inlineButton ).toHaveAttribute( 'aria-disabled', 'true' );

		// The panel button inside the excerpt dropdown is also disabled.
		await page
			.locator( '.editor-post-excerpt__dropdown button' )
			.first()
			.click();
		await expect(
			page.locator( '.ai-excerpt-generation button' )
		).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'Ensure the Excerpt Generation Experiment UI is not visible when Experiments are globally disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt Generation Experiment Globally Disabled',
			content:
				'This is some test content for the Excerpt Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the generate excerpt inline button doesn't exist.
		await expect(
			page.locator(
				'.editor-post-excerpt__dropdown .ai-excerpt-inline-wrapper .ai-excerpt-inline-button'
			)
		).not.toBeVisible();

		// Click the Add excerpt button.
		await page
			.locator( '.editor-post-excerpt__dropdown button' )
			.first()
			.click();

		// Ensure the generate excerpt button doesn't show in the modal.
		await expect(
			page.locator( '.ai-excerpt-generation button' )
		).not.toBeVisible();
	} );

	test( 'Ensure the Excerpt Generation Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Excerpt Generation Experiment.
		await disableExperiment( admin, page, 'Excerpt Generation' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt Generation Experiment Disabled',
			content:
				'This is some test content for the Excerpt Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the generate excerpt inline button doesn't exist.
		await expect(
			page.locator(
				'.editor-post-excerpt__dropdown .ai-excerpt-inline-wrapper .ai-excerpt-inline-button'
			)
		).not.toBeVisible();

		// Click the Add excerpt button.
		await page
			.locator( '.editor-post-excerpt__dropdown button' )
			.first()
			.click();

		// Ensure the generate excerpt button doesn't show in the modal.
		await expect(
			page.locator( '.ai-excerpt-generation button' )
		).not.toBeVisible();
	} );
} );

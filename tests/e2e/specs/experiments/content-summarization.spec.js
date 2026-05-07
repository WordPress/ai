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

test.describe( 'Content Summarization Experiment', () => {
	test( 'Can enable the content summarization experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Summarization Experiment.
		await enableExperiment( admin, page, 'Content Summarization' );
	} );

	test( 'Can use the Content Summarization Experiment', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Summarization Experiment.
		await enableExperiment( admin, page, 'Content Summarization' );

		// Create a new post with content that meets the minimum length requirement (>= 100 chars).
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Content Summarization Experiment',
			content:
				'This is some test content for the Content Summarization Experiment. It needs to be at least one hundred characters long.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the Generate Summary button exists, is visible, and has the correct text.
		const generateButton = page.locator(
			'.ai-summarization-plugin-container button'
		);
		await expect( generateButton ).toBeVisible();
		await expect( generateButton ).toHaveText( 'Generate Summary' );

		// Click the Generate Summary button.
		await generateButton.click();

		// Ensure the generated summary is inserted as a block.
		await expect(
			editor.canvas.locator( '.ai-summarization-summary', {
				hasText:
					'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure',
			} )
		).toBeVisible();

		// Ensure the sidebar is visible and on the Post tab.
		await editor.openDocumentSettingsSidebar();
		await page
			.locator( '.editor-sidebar__panel-tabs button:has-text("Post")' )
			.click();

		// Ensure the Generate Summary button text is updated.
		await expect( generateButton ).toBeVisible();
		await expect( generateButton ).toHaveText( 'Regenerate Summary' );

		// Save the post.
		await editor.saveDraft();
	} );

	test( 'Ensure the Content Summarization Experiment UI is not visible when Experiments are globally disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Content Summarization Experiment.
		await enableExperiment( admin, page, 'Content Summarization' );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Content Summarization Experiment Globally Disabled',
			content:
				'This is some test content for the Content Summarization Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the Generate Summary button doesn't exist.
		await expect(
			page.locator( '.ai-summarization-plugin-container button' )
		).not.toBeVisible();
	} );

	test( 'Summarize button is disabled when content is shorter than the minimum length', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Summarization Experiment.
		await enableExperiment( admin, page, 'Content Summarization' );

		// Create a new post with content shorter than 100 characters.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Short Content',
			content: 'Too short.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		const generateButton = page.locator(
			'.ai-summarization-plugin-container button'
		);

		// Button should be visible but disabled.
		await expect( generateButton ).toBeVisible();
		await expect( generateButton ).toBeDisabled();

		// The descriptive text should explain when the button will be enabled.
		await expect(
			page.locator( '.ai-summarization-plugin-container .description' )
		).toContainText( '100 characters' );
	} );

	test( 'Summarize button is enabled when content meets the minimum length', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Summarization Experiment.
		await enableExperiment( admin, page, 'Content Summarization' );

		// Create a new post with content that is at least 100 characters.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Sufficient Content',
			content:
				'This post has enough content to meet the minimum character requirement for the summarization feature to be enabled.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		const generateButton = page.locator(
			'.ai-summarization-plugin-container button'
		);

		// Button should be visible and enabled.
		await expect( generateButton ).toBeVisible();
		await expect( generateButton ).toBeEnabled();

		// The descriptive text should NOT mention the minimum character requirement.
		await expect(
			page.locator( '.ai-summarization-plugin-container .description' )
		).not.toContainText( 'characters' );
	} );

	test( 'Ensure the Content Summarization Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Content Summarization Experiment.
		await disableExperiment( admin, page, 'Content Summarization' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Content Summarization Experiment Disabled',
			content:
				'This is some test content for the Content Summarization Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the Generate Summary button doesn't exist.
		await expect(
			page.locator( '.ai-summarization-plugin-container button' )
		).not.toBeVisible();
	} );
} );

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

		// Create a new post with content that meets the minimum length requirement (>= 250 characters).
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Content Summarization Experiment',
			content:
				'This is some test content for the Content Summarization Experiment. It needs to have enough characters to meet the minimum content length requirement for summarization to be enabled. The summarization feature requires a substantial amount of text before it will allow the user to generate a summary of the post content. This ensures that the generated summary is meaningful and provides value to readers who want a quick overview of what the full article contains. Adding more characters here to make sure we exceed the minimum threshold that is configured for this experiment in the plugin settings and server side filters.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the Generate Summary button exists, is visible, and has the correct text.
		const generateButton = page.getByRole( 'button', {
			name: 'Generate Summary',
			exact: true,
		} );
		await expect( generateButton ).toBeVisible();

		// Click the Generate Summary button.
		await generateButton.click();

		const summaryBlock = editor.canvas
			.getByRole( 'document', {
				name: 'Block: Content Summary',
			} )
			.first();

		// Ensure the summary block is visible.
		await expect( summaryBlock ).toBeVisible();

		// Ensure the summary content is inside a paragraph within the group.
		await expect( summaryBlock ).toContainClass( 'wp-block-group' );

		await expect(
			summaryBlock.locator( 'p', {
				hasText:
					'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure',
			} )
		).toBeVisible();

		// Ensure the sidebar is visible and on the Post tab.
		await editor.openDocumentSettingsSidebar();
		await page.getByRole( 'tab', { name: 'Post' } ).click();

		// Ensure the Regenerate Summary button is visible.
		await expect(
			page
				.getByRole( 'button', { name: 'Regenerate Summary' } )
				.filter( { hasText: 'Regenerate Summary' } )
		).toBeVisible();

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
			page.getByRole( 'button', {
				name: 'Generate Summary',
				exact: true,
			} )
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

		// Create a new post with content shorter than 250 characters.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Short Content',
			content: 'Too short.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		const generateButton = page.getByRole( 'button', {
			name: 'Generate Summary',
			exact: true,
		} );

		// Button should be visible but disabled.
		await expect( generateButton ).toBeVisible();
		await expect( generateButton ).toBeDisabled();

		// The descriptive text should explain when the button will be enabled.
		await expect( generateButton ).toHaveAccessibleDescription(
			/250 characters?/i
		);
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

		// Create a new post with content that is at least 250 characters.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Sufficient Content',
			content:
				'This post has enough content to meet the minimum character count requirement for the summarization feature to be enabled. The content needs to contain at least 250 characters so that the summarization experiment can generate a meaningful summary of the text. By including multiple sentences with various topics and ideas, we ensure that the AI has sufficient material to work with when creating a concise overview. This paragraph continues to add more characters to reach the necessary threshold for testing purposes and to verify the feature works correctly.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		const generateButton = page.getByRole( 'button', {
			name: 'Generate Summary',
			exact: true,
		} );

		// Button should be visible and enabled.
		await expect( generateButton ).toBeVisible();
		await expect( generateButton ).toBeEnabled();

		// The descriptive text should NOT mention the minimum character requirement.
		await expect( generateButton ).not.toHaveAccessibleDescription(
			/characters?/i
		);
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
			page.getByRole( 'button', {
				name: 'Generate Summary',
				exact: true,
			} )
		).not.toBeVisible();
	} );
} );

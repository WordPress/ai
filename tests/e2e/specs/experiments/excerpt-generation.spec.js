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
	seedCredentials,
} = require( '../../utils/helpers' );

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

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt Generation Experiment',
			content:
				'This is some test content for the Excerpt Generation Experiment.',
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

	test( 'Shows a clean error notice when excerpt generation fails', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await seedCredentials( requestUtils );

		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// Create a new post without content so excerpt generation returns an error.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt Generation Error Notice',
		} );

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		const inlineButton = page.locator(
			'.editor-post-excerpt__dropdown .ai-excerpt-inline-wrapper .ai-excerpt-inline-button'
		);
		await expect( inlineButton ).toBeVisible( { timeout: 5000 } );
		await inlineButton.click();

		const notice = page.locator( '.components-notice', {
			hasText: 'Content is required to generate an excerpt suggestion.',
		} );
		await expect( notice ).toBeVisible( { timeout: 5000 } );
		await expect( notice ).not.toContainText(
			'Error: Content is required to generate an excerpt suggestion.'
		);
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

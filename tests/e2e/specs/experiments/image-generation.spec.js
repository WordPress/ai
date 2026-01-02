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
	visitAdminPage,
} = require( '../../utils/helpers' );

test.describe( 'Image Generation Experiment', () => {
	test( 'Can enable the image generation experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Image Generation Experiment.
		await enableExperiment( admin, page, 'image-generation' );
	} );

	test( 'Can generate a Featured Image', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Image Generation Experiment.
		await enableExperiment( admin, page, 'image-generation' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Featured Image Generation Experiment',
			content:
				'This is some test content for the Image Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the generate featured image button exists.
		await expect(
			page.locator(
				'.ai-featured-image .ai-featured-image__container button',
				{
					hasText: 'Generate featured image',
				}
			)
		).toBeVisible();

		// Click the generate featured image button.
		await page
			.locator(
				'.ai-featured-image .ai-featured-image__container button'
			)
			.click();

		// Ensure the generated image is visible.
		await expect(
			page.locator(
				'.editor-post-featured-image .editor-post-featured-image__container img'
			)
		).toBeVisible();

		// Save the post.
		await editor.saveDraft();

		// Ensure the image is in the Media Library.
		await visitAdminPage( admin, 'upload.php' );

		const imageContainer = page
			.locator( '.attachments-wrapper li' )
			.first();

		await expect( imageContainer ).toHaveAttribute(
			'aria-label',
			'AI Generated Image'
		);

		await expect( imageContainer.locator( 'img' ) ).toBeVisible();
	} );

	test( 'Ensure the Image Generation Experiment UI is not visible when Experiments are globally disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Image Generation Experiment.
		await enableExperiment( admin, page, 'image-generation' );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Image Generation Experiment Globally Disabled',
			content:
				'This is some test content for the Image Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the generate featured image button doesn't exist.
		await expect(
			page.locator(
				'.ai-featured-image .ai-featured-image__container button'
			)
		).not.toBeVisible();
	} );

	test( 'Ensure the Image Generation Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Image Generation Experiment.
		await disableExperiment( admin, page, 'image-generation' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Image Generation Experiment Disabled',
			content:
				'This is some test content for the Image Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the generate featured image button doesn't exist.
		await expect(
			page.locator(
				'.ai-featured-image .ai-featured-image__container button'
			)
		).not.toBeVisible();
	} );
} );

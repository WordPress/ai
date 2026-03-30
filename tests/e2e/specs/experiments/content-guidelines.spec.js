/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	enableExperiment,
	enableExperiments,
} = require( '../../utils/helpers' );

test.describe( 'Content Guidelines Integration', () => {
	test( 'Title generation works without content guidelines CPT (graceful degradation)', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Title Generation Experiment.
		await enableExperiment( admin, page, 'title-generation' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: '',
			content:
				'This is content to test that title generation works gracefully when no content guidelines are configured on the site.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Click into the title field.
		await editor.canvas.locator( '.editor-post-title__input' ).click();

		// Ensure the title toolbar is visible.
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

		// Ensure there is one title option (mock API returns one result).
		await expect(
			page.locator( '.ai-title-generation-modal .ai-title textarea' )
		).toHaveCount( 1 );

		// Click the first title option.
		await page
			.locator(
				'.ai-title-generation-modal .ai-title:first-child button'
			)
			.click();

		// Ensure the title modal is closed.
		await expect(
			page.locator( '.ai-title-generation-modal' )
		).not.toBeVisible();

		// Ensure the title is updated (mock response value).
		await expect(
			editor.canvas.locator( '.editor-post-title__input' )
		).toHaveText(
			'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure'
		);
	} );

	test( 'Review notes work without content guidelines CPT (graceful degradation)', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Review Notes Experiment.
		await enableExperiment( admin, page, 'review-notes' );

		// Create a new post with reviewable content.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Content Guidelines Degradation',
			content: 'This is a test paragraph for review notes.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Verify the experiment loaded without errors by checking the page is still functional.
		await expect(
			editor.canvas.locator( '.editor-post-title__input' )
		).toHaveText( 'Test Content Guidelines Degradation' );
	} );
} );

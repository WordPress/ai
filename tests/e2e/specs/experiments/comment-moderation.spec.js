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

test.describe( 'Comment Moderation Experiment', () => {
	test( 'Can enable the comment moderation experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Comment Moderation Experiment.
		await enableExperiment( admin, page, 'Comment Moderation' );
	} );

	test( 'Can use the Comment Moderation Experiment', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Comment Moderation Experiment.
		await disableExperiment( admin, page, 'Comment Moderation' );

		// Create a new post and comment.
		const post = await requestUtils.createPost( {
			title: 'Test Comment Moderation Experiment',
			status: 'publish',
		} );

		await requestUtils.createComment( {
			content: 'This is a test comment.',
			post: post.id,
		} );

		// Enable the Comment Moderation Experiment.
		await enableExperiment( admin, page, 'Comment Moderation' );

		// Go to the comments admin page.
		await admin.visitAdminPage( 'edit-comments.php' );

		// Select the first comment in the list.
		await page
			.locator( '#the-comment-list tr:first-child .check-column' )
			.click();

		// Trigger comment analysis.
		await page
			.locator( '#bulk-action-selector-top' )
			.selectOption( 'wpai_analyze' );

		// Click the apply button.
		await page.locator( '#doaction' ).click();

		// Ensure the admin notice shows and has the right text.
		await expect(
			page.locator( '.notice-success', {
				hasText: /1 comment queued for AI analysis/,
			} )
		).toBeVisible();

		// Ensure the comment sentiment and toxicity badges are visible and have the right text.
		await expect(
			page.locator( '.wpai_sentiment', {
				hasText: /Negative/,
			} )
		).toBeVisible();

		await expect(
			page.locator( '.wpai_toxicity', {
				hasText: /High/,
			} )
		).toBeVisible();

		// Go to the post on the front-end.
		expect( post.link ).toBeTruthy();
		await page.goto( post.link );

		// Leave a comment.
		await page
			.locator( '#comment' )
			.fill( 'This is a mean and toxic comment.' );
		await page.locator( '#submit' ).click();

		// Ensure we see the comment moderation message.
		await expect(
			page.locator( '.comment-awaiting-moderation' )
		).toBeVisible();
	} );

	test( 'Ensure the Comment Moderation Experiment UI is not visible when Experiments are globally disabled', async ( {
		admin,
		page,
	} ) => {
		// Enable the Comment Moderation Experiment.
		await enableExperiment( admin, page, 'Comment Moderation' );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Go to the comments admin page.
		await admin.visitAdminPage( 'edit-comments.php' );

		// Ensure the comment sentiment and toxicity badges are not visible.
		await expect( page.locator( '.wpai_sentiment' ) ).not.toBeVisible();

		await expect( page.locator( '.wpai_toxicity' ) ).not.toBeVisible();

		// Ensure our bulk option doesn't exist.
		await expect(
			page.locator( '#bulk-action-selector-top' )
		).not.toContainText( 'Analyze Sentiment and Toxicity' );
	} );

	test( 'Ensure the Comment Moderation Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Comment Moderation Experiment.
		await disableExperiment( admin, page, 'Comment Moderation' );

		// Go to the comments admin page.
		await admin.visitAdminPage( 'edit-comments.php' );

		// Ensure the comment sentiment and toxicity badges are not visible.
		await expect( page.locator( '.wpai_sentiment' ) ).not.toBeVisible();

		await expect( page.locator( '.wpai_toxicity' ) ).not.toBeVisible();

		// Ensure our bulk option doesn't exist.
		await expect(
			page.locator( '#bulk-action-selector-top' )
		).not.toContainText( 'Analyze Sentiment and Toxicity' );
	} );
} );

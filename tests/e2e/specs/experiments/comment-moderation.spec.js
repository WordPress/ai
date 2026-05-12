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
				hasText: /1 comment queued for analysis/,
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

	test( 'Can filter and sort comments by sentiment and toxicity', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.deleteAllComments();
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'Comment Moderation' );

		// Create a post for the comments.
		const post = await requestUtils.createPost( {
			title: 'Filter and Sort Test',
			status: 'publish',
		} );

		// Create three comments with distinct content.
		await requestUtils.createComment( {
			content: 'This is a negative comment.',
			post: post.id,
		} );
		await requestUtils.createComment( {
			content: 'This is a positive comment.',
			post: post.id,
		} );
		await requestUtils.createComment( {
			content: 'This is a neutral comment.',
			post: post.id,
		} );

		// Go to the comments admin page.
		await admin.visitAdminPage( 'edit-comments.php' );

		// Verify all labels.
		await expect(
			page.locator( '.wpai_sentiment', { hasText: /Negative/ } ).first()
		).toBeVisible();
		await expect(
			page.locator( '.wpai_sentiment', { hasText: /Positive/ } ).first()
		).toBeVisible();
		await expect(
			page.locator( '.wpai_sentiment', { hasText: /Neutral/ } ).first()
		).toBeVisible();

		// Test Filtering: Filter by Negative sentiment.
		await page
			.locator( '#wpai-filter-sentiment' )
			.selectOption( 'negative' );
		await page.locator( '#post-query-submit' ).click();

		// Verify only Negative is visible.
		await expect(
			page.locator( '.wpai_sentiment', { hasText: /Negative/ } ).first()
		).toBeVisible();
		await expect(
			page.locator( '.wpai_sentiment', { hasText: /Positive/ } )
		).not.toBeVisible();
		await expect(
			page.locator( '.wpai_sentiment', { hasText: /Neutral/ } )
		).not.toBeVisible();

		// Test Filtering: Filter by Neutral sentiment.
		await page
			.locator( '#wpai-filter-sentiment' )
			.selectOption( 'neutral' );
		await page.locator( '#post-query-submit' ).click();

		// Verify only Neutral is visible.
		await expect(
			page.locator( '.wpai_sentiment', { hasText: /Neutral/ } ).first()
		).toBeVisible();
		await expect(
			page.locator( '.wpai_sentiment', { hasText: /Negative/ } )
		).not.toBeVisible();
		await expect(
			page.locator( '.wpai_sentiment', { hasText: /Positive/ } )
		).not.toBeVisible();

		// Reset filter.
		await page.locator( '#wpai-filter-sentiment' ).selectOption( '' );
		await page.locator( '#post-query-submit' ).click();

		// Test Filtering: Filter by High toxicity.
		await page.locator( '#wpai-filter-toxicity' ).selectOption( 'high' );
		await page.locator( '#post-query-submit' ).click();

		// Verify only High toxicity is visible.
		await expect(
			page.locator( '.wpai_toxicity', { hasText: /High/ } ).first()
		).toBeVisible();
		await expect(
			page.locator( '.wpai_toxicity', { hasText: /Low/ } )
		).not.toBeVisible();

		// Reset filter.
		await page.locator( '#wpai-filter-toxicity' ).selectOption( '' );
		await page.locator( '#post-query-submit' ).click();

		// Test Sorting: Click the Toxicity column header to sort (ASC).
		await page.locator( 'th#wpai_toxicity a' ).click();
		await expect( page ).toHaveURL( /orderby=wpai_toxicity/ );
		await expect( page ).toHaveURL( /order=asc/ );

		// Verify order: Low toxicity should be first.
		let toxicityLabels = await page
			.locator( '.wpai_toxicity' )
			.allTextContents();
		expect( toxicityLabels[ 0 ] ).toContain( 'Low' );
		expect( toxicityLabels[ 1 ] ).toContain( 'Medium' );
		expect( toxicityLabels[ 2 ] ).toContain( 'High' );

		// Click again to sort (DESC).
		await page.locator( 'th#wpai_toxicity a' ).click();
		await expect( page ).toHaveURL( /order=desc/ );

		toxicityLabels = await page
			.locator( '.wpai_toxicity' )
			.allTextContents();
		expect( toxicityLabels[ 0 ] ).toContain( 'High' );
		expect( toxicityLabels[ 1 ] ).toContain( 'Medium' );
		expect( toxicityLabels[ 2 ] ).toContain( 'Low' );

		// Test Sorting: Click the Sentiment column header to sort.
		await page.locator( 'th#wpai_sentiment a' ).click();
		await expect( page ).toHaveURL( /orderby=wpai_sentiment/ );
		await expect( page ).toHaveURL( /order=asc/ );

		// Verify order: Negative (N) should be before Neutral (N) before Positive (P) in ASC.
		let sentimentLabels = await page
			.locator( '.wpai_sentiment' )
			.allTextContents();
		expect( sentimentLabels[ 0 ] ).toContain( 'Negative' );
		expect( sentimentLabels[ 1 ] ).toContain( 'Neutral' );
		expect( sentimentLabels[ 2 ] ).toContain( 'Positive' );

		// Click again to sort (DESC).
		await page.locator( 'th#wpai_sentiment a' ).click();
		await expect( page ).toHaveURL( /order=desc/ );

		// Verify order: Positive (P) should be before Neutral (N) before Negative (N) in DESC.
		sentimentLabels = await page
			.locator( '.wpai_sentiment' )
			.allTextContents();
		expect( sentimentLabels[ 0 ] ).toContain( 'Positive' );
		expect( sentimentLabels[ 1 ] ).toContain( 'Neutral' );
		expect( sentimentLabels[ 2 ] ).toContain( 'Negative' );
	} );
} );

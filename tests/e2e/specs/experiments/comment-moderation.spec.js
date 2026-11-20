/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	enableExperiment,
} = require( '../../utils/helpers' );

test.describe( 'Comment Moderation Experiment', () => {
	test( 'Can enable the comment moderation experiment', async ( {
		admin,
		page,
	} ) => {
		// Enable the Comment Moderation Experiment.
		await enableExperiment( admin, page, 'Comment Moderation' );
	} );

	test( 'Can use the Comment Moderation Experiment', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
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

		// Select the created comment in the list.
		const commentRow = page.getByRole( 'row' ).filter( {
			hasText: 'This is a test comment.',
		} );
		await commentRow
			.getByRole( 'checkbox', { name: 'Select comment', exact: true } )
			.check();

		// Trigger comment analysis.
		await page
			.getByRole( 'combobox', {
				name: 'Select bulk action',
				exact: true,
			} )
			.first()
			.selectOption( {
				label: 'Analyze Sentiment, Toxicity, and Value',
			} );

		// Click the apply button.
		await page
			.getByRole( 'button', { name: 'Apply', exact: true } )
			.first()
			.click();

		// Ensure the admin notice shows and has the right text.
		await expect(
			page
				.getByRole( 'main' )
				.getByText( /1 comment queued for analysis/ )
		).toBeVisible();

		// Ensure the comment sentiment, toxicity and value badges are visible and have the right text.
		await expect(
			commentRow.getByTitle( 'Negative', { exact: true } )
		).toBeVisible();

		await expect(
			commentRow.getByTitle( /^High \(\d+%\)$/ )
		).toBeVisible();

		await expect( commentRow.getByTitle( /^Low \(\d+%\)$/ ) ).toBeVisible();

		// Go to the post on the front-end.
		expect( post.link ).toBeTruthy();
		await page.goto( post.link );

		// Leave a comment.
		await page
			.getByRole( 'textbox', { name: 'Comment' } )
			.fill( 'This is a mean and toxic comment.' );
		await page
			.getByRole( 'button', { name: 'Post Comment', exact: true } )
			.click();

		// Ensure we see the comment moderation message.
		await expect(
			page.getByText( /^Your comment is awaiting moderation\./ )
		).toBeVisible();
	} );

	test( 'Ensure the Comment Moderation Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		page,
	} ) => {
		// Disable the Comment Moderation Experiment.
		await disableExperiment( admin, page, 'Comment Moderation' );

		// Go to the comments admin page.
		await admin.visitAdminPage( 'edit-comments.php' );

		// Ensure the analysis columns are not visible.
		await expect(
			page.getByRole( 'columnheader', { name: /^Sentiment\b/ } )
		).not.toBeVisible();

		await expect(
			page.getByRole( 'columnheader', { name: /^Toxicity\b/ } )
		).not.toBeVisible();

		await expect(
			page.getByRole( 'columnheader', { name: /^Value\b/ } )
		).not.toBeVisible();

		// Ensure our bulk option doesn't exist.
		await expect(
			page
				.getByRole( 'combobox', {
					name: 'Select bulk action',
					exact: true,
				} )
				.first()
		).not.toContainText( 'Analyze Sentiment, Toxicity, and Value' );
	} );

	test( 'Can filter and sort comments by sentiment, toxicity and value score', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.deleteAllComments();
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

		const negativeComment = page.getByRole( 'row' ).filter( {
			hasText: 'This is a negative comment.',
		} );
		const positiveComment = page.getByRole( 'row' ).filter( {
			hasText: 'This is a positive comment.',
		} );
		const neutralComment = page.getByRole( 'row' ).filter( {
			hasText: 'This is a neutral comment.',
		} );
		const commentRows = page.getByRole( 'row' ).filter( {
			has: page.getByRole( 'checkbox', {
				name: 'Select comment',
				exact: true,
			} ),
		} );

		// Verify the sentiment badges by their tooltip text.
		await expect(
			negativeComment.getByTitle( 'Negative', { exact: true } )
		).toBeVisible();
		await expect(
			positiveComment.getByTitle( 'Positive', { exact: true } )
		).toBeVisible();
		await expect(
			neutralComment.getByTitle( 'Neutral', { exact: true } )
		).toBeVisible();

		// Filter by Negative sentiment.
		await page
			.getByLabel( 'Filter by Sentiment', { exact: true } )
			.selectOption( 'negative' );
		await page
			.getByRole( 'button', { name: 'Filter', exact: true } )
			.click();
		await expect( negativeComment ).toBeVisible();
		await expect( positiveComment ).not.toBeVisible();
		await expect( neutralComment ).not.toBeVisible();

		// Filter by Neutral sentiment.
		await page
			.getByLabel( 'Filter by Sentiment', { exact: true } )
			.selectOption( 'neutral' );
		await page
			.getByRole( 'button', { name: 'Filter', exact: true } )
			.click();
		await expect( neutralComment ).toBeVisible();
		await expect( negativeComment ).not.toBeVisible();
		await expect( positiveComment ).not.toBeVisible();

		// Reset sentiment, then filter by High toxicity.
		await page
			.getByLabel( 'Filter by Sentiment', { exact: true } )
			.selectOption( '' );
		await page
			.getByLabel( 'Filter by Toxicity', { exact: true } )
			.selectOption( 'high' );
		await page
			.getByRole( 'button', { name: 'Filter', exact: true } )
			.click();
		await expect(
			negativeComment.getByTitle( /^High \(\d+%\)$/ )
		).toBeVisible();
		await expect( positiveComment ).not.toBeVisible();
		await expect( neutralComment ).not.toBeVisible();

		// Reset toxicity and sort from Low to High.
		await page
			.getByLabel( 'Filter by Toxicity', { exact: true } )
			.selectOption( '' );
		await page
			.getByRole( 'button', { name: 'Filter', exact: true } )
			.click();
		const toxicitySortLink = page
			.getByRole( 'columnheader', { name: /^Toxicity\b/ } )
			.first()
			.getByRole( 'link' );
		await toxicitySortLink.click();
		await expect( page ).toHaveURL( /orderby=wpai_toxicity/ );
		await expect( page ).toHaveURL( /order=asc/ );
		await expect( commentRows ).toHaveText( [
			/This is a positive comment\./,
			/This is a neutral comment\./,
			/This is a negative comment\./,
		] );

		// Sort toxicity from High to Low.
		await toxicitySortLink.click();
		await expect( page ).toHaveURL( /order=desc/ );
		await expect( commentRows ).toHaveText( [
			/This is a negative comment\./,
			/This is a neutral comment\./,
			/This is a positive comment\./,
		] );

		// Sort sentiment from Negative to Positive, then reverse it.
		const sentimentSortLink = page
			.getByRole( 'columnheader', { name: /^Sentiment\b/ } )
			.first()
			.getByRole( 'link' );
		await sentimentSortLink.click();
		await expect( page ).toHaveURL( /orderby=wpai_sentiment/ );
		await expect( page ).toHaveURL( /order=asc/ );
		await expect( commentRows ).toHaveText( [
			/This is a negative comment\./,
			/This is a neutral comment\./,
			/This is a positive comment\./,
		] );

		await sentimentSortLink.click();
		await expect( page ).toHaveURL( /order=desc/ );
		await expect( commentRows ).toHaveText( [
			/This is a positive comment\./,
			/This is a neutral comment\./,
			/This is a negative comment\./,
		] );

		// Filter by High value score.
		await page
			.getByLabel( 'Filter by Value Score', { exact: true } )
			.selectOption( 'high' );
		await page
			.getByRole( 'button', { name: 'Filter', exact: true } )
			.click();
		await expect(
			positiveComment.getByTitle( /^High \(\d+%\)$/ )
		).toBeVisible();
		await expect( negativeComment ).not.toBeVisible();
		await expect( neutralComment ).not.toBeVisible();

		// Reset value score and sort from Low to High, then reverse it.
		await page
			.getByLabel( 'Filter by Value Score', { exact: true } )
			.selectOption( '' );
		await page
			.getByRole( 'button', { name: 'Filter', exact: true } )
			.click();
		const valueSortLink = page
			.getByRole( 'columnheader', { name: /^Value\b/ } )
			.first()
			.getByRole( 'link' );
		await valueSortLink.click();
		await expect( page ).toHaveURL( /orderby=wpai_value_score/ );
		await expect( page ).toHaveURL( /order=asc/ );
		await expect( commentRows ).toHaveText( [
			/This is a negative comment\./,
			/This is a neutral comment\./,
			/This is a positive comment\./,
		] );

		await valueSortLink.click();
		await expect( page ).toHaveURL( /order=desc/ );
		await expect( commentRows ).toHaveText( [
			/This is a positive comment\./,
			/This is a neutral comment\./,
			/This is a negative comment\./,
		] );
	} );

	test( 'Sorting after a bulk analyze does not re-show the notice', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Enable the Comment Moderation Experiment.
		await enableExperiment( admin, page, 'Comment Moderation' );

		// Create a post and two comments so the bulk action has something to run on.
		const post = await requestUtils.createPost( {
			title: 'Comment Moderation Retrigger Test',
			status: 'publish',
		} );
		await requestUtils.createComment( {
			content: 'First retrigger test comment.',
			post: post.id,
		} );
		await requestUtils.createComment( {
			content: 'Second retrigger test comment.',
			post: post.id,
		} );

		// Go to the comments admin page and run the bulk action on all comments.
		await admin.visitAdminPage( 'edit-comments.php' );
		await page
			.getByRole( 'checkbox', { name: 'Select All', exact: true } )
			.first()
			.check();
		await page
			.getByRole( 'combobox', {
				name: 'Select bulk action',
				exact: true,
			} )
			.first()
			.selectOption( {
				label: 'Analyze Sentiment, Toxicity, and Value',
			} );
		await page
			.getByRole( 'button', { name: 'Apply', exact: true } )
			.first()
			.click();

		// The notice shows once after the bulk action.
		await expect(
			page.getByRole( 'main' ).getByText( /queued for analysis/ )
		).toBeVisible();

		// The links the list table rendered must not carry the notice trigger
		// params, otherwise every sort and pagination click re-shows the notice.
		const sortLink = page
			.getByRole( 'columnheader', { name: /^Author\b/ } )
			.first()
			.getByRole( 'link' );
		await expect( sortLink ).not.toHaveAttribute(
			'href',
			/wpai_analysis_queued/
		);

		// Click the sort header. The resulting page must not carry the trigger
		// param, which is what re-shows the notice.
		await sortLink.click();
		await page.waitForURL( /orderby=comment_author/ );

		expect( page.url() ).not.toContain( 'wpai_analysis_queued' );
		await expect(
			page.getByRole( 'main' ).getByText( /queued for analysis/ )
		).toHaveCount( 0 );
	} );
} );

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

test.describe( 'Suggest Reply Experiment', () => {
	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllComments();
	} );

	test( 'Can enable the suggest reply experiment', async ( {
		admin,
		page,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'Suggest Reply' );
	} );

	test( 'Can use the Suggest Reply Experiment on the Comments admin page', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'Suggest Reply' );

		// Create a new post and comment.
		const post = await requestUtils.createPost( {
			title: 'Test Suggest Reply Experiment',
			status: 'publish',
		} );

		await requestUtils.createComment( {
			content: 'This is a test comment for suggest reply.',
			post: post.id,
		} );

		await admin.visitAdminPage( 'edit-comments.php' );

		await expect( page.locator( '#the-comment-list' ) ).toBeVisible();

		// Row actions are revealed on hover.
		const firstCommentRow = page.locator(
			'#the-comment-list tr:first-child'
		);
		await firstCommentRow.hover();

		const suggestReplyLink = firstCommentRow.locator(
			'a.wpai-suggest-reply'
		);
		await expect( suggestReplyLink ).toHaveAttribute(
			'aria-label',
			'Suggest a reply to this comment'
		);

		await suggestReplyLink.click();

		await expect( page.locator( '#replyrow' ) ).toBeVisible( {
			timeout: 5000,
		} );

		await expect(
			page.locator( '#wpai-suggest-reply-controls' )
		).toBeVisible();

		const replyTextarea = page.locator( '#replyrow #replycontent' );
		await expect( replyTextarea ).toBeVisible();
		await expect( replyTextarea ).toHaveValue(
			'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure'
		);

		await expect( page.locator( '#wpai-suggest-reply-btn' ) ).toBeVisible();
		await expect( page.locator( '#wpai-suggest-reply-btn' ) ).toHaveText(
			'Suggest Reply'
		);

		// Open the tone dropdown and verify all three options.
		await expect(
			page.locator( '.wpai-split-button__toggle' )
		).toBeVisible();
		await page.locator( '.wpai-split-button__toggle' ).click();

		await expect(
			page.locator( '.wpai-split-button__dropdown' )
		).toBeVisible();
		await expect(
			page.locator( '.wpai-dropdown-item[data-tone="friendly"]' )
		).toBeVisible();
		await expect(
			page.locator( '.wpai-dropdown-item[data-tone="professional"]' )
		).toBeVisible();
		await expect(
			page.locator( '.wpai-dropdown-item[data-tone="casual"]' )
		).toBeVisible();

		// Friendly should be selected by default.
		await expect(
			page.locator( '.wpai-dropdown-item[data-tone="friendly"]' )
		).toHaveClass( /is-selected/ );
		await expect(
			page.locator( '.wpai-dropdown-item[data-tone="friendly"]' )
		).toHaveAttribute( 'aria-selected', 'true' );
	} );

	test( 'Can use the Suggest Reply Experiment on the Activity dashboard widget', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'Suggest Reply' );

		// Create a new post and comment so the Activity widget has content.
		const post = await requestUtils.createPost( {
			title: 'Test Suggest Reply Activity Widget',
			status: 'publish',
		} );

		await requestUtils.createComment( {
			content: 'This is an activity widget test comment.',
			post: post.id,
		} );

		await admin.visitAdminPage( 'index.php' );

		await expect( page.locator( '#dashboard_activity' ) ).toBeVisible( {
			timeout: 10000,
		} );

		const activityCommentList = page.locator(
			'#dashboard_activity #the-comment-list'
		);
		await expect( activityCommentList ).toBeVisible( {
			timeout: 10000,
		} );

		// Row actions are revealed on hover.
		const firstCommentItem = activityCommentList.locator(
			'li.comment-item:first-child'
		);
		await firstCommentItem.hover();

		const suggestReplyLink = firstCommentItem.locator(
			'a.wpai-suggest-reply'
		);
		await expect( suggestReplyLink ).toHaveAttribute(
			'aria-label',
			'Suggest a reply to this comment'
		);

		await suggestReplyLink.click();

		await expect( page.locator( '#replyrow' ) ).toBeVisible( {
			timeout: 5000,
		} );

		await expect(
			page.locator( '#wpai-suggest-reply-controls' )
		).toBeVisible();

		const replyTextarea = page.locator( '#replyrow #replycontent' );
		await expect( replyTextarea ).toBeVisible();
		await expect( replyTextarea ).toHaveValue(
			'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure'
		);

		await expect( page.locator( '#wpai-suggest-reply-btn' ) ).toBeVisible();
		await expect( page.locator( '#wpai-suggest-reply-btn' ) ).toHaveText(
			'Suggest Reply'
		);

		// Open the tone dropdown and verify all three options.
		await expect(
			page.locator( '.wpai-split-button__toggle' )
		).toBeVisible();
		await page.locator( '.wpai-split-button__toggle' ).click();

		await expect(
			page.locator( '.wpai-split-button__dropdown' )
		).toBeVisible();
		await expect(
			page.locator( '.wpai-dropdown-item[data-tone="friendly"]' )
		).toBeVisible();
		await expect(
			page.locator( '.wpai-dropdown-item[data-tone="professional"]' )
		).toBeVisible();
		await expect(
			page.locator( '.wpai-dropdown-item[data-tone="casual"]' )
		).toBeVisible();

		// Friendly should be selected by default.
		await expect(
			page.locator( '.wpai-dropdown-item[data-tone="friendly"]' )
		).toHaveClass( /is-selected/ );
		await expect(
			page.locator( '.wpai-dropdown-item[data-tone="friendly"]' )
		).toHaveAttribute( 'aria-selected', 'true' );

		// Close the reply form using the Cancel button.
		await page.locator( '#replyrow .cancel' ).click();
		await expect( page.locator( '#replyrow' ) ).not.toBeVisible();
	} );

	test( 'Ensure the Suggest Reply Experiment UI is not visible when Experiments are globally disabled', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'Suggest Reply' );

		const post = await requestUtils.createPost( {
			title: 'Test Suggest Reply Globally Disabled',
			status: 'publish',
		} );

		await requestUtils.createComment( {
			content: 'This is a comment for global disable test.',
			post: post.id,
		} );

		await disableExperiments( admin, page );

		await admin.visitAdminPage( 'edit-comments.php' );

		await expect( page.locator( '#the-comment-list' ) ).toBeVisible();

		await page.locator( '#the-comment-list tr:first-child' ).hover();

		await expect( page.locator( 'span.wpai_suggest_reply' ) ).toHaveCount(
			0
		);
	} );

	test( 'Ensure the Suggest Reply Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiments( admin, page );
		await disableExperiment( admin, page, 'Suggest Reply' );

		const post = await requestUtils.createPost( {
			title: 'Test Suggest Reply Experiment Disabled',
			status: 'publish',
		} );

		await requestUtils.createComment( {
			content: 'This is a comment for experiment disable test.',
			post: post.id,
		} );

		await admin.visitAdminPage( 'edit-comments.php' );

		await expect( page.locator( '#the-comment-list' ) ).toBeVisible();

		await page.locator( '#the-comment-list tr:first-child' ).hover();

		await expect( page.locator( 'span.wpai_suggest_reply' ) ).toHaveCount(
			0
		);
	} );
} );

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

const SUGGESTION =
	'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure';

/**
 * Opens the Comments screen with two comments and starts a suggestion for the
 * first one. The ability request is held until `releaseRequest()` is called,
 * so the reply form can be moved while it is running.
 *
 * @param {Object} admin        The admin fixture.
 * @param {Object} page         The page fixture.
 * @param {Object} requestUtils The request utils fixture.
 */
async function startHeldSuggestion( admin, page, requestUtils ) {
	await enableExperiment( admin, page, 'Suggest Reply' );

	const post = await requestUtils.createPost( {
		title: 'Test Suggest Reply Form Moves',
		status: 'publish',
	} );

	const firstComment = await requestUtils.createComment( {
		content: 'This is the comment the suggestion is generated for.',
		post: post.id,
	} );
	const secondComment = await requestUtils.createComment( {
		content: 'This is the comment the reply form moves to.',
		post: post.id,
	} );

	await admin.visitAdminPage( 'edit-comments.php' );

	await expect( page.locator( '#the-comment-list' ) ).toBeVisible();

	let releaseRequest;
	const requestPromise = new Promise( ( resolve ) => {
		releaseRequest = resolve;
	} );

	await page.route(
		// The path is URL-encoded when the site uses plain permalinks.
		( url ) =>
			decodeURIComponent( url.href ).includes( 'ai/suggest-reply/run' ),
		async ( route ) => {
			await requestPromise;
			await route.continue();
		}
	);

	const firstRow = page.locator( `#comment-${ firstComment.id }` );
	const secondRow = page.locator( `#comment-${ secondComment.id }` );
	const suggestReplyLink = firstRow.locator( 'a.wpai-suggest-reply' );
	const replyTextarea = page.locator( '#replyrow #replycontent' );
	const replyCommentId = page.locator( '#replyrow #comment_ID' );

	await firstRow.hover();
	await suggestReplyLink.click();

	await expect( replyCommentId ).toHaveValue( String( firstComment.id ) );
	await expect( replyTextarea ).toBeDisabled();

	return {
		firstComment,
		secondComment,
		firstRow,
		secondRow,
		suggestReplyLink,
		replyTextarea,
		replyCommentId,
		releaseRequest,
	};
}

test.describe( 'Suggest Reply Experiment', () => {
	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllComments();
	} );

	test( 'Can enable the suggest reply experiment', async ( {
		admin,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Suggest Reply' );
	} );

	test( 'Can use the Suggest Reply Experiment on the Comments admin page', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
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
		await expect( replyTextarea ).toHaveValue( SUGGESTION );

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

	test( 'Suggest Reply controls are hidden in Quick Edit mode', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, 'Suggest Reply' );

		const post = await requestUtils.createPost( {
			title: 'Test Suggest Reply Quick Edit Visibility',
			status: 'publish',
		} );

		await requestUtils.createComment( {
			content: 'This is a test comment for quick edit visibility.',
			post: post.id,
		} );

		await admin.visitAdminPage( 'edit-comments.php' );
		await expect( page.locator( '#the-comment-list' ) ).toBeVisible();

		const firstCommentRow = page.locator(
			'#the-comment-list tr:first-child'
		);
		const controls = page.locator( '#wpai-suggest-reply-controls' );

		// Row actions are revealed on hover.
		await firstCommentRow.hover();
		await firstCommentRow.locator( '.quickedit button' ).click();

		await expect( page.locator( '#replyrow' ) ).toBeVisible( {
			timeout: 5000,
		} );

		// In Quick Edit mode the Suggest Reply controls must be hidden.
		await expect( controls ).not.toBeVisible();
	} );

	test( 'Can use the Suggest Reply Experiment on the Activity dashboard widget', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
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
		await expect( replyTextarea ).toHaveValue( SUGGESTION );

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

	test( 'Does not insert a suggestion into the reply form of another comment', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const {
			secondComment,
			secondRow,
			suggestReplyLink,
			replyTextarea,
			replyCommentId,
			releaseRequest,
		} = await startHeldSuggestion( admin, page, requestUtils );

		// Move the reply form to the second comment before the suggestion arrives.
		await secondRow.hover();
		await secondRow.locator( '.reply button' ).click();

		await expect( replyCommentId ).toHaveValue(
			String( secondComment.id )
		);

		// The second comment's reply form is usable right away.
		await expect( replyTextarea ).toBeEnabled();

		releaseRequest();

		// The link returns to its idle text once the request has finished.
		await expect( suggestReplyLink ).toHaveText( 'Suggest reply' );

		await expect( replyCommentId ).toHaveValue(
			String( secondComment.id )
		);
		await expect( replyTextarea ).toHaveValue( '' );
	} );

	test( 'Inserts the suggestion when the reply form returns to its comment', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const {
			firstComment,
			secondComment,
			firstRow,
			secondRow,
			suggestReplyLink,
			replyTextarea,
			replyCommentId,
			releaseRequest,
		} = await startHeldSuggestion( admin, page, requestUtils );

		await secondRow.hover();
		await secondRow.locator( '.reply button' ).click();

		await expect( replyCommentId ).toHaveValue(
			String( secondComment.id )
		);
		await expect( replyTextarea ).toBeEnabled();

		await firstRow.hover();
		await firstRow.locator( '.reply button' ).click();

		await expect( replyCommentId ).toHaveValue( String( firstComment.id ) );

		// The form is locked again because the request is still running.
		await expect( replyTextarea ).toBeDisabled();

		releaseRequest();

		await expect( suggestReplyLink ).toHaveText( 'Suggest reply' );
		await expect( replyTextarea ).toBeEnabled();
		await expect( replyTextarea ).toHaveValue( SUGGESTION );
	} );

	test( 'Does not insert a suggestion into the Quick Edit form of the same comment', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Without animations the form switches to Quick Edit without ever
		// being hidden.
		await page.addInitScript( () => {
			window.addEventListener( 'DOMContentLoaded', () => {
				if ( window.jQuery ) {
					window.jQuery.fx.off = true;
				}
			} );
		} );

		const { firstRow, suggestReplyLink, replyTextarea, releaseRequest } =
			await startHeldSuggestion( admin, page, requestUtils );

		await firstRow.hover();
		await firstRow.locator( 'button.vim-q' ).click();

		await expect( page.locator( '#replyrow #savebtn' ) ).toBeVisible();
		await expect( replyTextarea ).toBeEnabled();

		releaseRequest();

		await expect( suggestReplyLink ).toHaveText( 'Suggest reply' );
		await expect( replyTextarea ).toHaveValue(
			'This is the comment the suggestion is generated for.'
		);
	} );

	test( 'Ensure the Suggest Reply Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
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

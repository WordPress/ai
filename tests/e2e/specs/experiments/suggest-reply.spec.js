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

const EXPERIMENT_LABEL = 'Suggest Reply';

test.describe( 'Suggest Reply Experiment', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllComments();
	} );

	test( 'Can enable the suggest reply experiment', async ( {
		admin,
		page,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'Can use the Suggest Reply Experiment on the Comments admin page', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const post = await requestUtils.createPost( {
			title: 'Test Suggest Reply Experiment',
			status: 'publish',
		} );

		await requestUtils.createComment( {
			content: 'This is a test comment for suggest reply.',
			post: post.id,
		} );

		await admin.visitAdminPage( 'edit-comments.php' );

		// Hover to reveal the hidden WordPress row actions, then click.
		await page.locator( '#the-comment-list tr:first-child' ).hover();
		await page
			.locator( '#the-comment-list tr:first-child a.wpai-suggest-reply' )
			.click();

		// Verify modal UI elements.
		await expect( page.getByText( 'Guidelines (optional)' ) ).toBeVisible();
		await expect( page.getByText( 'Tone' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Generate' } )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Generate' } ).click();

		// Wait for the AI suggestion and action buttons to appear.
		await expect(
			page.getByRole( 'button', { name: 'Use this reply' } )
		).toBeVisible( { timeout: 30000 } );
		await expect(
			page.getByRole( 'button', { name: 'Copy' } )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Use this reply' } ).click();

		// Ensure the inline reply form is populated before submitting.
		const commentTextbox = page.getByRole( 'textbox', { name: 'Comment' } );
		await expect( commentTextbox ).toBeVisible();
		await expect( commentTextbox ).not.toHaveValue( '' );

		await page
			.getByRole( 'button', { name: 'Reply', exact: true } )
			.click();
		await page.waitForLoadState( 'networkidle' );
		await page.reload();

		await expect(
			page.getByRole( 'cell', { name: /In reply to/ } ).first()
		).toBeVisible();
	} );

	test( 'Can use the Suggest Reply Experiment on the Activity dashboard widget', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const post = await requestUtils.createPost( {
			title: 'Test Suggest Reply Dashboard Widget',
			status: 'publish',
		} );

		await requestUtils.createComment( {
			content: 'This is a test comment for the dashboard widget.',
			post: post.id,
		} );

		await admin.visitAdminPage( 'index.php' );

		// Hover to reveal the hidden row actions in the Activity widget.
		await page
			.locator( '#activity-widget' )
			.locator( 'li' )
			.first()
			.hover();

		const suggestReplyLink = page
			.locator( '#activity-widget a.wpai-suggest-reply' )
			.first();
		await expect( suggestReplyLink ).toBeVisible();
		await suggestReplyLink.click();

		// Verify modal UI elements.
		await expect( page.getByText( 'Guidelines (optional)' ) ).toBeVisible();
		await expect( page.getByText( 'Tone' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Generate' } )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Close' } ).click();
		await expect(
			page.getByRole( 'button', { name: 'Generate' } )
		).not.toBeVisible();
	} );

	test( 'Ensure the Suggest Reply Experiment UI is not visible when Experiments are globally disabled', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const post = await requestUtils.createPost( {
			title: 'Test Suggest Reply Disabled Globally',
			status: 'publish',
		} );

		await requestUtils.createComment( {
			content: 'This is a test comment.',
			post: post.id,
		} );

		await disableExperiments( admin, page );

		await admin.visitAdminPage( 'edit-comments.php' );
		await expect( page.locator( 'a.wpai-suggest-reply' ) ).toHaveCount( 0 );

		await admin.visitAdminPage( 'index.php' );
		await expect( page.locator( 'a.wpai-suggest-reply' ) ).toHaveCount( 0 );
	} );

	test( 'Ensure the Suggest Reply Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiments( admin, page );
		await disableExperiment( admin, page, EXPERIMENT_LABEL );

		const post = await requestUtils.createPost( {
			title: 'Test Suggest Reply Experiment Disabled',
			status: 'publish',
		} );

		await requestUtils.createComment( {
			content: 'This is a test comment.',
			post: post.id,
		} );

		await admin.visitAdminPage( 'edit-comments.php' );
		await expect( page.locator( 'a.wpai-suggest-reply' ) ).toHaveCount( 0 );

		await admin.visitAdminPage( 'index.php' );
		await expect( page.locator( 'a.wpai-suggest-reply' ) ).toHaveCount( 0 );
	} );
} );

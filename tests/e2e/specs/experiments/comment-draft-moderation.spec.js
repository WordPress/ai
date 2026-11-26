/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableAdvancedSettings,
	enableAdvancedSettings,
	visitSettingsPage,
} = require( '../../utils/helpers' );

const setting = 'wpai_feature_comment-moderation_field_check_drafts';
const route = '**/*ai/v1/comment-draft-check*';
const toxic = 'This is a mean and toxic comment.';
const constructive = 'This plugin did not work for me.';
const draftRoute = ( request ) =>
	request.url().includes( '/ai/v1/comment-draft-check' );

function updateSettings( requestUtils, data ) {
	return requestUtils.rest( {
		path: '/wp/v2/settings',
		method: 'POST',
		data,
	} );
}

const postIds = [];

async function createPost( requestUtils ) {
	const post = await requestUtils.createPost( {
		title: 'Comment draft checks',
		status: 'publish',
		comment_status: 'open',
	} );
	postIds.push( post.id );
	return post;
}

test.describe( 'Comment draft moderation', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await updateSettings( requestUtils, {
			'wpai_feature_comment-moderation_enabled': true,
			[ setting ]: true,
		} );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await updateSettings( requestUtils, {
			'wpai_feature_comment-moderation_enabled': false,
			[ setting ]: false,
		} );
		await Promise.all(
			postIds.splice( 0 ).map( ( postId ) =>
				requestUtils.rest( {
					path: `/wp/v2/posts/${ postId }`,
					method: 'DELETE',
					params: { force: true },
				} )
			)
		);
	} );

	test( 'sends toxic comments to Pending Moderation with JavaScript disabled', async ( {
		browser,
		page,
		requestUtils,
	} ) => {
		const post = await createPost( requestUtils );
		const context = await browser.newContext( {
			javaScriptEnabled: false,
			storageState: await page.context().storageState(),
		} );
		try {
			const noScriptPage = await context.newPage();
			await noScriptPage.goto( post.link );
			await noScriptPage.locator( '#comment' ).fill( toxic );
			await noScriptPage
				.getByRole( 'button', { name: 'Post Comment' } )
				.click();
			await expect(
				noScriptPage.getByText( /your comment is awaiting moderation/i )
			).toBeVisible();
			const commentId = Number(
				new URL( noScriptPage.url() ).searchParams.get( 'unapproved' )
			);
			const saved = await requestUtils.rest( {
				path: `/wp/v2/comments/${ commentId }`,
				params: { context: 'edit' },
			} );
			expect( saved.status ).toBe( 'hold' );
			expect( saved.content.rendered ).toContain( toxic );
		} finally {
			await context.close();
		}
	} );

	test( 'blocks a toxic draft after a pause and submits a revision without checking it twice', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await createPost( requestUtils );
		const drafts = [];
		page.on( 'request', ( request ) => {
			if ( draftRoute( request ) ) {
				drafts.push( request.postDataJSON().text );
			}
		} );
		await page.goto( post.link );

		const comment = page.locator( '#comment' );
		const submit = page.getByRole( 'button', { name: 'Post Comment' } );
		const status = page.locator( '.wpai-comment-draft-status' );

		await comment.fill( toxic );
		await submit.focus();
		await expect( status ).toContainText( 'Please revise your comment' );
		await expect( status ).toBeVisible();
		await expect( submit ).toBeDisabled();
		await expect( comment ).toBeFocused();

		const checked = page.waitForResponse( draftRoute );
		await comment.fill( constructive );
		await expect( submit ).toBeEnabled();
		expect( await ( await checked ).json() ).toEqual( { blocked: false } );
		await expect( status ).toBeEmpty();
		await submit.click();
		await expect(
			page.locator( '.comment-content' ).getByText( constructive )
		).toBeVisible();
		expect( drafts ).toEqual( [ toxic, constructive ] );
	} );

	test( 'checks a fast submit and checks a revised draft before submitting', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await createPost( requestUtils );
		await page.goto( post.link );
		const comment = page.locator( '#comment' );
		const submit = page.getByRole( 'button', { name: 'Post Comment' } );
		await comment.fill( toxic );
		await submit.click();
		await expect( submit ).toBeDisabled();
		await expect( comment ).toBeFocused();

		await comment.fill( 'Another toxic draft.' );
		await submit.click();
		await expect( submit ).toBeDisabled();
		await expect( page.locator( '.comment-content' ) ).toHaveCount( 0 );

		await comment.fill( constructive );
		await submit.focus();
		await page.keyboard.press( 'Enter' );
		await expect(
			page.locator( '.comment-content' ).getByText( constructive )
		).toBeVisible();
	} );

	test( 'posts normally when the draft endpoint fails', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await createPost( requestUtils );
		let calls = 0;
		await page.route( route, async ( request ) => {
			calls++;
			await request.fulfill( { status: 503 } );
		} );
		await page.goto( post.link );
		await page.locator( '#comment' ).fill( constructive );
		await page.getByRole( 'button', { name: 'Post Comment' } ).click();
		await expect(
			page.locator( '.comment-content' ).getByText( constructive )
		).toBeVisible();
		expect( calls ).toBe( 1 );
	} );

	test( 'waits for the check already running for the submitted text', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await createPost( requestUtils );
		let calls = 0;
		let respond;
		await page.route( route, ( draftRequest ) => {
			calls++;
			return new Promise( ( resolve ) => {
				respond = async () => {
					await draftRequest.fulfill( { json: { blocked: false } } );
					resolve();
				};
			} );
		} );
		await page.goto( post.link );
		const requested = page.waitForRequest( draftRoute );
		await page.locator( '#comment' ).fill( constructive );
		await requested;
		await expect(
			page.locator( '.wpai-comment-draft-loading' )
		).toContainText( 'Checking your comment' );
		await page.getByRole( 'button', { name: 'Post Comment' } ).click();
		await expect(
			page.locator( '.wpai-comment-draft-loading' )
		).toContainText( 'Checking your comment' );
		await respond();
		await expect(
			page.locator( '.comment-content' ).getByText( constructive )
		).toBeVisible();
		expect( calls ).toBe( 1 );
	} );

	test( 'ignores an old response after the reader changes the draft', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await createPost( requestUtils );
		let respond;
		await page.route( route, ( draftRequest ) => {
			return new Promise( ( resolve ) => {
				respond = async () => {
					await draftRequest.fulfill( { json: { blocked: true } } );
					resolve();
				};
			} );
		} );
		await page.goto( post.link );
		const comment = page.locator( '#comment' );
		const submit = page.getByRole( 'button', { name: 'Post Comment' } );
		const requested = page.waitForRequest( draftRoute );
		await comment.fill( toxic );
		await submit.click();
		await requested;
		await comment.fill( '   ' );
		await expect(
			page.locator( '.wpai-comment-draft-loading' )
		).toBeHidden();
		const finished = page.waitForResponse( draftRoute );
		await respond();
		await finished;
		await expect( submit ).toBeEnabled();
		await expect(
			page.locator( '.wpai-comment-draft-status' )
		).toBeEmpty();
		await expect( page.locator( '.comment-content' ) ).toHaveCount( 0 );
	} );

	test( 'lets a reader submit when the draft check times out', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await createPost( requestUtils );
		let calls = 0;
		await page.route( route, () => {
			calls++;
			// Leave the request unanswered to exercise the browser timeout.
		} );
		await page.goto( post.link );
		await page.locator( '#comment' ).fill( constructive );
		await page.getByRole( 'button', { name: 'Post Comment' } ).click();
		await expect(
			page.locator( '.wpai-comment-draft-loading' )
		).toContainText( 'Checking your comment' );
		await expect(
			page.locator( '.comment-content' ).getByText( constructive )
		).toBeVisible();
		expect( calls ).toBe( 1 );
	} );

	test( 'gives each queued check its own bounded timeout', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await createPost( requestUtils );
		// Neither request responds. Each check gets four seconds, including the revised text.
		await page.route( route, () => {} );
		await page.goto( post.link );
		const first = page.waitForRequest( draftRoute );
		const comment = page.locator( '#comment' );
		await comment.fill( toxic );
		await first;
		await comment.fill( constructive );
		await page.getByRole( 'button', { name: 'Post Comment' } ).click();
		await expect(
			page.locator( '.comment-content' ).getByText( constructive )
		).toBeVisible( { timeout: 9500 } );
	} );

	test( 'does not post to a different thread when the reader cancels a reply during a check', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await createPost( requestUtils );
		await requestUtils.createComment( {
			content: 'A parent comment for the reply test.',
			post: post.id,
			status: 'approve',
		} );
		let respond;
		await page.route( route, ( draftRequest ) => {
			return new Promise( ( resolve ) => {
				respond = async () => {
					await draftRequest.fulfill( { json: { blocked: false } } );
					resolve();
				};
			} );
		} );
		await page.goto( post.link );
		await page.getByRole( 'link', { name: /reply to/i } ).click();
		const requested = page.waitForRequest( draftRoute );
		await page.locator( '#comment' ).fill( constructive );
		const submit = page.getByRole( 'button', { name: 'Post Comment' } );
		await submit.click();
		await requested;
		await page.getByRole( 'link', { name: /cancel reply/i } ).click();
		await respond();
		await expect(
			page.locator( '.wpai-comment-draft-status' )
		).toBeEmpty();
		await expect( page.locator( '#comment' ) ).toHaveValue( constructive );
		await expect( page.locator( '.comment-content' ) ).toHaveCount( 1 );
		await submit.click();
		await expect(
			page.locator( '.comment-content' ).getByText( constructive )
		).toBeVisible();
	} );

	test( 'checks guest drafts in the core block form', async ( {
		browser,
		requestUtils,
	} ) => {
		await requestUtils.activateTheme( 'twentytwentyfive' );
		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		try {
			const page = await context.newPage();
			const post = await createPost( requestUtils );
			await page.goto( post.link );
			await page.locator( '#comment' ).fill( toxic );
			await expect(
				page.locator( '.wpai-comment-draft-status' )
			).toContainText( 'Please revise your comment' );
			await expect(
				page.getByRole( 'button', { name: 'Post Comment' } )
			).toBeDisabled();
		} finally {
			await context.close();
			await requestUtils.activateTheme( 'twentytwentyone' );
		}
	} );

	test( 'leaves the comment form alone when draft checks are off', async ( {
		page,
		requestUtils,
	} ) => {
		await updateSettings( requestUtils, { [ setting ]: false } );
		const post = await createPost( requestUtils );
		await page.goto( post.link );
		await expect(
			page.getByText( /sent to this site’s AI provider/i )
		).toHaveCount( 0 );
		await page.locator( '#comment' ).fill( toxic );
		await page.getByRole( 'button', { name: 'Post Comment' } ).click();
		await expect(
			page.getByText( /your comment is awaiting moderation/i )
		).toBeVisible();
	} );

	test( 'saves the draft settings from the AI settings screen', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await updateSettings( requestUtils, { [ setting ]: false } );
		await visitSettingsPage( admin );
		await enableAdvancedSettings( page );
		try {
			const toggle = page.getByRole( 'checkbox', {
				name: 'Check comments before submission',
			} );
			await expect( toggle ).not.toBeChecked();
			await toggle.check();
			await expect(
				page.getByRole( 'spinbutton', {
					name: 'Draft toxicity threshold (%)',
				} )
			).toHaveCount( 0 );
			await page
				.getByRole( 'button', {
					name: 'Save Comment Moderation settings',
				} )
				.click();
			await expect(
				page
					.locator( '.components-snackbar__content' )
					.getByText( 'Comment Moderation settings saved.' )
			).toBeVisible();
			const settings = await requestUtils.rest( {
				path: '/wp/v2/settings',
			} );
			expect( settings[ setting ] ).toBe( true );
		} finally {
			await disableAdvancedSettings( page );
		}
	} );

	test( 'finishes checking revised text even when an older check used most of the wait', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await createPost( requestUtils );
		const drafts = [];
		await page.route( route, async ( draftRequest ) => {
			const text = draftRequest.request().postDataJSON().text;
			drafts.push( text );
			await new Promise( ( resolve ) =>
				setTimeout( resolve, text === constructive ? 3000 : 2000 )
			);
			await draftRequest.fulfill( { json: { blocked: text === toxic } } );
		} );
		await page.goto( post.link );
		const first = page.waitForRequest( draftRoute );
		await page.locator( '#comment' ).fill( constructive );
		await first;
		await page.locator( '#comment' ).fill( toxic );
		const submit = page.getByRole( 'button', { name: 'Post Comment' } );
		await submit.click();
		await expect(
			page.locator( '.wpai-comment-draft-loading' )
		).toBeVisible();
		await expect( submit ).toBeDisabled( { timeout: 8000 } );
		await expect( page.locator( '.comment-content' ) ).toHaveCount( 0 );
		expect( drafts ).toEqual( [ constructive, toxic ] );
	} );

	test( 'shows the spinner beside submit on a guest form with required fields', async ( {
		browser,
		requestUtils,
	} ) => {
		const post = await createPost( requestUtils );
		const context = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
			viewport: { width: 390, height: 520 },
		} );
		try {
			const page = await context.newPage();
			let respond;
			await page.route(
				route,
				( draftRequest ) =>
					new Promise( ( resolve ) => {
						respond = async () => {
							await draftRequest.fulfill( {
								json: { blocked: true },
							} );
							resolve();
						};
					} )
			);
			await page.goto( post.link );
			await page.locator( '#author' ).fill( 'Draft reviewer' );
			await page.locator( '#email' ).fill( 'draft-review@example.com' );
			await page.locator( '#comment' ).fill( toxic );
			const requested = page.waitForRequest( draftRoute );
			const submit = page.getByRole( 'button', { name: 'Post Comment' } );
			await submit.click();
			await requested;
			const loading = page.locator( '.wpai-comment-draft-loading' );
			await expect( loading ).toBeInViewport();
			await expect(
				loading.locator( '.wpai-comment-draft-spinner' )
			).toBeVisible();
			expect(
				await submit.evaluate(
					( button ) => button.nextElementSibling.className
				)
			).toBe( 'wpai-comment-draft-loading' );
			await respond();
			await expect( submit ).toBeDisabled();
			await expect( loading ).toBeHidden();
			await expect( page.locator( '#comment' ) ).toBeFocused();
		} finally {
			await context.close();
		}
	} );

	test( 'retries a failed background check when the reader submits', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await createPost( requestUtils );
		let calls = 0;
		await page.route( route, async ( draftRequest ) => {
			calls++;
			await draftRequest.fulfill(
				calls === 1 ? { status: 503 } : { json: { blocked: true } }
			);
		} );
		await page.goto( post.link );
		const response = page.waitForResponse( draftRoute );
		await page.locator( '#comment' ).fill( toxic );
		await response;
		const submit = page.getByRole( 'button', { name: 'Post Comment' } );
		await submit.click();
		await expect( submit ).toBeDisabled();
		expect( calls ).toBe( 2 );
	} );
} );

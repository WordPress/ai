/**
 * End-to-end coverage for AI Workspace proposals that change existing content.
 *
 * A post's title, excerpt or content, and the site title or tagline, are
 * changed in place, and a published post or the site's identity changes for
 * visitors at once. So the card shows the current value beside the new one,
 * lets the person edit the new one, and holds up when the edit is refused, when
 * the field changed in the meantime, and when the same chat is open twice.
 *
 * The fixtures name post 1, the "Hello world!" post a fresh install seeds,
 * which the E2E plugin puts back before each test.
 */
/**
 * External dependencies
 */
import type { Page, Route } from '@playwright/test';

/**
 * WordPress dependencies
 */
import {
	test,
	expect,
	type RequestUtils,
} from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	activateMockScenario,
	deactivateMockScenario,
	disableExperiment,
	enableExperiment,
	getMockScenarioReport,
	seedCredentials,
} from '../../utils/helpers';

const EXPERIMENT_LABEL = 'AI Workspace';
const WORKSPACE_PAGE = 'tools.php?page=ai-workspace';
const FIRST_POST = 1;
const NEW_TITLE = 'Welcome to the site';
const NEW_TAGLINE = 'Notes on getting started with WordPress';
const OLD_TAGLINE = 'Just another test site';

/**
 * Puts post 1 back as a fresh install seeds it.
 *
 * @param requestUtils The requestUtils fixture from the test context.
 */
async function resetFirstPost( requestUtils: RequestUtils ): Promise< void > {
	await requestUtils.rest( {
		path: '/ai-e2e/v1/first-post/reset',
		method: 'POST',
	} );
}

/**
 * Makes every post write on the server sleep, or stops it.
 *
 * @param requestUtils The requestUtils fixture from the test context.
 * @param seconds      How long each write sleeps; 0 for not at all.
 */
async function setWriteDelay(
	requestUtils: RequestUtils,
	seconds: number
): Promise< void > {
	await requestUtils.rest( {
		path: '/ai-e2e/v1/write-delay',
		method: 'POST',
		data: { seconds },
	} );
}

/**
 * Reads post 1's stored title, excerpt and content.
 *
 * @param requestUtils The requestUtils fixture from the test context.
 * @return The raw fields.
 */
async function firstPost(
	requestUtils: RequestUtils
): Promise< { title: string; excerpt: string; content: string } > {
	const post = ( await requestUtils.rest( {
		path: '/wp/v2/posts/' + FIRST_POST,
		params: { context: 'edit' },
	} ) ) as {
		title: { raw: string };
		excerpt: { raw: string };
		content: { raw: string };
	};

	return {
		title: post.title.raw,
		excerpt: post.excerpt.raw,
		content: post.content.raw,
	};
}

/**
 * Reads the site tagline.
 *
 * @param requestUtils The requestUtils fixture from the test context.
 * @return The tagline.
 */
async function tagline( requestUtils: RequestUtils ): Promise< string > {
	const settings = ( await requestUtils.rest( {
		path: '/wp/v2/settings',
	} ) ) as { description: string };

	return settings.description;
}

/**
 * Sets the site tagline.
 *
 * @param requestUtils The requestUtils fixture from the test context.
 * @param value        The tagline.
 */
async function setTagline(
	requestUtils: RequestUtils,
	value: string
): Promise< void > {
	await requestUtils.rest( {
		path: '/wp/v2/settings',
		method: 'POST',
		data: { description: value },
	} );
}

/**
 * Deletes every chat the current user has, active and archived.
 *
 * @param requestUtils The requestUtils fixture from the test context.
 */
async function deleteAllChats( requestUtils: RequestUtils ): Promise< void > {
	for ( const archived of [ false, true ] ) {
		const list = ( await requestUtils.rest( {
			path: '/ai/v1/workspace/conversations',
			params: { archived, per_page: 50 },
		} ) ) as { conversations: Array< { id: string } > };

		for ( const chat of list.conversations ) {
			await requestUtils.rest( {
				path: '/ai/v1/workspace/conversations/' + chat.id,
				method: 'DELETE',
			} );
		}
	}
}

/**
 * Asks for a change and waits for its card.
 *
 * @param page    The page.
 * @param message The message to send.
 * @param heading The card's name.
 * @return The card.
 */
async function propose( page: Page, message: string, heading: string ) {
	await page.getByLabel( 'Message', { exact: true } ).fill( message );
	await page.getByRole( 'button', { name: 'Send' } ).click();

	const card = page.getByRole( 'region', { name: heading } );
	await expect( card ).toBeVisible( { timeout: 30000 } );
	await expect( page ).toHaveURL( /[?&]chat=[A-Za-z0-9-]+/ );

	return card;
}

/**
 * Returns one item of an update card, by its label.
 *
 * @param card  The card.
 * @param label The item's label, such as `Title of “Hello world!”`.
 * @return The item.
 */
function cardItem( card: ReturnType< Page[ 'getByRole' ] >, label: string ) {
	return card.getByRole( 'group', { name: label } );
}

test.describe( 'AI Workspace update proposals', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await resetFirstPost( requestUtils );
		await seedCredentials( requestUtils, [ 'anthropic' ] );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
		await deleteAllChats( requestUtils );
	} );

	test.afterEach( async ( { admin, page, requestUtils } ) => {
		await page.unrouteAll( { behavior: 'ignoreErrors' } );
		await setWriteDelay( requestUtils, 0 );
		await deactivateMockScenario( requestUtils );
		await deleteAllChats( requestUtils );
		await disableExperiment( admin, page, EXPERIMENT_LABEL );
		await seedCredentials( requestUtils, [ 'openai' ] );
		await resetFirstPost( requestUtils );
		await setTagline( requestUtils, '' );
	} );

	test( 'shows the current title beside the new one, and writes the edited title', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await activateMockScenario( requestUtils, 'workspace-post-changes' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		const card = await propose(
			page,
			'Give my first post a clearer title.',
			'Review 1 change'
		);

		// Proposing ended the turn and wrote nothing.
		expect(
			( await getMockScenarioReport( requestUtils ) ).calls[
				'Anthropic/workspace-post-changes'
			]
		).toBe( 1 );
		expect( ( await firstPost( requestUtils ) ).title ).toBe(
			'Hello world!'
		);

		const item = cardItem( card, 'Title of “Hello world!”' );
		await expect(
			item.locator( '.ai-workspace__change-current' )
		).toHaveText( /Hello world!/ );
		await expect( item.locator( '.ai-workspace__change-new' ) ).toHaveText(
			new RegExp( NEW_TITLE )
		);

		// The post is published, so the card says the change goes live.
		await expect( item ).toContainText( 'live for visitors' );

		// Edit is reachable by keyboard, and moves focus into the field.
		await item.getByRole( 'button', { name: /^Edit/ } ).focus();
		await page.keyboard.press( 'Enter' );
		const field = item.getByRole( 'textbox', { name: 'New title' } );
		await expect( field ).toBeFocused();
		await expect( field ).toHaveValue( NEW_TITLE );
		await field.fill( 'Welcome, edited by hand' );

		// The current title stays in view for comparison.
		await expect(
			item.locator( '.ai-workspace__change-current' )
		).toHaveText( /Hello world!/ );

		await card.getByRole( 'button', { name: 'Apply 1 change' } ).click();

		const done = page.getByRole( 'region', { name: 'Updated 1 change' } );
		await expect( done ).toBeVisible();
		await expect(
			done.getByRole( 'link', { name: /^Open/ } )
		).toHaveAttribute( 'href', /post\.php\?post=1&action=edit/ );

		// What was written is the edit, not the model's value.
		expect( ( await firstPost( requestUtils ) ).title ).toBe(
			'Welcome, edited by hand'
		);
	} );

	test( 'shows the server message beside an edit that is too long, and stays editable', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await activateMockScenario( requestUtils, 'workspace-post-changes' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		const card = await propose(
			page,
			'Give my first post a clearer title.',
			'Review 1 change'
		);
		const item = cardItem( card, 'Title of “Hello world!”' );

		await item.getByRole( 'button', { name: /^Edit/ } ).click();
		const field = item.getByRole( 'textbox', { name: 'New title' } );
		await field.fill( 'x'.repeat( 501 ) );
		await card.getByRole( 'button', { name: 'Apply 1 change' } ).click();

		await expect( item ).toContainText(
			'A title can be at most 500 characters long.'
		);
		await expect( field ).toBeEditable();
		await expect(
			card.getByRole( 'button', { name: 'Apply 1 change' } )
		).toBeEnabled();
		expect( ( await firstPost( requestUtils ) ).title ).toBe(
			'Hello world!'
		);

		// Fixing the edit lets it through.
		await field.fill( 'A shorter title' );
		await card.getByRole( 'button', { name: 'Apply 1 change' } ).click();
		await expect(
			page.getByRole( 'region', { name: 'Updated 1 change' } )
		).toBeVisible();
		expect( ( await firstPost( requestUtils ) ).title ).toBe(
			'A shorter title'
		);
	} );

	test( 'a second page opened after approval shows the outcome and no Apply', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await activateMockScenario( requestUtils, 'workspace-post-changes' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		const card = await propose(
			page,
			'Give my first post a clearer title.',
			'Review 1 change'
		);
		await card.getByRole( 'button', { name: 'Apply 1 change' } ).click();
		await expect(
			page.getByRole( 'region', { name: 'Updated 1 change' } )
		).toBeVisible();
		await expect( page.locator( '.ai-workspace__turn' ) ).toHaveCount( 2 );

		const second = await page.context().newPage();
		await second.goto( page.url() );

		await expect(
			second.getByRole( 'region', { name: 'Updated 1 change' } )
		).toBeVisible( { timeout: 30000 } );
		await expect(
			second.getByRole( 'button', { name: /^Apply/ } )
		).toHaveCount( 0 );

		await second.close();
	} );

	test( 'approving in a second page while the first is still writing waits for its outcome', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await activateMockScenario( requestUtils, 'workspace-post-changes' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		const card = await propose(
			page,
			'Give my first post a clearer title.',
			'Review 1 change'
		);

		const second = await page.context().newPage();
		await second.goto( page.url() );
		const secondCard = second.getByRole( 'region', {
			name: 'Review 1 change',
		} );
		await expect(
			secondCard.getByRole( 'button', { name: 'Apply 1 change' } )
		).toBeVisible( { timeout: 30000 } );

		// The first page's write takes a while on the server, and its answer
		// is held back until the second page has had its say.
		await setWriteDelay( requestUtils, 3 );

		let release: () => void = () => {};
		const released = new Promise< void >( ( resolve ) => {
			release = resolve;
		} );

		await page.route( /\/execute/, async ( route: Route ) => {
			const response = await route.fetch( { timeout: 30000 } );
			await released;
			await route.fulfill( { response } );
		} );

		const sent = page.waitForRequest( /\/execute/ );
		await card.getByRole( 'button', { name: 'Apply 1 change' } ).click();
		await sent;

		await secondCard
			.getByRole( 'button', { name: 'Apply 1 change' } )
			.click();

		await expect(
			second.getByText( 'Applying in another window' )
		).toBeVisible();
		await expect(
			second.getByRole( 'region', { name: 'Updated 1 change' } )
		).toBeVisible( { timeout: 30000 } );

		release();

		await expect(
			page.getByRole( 'region', { name: 'Updated 1 change' } )
		).toBeVisible( { timeout: 30000 } );
		expect( ( await firstPost( requestUtils ) ).title ).toBe( NEW_TITLE );

		await second.close();
	} );

	test( 'an approval whose answer is lost after it wrote shows what was applied', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await activateMockScenario( requestUtils, 'workspace-post-changes' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		const card = await propose(
			page,
			'Give my first post a clearer title.',
			'Review 1 change'
		);

		// The write reaches the server and lands, but the answer is a server error.
		await page.route( /\/execute/, async ( route: Route ) => {
			await route.fetch( { timeout: 30000 } );
			await route.fulfill( {
				status: 500,
				contentType: 'application/json',
				body: '{}',
			} );
		} );

		await card.getByRole( 'button', { name: 'Apply 1 change' } ).click();

		await expect(
			page.getByRole( 'region', { name: 'Updated 1 change' } )
		).toBeVisible( { timeout: 30000 } );
		await expect( page.getByText( 'Nothing was changed' ) ).toHaveCount(
			0
		);
		expect( ( await firstPost( requestUtils ) ).title ).toBe( NEW_TITLE );
	} );

	test( 'discarding a change another page already applied shows what was applied', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await activateMockScenario( requestUtils, 'workspace-post-changes' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		const card = await propose(
			page,
			'Give my first post a clearer title.',
			'Review 1 change'
		);

		const second = await page.context().newPage();
		await second.goto( page.url() );
		const secondCard = second.getByRole( 'region', {
			name: 'Review 1 change',
		} );
		await secondCard
			.getByRole( 'button', { name: 'Apply 1 change' } )
			.click( { timeout: 30000 } );
		await expect(
			second.getByRole( 'region', { name: 'Updated 1 change' } )
		).toBeVisible( { timeout: 30000 } );

		// The first page still shows the card, and its discard is refused.
		await card.getByRole( 'button', { name: 'Discard' } ).click();

		await expect(
			page.getByRole( 'region', { name: 'Updated 1 change' } )
		).toBeVisible( { timeout: 30000 } );
		await expect( page.getByText( 'could not be discarded' ) ).toHaveCount(
			0
		);
		expect( ( await firstPost( requestUtils ) ).title ).toBe( NEW_TITLE );

		await second.close();
	} );

	test( 'a field that changed since it was proposed is left alone and links to the post', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await activateMockScenario( requestUtils, 'workspace-post-changes' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		const card = await propose(
			page,
			'Give my first post a clearer title.',
			'Review 1 change'
		);

		// Someone changes the title after the proposal was made.
		await requestUtils.rest( {
			path: '/wp/v2/posts/' + FIRST_POST,
			method: 'POST',
			data: { title: 'Changed elsewhere' },
		} );

		await card.getByRole( 'button', { name: 'Apply 1 change' } ).click();

		const done = page.getByRole( 'region', {
			name: 'Nothing was changed',
		} );
		await expect( done ).toContainText( 'Changed since proposed' );
		await expect(
			done.getByRole( 'link', { name: /^Open/ } )
		).toHaveAttribute( 'href', /post\.php\?post=1&action=edit/ );
		expect( ( await firstPost( requestUtils ) ).title ).toBe(
			'Changed elsewhere'
		);
	} );

	test( 'deselecting one of three changes applies the other two', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await activateMockScenario(
			requestUtils,
			'workspace-post-changes-three'
		);
		await admin.visitAdminPage( WORKSPACE_PAGE );

		const card = await propose(
			page,
			'Tidy up my first post.',
			'Review 3 changes'
		);

		// Content is rendered, not shown as markup, on both sides.
		const content = cardItem( card, 'Content of “Hello world!”' );
		await expect(
			content.locator( '.ai-workspace__change-current' )
		).toContainText( 'Welcome to WordPress. This is your first post.' );
		await expect(
			content.locator( '.ai-workspace__change-new strong' )
		).toHaveText( 'the first post' );
		await expect( content ).not.toContainText( '<!-- wp:paragraph' );
		await expect( content ).not.toContainText( '<p>' );

		// Editing the content shows the markup with a live preview under it.
		await content.getByRole( 'button', { name: /^Edit/ } ).click();
		const markup = content.getByRole( 'textbox', { name: 'New content' } );
		await expect( markup ).toHaveValue(
			/<strong>the first post<\/strong>/
		);
		await markup.fill(
			'<!-- wp:paragraph -->\n<p>An <em>edited</em> opening.</p>\n<!-- /wp:paragraph -->'
		);
		await expect(
			content.locator( '.ai-workspace__change-preview em' )
		).toHaveText( 'edited' );

		await expect(
			card.getByRole( 'button', { name: 'Apply 3 changes' } )
		).toBeVisible();
		await card
			.getByRole( 'checkbox', { name: 'Excerpt of “Hello world!”' } )
			.uncheck();
		await card.getByRole( 'button', { name: 'Apply 2 changes' } ).click();

		const done = page.getByRole( 'region', { name: 'Updated 2 changes' } );
		await expect( done ).toBeVisible();
		await expect( done ).toContainText( 'Not selected' );

		const post = await firstPost( requestUtils );
		expect( post.title ).toBe( NEW_TITLE );
		expect( post.content ).toContain( 'An <em>edited</em> opening.' );
		expect( post.excerpt ).toBe( '' );
	} );

	test( 'a reopened chat shows a discarded change as discarded', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await activateMockScenario( requestUtils, 'workspace-post-changes' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		const card = await propose(
			page,
			'Give my first post a clearer title.',
			'Review 1 change'
		);
		await card.getByRole( 'button', { name: 'Discard' } ).click();
		await expect(
			page.locator( '.components-notice__content', {
				hasText: 'Discarded. Nothing was changed.',
			} )
		).toBeVisible();
		await expect( page.locator( '.ai-workspace__turn' ) ).toHaveCount( 2 );

		await page.goto( page.url() );

		await expect(
			page.getByRole( 'region', { name: 'Discarded' } )
		).toBeVisible( { timeout: 30000 } );
		await expect(
			page.getByRole( 'button', { name: /^Apply/ } )
		).toHaveCount( 0 );
		expect( ( await firstPost( requestUtils ) ).title ).toBe(
			'Hello world!'
		);
	} );

	test( 'shows the current tagline beside the new one, and approving changes it', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setTagline( requestUtils, OLD_TAGLINE );
		await activateMockScenario( requestUtils, 'workspace-tagline' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		const card = await propose(
			page,
			'Make my tagline fit what I write about.',
			'Review 1 change'
		);

		const item = cardItem( card, 'Tagline' );
		await expect(
			item.locator( '.ai-workspace__change-current' )
		).toHaveText( new RegExp( OLD_TAGLINE ) );
		await expect( item.locator( '.ai-workspace__change-new' ) ).toHaveText(
			new RegExp( NEW_TAGLINE )
		);
		await expect( item ).toContainText( 'live for visitors' );
		expect( await tagline( requestUtils ) ).toBe( OLD_TAGLINE );

		await card.getByRole( 'button', { name: 'Apply 1 change' } ).click();

		const done = page.getByRole( 'region', { name: 'Updated 1 change' } );
		await expect( done ).toBeVisible();
		// The outcome keeps the new value once the side-by-side view is gone.
		await expect( done ).toContainText( `Now: ${ NEW_TAGLINE }` );
		await expect(
			done.getByRole( 'link', { name: /^Open/ } )
		).toHaveAttribute( 'href', /options-general\.php/ );
		expect( await tagline( requestUtils ) ).toBe( NEW_TAGLINE );
	} );
} );

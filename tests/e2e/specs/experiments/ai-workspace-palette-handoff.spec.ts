/**
 * Browser coverage for "Ask AI" from the command palette into the AI Workspace.
 *
 * When the palette finds nothing for a search, a workspace user is offered an
 * "Ask AI: <query>" row after any AI suggestions. Choosing it opens the
 * workspace with the query in the composer, unsent, and the query is removed
 * from the URL so a reload does not bring it back.
 */
/**
 * External dependencies
 */
import type { Browser, Page } from '@playwright/test';

/**
 * WordPress dependencies
 */
import {
	test,
	expect,
	RequestUtils,
} from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { disableExperiment, enableExperiment } from '../../utils/helpers';

const EXPERIMENT_LABEL = 'AI Workspace';
const AI_RESULT = '[cmdk-item][data-value^="ai-command-palette-ai-search-"]';
const ASK_AI_ROW =
	'[cmdk-item][data-value^="ai-command-palette-ai-search-ask-ai "]';

/**
 * Matches requests to the command palette AI search ability.
 *
 * @param url The request URL.
 * @return Whether the request runs the ability.
 */
const isAbilityRequest = ( url: URL ) =>
	decodeURIComponent( url.href ).includes(
		'ai/command-palette-ai-search/run'
	);

/**
 * Opens the command palette on the Dashboard and types a search.
 *
 * @param page   The page to search on.
 * @param search The text to type.
 */
async function searchPalette( page: Page, search: string ) {
	await page.goto( '/wp-admin/index.php' );
	await page.keyboard.press( 'ControlOrMeta+k' );

	const input = page.locator( '[cmdk-input]' );
	await expect( input ).toBeFocused();
	await input.fill( search );
}

/**
 * Searches the palette and waits for the AI request to finish.
 *
 * @param page   The page to search on.
 * @param search The text to type.
 */
async function searchPaletteAndWait( page: Page, search: string ) {
	const response = page.waitForResponse( ( candidate ) =>
		isAbilityRequest( new URL( candidate.url() ) )
	);

	await searchPalette( page, search );
	await response;
	await expect(
		page.getByRole( 'option', { name: 'Searching with AI…' } )
	).toBeHidden();
}

/**
 * Opens a page logged in as a new user with the given role.
 *
 * @param browser      The browser fixture.
 * @param requestUtils The request utils fixture.
 * @param role         The role.
 * @return The page, in its own browser context.
 */
async function openPageAs(
	browser: Browser,
	requestUtils: RequestUtils,
	role: string
): Promise< Page > {
	const username = `handoff-${ role }-${ Date.now() }`;
	const password = 'password';

	await requestUtils.createUser( {
		username,
		email: `${ username }@example.com`,
		password,
		roles: [ role ],
	} );

	const userRequestUtils = await RequestUtils.setup( {
		user: { username, password },
	} );

	await userRequestUtils.login();

	const context = await browser.newContext( {
		baseURL: requestUtils.baseURL,
		storageState: await userRequestUtils.request.storageState(),
	} );
	await userRequestUtils.request.dispose();

	return context.newPage();
}

test.describe( 'AI Workspace handoff from the command palette', () => {
	test.beforeEach( async ( { admin, page } ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test.afterEach( async ( { admin, page, requestUtils } ) => {
		await requestUtils.deleteAllUsers();
		await disableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'Ask AI opens the workspace with the query in the composer, unsent', async ( {
		page,
	} ) => {
		const query = 'which of my posts are missing featured images';

		await searchPaletteAndWait( page, query );

		const row = page.locator( ASK_AI_ROW );
		await expect( row ).toHaveText( `Ask AI: ${ query }`, {
			useInnerText: true,
		} );
		await expect( page.locator( AI_RESULT ).last() ).toHaveText(
			`Ask AI: ${ query }`,
			{
				useInnerText: true,
			}
		);

		await row.click();
		await page.waitForURL( /page=ai-workspace/ );

		await expect(
			page.getByLabel( 'Message', { exact: true } )
		).toHaveValue( query );
		await expect( page.locator( '.ai-workspace__turn' ) ).toHaveCount( 0 );
	} );

	test( 'After a reload, the composer is empty and the URL has no prompt', async ( {
		page,
	} ) => {
		await searchPaletteAndWait( page, 'summarise what I wrote last month' );

		await page.locator( ASK_AI_ROW ).click();
		await page.waitForURL( /page=ai-workspace/ );

		const composer = page.getByLabel( 'Message', { exact: true } );
		await expect( composer ).toHaveValue(
			'summarise what I wrote last month'
		);
		await expect.poll( () => page.url() ).not.toContain( 'wpai-prompt' );

		await page.reload();

		await expect( composer ).toBeVisible();
		await expect( composer ).toHaveValue( '' );
		expect( page.url() ).not.toContain( 'wpai-prompt' );
	} );

	test( 'Ask AI comes after the AI suggestions', async ( { page } ) => {
		await searchPaletteAndWait( page, 'how do I hide my site from google' );

		const rows = page.locator( AI_RESULT );
		await expect( rows ).toHaveCount( 2 );
		await expect( rows.first() ).toContainText( 'Settings > Reading' );
		await expect( rows.last() ).toHaveText(
			'Ask AI: how do I hide my site from google',
			{
				useInnerText: true,
			}
		);
	} );

	test( 'An editor sees AI suggestions but no Ask AI row', async ( {
		browser,
		requestUtils,
	} ) => {
		const editorPage = await openPageAs( browser, requestUtils, 'editor' );

		await searchPaletteAndWait( editorPage, 'how do I stop spam comments' );

		await expect( editorPage.locator( AI_RESULT ).first() ).toContainText(
			'Comments'
		);
		await expect( editorPage.locator( ASK_AI_ROW ) ).toHaveCount( 0 );

		await editorPage.context().close();
	} );

	test( 'A 2,000-character query is cut to the cap', async ( {
		admin,
		page,
	} ) => {
		/*
		 * Opened by URL: typing 2,000 characters into the palette makes its
		 * own fuzzy matching take minutes. The cap is the server's either way.
		 */
		await admin.visitAdminPage(
			'tools.php',
			`page=ai-workspace&wpai-prompt=${ 'a'.repeat( 2000 ) }`
		);

		await expect(
			page.getByLabel( 'Message', { exact: true } )
		).toHaveValue( 'a'.repeat( 500 ) );
	} );

	test( 'A query with markup arrives as plain text', async ( { page } ) => {
		await searchPaletteAndWait(
			page,
			'posts with <b>no</b> featured <img src=x onerror=alert(1)>image'
		);

		await page.locator( ASK_AI_ROW ).click();
		await page.waitForURL( /page=ai-workspace/ );

		await expect(
			page.getByLabel( 'Message', { exact: true } )
		).toHaveValue( 'posts with no featured image' );
	} );

	test( 'An empty or whitespace query shows no Ask AI row', async ( {
		page,
	} ) => {
		let requests = 0;
		await page.route( isAbilityRequest, async ( route ) => {
			requests++;
			await route.continue();
		} );

		await searchPalette( page, '      ' );

		// Longer than the loader's debounce.
		await page.waitForTimeout( 1000 );
		await expect( page.locator( ASK_AI_ROW ) ).toHaveCount( 0 );
		expect( requests ).toBe( 0 );
	} );

	test( 'When the AI request fails, a workspace user sees only the Ask AI row', async ( {
		page,
	} ) => {
		await page.route( isAbilityRequest, ( route ) =>
			route.fulfill( {
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify( {
					code: 'invalid_response',
					message: 'Could not parse the AI response.',
				} ),
			} )
		);

		await searchPaletteAndWait( page, 'how do I hide my site from google' );

		const rows = page.locator( AI_RESULT );
		await expect( rows ).toHaveCount( 1 );
		await expect( rows ).toHaveText(
			'Ask AI: how do I hide my site from google',
			{
				useInnerText: true,
			}
		);
		await expect( rows ).toHaveAttribute( 'aria-selected', 'true' );
	} );
} );

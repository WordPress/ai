/**
 * WordPress dependencies
 */
const {
	test,
	expect,
	RequestUtils,
} = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	enableExperiment,
} = require( '../../utils/helpers' );

// The palette search is part of the AI Workspace experiment.
const EXPERIMENT_LABEL = 'AI Workspace';
const AI_RESULT = '[cmdk-item][data-value^="ai-command-palette-ai-search-"]';

/**
 * Matches requests to the command palette AI search ability.
 *
 * The path is URL-encoded when the site uses plain permalinks.
 *
 * @param {URL} url The request URL.
 * @return {boolean} Whether the request runs the ability.
 */
const isAbilityRequest = ( url ) =>
	decodeURIComponent( url.href ).includes(
		'ai/command-palette-ai-search/run'
	);

/**
 * Opens the command palette on the Dashboard and types a search.
 *
 * @param {Object} page   The page to search on.
 * @param {string} search The text to type.
 * @return {Promise<Object>} The palette's search input.
 */
async function searchPalette( page, search ) {
	await page.goto( '/wp-admin/index.php' );
	await page.keyboard.press( 'ControlOrMeta+k' );

	const input = page.locator( '[cmdk-input]' );
	await expect( input ).toBeFocused();
	await input.fill( search );

	return input;
}

/**
 * Lists the command loaders registered on a page.
 *
 * @param {Object} page The page.
 * @return {Promise<string[]>} The loader names.
 */
const getLoaderNames = ( page ) =>
	page.evaluate( () =>
		window.wp.data
			.select( 'core/commands' )
			.getCommandLoaders()
			.map( ( loader ) => loader.name )
	);

/**
 * Opens a page logged in as a new user with the given role.
 *
 * The page has its own browser context, so the admin session the other
 * fixtures use is left alone.
 *
 * @param {Object} browser      The browser fixture.
 * @param {Object} requestUtils The request utils fixture.
 * @param {string} role         The role.
 * @return {Promise<Object>} The page.
 */
async function openPageAs( browser, requestUtils, role ) {
	const username = `palette-${ role }-${ Date.now() }`;
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

test.describe( 'Command Palette AI Search', () => {
	test.afterEach( async ( { admin, page, requestUtils } ) => {
		await requestUtils.deleteAllUsers();
		await disableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'Finds the right screen when the palette has no results', async ( {
		admin,
		page,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		let releaseRequest;
		const requestPromise = new Promise( ( resolve ) => {
			releaseRequest = resolve;
		} );

		await page.route( isAbilityRequest, async ( route ) => {
			await requestPromise;
			await route.continue();
		} );

		await searchPalette( page, 'how do I hide my site from google' );

		await expect(
			page.getByRole( 'option', { name: 'Searching with AI…' } )
		).toBeVisible();

		releaseRequest();

		const result = page.locator( AI_RESULT ).first();
		await expect( result ).toContainText( 'Settings > Reading' );
		await expect( result ).toHaveAttribute( 'aria-selected', 'true' );
		await expect(
			page.getByRole( 'option', { name: 'Searching with AI…' } )
		).toBeHidden();

		await page.keyboard.press( 'Enter' );
		await page.waitForURL( /options-reading\.php/ );
	} );

	test( 'Shows no AI suggestions when no screen fits', async ( {
		admin,
		page,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const responsePromise = page.waitForResponse( ( response ) =>
			isAbilityRequest( new URL( response.url() ) )
		);

		await searchPalette( page, 'how do I bake a cake' );

		const response = await responsePromise;
		expect( response.ok() ).toBe( true );

		await expect(
			page.getByRole( 'option', { name: 'Searching with AI…' } )
		).toBeHidden();
		await expect( page.locator( AI_RESULT ) ).toHaveCount( 0 );
	} );

	test( 'Does not search with AI when the palette has results', async ( {
		admin,
		page,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		let requests = 0;
		await page.route( isAbilityRequest, async ( route ) => {
			requests++;
			await route.continue();
		} );

		await searchPalette( page, 'reading' );

		await expect(
			page.getByRole( 'option', { name: /Settings > Reading/ } )
		).toBeVisible();
		await expect(
			page.getByRole( 'option', { name: 'Searching with AI…' } )
		).toBeHidden();

		// Longer than the loader's debounce.
		await page.waitForTimeout( 1000 );
		expect( requests ).toBe( 0 );
		await expect( page.locator( AI_RESULT ) ).toHaveCount( 0 );
	} );

	test( 'Shows the palette’s own empty state when the request fails', async ( {
		admin,
		browser,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const editorPage = await openPageAs( browser, requestUtils, 'editor' );

		await editorPage.route( isAbilityRequest, ( route ) =>
			route.fulfill( {
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify( {
					code: 'invalid_response',
					message: 'Could not parse the AI response.',
				} ),
			} )
		);

		const responsePromise = editorPage.waitForResponse( ( response ) =>
			isAbilityRequest( new URL( response.url() ) )
		);

		await searchPalette( editorPage, 'how do I hide my site from google' );
		await responsePromise;

		await expect(
			editorPage.getByRole( 'option', { name: 'Searching with AI…' } )
		).toBeHidden();
		await expect( editorPage.locator( AI_RESULT ) ).toHaveCount( 0 );
		await expect(
			editorPage.getByText( 'No results found.' )
		).toBeVisible();

		await editorPage.context().close();
	} );

	test( 'An editor gets AI suggestions', async ( {
		admin,
		browser,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const editorPage = await openPageAs( browser, requestUtils, 'editor' );

		await searchPalette( editorPage, 'how do I stop spam comments' );

		const result = editorPage.locator( AI_RESULT ).first();
		await expect( result ).toContainText( 'Comments' );
		await expect( result ).toHaveAttribute( 'aria-selected', 'true' );

		await editorPage.keyboard.press( 'Enter' );
		await editorPage.waitForURL( /edit-comments\.php/ );

		await editorPage.context().close();
	} );

	test( 'A subscriber gets no AI suggestions', async ( {
		admin,
		browser,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const subscriberPage = await openPageAs(
			browser,
			requestUtils,
			'subscriber'
		);

		await subscriberPage.goto( '/wp-admin/profile.php' );

		expect( await getLoaderNames( subscriberPage ) ).not.toContain(
			'ai/command-palette-ai-search'
		);

		await subscriberPage.context().close();
	} );

	test( 'Shows no AI row and makes no request when the experiment is disabled', async ( {
		admin,
		page,
	} ) => {
		await disableExperiment( admin, page, EXPERIMENT_LABEL );

		let requests = 0;
		await page.route( isAbilityRequest, async ( route ) => {
			requests++;
			await route.continue();
		} );

		await searchPalette( page, 'how do I hide my site from google' );

		expect( await getLoaderNames( page ) ).not.toContain(
			'ai/command-palette-ai-search'
		);

		// Longer than the loader's debounce.
		await page.waitForTimeout( 1000 );
		expect( requests ).toBe( 0 );
		await expect( page.locator( AI_RESULT ) ).toHaveCount( 0 );
	} );
} );

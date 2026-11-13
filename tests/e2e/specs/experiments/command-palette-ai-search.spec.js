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
 * Reads the search an ability request was sent for.
 *
 * @param {Object} request The request.
 * @return {string} The search.
 */
const getRequestQuery = ( request ) =>
	JSON.parse( request.postData() ?? '{}' ).input?.query ?? '';

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

		// An administrator still gets the Ask AI row, and nothing else.
		const rows = page.locator( AI_RESULT );
		await expect( rows ).toHaveCount( 1 );
		await expect( rows ).toHaveText(
			'Ask in AI Workspace: how do I bake a cake',
			{
				useInnerText: true,
			}
		);
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

	test( 'A one or two character search makes no request and shows no AI rows', async ( {
		admin,
		page,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		let requests = 0;
		await page.route( isAbilityRequest, async ( route ) => {
			requests++;
			await route.continue();
		} );

		// Characters no command label contains, so the palette has no results of its own
		// whichever experiments (and so admin screens) are on.
		const input = await searchPalette( page, '~' );
		await expect( page.getByText( 'No results found.' ) ).toBeVisible();

		await input.fill( '~^' );
		await expect( page.getByText( 'No results found.' ) ).toBeVisible();

		// Longer than the loader's debounce.
		await page.waitForTimeout( 1000 );
		expect( requests ).toBe( 0 );
		await expect( page.locator( AI_RESULT ) ).toHaveCount( 0 );
	} );

	test( 'Only the last search is sent and answered when the search changes', async ( {
		admin,
		page,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const queries = [];
		const labels = [];
		let releaseFirst;
		const firstHeld = new Promise( ( resolve ) => {
			releaseFirst = resolve;
		} );
		let firstSent;
		const firstArrived = new Promise( ( resolve ) => {
			firstSent = resolve;
		} );

		// Each search is answered with a different command from the list it sent.
		await page.route( isAbilityRequest, async ( route ) => {
			const request = route.request();
			const { commands } = JSON.parse( request.postData() ).input;
			const index = queries.length;
			queries.push( getRequestQuery( request ) );
			labels.push( commands[ index ].label );

			if ( 0 === index ) {
				firstSent();
				await firstHeld;
			}

			try {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						commands: [ commands[ index ].name ],
					} ),
				} );
			} catch {
				// The palette dropped this request when the search changed.
			}
		} );

		// Typed over before the debounce runs out, so never sent.
		const input = await searchPalette( page, 'how do I bake a pie' );
		await page.waitForTimeout( 100 );
		await input.fill( 'how do I bake a cake' );

		// Changed while its request is still waiting for an answer.
		await firstArrived;
		await expect(
			page.getByRole( 'option', { name: 'Searching with AI…' } )
		).toBeVisible();
		const responsePromise = page.waitForResponse(
			( response ) =>
				isAbilityRequest( new URL( response.url() ) ) &&
				'how do I bake a pizza' ===
					getRequestQuery( response.request() )
		);
		await input.fill( 'how do I bake a pizza' );
		await responsePromise;
		releaseFirst();

		const rows = page.locator( AI_RESULT );
		await expect( rows ).toHaveCount( 2 );
		await expect( rows.first() ).toHaveText( labels[ 1 ], {
			useInnerText: true,
		} );
		await expect( rows.last() ).toHaveText(
			'Ask in AI Workspace: how do I bake a pizza',
			{ useInnerText: true }
		);

		// Give the first answer time to arrive, then check it was not shown.
		await page.waitForTimeout( 500 );
		expect( queries ).toEqual( [
			'how do I bake a cake',
			'how do I bake a pizza',
		] );
		await expect( rows ).toHaveCount( 2 );
		await expect( rows.first() ).toHaveText( labels[ 1 ], {
			useInnerText: true,
		} );
		await expect(
			page.getByRole( 'option', { name: 'Searching with AI…' } )
		).toBeHidden();
	} );

	test( 'Drops an answer that names a command the palette did not send', async ( {
		admin,
		page,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		let sentLabel = '';
		await page.route( isAbilityRequest, async ( route ) => {
			const { commands } = JSON.parse( route.request().postData() ).input;
			sentLabel = commands[ 0 ].label;

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					commands: [ 'made-up/command', commands[ 0 ].name ],
				} ),
			} );
		} );

		const responsePromise = page.waitForResponse( ( response ) =>
			isAbilityRequest( new URL( response.url() ) )
		);

		await searchPalette( page, 'how do I hide my site from google' );
		await responsePromise;

		const rows = page.locator( AI_RESULT );
		await expect( rows ).toHaveCount( 2 );
		await expect( rows.first() ).toHaveText( sentLabel, {
			useInnerText: true,
		} );
		await expect( rows.last() ).toHaveText(
			'Ask in AI Workspace: how do I hide my site from google',
			{ useInnerText: true }
		);
	} );

	test( 'Waits for slow post results before searching with AI', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const title = 'Quarterly lighthouse keeper roundup';
		const post = await requestUtils.createPost( {
			title,
			status: 'publish',
		} );

		// The palette's own post and page searches answer slowly.
		await page.route(
			( url ) => {
				const href = decodeURIComponent( url.href );
				return (
					/\/wp\/v2\/(posts|pages)/.test( href ) &&
					href.includes( 'search=' )
				);
			},
			async ( route ) => {
				await new Promise( ( resolve ) => setTimeout( resolve, 800 ) );
				await route.continue();
			}
		);

		let requests = 0;
		await page.route( isAbilityRequest, async ( route ) => {
			requests++;
			await route.continue();
		} );

		await searchPalette( page, title.toLowerCase() );

		await expect(
			page.getByRole( 'option', { name: title } )
		).toBeVisible();

		// Longer than the loader's debounce and its wait for other loaders.
		await page.waitForTimeout( 2500 );
		expect( requests ).toBe( 0 );
		await expect( page.locator( AI_RESULT ) ).toHaveCount( 0 );

		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/posts/${ post.id }`,
			params: { force: true },
		} );
	} );

	test( 'A search longer than the ability accepts shows only Ask AI', async ( {
		admin,
		page,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		let requests = 0;
		await page.route( isAbilityRequest, async ( route ) => {
			requests++;
			await route.continue();
		} );

		const search = 'zq '.repeat( 67 ).trim() + 'z';
		expect( search.length ).toBe( 201 );

		await searchPalette( page, search );

		const rows = page.locator( AI_RESULT );
		await expect( rows ).toHaveCount( 1 );
		await expect( rows ).toHaveText( `Ask in AI Workspace: ${ search }`, {
			useInnerText: true,
		} );
		expect( requests ).toBe( 0 );
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

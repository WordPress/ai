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

const EXPERIMENT_LABEL = 'Command Palette AI Search';

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
 * @param {Object} admin     The admin fixture.
 * @param {Object} page      The page fixture.
 * @param {Object} pageUtils The page utils fixture.
 * @param {string} search    The text to type.
 * @return {Promise<Object>} The palette's search input.
 */
async function searchPalette( admin, page, pageUtils, search ) {
	await admin.visitAdminPage( 'index.php' );
	await pageUtils.pressKeys( 'primary+k' );

	const input = page.locator( '[cmdk-input]' );
	await expect( input ).toBeFocused();
	await input.fill( search );

	return input;
}

test.describe( 'Command Palette AI Search Experiment', () => {
	test( 'Can enable the command palette AI search experiment', async ( {
		admin,
		page,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'Finds the right screen when the palette has no results', async ( {
		admin,
		page,
		pageUtils,
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

		await searchPalette(
			admin,
			page,
			pageUtils,
			'how do I hide my site from google'
		);

		await expect(
			page.getByRole( 'option', { name: 'Searching with AI…' } )
		).toBeVisible();

		releaseRequest();

		const result = page.locator(
			'[cmdk-item][data-value^="ai-command-palette-ai-search-"]'
		);
		await expect( result ).toHaveCount( 1 );
		await expect( result ).toContainText( 'Settings > Reading' );
		await expect( result ).toHaveAttribute( 'aria-selected', 'true' );
		await expect(
			page.getByRole( 'option', { name: 'Searching with AI…' } )
		).toBeHidden();

		await page.keyboard.press( 'Enter' );
		await page.waitForURL( /options-reading\.php/ );
	} );

	test( 'Shows no AI results when no screen fits', async ( {
		admin,
		page,
		pageUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const responsePromise = page.waitForResponse( ( response ) =>
			isAbilityRequest( new URL( response.url() ) )
		);

		await searchPalette( admin, page, pageUtils, 'how do I bake a cake' );

		const response = await responsePromise;
		expect( response.ok() ).toBe( true );

		await expect(
			page.getByRole( 'option', { name: 'Searching with AI…' } )
		).toBeHidden();
		await expect(
			page.locator(
				'[cmdk-item][data-value^="ai-command-palette-ai-search-"]'
			)
		).toHaveCount( 0 );
	} );

	test( 'Does not search with AI when the palette has results', async ( {
		admin,
		page,
		pageUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		let requests = 0;
		await page.route( isAbilityRequest, async ( route ) => {
			requests++;
			await route.continue();
		} );

		await searchPalette( admin, page, pageUtils, 'reading' );

		await expect(
			page.getByRole( 'option', { name: /Settings > Reading/ } )
		).toBeVisible();
		await expect(
			page.getByRole( 'option', { name: 'Searching with AI…' } )
		).toBeHidden();

		// Longer than the loader's debounce.
		await page.waitForTimeout( 1000 );
		expect( requests ).toBe( 0 );
	} );

	test( 'Hides the AI row when the request fails', async ( {
		admin,
		page,
		pageUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

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

		await searchPalette(
			admin,
			page,
			pageUtils,
			'how do I hide my site from google'
		);

		await expect(
			page.getByRole( 'option', { name: 'Searching with AI…' } )
		).toBeHidden();
		await expect(
			page.locator(
				'[cmdk-item][data-value^="ai-command-palette-ai-search-"]'
			)
		).toHaveCount( 0 );
		await expect( page.locator( '[cmdk-input]' ) ).toBeVisible();
	} );

	test( 'Does not load when the experiment is disabled', async ( {
		admin,
		page,
	} ) => {
		await disableExperiment( admin, page, EXPERIMENT_LABEL );

		await admin.visitAdminPage( 'index.php' );

		const loaders = await page.evaluate( () =>
			window.wp.data
				.select( 'core/commands' )
				.getCommandLoaders()
				.map( ( loader ) => loader.name )
		);

		expect( loaders ).not.toContain( 'ai/command-palette-ai-search' );
	} );
} );

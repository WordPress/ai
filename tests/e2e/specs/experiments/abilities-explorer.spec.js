/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const PAGE_QUERY = 'page=ai-abilities-explorer';
const VIEW_STORAGE_KEY = 'ai.abilitiesExplorer.view';
const FIELD_PLUGIN = 'e2e-abilities-explorer-field';
const FIXTURE_CATEGORY = 'E2E Fixtures & Friends';

const REMOVED = 'Ability removed from the assistant.';
const RETURNED = 'Ability returned to the assistant.';
const POLICY_OFF =
	'Assistant admission policy switched off. Only the built-in abilities are offered.';
const POLICY_ON = 'Assistant admission policy switched on.';
const WITHHELD_REASON =
	'Held back by this plugin: it reads personal data, settings or environment detail.';
const POLICY_OFF_REASON =
	'The assistant admission policy is off, so only the built-in abilities are offered.';

/**
 * Sets the plugin's feature switches through the settings REST route.
 *
 * @param {Object} requestUtils The requestUtils fixture.
 * @param {Object} settings     Map of option name to boolean.
 */
const setSettings = ( requestUtils, settings ) =>
	requestUtils.rest( {
		path: '/wp/v2/settings',
		method: 'POST',
		data: settings,
	} );

/**
 * Switches the E2E Testing plugin's Explorer fixtures, and clears the
 * assistant's owner exclusions and policy switch either way.
 *
 * @param {Object}  requestUtils The requestUtils fixture.
 * @param {boolean} enabled      Whether the fixtures are registered.
 */
const setFixtures = ( requestUtils, enabled ) =>
	requestUtils.rest( {
		path: '/ai-e2e/v1/explorer-fixtures',
		method: 'POST',
		data: { enabled },
	} );

/**
 * Reads the Explorer's list route, which reports the stored surface state.
 *
 * @param {Object} requestUtils The requestUtils fixture.
 * @return {Promise<Object>} The list payload.
 */
const readList = ( requestUtils ) =>
	requestUtils.rest( { path: '/ai/v1/abilities', method: 'GET' } );

/**
 * Returns one row of the list payload.
 *
 * @param {Object} list The list payload.
 * @param {string} slug The ability name.
 * @return {Object|undefined} The row.
 */
const payloadRow = ( list, slug ) =>
	list.items.find( ( item ) => item.slug === slug );

/**
 * Matches requests to one of the Explorer's REST routes, whether the site uses
 * pretty permalinks (`/wp-json/ai/v1/...`) or plain ones (`?rest_route=...`).
 *
 * @param {string} route The route path, such as `/ai/v1/abilities/surface`.
 * @return {Function} A URL predicate for `page.route()` and `waitForResponse()`.
 */
const restRoute = ( route ) => {
	const pattern = new RegExp(
		`${ route.replace( /\//g, '\\/' ) }(?:[?&]|$)`
	);

	return ( url ) => pattern.test( decodeURIComponent( url.toString() ) );
};

/**
 * Opens the Explorer, with optional extra query arguments.
 *
 * @param {Object} admin The admin fixture.
 * @param {Object} page  The page.
 * @param {string} extra Extra query arguments, without a leading `&`.
 */
const openExplorer = async ( admin, page, extra = '' ) => {
	await admin.visitAdminPage(
		'tools.php',
		extra ? `${ PAGE_QUERY }&${ extra }` : PAGE_QUERY
	);
};

/**
 * The data rows of the ability table.
 *
 * @param {Object} page The page.
 * @return {Object} The rows locator.
 */
const dataRows = ( page ) =>
	page
		.getByRole( 'table' )
		.getByRole( 'row' )
		.filter( { has: page.getByRole( 'cell' ) } );

/**
 * One data row, found by the text it contains.
 *
 * @param {Object} page The page.
 * @param {string} text Text in the row, such as the ability label.
 * @return {Object} The row locator.
 */
const row = ( page, text ) => dataRows( page ).filter( { hasText: text } );

/**
 * A filter chip. The chips precede the table, whose column headers carry the
 * same names, so the first match is the chip.
 *
 * @param {Object} page  The page.
 * @param {string} label The filter's field label.
 * @return {Object} The chip locator.
 */
const filterChip = ( page, label ) =>
	page.getByRole( 'button', { name: new RegExp( `^${ label }` ) } ).first();

/**
 * Picks one option from a filter.
 *
 * @param {Object} page   The page.
 * @param {string} label  The filter's field label.
 * @param {string} option The option to pick.
 */
const pickFilter = async ( page, label, option ) => {
	await filterChip( page, label ).click();
	await page
		.getByRole( 'listbox', { name: `List of: ${ label }` } )
		.getByRole( 'option', { name: option, exact: true } )
		.click();
	await page.keyboard.press( 'Escape' );
};

/**
 * Opens a column's header menu and picks an item.
 *
 * @param {Object} page   The page.
 * @param {string} column The column label.
 * @param {string} item   The menu item.
 */
const columnMenu = async ( page, column, item ) => {
	await page
		.getByRole( 'table' )
		.getByRole( 'button', { name: column, exact: true } )
		.click();
	const menuItem = page
		.getByRole( 'menuitemradio', { name: item } )
		.or( page.getByRole( 'menuitem', { name: item } ) );

	await menuItem.click();

	// Sorting leaves the menu open; close it so it cannot cover the next control.
	if ( await menuItem.isVisible() ) {
		await page.keyboard.press( 'Escape' );
	}
	await expect( menuItem ).toBeHidden();
};

/**
 * The confirmation snackbar with the given text.
 *
 * @param {Object} page The page.
 * @param {string} text The message.
 * @return {Object} The snackbar locator.
 */
const snackbar = ( page, text ) =>
	page.getByTestId( 'snackbar' ).filter( { hasText: text } );

/**
 * Reads the saved view from local storage.
 *
 * @param {Object} page The page.
 * @return {Promise<Object|null>} The stored record.
 */
const savedView = ( page ) =>
	page.evaluate(
		( key ) => JSON.parse( window.localStorage.getItem( key ) ?? 'null' ),
		VIEW_STORAGE_KEY
	);

/**
 * Searches the list, so a row is on the first page however many abilities
 * the site registers.
 *
 * @param {Object} page The page.
 * @param {string} text The search text.
 */
const search = async ( page, text ) => {
	await page
		.getByRole( 'searchbox', { name: 'Search Abilities' } )
		.fill( text );
};

/**
 * Searches for one row and returns it once it is shown.
 *
 * @param {Object} page The page.
 * @param {string} text The row's label or slug.
 * @return {Promise<Object>} The row locator.
 */
const findRow = async ( page, text ) => {
	await search( page, text );
	const found = row( page, text );
	await expect( found ).toBeVisible();

	return found;
};

/**
 * The number of rows the unfiltered first page shows, from the list route.
 *
 * @param {Object} requestUtils The requestUtils fixture.
 * @return {Promise<number>} The row count.
 */
const firstPageRowCount = async ( requestUtils ) =>
	Math.min( ( await readList( requestUtils ) ).items.length, 20 );

/**
 * The runner's JSON input, by its accessible name.
 *
 * @param {Object} page The page.
 * @return {Object} The textarea locator.
 */
const runnerInput = ( page ) =>
	page.getByRole( 'textbox', { name: 'Ability test input (JSON)' } );

/**
 * One message in the runner's validation panel. The same words are also
 * spoken through a live region, so the panel's list item is matched.
 *
 * @param {Object} page The page.
 * @param {string} text The message.
 * @return {Object} The message locator.
 */
const validationMessage = ( page, text ) =>
	page.getByRole( 'listitem' ).filter( { hasText: text } );

/**
 * Asserts the view has exactly one `h1`, named "Abilities Explorer".
 *
 * @param {Object} page The page.
 */
const expectSingleHeading = async ( page ) => {
	await expect( page.getByRole( 'heading', { level: 1 } ) ).toHaveCount( 1 );
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Abilities Explorer' } )
	).toBeVisible();
};

test.describe( 'Abilities Explorer', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await setSettings( requestUtils, {
			wpai_features_enabled: true,
			'wpai_feature_abilities-explorer_enabled': true,
		} );
		await setFixtures( requestUtils, true );
		// The field fixture is on only inside "Field extensions".
		await requestUtils.deactivatePlugin( FIELD_PLUGIN );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		// Every test starts with the Explorer on, the fixtures registered, and
		// no owner exclusion or policy switch left behind.
		await setSettings( requestUtils, {
			wpai_features_enabled: true,
			'wpai_feature_abilities-explorer_enabled': true,
		} );
		await setFixtures( requestUtils, true );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setFixtures( requestUtils, false );
	} );

	test.describe( 'List', () => {
		test( 'shows the statistics and the table under a single heading', async ( {
			admin,
			page,
		} ) => {
			await admin.visitAdminPage( 'tools.php' );
			await expect(
				page
					.locator( '#adminmenu' )
					.getByRole( 'link', { name: 'Abilities Explorer' } )
			).toBeVisible();

			await openExplorer( admin, page );

			await expectSingleHeading( page );
			await expect(
				page.getByRole( 'list', { name: 'Ability statistics' } )
			).toBeVisible();
			await expect( page.getByRole( 'table' ) ).toBeVisible();
			await findRow( page, 'Acme Provider Fixture' );
		} );

		test( 'narrows the rows with the "Category" filter', async ( {
			admin,
			page,
		} ) => {
			await openExplorer( admin, page );
			await expect( dataRows( page ).first() ).toBeVisible();

			await filterChip( page, 'Category' ).click();

			// The label carries an ampersand, which renders as text.
			await page
				.getByRole( 'listbox', { name: 'List of: Category' } )
				.getByRole( 'option', { name: FIXTURE_CATEGORY, exact: true } )
				.click();
			await page.keyboard.press( 'Escape' );

			await expect( dataRows( page ) ).toHaveCount( 4 );
			await expect( row( page, 'Get Environment Info' ) ).toHaveCount(
				0
			);
			await expect( row( page, 'Acme Provider Fixture' ) ).toBeVisible();
		} );

		test( 'matches "Plugin" by origin and "Acme" by its exact label', async ( {
			admin,
			page,
		} ) => {
			await openExplorer( admin, page );
			await expect( dataRows( page ).first() ).toBeVisible();

			await pickFilter( page, 'Provider', 'Plugin' );
			await search( page, 'ai-e2e/' );

			await expect( row( page, 'Acme Provider Fixture' ) ).toBeVisible();
			await search( page, 'core/get-environment-info' );
			await expect( dataRows( page ) ).toHaveCount( 0 );
			await search( page, '' );

			await pickFilter( page, 'Provider', 'Acme' );

			await expect( dataRows( page ) ).toHaveCount( 1 );
			await expect( row( page, 'Acme Provider Fixture' ) ).toBeVisible();
		} );

		test( 'keeps the statistics unaffected by search', async ( {
			admin,
			page,
		} ) => {
			await openExplorer( admin, page );

			const stats = page.getByRole( 'list', {
				name: 'Ability statistics',
			} );
			await expect( dataRows( page ).first() ).toBeVisible();
			const before = await stats.textContent();

			await search( page, 'ai-e2e/acme-provider' );

			await expect( dataRows( page ) ).toHaveCount( 1 );
			await expect( stats ).toHaveText( before );
		} );

		test( 'filters "Exposed in" down to what the assistant can reach', async ( {
			admin,
			page,
		} ) => {
			await openExplorer( admin, page );
			await expect( dataRows( page ).first() ).toBeVisible();

			await pickFilter( page, 'Exposed in', 'Assistant' );
			await search( page, 'ai-e2e/' );

			await expect( dataRows( page ) ).toHaveCount( 1 );
			await expect(
				row( page, 'Assistant Reader Fixture' )
			).toBeVisible();

			// Whatever else the site registers, no row off the assistant stays.
			await search( page, '' );
			await expect( dataRows( page ).first() ).toBeVisible();
			await expect(
				dataRows( page ).filter( { hasText: 'Not the assistant' } )
			).toHaveCount( 0 );
		} );

		test( 'restores layout, fields and sort from a saved view, but not search or filters', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await openExplorer( admin, page );
			await expect( dataRows( page ).first() ).toBeVisible();

			await columnMenu( page, 'Name', 'Sort descending' );
			await columnMenu( page, 'Slug', 'Hide column' );
			await pickFilter( page, 'Provider', 'Plugin' );
			await search( page, 'ai-e2e/' );

			await expect( dataRows( page ) ).toHaveCount( 4 );

			await page.reload();

			await expect(
				page.getByRole( 'searchbox', { name: 'Search Abilities' } )
			).toHaveValue( '' );
			await expect(
				page.getByRole( 'button', { name: /^Provider is/ } )
			).toHaveCount( 0 );
			const list = await readList( requestUtils );
			await expect( dataRows( page ) ).toHaveCount(
				Math.min( list.items.length, 20 )
			);
			await expect(
				page
					.getByRole( 'table' )
					.getByRole( 'columnheader', { name: 'Slug', exact: true } )
			).toHaveCount( 0 );

			// Name descending: the first row is the last name in the registry.
			const lastName = await page.evaluate(
				( names ) =>
					[ ...names ].sort( ( a, b ) => b.localeCompare( a ) )[ 0 ],
				list.items.map( ( item ) => item.name ?? item.slug )
			);
			await expect(
				dataRows( page ).first().getByRole( 'cell' ).first()
			).toHaveText( lastName );

			const stored = await savedView( page );
			expect( stored.sort ).toEqual( {
				field: 'name',
				direction: 'desc',
			} );
			expect( stored.fields ).not.toContain( 'slug' );
			expect( stored ).not.toHaveProperty( 'search' );
			expect( stored ).not.toHaveProperty( 'filters' );
		} );

		test( 'loads a saved view with an unknown field ID and keeps the ID', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await openExplorer( admin, page );
			await expect( dataRows( page ).first() ).toBeVisible();

			await page.evaluate( ( key ) => {
				window.localStorage.setItem(
					key,
					JSON.stringify( {
						version: 1,
						type: 'table',
						layout: {},
						fields: [
							'acme/unknown-field',
							'slug',
							'provider',
							'surface',
						],
						sort: { field: 'name', direction: 'asc' },
						perPage: 20,
						seenFields: [
							'name',
							'acme/unknown-field',
							'slug',
							'provider',
							'category',
							'surface',
							'description',
						],
					} )
				);
			}, VIEW_STORAGE_KEY );

			await page.reload();

			await expect( dataRows( page ) ).toHaveCount(
				await firstPageRowCount( requestUtils )
			);
			await expect(
				page
					.getByRole( 'table' )
					.getByRole( 'columnheader', { name: 'Slug', exact: true } )
			).toBeVisible();

			// Saving again must not prune the unknown ID.
			await columnMenu( page, 'Name', 'Sort descending' );
			await expect
				.poll( async () => ( await savedView( page ) ).sort.direction )
				.toBe( 'desc' );
			expect( ( await savedView( page ) ).fields ).toContain(
				'acme/unknown-field'
			);
		} );
	} );

	test.describe( 'Assistant surface', () => {
		test( 'removes an ability and returns it, with a notice for each', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await openExplorer( admin, page );
			await search( page, 'Reader Fixture' );

			const reader = row( page, 'Assistant Reader Fixture' );
			await expect( reader ).toContainText( 'Assistant' );

			await reader
				.getByRole( 'button', { name: 'Remove from assistant' } )
				.click();

			await expect( snackbar( page, REMOVED ) ).toBeVisible();
			await expect( reader ).toContainText( 'Not the assistant' );
			await expect(
				reader.getByRole( 'button', { name: 'Return to assistant' } )
			).toBeVisible();
			expect(
				payloadRow(
					await readList( requestUtils ),
					'ai-e2e/assistant-reader'
				).owner_excluded
			).toBe( true );

			await reader
				.getByRole( 'button', { name: 'Return to assistant' } )
				.click();

			await expect( snackbar( page, RETURNED ) ).toBeVisible();
			await expect(
				reader.getByRole( 'button', { name: 'Remove from assistant' } )
			).toBeVisible();
			await expect( reader ).not.toContainText( 'Not the assistant' );
			expect(
				payloadRow(
					await readList( requestUtils ),
					'ai-e2e/assistant-reader'
				).owner_excluded
			).toBe( false );
		} );

		test( 'returning a withheld ability shows the withheld reason, not the assistant badge', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await requestUtils.rest( {
				path: '/ai/v1/abilities/surface',
				method: 'POST',
				data: { change: 'remove', name: 'ai-e2e/withheld-reader' },
			} );

			await openExplorer( admin, page );
			await search( page, 'Reader Fixture' );

			const withheld = row( page, 'Withheld Reader Fixture' );
			await withheld
				.getByRole( 'button', { name: 'Return to assistant' } )
				.click();

			await expect( snackbar( page, RETURNED ) ).toBeVisible();
			await expect( withheld ).toContainText( WITHHELD_REASON );
			await expect( withheld ).toContainText( 'Not the assistant' );
			await expect( withheld.getByRole( 'button' ) ).toHaveText( [
				'View',
				'Test',
			] );
			expect(
				payloadRow(
					await readList( requestUtils ),
					'ai-e2e/withheld-reader'
				).owner_excluded
			).toBe( false );
		} );

		test( 'turns the admission policy off and on, updating every row', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await openExplorer( admin, page );
			await search( page, 'Reader Fixture' );

			const reader = row( page, 'Assistant Reader Fixture' );
			const withheld = row( page, 'Withheld Reader Fixture' );
			await expect( withheld ).toContainText( WITHHELD_REASON );

			await page
				.getByRole( 'button', { name: 'Turn policy off' } )
				.click();

			await expect( snackbar( page, POLICY_OFF ) ).toBeVisible();
			await expect(
				page.getByText(
					'Assistant admission policy: off (built-in abilities only).'
				)
			).toBeVisible();
			await expect( reader ).toContainText( POLICY_OFF_REASON );
			await expect( withheld ).toContainText( POLICY_OFF_REASON );
			await expect(
				page.getByRole( 'button', { name: 'Remove from assistant' } )
			).toHaveCount( 0 );
			expect( ( await readList( requestUtils ) ).policy.disabled ).toBe(
				true
			);

			await page
				.getByRole( 'button', { name: 'Turn policy on' } )
				.click();

			await expect( snackbar( page, POLICY_ON ) ).toBeVisible();
			await expect( withheld ).toContainText( WITHHELD_REASON );
			await expect(
				reader.getByRole( 'button', { name: 'Remove from assistant' } )
			).toBeVisible();
			expect( ( await readList( requestUtils ) ).policy.disabled ).toBe(
				false
			);
		} );

		test( 'disables a pending change, so a second click sends nothing', async ( {
			admin,
			page,
		} ) => {
			let requests = 0;
			let release;
			const released = new Promise( ( resolve ) => {
				release = resolve;
			} );

			await page.route(
				restRoute( '/ai/v1/abilities/surface' ),
				async ( route ) => {
					requests++;
					await released;
					await route.continue();
				}
			);

			await openExplorer( admin, page );
			await search( page, 'Reader Fixture' );

			const reader = row( page, 'Assistant Reader Fixture' );
			const control = reader.getByRole( 'button', {
				name: 'Remove from assistant',
			} );

			await control.click();
			await expect( control ).toBeDisabled();
			await control.click( { force: true } );

			release();

			await expect( snackbar( page, REMOVED ) ).toBeVisible();
			await expect(
				reader.getByRole( 'button', { name: 'Return to assistant' } )
			).toBeVisible();
			expect( requests ).toBe( 1 );
		} );

		test( 'does not let a refresh answered after a remove undo it', async ( {
			admin,
			page,
		} ) => {
			await openExplorer( admin, page );
			await search( page, 'Reader Fixture' );

			const reader = row( page, 'Assistant Reader Fixture' );
			await expect(
				reader.getByRole( 'button', { name: 'Remove from assistant' } )
			).toBeVisible();

			let release;
			const released = new Promise( ( resolve ) => {
				release = resolve;
			} );
			let served;
			const serverAnswered = new Promise( ( resolve ) => {
				served = resolve;
			} );

			// The refresh reaches the server now; its answer is held back.
			const listRoute = restRoute( '/ai/v1/abilities' );
			await page.route( listRoute, async ( route ) => {
				const response = await route.fetch();
				served();
				await released;
				await route.fulfill( { response } );
			} );

			await page.getByRole( 'button', { name: 'Refresh' } ).click();
			await serverAnswered;

			await reader
				.getByRole( 'button', { name: 'Remove from assistant' } )
				.click();
			await expect( snackbar( page, REMOVED ) ).toBeVisible();

			const refreshed = page.waitForResponse( ( response ) =>
				listRoute( response.url() )
			);
			release();
			await refreshed;

			await expect(
				page.getByRole( 'button', { name: 'Refresh' } )
			).toBeEnabled();
			await expect( reader ).toContainText( 'Not the assistant' );
			await expect(
				reader.getByRole( 'button', { name: 'Return to assistant' } )
			).toBeVisible();
		} );
	} );

	test.describe( 'Detail view and test runner', () => {
		test( 'shows each detail section and copies the JSON', async ( {
			admin,
			page,
			context,
		} ) => {
			await context.grantPermissions( [
				'clipboard-read',
				'clipboard-write',
			] );

			await openExplorer( admin, page );
			await ( await findRow( page, 'Get Environment Info' ) )
				.getByRole( 'button', { name: 'View' } )
				.click();

			await expectSingleHeading( page );
			await expect(
				page.getByRole( 'heading', {
					level: 2,
					name: 'Get Environment Info',
				} )
			).toBeVisible();

			for ( const section of [ 'Description', 'Details', 'Raw Data' ] ) {
				await expect(
					page.getByRole( 'heading', { level: 3, name: section } )
				).toBeVisible();
			}

			const copy = page.getByRole( 'button', { name: 'Copy Raw Data' } );
			await copy.click();
			await expect( copy ).toHaveText( 'Copied!' );

			const copied = JSON.parse(
				await page.evaluate( () => navigator.clipboard.readText() )
			);
			expect( copied.name ).toBe( 'core/get-environment-info' );
		} );

		test( 'validates deep-linked input against the schema', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await setSettings( requestUtils, {
				'wpai_feature_content-classification_enabled': true,
			} );

			try {
				await openExplorer(
					admin,
					page,
					'action=test&ability=ai/content-classification'
				);

				const input = runnerInput( page );
				const validate = page.getByRole( 'button', {
					name: 'Validate Input',
				} );
				const payload = ( maxSuggestions ) =>
					JSON.stringify(
						{
							content:
								'This is enough content to classify for validation testing.',
							post_id: 1,
							taxonomy: 'post_tag',
							strategy: 'existing_only',
							max_suggestions: maxSuggestions,
						},
						null,
						2
					);

				// Typed straight after the deep link; the example must not replace it.
				await input.fill( payload( 11 ) );
				await expectSingleHeading( page );
				await expect( input ).toHaveValue( payload( 11 ) );

				await validate.click();
				await expect(
					validationMessage(
						page,
						'Field "max_suggestions" must be at most 10'
					)
				).toBeVisible();

				await input.fill( payload( 5 ) );
				await validate.click();
				await expect(
					validationMessage(
						page,
						'Input is valid according to the schema'
					)
				).toBeVisible();

				await input.fill( '"hello"' );
				await validate.click();
				await expect(
					validationMessage(
						page,
						'Input should be of type "object"'
					)
				).toBeVisible();
			} finally {
				await setSettings( requestUtils, {
					'wpai_feature_content-classification_enabled': false,
				} );
			}
		} );

		test( 'shows the success and error panels, and Clear hides them', async ( {
			admin,
			page,
		} ) => {
			await openExplorer(
				admin,
				page,
				'action=test&ability=ai-e2e/acme-provider'
			);

			await expect( runnerInput( page ) ).toBeVisible();
			await page
				.getByRole( 'button', { name: 'Invoke Ability' } )
				.click();

			await expect(
				page.getByRole( 'heading', { name: 'Success!' } )
			).toBeVisible();
			await expect( page.getByText( '"ok": true' ) ).toBeVisible();
			await expect(
				snackbar( page, 'Ability invoked successfully.' )
			).toBeVisible();

			await page
				.getByRole( 'button', { name: 'Validate Input' } )
				.click();
			await expect(
				page.getByRole( 'heading', { name: 'Valid', exact: true } )
			).toBeVisible();

			await page.getByRole( 'button', { name: 'Clear Result' } ).click();
			await expect(
				page.getByRole( 'heading', { name: 'Result' } )
			).toHaveCount( 0 );
			await expect(
				page.getByRole( 'heading', { name: 'Valid', exact: true } )
			).toHaveCount( 0 );

			await openExplorer(
				admin,
				page,
				'action=test&ability=ai-e2e/failing-fixture'
			);
			await page
				.getByRole( 'button', { name: 'Invoke Ability' } )
				.click();

			const panel = page.locator( 'pre' ).filter( {
				hasText: 'ai_e2e_fixture_failed',
			} );
			await expect(
				page.getByRole( 'heading', { name: 'Error', exact: true } )
			).toBeVisible();
			await expect( panel ).toContainText(
				'The fixture failed on purpose.'
			);
			await expect( panel ).toContainText( '"reason": "e2e"' );
			await expect( panel ).not.toContainText( 'trace' );
		} );

		test( 'never shows one runner the result of another ability in flight', async ( {
			admin,
			page,
		} ) => {
			let release;
			const released = new Promise( ( resolve ) => {
				release = resolve;
			} );
			let sent;
			const invokeSent = new Promise( ( resolve ) => {
				sent = resolve;
			} );

			await page.route(
				restRoute( '/ai/v1/abilities/invoke' ),
				async ( route ) => {
					sent();
					await released;
					// The runner aborts the request when it leaves the ability.
					await route.continue().catch( () => {} );
				}
			);

			await openExplorer(
				admin,
				page,
				'action=test&ability=ai-e2e/acme-provider'
			);
			await page
				.getByRole( 'button', { name: 'Invoke Ability' } )
				.click();
			await invokeSent;

			await page.getByRole( 'link', { name: '← Back to List' } ).click();
			await ( await findRow( page, 'Failing Fixture' ) )
				.getByRole( 'button', { name: 'Test' } )
				.click();

			await expect(
				page.getByRole( 'heading', {
					level: 2,
					name: 'Test Ability: Failing Fixture',
				} )
			).toBeVisible();

			release();
			await page.waitForTimeout( 500 );

			await expect(
				page.getByRole( 'heading', { name: 'Result' } )
			).toHaveCount( 0 );
			await expect(
				page.getByRole( 'heading', { name: 'Success!' } )
			).toHaveCount( 0 );
			await expect(
				page.getByRole( 'button', { name: 'Invoke Ability' } )
			).toBeEnabled();
		} );
	} );

	test.describe( 'Navigation', () => {
		test( 'keeps one heading per view, and Back and reload keep the view', async ( {
			admin,
			page,
		} ) => {
			await openExplorer( admin, page );
			await expectSingleHeading( page );

			await columnMenu( page, 'Slug', 'Hide column' );
			const slugHeader = page
				.getByRole( 'table' )
				.getByRole( 'columnheader', { name: 'Slug', exact: true } );
			await expect( slugHeader ).toHaveCount( 0 );

			await ( await findRow( page, 'Acme Provider Fixture' ) )
				.getByRole( 'button', { name: 'View' } )
				.click();
			await expect( page ).toHaveURL( /action=view/ );
			await expectSingleHeading( page );

			await page.getByRole( 'link', { name: 'Test Ability' } ).click();
			await expect( page ).toHaveURL( /action=test/ );
			await expectSingleHeading( page );
			await expect( runnerInput( page ) ).toBeVisible();

			await page.getByRole( 'link', { name: '← Back to List' } ).click();
			await expect( page ).not.toHaveURL( /action=/ );
			await expect( dataRows( page ).first() ).toBeVisible();
			await expect( slugHeader ).toHaveCount( 0 );

			await ( await findRow( page, 'Acme Provider Fixture' ) )
				.getByRole( 'button', { name: 'Test' } )
				.click();
			await expect( runnerInput( page ) ).toBeVisible();

			await page.reload();
			await expect( page ).toHaveURL( /action=test/ );
			await expect(
				page.getByRole( 'heading', {
					level: 2,
					name: 'Test Ability: Acme Provider Fixture',
				} )
			).toBeVisible();

			await page.goBack();
			await expect( page ).not.toHaveURL( /action=/ );
			await expectSingleHeading( page );
			await expect( dataRows( page ).first() ).toBeVisible();
			await expect( slugHeader ).toHaveCount( 0 );
		} );
	} );

	test.describe( 'Field extensions', () => {
		test.beforeEach( async ( { requestUtils } ) => {
			await requestUtils.activatePlugin( FIELD_PLUGIN );
		} );

		test.afterAll( async ( { requestUtils } ) => {
			await requestUtils.deactivatePlugin( FIELD_PLUGIN );
		} );

		const slugLengthHeader = ( page ) =>
			page
				.getByRole( 'table' )
				.getByRole( 'columnheader', { name: 'Slug length' } );

		test( 'shows an extension column on first load and keeps it through deactivation', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await openExplorer( admin, page );
			await expect( slugLengthHeader( page ) ).toBeVisible();

			// Save a view that includes the extension column.
			await columnMenu( page, 'Name', 'Sort descending' );
			await expect
				.poll( async () => ( await savedView( page ) )?.fields ?? [] )
				.toContain( 'e2e/slug-length' );

			await requestUtils.deactivatePlugin( FIELD_PLUGIN );

			try {
				await page.reload();
				await expect( dataRows( page ) ).toHaveCount(
					await firstPageRowCount( requestUtils )
				);
				await expect( slugLengthHeader( page ) ).toHaveCount( 0 );

				await columnMenu( page, 'Name', 'Sort ascending' );
				await expect
					.poll(
						async () => ( await savedView( page ) ).sort.direction
					)
					.toBe( 'asc' );
				expect( ( await savedView( page ) ).fields ).toContain(
					'e2e/slug-length'
				);
			} finally {
				await requestUtils.activatePlugin( FIELD_PLUGIN );
			}

			await page.reload();
			await expect( slugLengthHeader( page ) ).toBeVisible();
		} );

		test( 'keeps the built-in columns when the filter returns a non-array', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await openExplorer( admin, page, 'e2e_fields=non-array' );

			const table = page.getByRole( 'table' );
			for ( const column of [
				'Name',
				'Slug',
				'Provider',
				'Exposed in',
			] ) {
				await expect(
					table.getByRole( 'columnheader', {
						name: column,
						exact: true,
					} )
				).toBeVisible();
			}
			await expect( slugLengthHeader( page ) ).toHaveCount( 0 );
			await expect( dataRows( page ) ).toHaveCount(
				await firstPageRowCount( requestUtils )
			);
		} );

		test( 'does not let an extension replace a built-in field', async ( {
			admin,
			page,
		} ) => {
			await openExplorer( admin, page, 'e2e_fields=collide' );

			const table = page.getByRole( 'table' );
			await expect( slugLengthHeader( page ) ).toBeVisible();
			await expect(
				table.getByRole( 'columnheader', { name: 'Slug', exact: true } )
			).toHaveCount( 1 );
			await expect(
				table.getByRole( 'columnheader', { name: 'Hijacked slug' } )
			).toHaveCount( 0 );
			await findRow( page, 'ai-e2e/acme-provider' );
			await expect( table ).not.toContainText( 'hijacked' );
		} );
	} );

	test.describe( 'Availability', () => {
		test.afterEach( async ( { requestUtils } ) => {
			await setSettings( requestUtils, {
				wpai_features_enabled: true,
				'wpai_feature_abilities-explorer_enabled': true,
			} );
		} );

		test( 'is unreachable when the experiment is off', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await setSettings( requestUtils, {
				'wpai_feature_abilities-explorer_enabled': false,
			} );

			await admin.visitAdminPage( 'tools.php' );
			await expect(
				page
					.locator( '#adminmenu' )
					.getByRole( 'link', { name: 'Abilities Explorer' } )
			).toHaveCount( 0 );

			await openExplorer( admin, page );
			await expect(
				page.getByText(
					'Sorry, you are not allowed to access this page.'
				)
			).toBeVisible();
			await expect(
				page.getByRole( 'heading', { name: 'Abilities Explorer' } )
			).toHaveCount( 0 );
		} );

		test( 'is unreachable when AI is globally off', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await setSettings( requestUtils, { wpai_features_enabled: false } );

			await openExplorer( admin, page );
			await expect(
				page.getByText(
					'Sorry, you are not allowed to access this page.'
				)
			).toBeVisible();
		} );
	} );
} );

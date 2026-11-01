/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const EXPERIMENT_OPTION = 'wpai_feature_abilities-explorer_enabled';

const setSettings = ( requestUtils, data ) =>
	requestUtils.rest( { path: '/wp/v2/settings', method: 'POST', data } );

const openExplorer = ( admin, page, extra = '' ) =>
	admin.visitAdminPage(
		'tools.php',
		'page=ai-abilities-explorer' + ( extra ? `&${ extra }` : '' )
	);

const dataRows = ( page ) =>
	page
		.getByRole( 'table' )
		.getByRole( 'row' )
		.filter( { has: page.getByRole( 'cell' ) } );

const row = ( page, text ) => dataRows( page ).filter( { hasText: text } );

const search = ( page, text ) =>
	page.getByRole( 'searchbox', { name: 'Search Abilities' } ).fill( text );

// Row actions show on hover, as in WP_List_Table.
const findRow = async ( page, text ) => {
	await search( page, text );
	const found = row( page, text );
	await expect( found ).toBeVisible();
	await found.hover();

	return found;
};

const pickFilter = async ( page, label, option ) => {
	await page
		.getByRole( 'button', { name: new RegExp( `^${ label }` ) } )
		.first()
		.click();
	await page
		.getByRole( 'listbox', { name: `List of: ${ label }` } )
		.getByRole( 'option', { name: option, exact: true } )
		.click();
	await page.keyboard.press( 'Escape' );
};

const columnMenu = async ( page, column, item ) => {
	await page
		.getByRole( 'table' )
		.getByRole( 'button', { name: column, exact: true } )
		.click();
	const menuItem = page
		.getByRole( 'menuitemradio', { name: item } )
		.or( page.getByRole( 'menuitem', { name: item } ) );
	await menuItem.click();

	// Sorting leaves the menu open.
	if ( await menuItem.isVisible() ) {
		await page.keyboard.press( 'Escape' );
	}
	await expect( menuItem ).toBeHidden();
};

const columnHeader = ( page, name ) =>
	page
		.getByRole( 'table' )
		.getByRole( 'columnheader', { name, exact: true } );

const savedView = ( page ) =>
	page.evaluate( () =>
		JSON.parse(
			window.localStorage.getItem( 'ai.abilitiesExplorer.view' ) ?? 'null'
		)
	);

const readList = ( requestUtils ) =>
	requestUtils.rest( { path: '/ai/v1/abilities', method: 'GET' } );

const firstPageRowCount = async ( requestUtils ) =>
	Math.min( ( await readList( requestUtils ) ).items.length, 20 );

// Matches a REST route with pretty or plain permalinks.
const restRoute = ( route ) => {
	const pattern = new RegExp(
		`${ route.replace( /\//g, '\\/' ) }(?:[?&]|$)`
	);

	return ( url ) => pattern.test( decodeURIComponent( url.toString() ) );
};

const runnerInput = ( page ) =>
	page.getByRole( 'textbox', { name: 'Ability test input (JSON)' } );

const validationMessage = ( page, text ) =>
	page.getByRole( 'listitem' ).filter( { hasText: text } );

const expectSingleHeading = async ( page ) => {
	await expect( page.getByRole( 'heading', { level: 1 } ) ).toHaveCount( 1 );
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Abilities Explorer' } )
	).toBeVisible();
};

test.describe( 'Abilities Explorer', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await setSettings( requestUtils, { [ EXPERIMENT_OPTION ]: true } );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setSettings( requestUtils, { [ EXPERIMENT_OPTION ]: false } );
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
			await findRow( page, 'Acme Provider Fixture' );
		} );

		test( 'narrows the rows with the "Category" filter', async ( {
			admin,
			page,
		} ) => {
			await openExplorer( admin, page );
			await expect( dataRows( page ).first() ).toBeVisible();

			// The label carries an ampersand, which must render as text.
			await pickFilter( page, 'Category', 'E2E Fixtures & Friends' );

			await expect( dataRows( page ) ).toHaveCount( 2 );
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
			await expect( dataRows( page ) ).toHaveCount( 2 );

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
			await expect( columnHeader( page, 'Slug' ) ).toHaveCount( 0 );

			// Name descending: the first row is the last name in the registry.
			const lastName = await page.evaluate(
				( names ) =>
					[ ...names ].sort( ( a, b ) => b.localeCompare( a ) )[ 0 ],
				list.items.map( ( item ) => item.name ?? item.slug )
			);
			await expect(
				dataRows( page )
					.first()
					.getByRole( 'cell' )
					.first()
					.getByRole( 'link' )
					.first()
			).toHaveText( lastName );
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
			await expect( columnHeader( page, 'Slug' ) ).toHaveCount( 0 );

			await ( await findRow( page, 'Acme Provider Fixture' ) )
				.getByRole( 'link', { name: 'View' } )
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
			await expect( columnHeader( page, 'Slug' ) ).toHaveCount( 0 );

			await ( await findRow( page, 'Acme Provider Fixture' ) )
				.getByRole( 'link', { name: 'Test' } )
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
			await expect( columnHeader( page, 'Slug' ) ).toHaveCount( 0 );
		} );

		test( 'fetches an ability again when it is opened after returning to the list', async ( {
			admin,
			page,
		} ) => {
			const itemRoute = restRoute( '/ai/v1/abilities/item' );
			const itemRequest = () =>
				page.waitForRequest(
					( request ) =>
						'GET' === request.method() && itemRoute( request.url() )
				);
			const openDetail = async () => {
				await ( await findRow( page, 'Acme Provider Fixture' ) )
					.getByRole( 'link', { name: 'View' } )
					.click();
				await expect(
					page.getByRole( 'heading', {
						level: 2,
						name: 'Acme Provider Fixture',
					} )
				).toBeVisible();
			};

			await openExplorer( admin, page );

			const firstLoad = itemRequest();
			await openDetail();
			await firstLoad;

			await page.getByRole( 'link', { name: '← Back to List' } ).click();
			await expect( dataRows( page ).first() ).toBeVisible();

			const secondLoad = itemRequest();
			await openDetail();
			await secondLoad;
		} );

		test( 'shows an extension column, and keeps it in the saved view while it is absent', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			// The E2E Testing plugin adds a "Slug length" column through the
			// `ai.abilitiesExplorer.fields` filter when `e2e_fields` is set.
			await openExplorer( admin, page, 'e2e_fields=1' );
			await expect( columnHeader( page, 'Slug length' ) ).toBeVisible();

			await columnMenu( page, 'Name', 'Sort descending' );
			await expect
				.poll( async () => ( await savedView( page ) )?.fields ?? [] )
				.toContain( 'e2e/slug-length' );

			await openExplorer( admin, page );
			await expect( dataRows( page ) ).toHaveCount(
				await firstPageRowCount( requestUtils )
			);
			await expect( columnHeader( page, 'Slug length' ) ).toHaveCount(
				0
			);

			// Saving without the field must not drop it from the view.
			await columnMenu( page, 'Name', 'Sort ascending' );
			await expect
				.poll( async () => ( await savedView( page ) ).sort.direction )
				.toBe( 'asc' );
			expect( ( await savedView( page ) ).fields ).toContain(
				'e2e/slug-length'
			);

			await openExplorer( admin, page, 'e2e_fields=1' );
			await expect( columnHeader( page, 'Slug length' ) ).toBeVisible();
		} );

		test( 'is unreachable when the experiment is off', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await setSettings( requestUtils, { [ EXPERIMENT_OPTION ]: false } );

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
				.getByRole( 'link', { name: 'View' } )
				.click();

			await expectSingleHeading( page );
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

			await page.getByRole( 'button', { name: 'Clear Result' } ).click();
			await expect(
				page.getByRole( 'heading', { name: 'Result' } )
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
			let settled;
			const routeSettled = new Promise( ( resolve ) => {
				settled = resolve;
			} );

			await page.route(
				restRoute( '/ai/v1/abilities/invoke' ),
				async ( route ) => {
					sent( route.request() );
					await released;
					// The runner aborts the request when it leaves the ability.
					await route.continue().catch( () => {} );
					settled();
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
			const invokeRequest = await invokeSent;

			await page.getByRole( 'link', { name: '← Back to List' } ).click();
			await ( await findRow( page, 'Failing Fixture' ) )
				.getByRole( 'link', { name: 'Test' } )
				.click();
			await expect(
				page.getByRole( 'heading', {
					level: 2,
					name: 'Test Ability: Failing Fixture',
				} )
			).toBeVisible();

			// Let the held invoke go, and wait until it has failed or its
			// response has fully reached the page.
			release();
			await routeSettled;
			const invokeResponse = await invokeRequest.response();
			if ( invokeResponse ) {
				await invokeResponse.finished();
			}

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
} );

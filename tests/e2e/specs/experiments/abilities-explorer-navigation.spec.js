/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	FIELD_PLUGIN,
	columnMenu,
	dataRows,
	enableExplorer,
	expectSingleHeading,
	findRow,
	firstPageRowCount,
	openExplorer,
	restRoute,
	runnerInput,
	savedView,
	setFixtures,
	setSettings,
} = require( '../../utils/abilities-explorer' );

test.describe( 'Abilities Explorer', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await enableExplorer( requestUtils );
		// The field fixture is on only inside "Field extensions".
		await requestUtils.deactivatePlugin( FIELD_PLUGIN );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		// Every test starts with the Explorer on, the fixtures registered, and
		// no owner exclusion or policy switch left behind.
		await enableExplorer( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setFixtures( requestUtils, false );
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
					.getByRole( 'button', { name: 'View' } )
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
			await expect( page ).not.toHaveURL( /action=/ );
			await expect( dataRows( page ).first() ).toBeVisible();

			// The same ability again: the held item must not be reused.
			const secondLoad = itemRequest();
			await openDetail();
			await secondLoad;
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

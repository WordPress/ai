/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	FIELD_PLUGIN,
	FIXTURE_CATEGORY,
	VIEW_STORAGE_KEY,
	columnMenu,
	dataRows,
	enableExplorer,
	expectSingleHeading,
	filterChip,
	findRow,
	firstPageRowCount,
	openExplorer,
	pickFilter,
	readList,
	row,
	savedView,
	search,
	setFixtures,
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
			// The Name cell's first link is the name; the row actions follow it.
			await expect(
				dataRows( page )
					.first()
					.getByRole( 'cell' )
					.first()
					.getByRole( 'link' )
					.first()
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

			// A saved layout without column styles still sizes "Exposed in".
			await expect(
				page.getByRole( 'table' ).getByRole( 'columnheader', {
					name: 'Exposed in',
					exact: true,
				} )
			).toHaveAttribute( 'style', /min-width: 16em/ );

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
} );

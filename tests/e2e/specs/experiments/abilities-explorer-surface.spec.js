/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	REMOVED,
	REMOVED_LABEL,
	RESTORED,
	USED_LABEL,
	enableExplorer,
	openExplorer,
	payloadRow,
	pickFilter,
	readList,
	restRoute,
	row,
	search,
	setFixtures,
	snackbar,
} = require( '../../utils/abilities-explorer' );

const REMOVE = 'Remove from AI Workspace';
const RESTORE = 'Restore to AI Workspace';

test.describe( 'Abilities Explorer', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await enableExplorer( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setFixtures( requestUtils, false );
	} );

	test.describe( 'AI Workspace', () => {
		test( 'removes an ability and restores it, with a notice for each', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await openExplorer( admin, page );
			await search( page, 'Reader Fixture' );

			const reader = row( page, 'Assistant Reader Fixture' );
			await expect( reader ).toContainText( USED_LABEL );

			await reader.hover();
			await reader.getByRole( 'button', { name: REMOVE } ).click();

			await expect( snackbar( page, REMOVED ) ).toBeVisible();
			await expect( reader ).toContainText( REMOVED_LABEL );
			await expect( reader ).not.toContainText( USED_LABEL );
			// The same control, relabelled, keeps focus.
			await expect(
				reader.getByRole( 'button', { name: RESTORE } )
			).toBeFocused();
			expect(
				payloadRow(
					await readList( requestUtils ),
					'ai-e2e/assistant-reader'
				).owner_excluded
			).toBe( true );

			await reader.hover();
			await reader.getByRole( 'button', { name: RESTORE } ).click();

			await expect( snackbar( page, RESTORED ) ).toBeVisible();
			await expect(
				reader.getByRole( 'button', { name: REMOVE } )
			).toBeVisible();
			await expect( reader ).toContainText( USED_LABEL );
			await expect( reader ).not.toContainText( REMOVED_LABEL );
			expect(
				payloadRow(
					await readList( requestUtils ),
					'ai-e2e/assistant-reader'
				).owner_excluded
			).toBe( false );
		} );

		test( 'restoring an ability the AI Workspace does not use leaves the row with nothing to show', async ( {
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
			await expect( withheld ).toContainText( REMOVED_LABEL );
			await withheld.hover();
			await withheld.getByRole( 'button', { name: RESTORE } ).click();

			await expect( snackbar( page, RESTORED ) ).toBeVisible();
			await expect( withheld ).not.toContainText( 'AI Workspace' );
			// Only View and Test are left under the name: no Workspace control.
			await expect( withheld.getByRole( 'button' ) ).toHaveCount( 0 );
			await expect(
				withheld.getByRole( 'link', { name: /^(View|Test)$/ } )
			).toHaveText( [ 'View', 'Test' ] );
			expect(
				payloadRow(
					await readList( requestUtils ),
					'ai-e2e/withheld-reader'
				).owner_excluded
			).toBe( false );
		} );

		test( 'shows no policy switch', async ( { admin, page } ) => {
			await openExplorer( admin, page );
			await search( page, 'Reader Fixture' );

			await expect(
				row( page, 'Assistant Reader Fixture' )
			).toBeVisible();
			await expect(
				page.getByRole( 'button', { name: /policy/i } )
			).toHaveCount( 0 );
			await expect( page.getByText( /eligible/i ) ).toHaveCount( 0 );
		} );

		test( 'moves focus to the list when a change filters its row out', async ( {
			admin,
			page,
		} ) => {
			await openExplorer( admin, page );
			await pickFilter( page, 'Exposed in', USED_LABEL );
			await search( page, 'Reader Fixture' );

			const reader = row( page, 'Assistant Reader Fixture' );
			await reader.hover();
			await reader.getByRole( 'button', { name: REMOVE } ).click();

			await expect( snackbar( page, REMOVED ) ).toBeVisible();
			await expect( reader ).toHaveCount( 0 );
			// With no rows DataViews renders no table, so focus goes to the list region.
			await expect(
				page.getByRole( 'region', { name: 'Abilities list' } )
			).toBeFocused();
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
			const control = reader.getByRole( 'button', { name: REMOVE } );

			await reader.hover();
			await control.click();
			await expect( control ).toBeDisabled();
			await control.click( { force: true } );

			release();

			await expect( snackbar( page, REMOVED ) ).toBeVisible();
			await expect(
				reader.getByRole( 'button', { name: RESTORE } )
			).toBeVisible();
			expect( requests ).toBe( 1 );
		} );

		test( 'shows a failed change under the row and leaves the row as it was', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await page.route(
				restRoute( '/ai/v1/abilities/surface' ),
				( route ) =>
					route.fulfill( {
						status: 500,
						contentType: 'application/json',
						body: JSON.stringify( {
							code: 'e2e_failure',
							message: 'The server could not save the change.',
							data: { status: 500 },
						} ),
					} )
			);

			await openExplorer( admin, page );
			await search( page, 'Reader Fixture' );

			const reader = row( page, 'Assistant Reader Fixture' );
			const control = reader.getByRole( 'button', { name: REMOVE } );

			await reader.hover();
			await control.click();

			await expect( reader ).toContainText(
				'Could not remove this ability from AI Workspace. The server could not save the change.'
			);
			await expect( reader ).toContainText( USED_LABEL );
			await expect( control ).toBeEnabled();
			await expect( snackbar( page, REMOVED ) ).toHaveCount( 0 );
			expect(
				payloadRow(
					await readList( requestUtils ),
					'ai-e2e/assistant-reader'
				).owner_excluded
			).toBe( false );
		} );
	} );
} );

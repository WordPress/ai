/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	FIELD_PLUGIN,
	POLICY_OFF,
	POLICY_OFF_REASON,
	POLICY_ON,
	REMOVED,
	RETURNED,
	WITHHELD_REASON,
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

			await reader.hover();
			await reader
				.getByRole( 'button', { name: 'Remove from assistant' } )
				.click();

			await expect( snackbar( page, REMOVED ) ).toBeVisible();
			await expect( reader ).toContainText( 'Not the assistant' );
			// The same control, relabelled, keeps focus.
			await expect(
				reader.getByRole( 'button', { name: 'Return to assistant' } )
			).toBeFocused();
			expect(
				payloadRow(
					await readList( requestUtils ),
					'ai-e2e/assistant-reader'
				).owner_excluded
			).toBe( true );

			await reader.hover();
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
			await withheld.hover();
			await withheld
				.getByRole( 'button', { name: 'Return to assistant' } )
				.click();

			await expect( snackbar( page, RETURNED ) ).toBeVisible();
			await expect( withheld ).toContainText( WITHHELD_REASON );
			await expect( withheld ).toContainText( 'Not the assistant' );
			// Only View and Test are left under the name: no surface action.
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

		test( 'moves focus to the list when a change filters its row out', async ( {
			admin,
			page,
		} ) => {
			await openExplorer( admin, page );
			await pickFilter( page, 'Exposed in', 'Assistant' );
			await search( page, 'Reader Fixture' );

			const reader = row( page, 'Assistant Reader Fixture' );
			await reader.hover();
			await reader
				.getByRole( 'button', { name: 'Remove from assistant' } )
				.click();

			await expect( snackbar( page, REMOVED ) ).toBeVisible();
			await expect( reader ).toHaveCount( 0 );
			// The only matching row is gone, so DataViews shows no table; focus
			// lands on the labelled list region either way.
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
			const control = reader.getByRole( 'button', {
				name: 'Remove from assistant',
			} );

			await reader.hover();
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

			await reader.hover();
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
} );

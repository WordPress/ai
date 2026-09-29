/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	FIELD_PLUGIN,
	enableExplorer,
	expectSingleHeading,
	findRow,
	openExplorer,
	restRoute,
	runnerInput,
	setFixtures,
	setSettings,
	snackbar,
	validationMessage,
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

			// Let the held invoke go, and wait until it has either failed (the
			// runner aborted it) or its whole response has reached the page.
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

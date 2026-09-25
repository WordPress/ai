/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	seedCredentials,
	clearCredentials,
	setConnectorCapabilities,
	clearConnectorCapabilities,
	visitSettingsPage,
} = require( '../../utils/helpers' );

const TEXT_GENERATION_REQUIRED =
	'These AI features need an AI Connector that can generate text.';
const LEGACY_INVALID_CONNECTOR_COPY =
	'Please review the AI Connectors you have configured to ensure they are valid.';
const NO_CONNECTORS_COPY =
	'Verify you have one or more AI Connectors configured.';

/**
 * Scopes text lookups to the settings app.
 *
 * The notice copy is also announced through the `a11y-speak` live region, so an
 * unscoped getByText matches twice and trips Playwright's strict mode.
 *
 * @param {import('@playwright/test').Page} page The page fixture.
 * @param {string}                          text The text to find.
 * @return {import('@playwright/test').Locator} The scoped locator.
 */
const settingsText = ( page, text ) =>
	page.locator( '#ai-wp-admin-app' ).getByText( text );

test.describe( 'Connector capability notices', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'e2e-testing' );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await clearConnectorCapabilities( requestUtils );
		await seedCredentials( requestUtils );
	} );

	test( 'a speech-only connector is not reported as invalid', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await seedCredentials( requestUtils );
		await setConnectorCapabilities( requestUtils, [
			'text_to_speech_conversion',
			'speech_generation',
		] );

		await visitSettingsPage( admin );

		// The connector is fine; it simply does not do text.
		await expect(
			settingsText( page, TEXT_GENERATION_REQUIRED )
		).toBeVisible();

		// It must name what the connectors do provide.
		await expect( settingsText( page, 'speech generation' ) ).toBeVisible();

		// And must not accuse a working connector of being invalid.
		await expect(
			settingsText( page, LEGACY_INVALID_CONNECTOR_COPY )
		).toBeHidden();
	} );

	test( 'a text-capable connector shows no notice', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await seedCredentials( requestUtils );
		await setConnectorCapabilities( requestUtils, [ 'text_generation' ] );

		await visitSettingsPage( admin );

		// Wait for the settings form to render before asserting absence.
		await expect( page.getByLabel( 'Enable AI' ) ).toBeVisible( {
			timeout: 10000,
		} );

		await expect(
			settingsText( page, TEXT_GENERATION_REQUIRED )
		).toBeHidden();
		await expect( settingsText( page, NO_CONNECTORS_COPY ) ).toBeHidden();
	} );

	test( 'no configured connectors still shows the configuration notice', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await clearCredentials( requestUtils );
		await clearConnectorCapabilities( requestUtils );

		await visitSettingsPage( admin );

		await expect( settingsText( page, NO_CONNECTORS_COPY ) ).toBeVisible();
		await expect(
			settingsText( page, TEXT_GENERATION_REQUIRED )
		).toBeHidden();
	} );
} );

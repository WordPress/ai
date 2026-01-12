/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	clearCredentials,
	disableExperiments,
	enableExperiments,
	visitCredentialsPage,
	visitSettingsPage,
} = require( '../../utils/helpers' );

test.describe( 'Plugin settings', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deactivatePlugin( 'e2e-test-request-mocking' );
	} );

	test( 'Can visit the settings page and see error message', async ( {
		admin,
		page,
	} ) => {
		// Clear out any existing credentials.
		await clearCredentials( admin, page );

		// Visit the settings page.
		await visitSettingsPage( admin );

		// Ensure the page title is correct.
		await expect(
			page.locator( '.wrap h1', { hasText: 'AI Experiments' } )
		).toHaveCount( 1 );

		// Ensure the no AI credentials error message is displayed.
		await expect(
			page.locator( '.wrap .notice-error', {
				hasText:
					'Before you can run experiments, you need to ensure you have one or more AI credentials set',
			} )
		).toHaveCount( 1 );
	} );

	test( 'Can visit the credentials page', async ( { admin, page } ) => {
		await visitCredentialsPage( admin );

		// Ensure the page title is correct.
		await expect(
			page.locator( '.wrap h1', { hasText: 'AI Client Credentials' } )
		).toHaveCount( 1 );

		// Ensure there are three password fields in the table.
		await expect(
			page.locator( '.form-table input[type="password"]' )
		).toHaveCount( 3 );

		// Add dummy credentials for OpenAI.
		await page
			.locator( '#wp-ai-client-provider-api-key-openai' )
			.fill( 'invalid-api-key' );

		// Save the credentials.
		await page.locator( '#submit' ).click();

		// Ensure the save was successful.
		await expect(
			page.locator( '.wrap .notice-success', {
				hasText: 'Settings saved',
			} )
		).toHaveCount( 1 );
	} );

	test( 'Can visit the settings page and see new error message', async ( {
		admin,
		page,
	} ) => {
		await visitSettingsPage( admin );

		// Ensure the no valid AI credentials error message is displayed.
		await expect(
			page.locator( '.wrap .notice-error', {
				hasText:
					'Before you can run experiments, you need to ensure you have set valid AI credentials',
			} )
		).toHaveCount( 1 );
	} );

	test( 'Can add valid credentials and turn on Experiments', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Activate the request mocking plugin.
		await requestUtils.activatePlugin( 'e2e-test-request-mocking' );

		// Visit the credentials page.
		await visitCredentialsPage( admin );

		// Add dummy-valid credentials for OpenAI.
		await page
			.locator( '#wp-ai-client-provider-api-key-openai' )
			.fill( 'valid-api-key' );

		// Save the credentials.
		await page.locator( '#submit' ).click();

		// Ensure the save was successful.
		await expect(
			page.locator( '.wrap .notice-success', {
				hasText: 'Settings saved',
			} )
		).toHaveCount( 1 );

		// Visit the settings page.
		await visitSettingsPage( admin );

		// Globally disable experiments.
		await disableExperiments( admin, page );

		// Ensure the experiments disabled notice is displayed.
		await expect(
			page.locator( '#ai-experiments-disabled-notice', {
				hasText:
					'Enable experiments above to configure individual experiment settings',
			} )
		).toHaveCount( 1 );

		// Globally turn on experiments.
		await enableExperiments( admin, page );

		// Ensure the experiments disabled notice is removed.
		await expect(
			page.locator( '#ai-experiments-disabled-notice' )
		).toHaveCount( 0 );
	} );
} );

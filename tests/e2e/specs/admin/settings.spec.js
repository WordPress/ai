/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	clearConnectors,
	disableExperiments,
	enableExperiments,
	visitConnectorsPage,
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
		// Clear out any existing Connectors.
		await clearConnectors( admin, page );

		// Visit the settings page.
		await visitSettingsPage( admin );

		// Ensure the page title is correct.
		await expect(
			page.locator( '.wrap h1', { hasText: 'AI Experiments' } )
		).toHaveCount( 1 );

		// Ensure the no AI Connectors error message is displayed.
		await expect(
			page.locator( '.wrap .notice-error', {
				hasText:
					'Most experiments require a valid AI Connector to function properly. To ensure those work properly, you need to have one or more AI Connectors configured',
			} )
		).toHaveCount( 1 );
	} );

	test( 'Can visit the Connectors page and add a valid OpenAI Connector', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Activate the request mocking plugin.
		await requestUtils.activatePlugin( 'e2e-test-request-mocking' );

		await visitConnectorsPage( admin );

		// Add dummy credentials for OpenAI.
		await page
			.locator( '.connector-item--ai-provider-for-openai button' )
			.click();
		await page
			.locator(
				'.connector-item--ai-provider-for-openai input[type="text"]'
			)
			.fill( 'valid-api-key' );

		// Save the credentials.
		await page
			.locator(
				'.connector-item--ai-provider-for-openai .connector-settings button'
			)
			.click();
	} );

	test( 'Can turn on Experiments', async ( { admin, page } ) => {
		// Globally disable experiments.
		await disableExperiments( admin, page );

		// Ensure the experiments disabled notice is displayed.
		await expect(
			page
				.locator( '.ai-experiments__notice', {
					hasText:
						'Enable experiments above to configure individual experiment settings.',
				} )
				.first()
		).toHaveCount( 1 );

		// Globally turn on experiments.
		await enableExperiments( admin, page );

		// Ensure the experiments disabled notice is removed.
		await expect( page.locator( '.ai-experiments__notice' ) ).toHaveCount(
			0
		);

		// Ensure we see the editor experiments section.
		await expect(
			page.locator(
				'.ai-experiments__card .ai-experiments__card-heading',
				{
					hasText: 'Editor Experiments',
				}
			)
		).toHaveCount( 1 );

		// Ensure we see the admin experiments section.
		await expect(
			page.locator(
				'.ai-experiments__card .ai-experiments__card-heading',
				{
					hasText: 'Admin Experiments',
				}
			)
		).toHaveCount( 1 );
	} );
} );

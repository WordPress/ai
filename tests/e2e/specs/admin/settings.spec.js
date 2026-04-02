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
		requestUtils,
	} ) => {
		// Activate the request mocking plugin.
		await requestUtils.activatePlugin( 'e2e-test-request-mocking' );

		// Clear out any existing Connectors.
		await clearConnectors( admin, page );

		// Visit the settings page.
		await visitSettingsPage( admin );

		// Ensure the page title is correct.
		await expect(
			page.locator( '.wrap h1', { hasText: 'AI' } )
		).toHaveCount( 1 );

		// Ensure the no AI Connectors error message is displayed.
		await expect(
			page.locator( '.wrap .notice-error', {
				hasText:
					'The AI plugin requires a valid AI Connector to function properly',
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

		const openAIConnector = page.locator( '[role="listitem"]', {
			has: page.getByRole( 'heading', { name: 'OpenAI', exact: true } ),
		} );

		// Add dummy credentials for OpenAI.
		await openAIConnector
			.getByRole( 'button', { name: /Set up|Edit/i } )
			.click();
		await openAIConnector
			.getByRole( 'textbox' )
			.first()
			.fill( 'valid-api-key' );

		// Save the credentials.
		await openAIConnector
			.getByRole( 'button', { name: /Save|Update/i } )
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
						'Enable AI above to configure individual experiment settings',
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

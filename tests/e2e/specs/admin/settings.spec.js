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
	enableExperiment,
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
			page.getByText(
				'Configure AI features and experiments for your WordPress site.'
			)
		).toBeVisible();

		// Ensure the no AI Connectors error message is displayed.
		await expect(
			page
				.locator( '#ai-wp-admin-app' )
				.getByText(
					'The AI plugin requires a valid AI Connector to function properly'
				)
		).toBeVisible();
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

		// Ensure global AI setting is disabled.
		await expect( page.getByLabel( 'Enable AI' ) ).not.toBeChecked();

		// Ensure feature toggles are disabled when AI is disabled.
		await expect(
			page
				.locator( '#ai-wp-admin-app input[type="checkbox"]:disabled' )
				.first()
		).toBeVisible();

		// Globally turn on experiments.
		await enableExperiments( admin, page );

		// Ensure global AI setting is enabled.
		await expect( page.getByLabel( 'Enable AI' ) ).toBeChecked();

		// Ensure we see the editor experiments section.
		await expect( page.getByText( 'Editor Experiments' ) ).toBeVisible();

		// Ensure we see the admin experiments section.
		await expect( page.getByText( 'Admin Experiments' ) ).toBeVisible();
	} );

	test( 'Inline settings retain pending edits when another toggle auto-saves', async ( {
		admin,
		page,
	} ) => {
		// Setup: Enable AI and Content Classification.
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'Content Classification' );

		// Visit settings page fresh to ensure no stale snackbars.
		await visitSettingsPage( admin );

		// Wait for Content Classification inline settings to render.
		const strategySelect = page.getByLabel( 'Taxonomy strategy' );
		await expect( strategySelect ).toBeVisible( { timeout: 10000 } );

		// Change the strategy to create a pending local edit.
		const originalValue = await strategySelect.inputValue();
		const newValue =
			originalValue === 'existing_only'
				? 'allow_new'
				: 'existing_only';
		await strategySelect.selectOption( newValue );

		// Verify the Save button appears (confirms pending edits exist).
		const saveButton = page
			.locator( '.ai-feature-settings-form' )
			.getByRole( 'button', { name: 'Save' } );
		await expect( saveButton ).toBeVisible();

		// Toggle another experiment to trigger auto-save (changes siteSettings).
		const otherToggle = page.getByLabel( 'Title Generation' );
		await otherToggle.click();

		// Wait for the auto-save snackbar to confirm siteSettings changed.
		await expect( page.getByTestId( 'snackbar' ) ).toBeVisible();

		// Assert: inline settings must still show the pending edit (not reset).
		await expect( strategySelect ).toHaveValue( newValue );
		await expect( saveButton ).toBeVisible();
	} );
} );

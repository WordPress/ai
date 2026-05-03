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
	visitAdminPage,
} = require( '../../utils/helpers' );

async function loginAsUser( page, username, password ) {
	await page.goto( '/wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( username );
	await page.getByLabel( 'Password', { exact: true } ).fill( password );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
}

test.describe( 'Dashboard widgets', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deactivatePlugin( 'e2e-test-request-mocking' );
	} );

	test( 'Admin can see AI dashboard widgets', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.activatePlugin( 'e2e-test-request-mocking' );

		await visitAdminPage( admin, 'index.php' );

		await expect(
			page.getByRole( 'heading', { name: 'AI Status' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'AI Capabilities' } )
		).toBeVisible();
	} );

	test( 'AI Capabilities shows the expected summary metrics', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.activatePlugin( 'e2e-test-request-mocking' );

		await visitAdminPage( admin, 'index.php' );

		const capabilitiesWidget = page.locator( '#wpai_capabilities' );
		const statCards = capabilitiesWidget.locator(
			'.ai-dashboard-capabilities__stat-card'
		);

		await expect( capabilitiesWidget ).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'AI Capabilities' } )
		).toBeVisible();
		await expect( statCards ).toHaveCount( 4 );
		await expect( capabilitiesWidget ).toContainText( 'Total Abilities' );
		await expect( capabilitiesWidget ).toContainText( 'Core' );
		await expect( capabilitiesWidget ).toContainText( 'Plugins' );
		await expect( capabilitiesWidget ).toContainText( 'Theme' );
		await expect( capabilitiesWidget ).toContainText(
			'Provider Capabilities'
		);
	} );

	test( 'AI Status shows getting started checklist when setup is incomplete', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.activatePlugin( 'e2e-test-request-mocking' );

		await clearConnectors( admin, page );
		await disableExperiments( admin, page );

		await visitAdminPage( admin, 'index.php' );

		await expect(
			page.getByText(
				'Complete these steps to get started with the AI plugin:'
			)
		).toBeVisible();

		await expect(
			page.getByRole( 'link', { name: 'Configure an AI provider' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Globally enable AI Features' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Enable a feature or experiment' } )
		).toBeVisible();
	} );

	test( 'Non-admin users do not see AI dashboard widgets', async ( {
		page,
		requestUtils,
	} ) => {
		const username = `ai-editor-${ Date.now() }`;
		const password = 'password';

		await requestUtils.createUser( {
			username,
			email: `${ username }@example.com`,
			password,
			roles: [ 'editor' ],
		} );

		await loginAsUser( page, username, password );
		await page.goto( '/wp-admin/index.php' );

		await expect(
			page.getByRole( 'heading', { name: 'AI Status' } )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'heading', { name: 'AI Capabilities' } )
		).toHaveCount( 0 );
	} );
} );

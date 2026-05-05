/**
 * WordPress dependencies
 */
const {
	RequestUtils,
	test,
	expect,
} = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	clearConnectors,
	disableExperiments,
	visitAdminPage,
} = require( '../../utils/helpers' );

test.describe( 'Dashboard widgets', () => {
	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
	} );

	test( 'Admin can see AI dashboard widgets', async ( { admin, page } ) => {
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
	} ) => {
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
	} ) => {
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

		const editorRequestUtils = await RequestUtils.setup( {
			user: { username, password },
		} );

		await editorRequestUtils.login();
		await page
			.context()
			.addCookies(
				( await editorRequestUtils.request.storageState() ).cookies
			);
		await editorRequestUtils.request.dispose();
		await page.goto( '/wp-admin/index.php' );

		await expect(
			page.getByRole( 'heading', { name: 'AI Status' } )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'heading', { name: 'AI Capabilities' } )
		).toHaveCount( 0 );
	} );
} );

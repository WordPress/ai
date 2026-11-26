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
	seedCredentials,
	disableAllFeatures,
	visitAdminPage,
} = require( '../../utils/helpers' );

test.describe( 'Dashboard widgets', () => {
	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
		await seedCredentials( requestUtils );
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

		const main = page.getByRole( 'main' );
		await expect(
			main.getByRole( 'heading', {
				name: 'AI Capabilities',
				exact: true,
			} )
		).toBeVisible();

		for ( const label of [
			'Total Abilities',
			'Core',
			'Plugins',
			'Theme',
		] ) {
			await expect(
				main.getByText( label, { exact: true } )
			).toBeVisible();
		}
		await expect(
			main.getByText( 'Provider Capabilities', { exact: true } )
		).toBeVisible();
	} );

	test( 'AI Status shows getting started checklist when setup is incomplete', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await clearConnectors( admin, page );
		await disableAllFeatures( requestUtils );

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

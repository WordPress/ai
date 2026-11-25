/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { visitAdminPage } = require( '../../utils/helpers' );

test.describe( 'Plugin activation', () => {
	test( 'Can deactivate the plugin', async ( { admin, page } ) => {
		await visitAdminPage( admin, 'plugins.php' );
		await page
			.getByRole( 'link', { name: 'Deactivate AI', exact: true } )
			.click();
		await expect( page.getByText( 'Plugin deactivated.' ) ).toHaveCount(
			1
		);
	} );

	test( 'Can activate the plugin', async ( { admin, page } ) => {
		await visitAdminPage( admin, 'plugins.php' );
		await page
			.getByRole( 'link', { name: 'Activate AI', exact: true } )
			.click();
		await expect( page.getByText( 'Plugin activated.' ) ).toHaveCount( 1 );
	} );

	test( 'Can see Connectors and Settings links in the plugin action links', async ( {
		admin,
		page,
	} ) => {
		// Visit the plugins page.
		await visitAdminPage( admin, 'plugins.php' );

		const plugin = page.getByRole( 'row' ).filter( {
			has: page.getByRole( 'link', {
				name: 'Deactivate AI',
				exact: true,
			} ),
		} );

		// Ensure the Connectors and Settings links are visible.
		await expect(
			plugin.getByRole( 'link', { name: 'Connectors', exact: true } )
		).toHaveCount( 1 );
		await expect(
			plugin.getByRole( 'link', { name: 'Settings', exact: true } )
		).toHaveCount( 1 );
	} );
} );

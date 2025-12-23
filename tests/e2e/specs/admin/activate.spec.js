/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Plugin activation', () => {
	test( 'Can deactivate the plugin', async ( { page } ) => {
		await page.goto( '/wp-admin/' );
		await page.goto( '/wp-admin/plugins.php' );
		await page.locator( '#deactivate-ai' ).click();
		await expect( page.getByText( 'Plugin deactivated.' ) ).toHaveCount(
			1
		);
	} );

	test( 'Can activate the plugin', async ( { page } ) => {
		await page.goto( '/wp-admin/' );
		await page.goto( '/wp-admin/plugins.php' );
		await page.locator( '#activate-ai' ).click();
		await expect( page.getByText( 'Plugin activated.' ) ).toHaveCount( 1 );
	} );
} );

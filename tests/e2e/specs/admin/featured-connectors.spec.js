/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { visitConnectorsPage } = require( '../../utils/helpers' );

const FEATURED_CONNECTORS = [ 'Vercel AI Gateway', 'Mistral' ];

test.describe( 'Featured connectors', () => {
	test( 'Featured connectors are listed with an install action', async ( {
		admin,
		page,
	} ) => {
		await visitConnectorsPage( admin );

		for ( const name of FEATURED_CONNECTORS ) {
			const connector = page.locator( '[role="listitem"]', {
				has: page.getByRole( 'heading', { name, exact: true } ),
			} );

			await expect( connector ).toBeVisible();
			await expect(
				connector.getByRole( 'button', { name: 'Install' } )
			).toBeVisible();
		}
	} );
} );

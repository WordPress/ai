/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );

test.describe( 'MCP Access Experiment', () => {
	test( 'Can enable the MCP Access experiment', async ( { admin, page } ) => {
		await enableExperiment( admin, page, 'MCP Access' );
	} );

	test( 'Can manage ability exposure on the MCP Access screen', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, 'MCP Access' );

		// Start from a clean slate: clear any overrides left behind by a
		// previous (retried) run, so toggling always creates an override.
		const settings = await requestUtils.rest( {
			path: '/ai/v1/mcp/settings',
		} );
		const stale = Object.keys( settings.overrides ?? {} );
		if ( stale.length ) {
			await requestUtils.rest( {
				method: 'POST',
				path: '/ai/v1/mcp/settings',
				data: {
					overrides: Object.fromEntries(
						stale.map( ( name ) => [ name, null ] )
					),
				},
			} );
		}

		await admin.visitAdminPage( 'tools.php' );

		// Ensure there's a page under Tools.
		await expect(
			page.locator( '#adminmenu' ).getByRole( 'link', {
				name: 'MCP Access',
				exact: true,
			} )
		).toBeVisible();

		// Visit the MCP Access page.
		await admin.visitAdminPage( 'tools.php?page=ai-mcp-access' );

		await expect(
			page.getByRole( 'heading', { name: 'MCP Access', exact: true } )
		).toBeVisible();

		// The abilities table renders with at least one row.
		const table = page.locator( '.ai-mcp-access' ).getByRole( 'table' );
		await expect( table ).toBeVisible();

		const firstRow = table.locator( 'tbody tr' ).first();
		await expect( firstRow ).toBeVisible();

		// The adapter's own default-server abilities are never listed.
		await expect(
			table.locator( 'code', { hasText: 'mcp-adapter/' } )
		).toHaveCount( 0 );

		// Toggle the first ability away from its default. The exposure
		// checkbox is targeted by label to avoid the DataViews bulk-selection
		// checkbox in the same row.
		await firstRow
			.getByRole( 'checkbox', { name: /^Expose .* over MCP$/ } )
			.click();
		await expect( firstRow ).toContainText( 'Overridden' );

		await page
			.getByRole( 'button', { name: 'Save changes', exact: true } )
			.click();
		await expect(
			page
				.locator( '.ai-mcp-access' )
				.getByText( 'MCP exposure settings saved.' )
		).toBeVisible();

		// The override survives a reload.
		await admin.visitAdminPage( 'tools.php?page=ai-mcp-access' );
		await expect( table.locator( 'tbody tr' ).first() ).toContainText(
			'Overridden'
		);

		// Reset the ability back to its default via the row actions menu.
		await table
			.locator( 'tbody tr' )
			.first()
			.getByRole( 'button', { name: 'Actions' } )
			.click();
		await page
			.getByRole( 'menuitem', { name: 'Reset to default' } )
			.click();
		await page
			.getByRole( 'button', { name: 'Save changes', exact: true } )
			.click();
		await expect(
			page
				.locator( '.ai-mcp-access' )
				.getByText( 'MCP exposure settings saved.' )
		).toBeVisible();
		await expect( table.locator( 'tbody tr' ).first() ).toContainText(
			'Default'
		);
	} );
} );

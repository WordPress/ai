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
			page
				.getByRole( 'navigation', { name: 'Main menu', exact: true } )
				.getByRole( 'link', {
					name: 'MCP Access',
					exact: true,
				} )
		).toBeVisible();

		// Visit the MCP Access page.
		await admin.visitAdminPage( 'tools.php?page=ai-mcp-access' );

		await expect(
			page.getByRole( 'heading', { name: 'MCP Access', exact: true } )
		).toBeVisible();

		const main = page.getByRole( 'main' );

		// The abilities table renders with at least one ability row.
		const table = main.getByRole( 'table' );
		await expect( table ).toBeVisible();

		const exposureCheckbox = page.getByRole( 'checkbox', {
			name: /^Expose .* over MCP$/,
		} );
		const firstRow = table
			.getByRole( 'row' )
			.filter( { has: exposureCheckbox } )
			.first();
		await expect( firstRow ).toBeVisible();

		// The adapter's own default-server abilities are never listed.
		await expect( table.getByText( /^mcp-adapter\// ) ).toHaveCount( 0 );

		const checkbox = firstRow.getByRole( 'checkbox' );
		const saveButton = main.getByRole( 'button', {
			name: 'Save changes',
			exact: true,
		} );
		const savedNotice = main.getByText( 'MCP exposure settings saved.', {
			exact: true,
		} );
		const overriddenStatus = firstRow.getByRole( 'cell', {
			name: 'Overridden',
			exact: true,
		} );
		const defaultStatus = firstRow.getByRole( 'cell', {
			name: 'Default',
			exact: true,
		} );

		// Toggle the first ability away from its default.
		await checkbox.click();
		await expect( overriddenStatus ).toBeVisible();

		await saveButton.click();
		await expect( savedNotice ).toBeVisible();

		// The override survives a reload.
		await admin.visitAdminPage( 'tools.php?page=ai-mcp-access' );
		await expect( overriddenStatus ).toBeVisible();

		// Toggling the checkbox back restores the default (no reset action).
		await checkbox.click();
		await expect( defaultStatus ).toBeVisible();

		await saveButton.click();
		await expect( savedNotice ).toBeVisible();
		await expect( defaultStatus ).toBeVisible();
	} );
} );

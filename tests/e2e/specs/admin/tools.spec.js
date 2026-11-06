/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const tools = [
	[ 'abilities-explorer', 'Abilities Explorer', 'ai-abilities-explorer' ],
	[ 'ai-request-logging', 'AI Request Logs', 'ai-request-logs' ],
	[ 'mcp-adapter', 'MCP Access', 'ai-mcp-access' ],
	[ 'connector-approval', 'Connector Approvals', 'ai-connector-approval' ],
];

test.beforeAll( async ( { requestUtils } ) => {
	await requestUtils.activatePlugin( 'ai' );
} );

test( 'Available Tools lists only enabled AI tools', async ( {
	admin,
	page,
	requestUtils,
} ) => {
	const settingsPath = '/wp/v2/settings';
	const settings = await requestUtils.rest( { path: settingsPath } );
	const keys = tools.map( ( [ id ] ) => `wpai_feature_${ id }_enabled` );
	const updateSettings = ( data ) =>
		requestUtils.rest( { path: settingsPath, method: 'POST', data } );
	const card = page.locator( '.card' ).filter( {
		has: page.getByRole( 'heading', { name: 'AI Tools', exact: true } ),
	} );
	const menu = page.locator( '#menu-tools' );

	try {
		for ( const enabled of [ [ 0, 1, 2, 3 ], [ 1 ], [] ] ) {
			await updateSettings(
				Object.fromEntries(
					keys.map( ( key, index ) => [
						key,
						enabled.includes( index ),
					] )
				)
			);
			await admin.visitAdminPage(
				'tools.php',
				'ai-e2e-skip-mcp-install=1'
			);
			await expect( card ).toHaveCount( enabled.length ? 1 : 0 );
			await expect( card.getByRole( 'link' ) ).toHaveCount(
				enabled.length
			);

			for ( const [ index, [ , name, slug ] ] of tools.entries() ) {
				const link = card.getByRole( 'link', { name, exact: true } );
				const menuLink = menu.getByRole( 'link', {
					name,
					exact: true,
				} );
				if ( enabled.includes( index ) ) {
					const url = `tools.php?page=${ slug }`;
					await expect( menuLink ).toHaveAttribute( 'href', url );
					await expect( link ).toHaveAttribute(
						'href',
						new URL( url, page.url() ).href
					);
				} else {
					await expect( menuLink ).toHaveCount( 0 );
					await expect( link ).toHaveCount( 0 );
				}
			}

			if ( enabled.length === tools.length ) {
				for ( const [ , name, slug ] of tools ) {
					await admin.visitAdminPage(
						'tools.php',
						`page=${ slug }&ai-e2e-skip-mcp-install=1`
					);
					await expect(
						page.getByRole( 'heading', { name, exact: true } )
					).toBeVisible();
				}
			}
		}
	} finally {
		await updateSettings(
			Object.fromEntries(
				keys.map( ( key ) => [ key, Boolean( settings[ key ] ) ] )
			)
		);
	}
} );

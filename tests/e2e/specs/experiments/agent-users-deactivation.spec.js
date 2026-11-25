/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );
const {
	addApplicationPasswordOnProfile,
	agentRequest,
	createAgent,
	createApplicationPassword,
	createHuman,
	deleteAllUsers,
	loginAs,
} = require( '../../utils/agent-users' );

/**
 * Returns the AI plugin's row on the Plugins screen.
 *
 * @param {Object} page Page fixture.
 * @return {Object} The row locator.
 */
const pluginRow = ( page ) =>
	page.locator( 'tr[data-plugin="ai/ai.php"]' ).first();

test.describe( 'Agent Users across plugin deactivation', () => {
	// The tests deactivate the plugin, so make sure it is active again for the specs that follow.
	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'ai' );
		await deleteAllUsers( requestUtils );
	} );

	test( 'Deactivating revokes agent credentials and reactivation explains how to reissue them', async ( {
		admin,
		page,
		requestUtils,
		playwright,
		baseURL,
	} ) => {
		await enableExperiment( admin, page, 'Agent Users' );

		const parent = await createHuman( requestUtils, 'author' );
		const agent = await createAgent( admin, page, {
			parentId: parent.id,
			role: 'author',
		} );
		const revoked = {
			login: agent.login,
			password: await createApplicationPassword( requestUtils, agent.id ),
		};
		const whoAmI = ( credentials ) =>
			agentRequest( playwright, baseURL, credentials, {
				route: '/wp/v2/users/me',
			} );
		expect( ( await whoAmI( revoked ) ).status ).toBe( 200 );

		// The Plugins screen warns, and the Deactivate link asks for confirmation.
		await admin.visitAdminPage( 'plugins.php' );
		await expect(
			page.locator( '.wpai-agent-deactivation-warning' )
		).toContainText( 'Deactivating this plugin revokes every agent' );

		let confirmation = '';
		page.once( 'dialog', async ( dialog ) => {
			confirmation = dialog.message();
			await dialog.accept();
		} );
		await pluginRow( page )
			.getByRole( 'link', { name: /^Deactivate/ } )
			.click();
		await expect( page.locator( '#message' ) ).toContainText(
			'Plugin deactivated.'
		);
		expect( confirmation ).toContain( 'revokes every agent' );
		expect( ( await whoAmI( revoked ) ).status ).toBe( 401 );

		// Reactivation explains the revoked credential to the agent.
		await pluginRow( page )
			.getByRole( 'link', { name: /^Activate/ } )
			.click();
		await expect( page.locator( '#message' ) ).toContainText(
			'Plugin activated.'
		);
		const explained = await whoAmI( revoked );
		expect( explained.status ).toBe( 401 );
		expect( explained.body.code ).toBe( 'wpai_agent_credentials_revoked' );
		expect( explained.body.message ).toContain(
			`Ask ${ parent.username }`
		);

		// The parent sees which agent needs a new credential until it has one.
		await loginAs( page, parent.username );
		await page.goto( '/wp-admin/index.php' );
		const notice = page.locator( '#wpai-agent-reissue-notice' );
		await expect( notice ).toContainText( agent.login );
		await notice.getByRole( 'link', { name: agent.login } ).click();

		const password = await addApplicationPasswordOnProfile(
			page,
			'Reissued'
		);
		expect(
			( await whoAmI( { login: agent.login, password } ) ).status
		).toBe( 200 );

		await page.goto( '/wp-admin/index.php' );
		await expect( notice ).toHaveCount( 0 );
	} );
} );

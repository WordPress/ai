/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );
const {
	agentRequest,
	createAgent,
	createApplicationPassword,
	createHuman,
	siteRest,
	uniqueSuffix,
} = require( '../../utils/agent-users' );

/**
 * Creates a site in the network.
 *
 * @param {Object} requestUtils Request utils of the network administrator.
 * @return {Promise<{id: number, path: string}>} The site.
 */
const createSite = ( requestUtils ) =>
	requestUtils.rest( {
		method: 'POST',
		path: '/ai-e2e/v1/sites',
		data: { slug: `agents-${ uniqueSuffix() }` },
	} );

/**
 * Adds a user to a site with a role.
 *
 * @param {Object} requestUtils Request utils of the network administrator.
 * @param {number} siteId       Site ID.
 * @param {number} userId       User ID.
 * @param {string} role         Role slug.
 */
const addToSite = ( requestUtils, siteId, userId, role ) =>
	requestUtils.rest( {
		method: 'POST',
		path: '/ai-e2e/v1/site-users',
		data: { site_id: siteId, user_id: userId, role },
	} );

/**
 * Grants or revokes super admin status.
 *
 * @param {Object}  requestUtils Request utils of the network administrator.
 * @param {number}  userId       User ID.
 * @param {boolean} grant        Whether to grant the status.
 */
const setSuperAdmin = ( requestUtils, userId, grant ) =>
	requestUtils.rest( {
		method: 'POST',
		path: '/ai-e2e/v1/super-admins',
		data: { user_id: userId, grant },
	} );

/**
 * Starts deleting a user from the network and opens the confirmation screen.
 *
 * @param {Object} page     Page fixture.
 * @param {string} username Username.
 */
const openNetworkDeleteScreen = async ( page, username ) => {
	await page.goto( `/wp-admin/network/users.php?s=${ username }` );
	const row = page.locator( '#the-list tr', { hasText: username } );
	await row.hover();
	await row.getByRole( 'link', { name: 'Delete', exact: true } ).click();
	await expect(
		page.getByRole( 'heading', { name: 'Delete Users' } )
	).toBeVisible();
};

/**
 * Sets up a parent on the main site whose agent owns a post on another site.
 *
 * Agents only join their parent's sites, or any site while the parent is a
 * super admin, so the parent is one while the agent joins the other site.
 *
 * @param {Object} admin        Admin fixture.
 * @param {Object} page         Page fixture.
 * @param {Object} requestUtils Request utils of the network administrator.
 * @return {Promise<Object>} The parent, agent, receiver, site, and post.
 */
const setUpAgentContentOutsideParentSites = async (
	admin,
	page,
	requestUtils
) => {
	const parent = await createHuman( requestUtils, 'editor' );
	const receiver = await createHuman( requestUtils, 'author' );
	const agent = await createAgent( admin, page, {
		parentId: parent.id,
		role: 'author',
	} );
	const site = await createSite( requestUtils );
	await setSuperAdmin( requestUtils, parent.id, true );
	await addToSite( requestUtils, site.id, agent.id, 'author' );
	await setSuperAdmin( requestUtils, parent.id, false );
	await addToSite( requestUtils, site.id, receiver.id, 'author' );
	const post = await siteRest( requestUtils, site.path, {
		method: 'POST',
		path: '/wp/v2/posts',
		data: {
			title: 'Agent post outside the parent sites',
			status: 'publish',
			author: agent.id,
		},
	} );

	return { parent, agent, receiver, site, post };
};

test.describe( 'Agent Users Experiment on multisite', () => {
	test.beforeEach( async ( { admin, page } ) => {
		await enableExperiment( admin, page, 'Agent Users' );
	} );

	// Users are not cleaned up: core's REST API cannot delete users on
	// multisite, and every test creates uniquely named ones.

	test( 'Add Agent shows only the agent form', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'user-new.php', 'wpai_agent=1' );

		await expect(
			page.getByRole( 'heading', { level: 1, name: 'Add Agent' } )
		).toBeVisible();
		await expect( page.locator( '#createuser' ) ).toBeVisible();
		await expect( page.locator( '#adduser' ) ).toBeHidden();
		await expect( page.locator( '#add-existing-user' ) ).toBeHidden();
		await expect( page.locator( '#create-new-user' ) ).toBeHidden();
	} );

	test( 'Removing a parent from one site keeps their agent on the others', async ( {
		admin,
		page,
		requestUtils,
		playwright,
		baseURL,
	} ) => {
		const parent = await createHuman( requestUtils, 'editor' );
		const agent = await createAgent( admin, page, {
			parentId: parent.id,
			role: 'editor',
		} );
		const site = await createSite( requestUtils );
		await addToSite( requestUtils, site.id, parent.id, 'editor' );
		await addToSite( requestUtils, site.id, agent.id, 'editor' );
		const credentials = {
			login: agent.login,
			password: await createApplicationPassword( requestUtils, agent.id ),
		};
		const createDraft = ( sitePath ) =>
			agentRequest( playwright, baseURL, credentials, {
				method: 'POST',
				route: '/wp/v2/posts',
				data: { title: 'Agent draft', status: 'draft' },
				sitePath,
			} );

		expect( ( await createDraft( '' ) ).status ).toBe( 201 );
		expect( ( await createDraft( site.path ) ).status ).toBe( 201 );

		// Remove the parent from the main site through the site Users screen.
		await admin.visitAdminPage( 'users.php', `s=${ parent.username }` );
		const row = page.locator( '#the-list tr', {
			hasText: parent.username,
		} );
		await row.hover();
		await row.getByRole( 'link', { name: 'Remove', exact: true } ).click();
		await expect(
			page.getByText(
				`Their agents will be removed from this site too: ${ agent.login }.`
			)
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Confirm Removal' } ).click();
		await expect( page.locator( '#message' ) ).toContainText(
			'User removed'
		);

		const mainSite = await createDraft( '' );
		expect( mainSite.status, JSON.stringify( mainSite.body ) ).toBe( 401 );
		// A wrong or revoked credential would be a 401 as well; this one is suspended here.
		expect( mainSite.body.code ).toBe( 'wpai_agent_suspended' );
		const otherSite = await createDraft( site.path );
		expect( otherSite.status, JSON.stringify( otherSite.body ) ).toBe(
			201
		);

		// The network profile describes the agent across its sites.
		await page.goto(
			`/wp-admin/network/user-edit.php?user_id=${ agent.id }`
		);
		await expect(
			page.locator( '.wpai-agent-account-type' )
		).toContainText(
			'and, on each site, can only do what both its role and that user allow.'
		);

		const mainMembers = await requestUtils.rest( {
			path: '/wp/v2/users',
			params: { context: 'edit', per_page: 100 },
		} );
		expect( mainMembers.map( ( user ) => user.id ) ).not.toContain(
			agent.id
		);
	} );

	test( 'Deleting a parent can reassign agent content on sites the parent does not belong to', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { parent, agent, receiver, site, post } =
			await setUpAgentContentOutsideParentSites(
				admin,
				page,
				requestUtils
			);

		await openNetworkDeleteScreen( page, parent.username );

		const choice = page.locator( '.wpai-agent-site-content', {
			hasText: agent.login,
		} );
		await expect( choice ).toBeVisible();
		await choice
			.getByRole( 'radio', {
				name: 'Attribute all content to another user.',
			} )
			.check();
		const receivers = choice.getByRole( 'combobox' );
		await expect(
			receivers.locator( `option[value="${ agent.id }"]` )
		).toHaveCount( 0 );
		await receivers.selectOption( String( receiver.id ) );
		await page.getByRole( 'button', { name: 'Confirm Deletion' } ).click();
		await expect( page.locator( '#message' ) ).toContainText(
			'User deleted'
		);

		const kept = await siteRest( requestUtils, site.path, {
			path: `/wp/v2/posts/${ post.id }`,
			params: { context: 'edit' },
		} );
		expect( kept.author ).toBe( receiver.id );
		expect( kept.status ).toBe( 'publish' );
		await expect(
			siteRest( requestUtils, '', { path: `/wp/v2/users/${ agent.id }` } )
		).rejects.toThrow( 'rest_user_invalid_id' );
	} );

	test( 'Deleting a parent applies "Delete all content" to agent content outside the parent sites as core does', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const { parent, agent, site, post } =
			await setUpAgentContentOutsideParentSites(
				admin,
				page,
				requestUtils
			);

		await openNetworkDeleteScreen( page, parent.username );

		await page
			.locator( '.wpai-agent-site-content', { hasText: agent.login } )
			.getByRole( 'radio', { name: 'Delete all content.' } )
			.check();
		await page.getByRole( 'button', { name: 'Confirm Deletion' } ).click();
		await expect( page.locator( '#message' ) ).toContainText(
			'User deleted'
		);

		// Core removes the user from the site without deleting the content, for any user.
		const kept = await siteRest( requestUtils, site.path, {
			path: `/wp/v2/posts/${ post.id }`,
			params: { context: 'edit' },
		} );
		expect( kept.status ).toBe( 'publish' );
		await expect(
			siteRest( requestUtils, '', { path: `/wp/v2/users/${ agent.id }` } )
		).rejects.toThrow( 'rest_user_invalid_id' );
	} );
} );

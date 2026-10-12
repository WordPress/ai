/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );

const {
	createAgent,
	createApplicationPassword,
	createHuman,
	agentRequest,
	loginAs,
	addApplicationPasswordOnProfile,
} = require( '../../utils/agent-users' );

test.describe( 'Agent Users Experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
		await enableExperiment( admin, page, 'Agent Users' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
		await requestUtils.deleteAllPosts();
	} );

	test( 'Administrator creates an agent and connects it', async ( {
		admin,
		page,
		requestUtils,
		playwright,
		baseURL,
	} ) => {
		const parent = await createHuman( requestUtils, 'editor' );

		await admin.visitAdminPage( 'user-new.php', 'wpai_agent=1' );
		await expect(
			page.getByRole( 'heading', { name: 'Add Agent' } )
		).toBeVisible();
		await expect(
			page.getByText(
				'Every agent acts on behalf of a parent user and can never do more than they can.',
				{ exact: false }
			)
		).toBeVisible();

		// The parent is a required, deliberate choice.
		const parentField = page.getByLabel( 'Parent user' );
		await expect( parentField ).toHaveAttribute( 'required', '' );
		await expect( parentField ).toHaveValue( '' );

		const agent = await createAgent( admin, page, {
			parentId: parent.id,
			role: 'editor',
		} );

		// Creation leads straight to the one-time credential step.
		await expect( page ).toHaveURL( /wpai_agent_created=1/ );
		await expect(
			page.locator( '.notice-success', {
				hasText: 'The password is shown only once.',
			} )
		).toBeVisible();

		const password = await addApplicationPasswordOnProfile(
			page,
			'Agent connection'
		);

		const me = await agentRequest(
			playwright,
			baseURL,
			{ login: agent.login, password },
			{
				method: 'GET',
				route: '/wp/v2/users/me',
			}
		);
		expect( me.status, JSON.stringify( me.body ) ).toBe( 200 );
		expect( me.body.id ).toBe( agent.id );
		expect( me.body.wpai_is_agent ).toBe( true );
		expect( me.body.wpai_agent_parent ).toBe( parent.id );

		// After a reload the credential is listed, but its secret is gone.
		await page.reload();
		await expect(
			page.locator( '.application-passwords-user tbody tr', {
				hasText: 'Agent connection',
			} )
		).toBeVisible();
		await expect(
			page.locator( '#new-application-password-value' )
		).toHaveCount( 0 );
		await expect( page.getByText( password ) ).toHaveCount( 0 );
	} );

	test( 'Parent manages the credentials of their agent', async ( {
		admin,
		page,
		requestUtils,
		playwright,
		baseURL,
	} ) => {
		const parent = await createHuman( requestUtils, 'author' );
		const stranger = await createHuman( requestUtils, 'author' );
		const agent = await createAgent( admin, page, {
			parentId: parent.id,
			role: 'author',
		} );

		// The parent finds the agent through their own profile.
		await loginAs( page, parent.username );
		await page.goto( '/wp-admin/profile.php' );
		await page
			.locator( '.wpai-agent-list' )
			.getByRole( 'link', { name: new RegExp( agent.login ) } )
			.click();
		await expect( page ).toHaveURL(
			new RegExp( `user-edit\\.php\\?user_id=${ agent.id }` )
		);

		const password = await addApplicationPasswordOnProfile(
			page,
			'Parent credential'
		);
		const credentials = { login: agent.login, password };
		expect(
			(
				await agentRequest( playwright, baseURL, credentials, {
					method: 'GET',
					route: '/wp/v2/users/me',
				} )
			).status
		).toBe( 200 );

		// Revoking the credential stops it from working.
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		const row = page.locator( '.application-passwords-user tbody tr', {
			hasText: 'Parent credential',
		} );
		await row.getByRole( 'button', { name: /Revoke/ } ).click();
		await expect( row ).toHaveCount( 0 );
		expect(
			(
				await agentRequest( playwright, baseURL, credentials, {
					method: 'GET',
					route: '/wp/v2/users/me',
				} )
			).status
		).toBe( 401 );

		// An unrelated user cannot manage the agent.
		await loginAs( page, stranger.username );
		await page.goto( `/wp-admin/user-edit.php?user_id=${ agent.id }` );
		await expect(
			page.getByText( 'Sorry, you are not allowed to edit this user.' )
		).toBeVisible();
	} );

	test( 'Agent access follows the permissions of its parent', async ( {
		admin,
		page,
		requestUtils,
		playwright,
		baseURL,
	} ) => {
		const parent = await createHuman( requestUtils, 'editor' );
		const writer = await createHuman( requestUtils, 'author' );
		const agent = await createAgent( admin, page, {
			parentId: parent.id,
			role: 'editor',
		} );
		const credentials = {
			login: agent.login,
			password: await createApplicationPassword( requestUtils, agent.id ),
		};
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: {
				title: 'Written by someone else',
				status: 'publish',
				author: writer.id,
			},
		} );
		const editOthersPost = () =>
			agentRequest( playwright, baseURL, credentials, {
				method: 'POST',
				route: `/wp/v2/posts/${ post.id }`,
				data: { title: 'Edited by the agent' },
			} );

		expect( ( await editOthersPost() ).status ).toBe( 200 );

		// Demoting the parent narrows the agent immediately.
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/users/${ parent.id }`,
			data: { roles: [ 'author' ] },
		} );
		expect( ( await editOthersPost() ).status ).toBe( 403 );

		// A parent who can no longer have agents suspends them. Without an
		// explicit grant, only users who can edit posts may have agents.
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/users/${ parent.id }`,
			data: { roles: [ 'subscriber' ] },
		} );
		const suspended = await agentRequest(
			playwright,
			baseURL,
			credentials,
			{
				method: 'GET',
				route: '/wp/v2/users/me',
			}
		);
		expect( suspended.status, JSON.stringify( suspended.body ) ).toBe(
			401
		);
		expect( suspended.body.code ).toBe( 'wpai_agent_suspended' );

		// The admin screens explain the suspension.
		await admin.visitAdminPage( 'users.php', `s=${ agent.login }` );
		await expect(
			page.locator( '#the-list tr', { hasText: agent.login } )
		).toContainText( 'suspended' );

		await admin.visitAdminPage( 'user-edit.php', `user_id=${ agent.id }` );
		await expect(
			page.locator( '.wpai-agent-account-type' )
		).toContainText( 'can no longer have agents' );
	} );

	test( 'Deleting a parent keeps agent content when it is reassigned', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const parent = await createHuman( requestUtils, 'editor' );
		const receiver = await createHuman( requestUtils, 'editor' );
		const agent = await createAgent( admin, page, {
			parentId: parent.id,
			role: 'author',
		} );
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: {
				title: 'Agent draft',
				status: 'draft',
				author: agent.id,
			},
		} );

		await admin.visitAdminPage( 'users.php', `s=${ parent.username }` );
		const row = page.locator( '#the-list tr', {
			hasText: parent.username,
		} );
		await row.hover();
		await row.locator( '.delete a' ).click();

		// The parent owns nothing, but the agent's content still offers reassignment.
		await expect(
			page.getByRole( 'heading', { name: 'Delete Users' } )
		).toBeVisible();
		await expect(
			page.locator( '.wpai-agents-deleted-with-parent' )
		).toContainText( agent.login );

		const reassignTo = page.getByLabel(
			'Select a user to attribute the content to.'
		);
		await expect(
			reassignTo.locator( `option[value="${ receiver.id }"]` )
		).toHaveCount( 1 );
		await expect(
			reassignTo.locator( `option[value="${ agent.id }"]` )
		).toHaveCount( 0 );

		await page
			.getByRole( 'radio', {
				name: 'Attribute all content to another user.',
			} )
			.check();
		await reassignTo.selectOption( String( receiver.id ) );
		await page.getByRole( 'button', { name: 'Confirm Deletion' } ).click();
		await expect( page.locator( '#message' ) ).toContainText(
			'User deleted.'
		);

		const kept = await requestUtils.rest( {
			path: `/wp/v2/posts/${ post.id }`,
			params: { context: 'edit' },
		} );
		expect( kept.author ).toBe( receiver.id );
		expect( kept.status ).toBe( 'draft' );

		const users = await requestUtils.rest( {
			path: '/wp/v2/users',
			params: { context: 'edit', per_page: 100 },
		} );
		const ids = users.map( ( user ) => user.id );
		expect( ids ).not.toContain( agent.id );
		expect( ids ).not.toContain( parent.id );
	} );
} );

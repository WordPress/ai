/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );

/**
 * Runs an ability through the client-side Abilities API, exactly as a consumer
 * would in the browser.
 *
 * Mirrors the plugin's own sequence in `src/utils/run-ability.ts`: importing
 * `@wordpress/core-abilities` initializes the client store, so we await `ready`
 * before calling `executeAbility` from `@wordpress/abilities`. The client
 * modules are only present in the page's import map once an AI experiment is
 * enabled in the block editor (it declares them as `module_dependencies`).
 *
 * @param {import('@playwright/test').Page} page      The Playwright page.
 * @param {string}                          abilityId The ability to run.
 * @param {Object}                          input     The ability input.
 * @return {Promise<Object>} `{ ok: true, result }` or `{ ok: false, code }`.
 */
async function runAbility( page, abilityId, input ) {
	return page.evaluate(
		async ( { id, abilityInput } ) => {
			const { ready } = await import( '@wordpress/core-abilities' );
			if ( ready ) {
				await ready;
			}

			const { executeAbility } = await import( '@wordpress/abilities' );

			try {
				const result = await executeAbility( id, abilityInput );
				return { ok: true, result };
			} catch ( e ) {
				return { ok: false, code: e && e.code ? e.code : null };
			}
		},
		{ id: abilityId, abilityInput: input }
	);
}

test.describe( 'core/user-create, core/user-update, and core/user-delete abilities (client-side Abilities API)', () => {
	let editorPostId;

	// Every user written by this spec, removed in `afterAll`.
	const createdUserIds = [];

	test.beforeAll( async ( { requestUtils } ) => {
		// The global setup deletes all `post` entries, so seed one to open the
		// block editor on; the abilities client modules are only loaded there.
		const post = await requestUtils.createPost( {
			title: 'core/user-write host post',
			status: 'publish',
		} );
		editorPostId = post.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove only the users and the post written here, leaving any other specs'
		// content alone.
		await Promise.all( [
			...createdUserIds.map( ( id ) =>
				requestUtils
					.rest( {
						method: 'DELETE',
						path: `/wp/v2/users/${ id }`,
						params: { force: true, reassign: false },
					} )
					.catch( () => {} )
			),
			requestUtils
				.rest( {
					method: 'DELETE',
					path: `/wp/v2/posts/${ editorPostId }`,
					params: { force: true },
				} )
				.catch( () => {} ),
		] );
		createdUserIds.length = 0;
	} );

	test.beforeEach( async ( { admin, page } ) => {
		// Enabling an experiment loads its block-editor script, which declares the
		// `@wordpress/abilities` + `@wordpress/core-abilities` modules as dependencies
		// and so adds them to the editor's import map.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// The user abilities are gated behind the Custom Abilities experiment, so
		// enable it to register them server-side.
		await enableExperiment( admin, page, 'Custom Abilities' );

		// Run from the block editor, where the abilities client modules are available.
		await admin.editPost( editorPostId );
	} );

	test( 'creates, updates, and deletes a user', async ( { page } ) => {
		// A unique login keeps reruns from colliding with a user a failed run left behind.
		const username = `e2e_ability_${ Date.now() }`;
		const fields = [ 'id', 'username', 'email', 'name', 'roles' ];

		const created = await runAbility( page, 'core/user-create', {
			username,
			email: `${ username }@example.com`,
			password: 'An e2e password',
			name: 'Created by an ability',
			roles: [ 'author' ],
			fields,
		} );

		expect( created.ok ).toBe( true );
		createdUserIds.push( created.result.id );
		expect( created.result.username ).toBe( username );
		expect( created.result.email ).toBe( `${ username }@example.com` );
		expect( created.result.name ).toBe( 'Created by an ability' );
		expect( created.result.roles ).toEqual( [ 'author' ] );
		// The password is never returned.
		expect( Object.keys( created.result ).sort() ).toEqual(
			[ ...fields ].sort()
		);

		const updated = await runAbility( page, 'core/user-update', {
			id: created.result.id,
			name: 'Updated by an ability',
			roles: [ 'editor' ],
			fields,
		} );

		expect( updated.ok ).toBe( true );
		expect( updated.result.id ).toBe( created.result.id );
		expect( updated.result.name ).toBe( 'Updated by an ability' );
		expect( updated.result.roles ).toEqual( [ 'editor' ] );
		// The omitted email address keeps its value.
		expect( updated.result.email ).toBe( created.result.email );

		// The deleted user is returned as it was before the deletion.
		const deleted = await runAbility( page, 'core/user-delete', {
			id: created.result.id,
			reassign: false,
			fields: [ 'id', 'name' ],
		} );

		expect( deleted.ok ).toBe( true );
		expect( deleted.result ).toEqual( {
			id: created.result.id,
			name: 'Updated by an ability',
		} );

		// The user is gone, so reading it is denied.
		const read = await runAbility( page, 'core/users-query', {
			id: created.result.id,
		} );

		expect( read.ok ).toBe( false );
	} );
} );

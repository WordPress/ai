/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );

/**
 * Runs the `core/users-query` ability through the client-side Abilities API, exactly
 * as a consumer would in the browser.
 *
 * Mirrors the plugin's own sequence in `src/utils/run-ability.ts`: importing
 * `@wordpress/core-abilities` initializes the client store (WordPress core's build
 * runs `initialize()` on load and exports the resulting `ready` promise), so we
 * await `ready` before calling `executeAbility` from `@wordpress/abilities`.
 *
 * The client modules are only present in the page's import map once an AI experiment
 * is enabled in the block editor (it declares them as `module_dependencies`), which is
 * set up in `beforeEach`.
 *
 * @param {import('@playwright/test').Page} page  The Playwright page.
 * @param {Object}                          input Optional. The ability input.
 * @return {Promise<Object>} `{ ok: true, result }` or `{ ok: false, code }`.
 */
async function runCoreUsers( page, input ) {
	const hasInput = arguments.length > 1;

	return page.evaluate(
		async ( { abilityInput, shouldPassInput } ) => {
			const { ready } = await import( '@wordpress/core-abilities' );
			if ( ready ) {
				await ready;
			}

			const { executeAbility } = await import( '@wordpress/abilities' );

			try {
				const result = shouldPassInput
					? await executeAbility( 'core/users-query', abilityInput )
					: await executeAbility( 'core/users-query' );
				return { ok: true, result };
			} catch ( e ) {
				return { ok: false, code: e && e.code ? e.code : null };
			}
		},
		{ abilityInput: input, shouldPassInput: hasInput }
	);
}

test.describe( 'core/users-query ability (client-side Abilities API)', () => {
	let currentUser;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'ai' );

		currentUser = await requestUtils.rest( {
			path: '/wp/v2/users/me',
			params: { context: 'edit' },
		} );
	} );

	test.beforeEach( async ( { admin, page } ) => {
		// Enabling an experiment loads its block-editor script, which declares the
		// `@wordpress/abilities` + `@wordpress/core-abilities` modules as dependencies
		// and so adds them to the editor's import map.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// The core/users-query ability is gated behind the Custom Abilities
		// experiment, so enable it to register the ability server-side.
		await enableExperiment( admin, page, 'Custom Abilities' );

		// Run from the block editor, where the abilities client modules are available.
		await admin.createNewPost( {
			postType: 'post',
			title: 'core/users-query ability test',
		} );
	} );

	test( 'returns the current user by ID', async ( { page } ) => {
		const outcome = await runCoreUsers( page, {
			where: { ids: [ currentUser.id ] },
			fields: [ 'id', 'name', 'email' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.items ).toHaveLength( 1 );
		expect( outcome.result.items[ 0 ].id ).toBe( currentUser.id );
		expect( outcome.result.items[ 0 ].email ).toBe( currentUser.email );
		expect( outcome.result.pagination ).toEqual( {
			total: 1,
			total_pages: 1,
		} );
	} );

	test( 'returns lean fields by default', async ( { page } ) => {
		const outcome = await runCoreUsers( page, {
			where: { ids: [ currentUser.id ] },
		} );

		expect( outcome.ok ).toBe( true );
		expect( Object.keys( outcome.result.items[ 0 ] ).sort() ).toEqual( [
			'avatar_urls',
			'id',
			'link',
			'name',
			'slug',
		] );
	} );

	test( 'returns a page of users for an empty request', async ( {
		page,
	} ) => {
		const outcome = await runCoreUsers( page, {} );

		expect( outcome.ok ).toBe( true );
		expect( Array.isArray( outcome.result.items ) ).toBe( true );
		expect( outcome.result.items.length ).toBeGreaterThan( 0 );
		expect(
			outcome.result.items.some( ( user ) => user.id === currentUser.id )
		).toBe( true );
	} );

	test( 'returns a page of users when input is omitted', async ( {
		page,
	} ) => {
		const outcome = await runCoreUsers( page );

		expect( outcome.ok ).toBe( true );
		expect( Array.isArray( outcome.result.items ) ).toBe( true );
		expect( outcome.result.items.length ).toBeGreaterThan( 0 );
		expect(
			outcome.result.items.some( ( user ) => user.id === currentUser.id )
		).toBe( true );
	} );

	test( 'limits each user to the requested fields', async ( { page } ) => {
		const outcome = await runCoreUsers( page, {
			fields: [ 'id', 'name' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.items.length ).toBeGreaterThan( 0 );
		for ( const user of outcome.result.items ) {
			expect( Object.keys( user ).sort() ).toEqual( [ 'id', 'name' ] );
		}
	} );

	test( 'filters users by slug and username', async ( { page } ) => {
		const bySlug = await runCoreUsers( page, {
			where: { slugs: [ currentUser.slug ] },
			fields: [ 'id' ],
		} );

		expect( bySlug.ok ).toBe( true );
		expect( bySlug.result.items ).toEqual( [ { id: currentUser.id } ] );

		const byUsername = await runCoreUsers( page, {
			where: { usernames: [ currentUser.username ] },
			fields: [ 'id' ],
		} );

		expect( byUsername.ok ).toBe( true );
		expect( byUsername.result.items ).toEqual( [ { id: currentUser.id } ] );
	} );

	test( 'filters users by role', async ( { page } ) => {
		const outcome = await runCoreUsers( page, {
			where: { roles: [ 'administrator' ] },
			fields: [ 'id', 'roles' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect(
			outcome.result.items.some( ( user ) => user.id === currentUser.id )
		).toBe( true );
		for ( const user of outcome.result.items ) {
			expect( user.roles ).toContain( 'administrator' );
		}
	} );

	test( 'paginates the results', async ( { page } ) => {
		const outcome = await runCoreUsers( page, {
			fields: [ 'id' ],
			pagination: { page: 1, per_page: 1 },
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.items ).toHaveLength( 1 );
		expect( outcome.result.pagination.total_pages ).toBe(
			outcome.result.pagination.total
		);
	} );

	test( 'rejects the old root-level filters', async ( { page } ) => {
		const outcome = await runCoreUsers( page, {
			id: currentUser.id,
		} );

		expect( outcome.ok ).toBe( false );
	} );
} );

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	enableExperiment,
	enableExperiments,
} = require( '../../utils/helpers' );

/**
 * Runs the `core/read-users` ability through the client-side Abilities API, exactly
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
					? await executeAbility( 'core/read-users', abilityInput )
					: await executeAbility( 'core/read-users' );
				return { ok: true, result };
			} catch ( e ) {
				return { ok: false, code: e && e.code ? e.code : null };
			}
		},
		{ abilityInput: input, shouldPassInput: hasInput }
	);
}

test.describe( 'core/read-users ability (client-side Abilities API)', () => {
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
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// Run from the block editor, where the abilities client modules are available.
		await admin.createNewPost( {
			postType: 'post',
			title: 'core/read-users ability test',
		} );
	} );

	test( 'returns the current user by ID', async ( { page } ) => {
		const outcome = await runCoreUsers( page, {
			id: currentUser.id,
			fields: [ 'id', 'display_name', 'user_email' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.id ).toBe( currentUser.id );
		expect( outcome.result.user_email ).toBe( currentUser.email );
		expect( outcome.result.users ).toBeUndefined();
		expect( outcome.result.total ).toBeUndefined();
		expect( outcome.result.total_pages ).toBeUndefined();
	} );

	test( 'returns lean fields by default for a single user', async ( {
		page,
	} ) => {
		const outcome = await runCoreUsers( page, {
			id: currentUser.id,
		} );

		expect( outcome.ok ).toBe( true );
		expect( Object.keys( outcome.result ).sort() ).toEqual( [
			'avatar_urls',
			'display_name',
			'id',
			'link',
			'user_nicename',
		] );
	} );

	test( 'returns a users collection for an empty request', async ( {
		page,
	} ) => {
		const outcome = await runCoreUsers( page, {} );

		expect( outcome.ok ).toBe( true );
		expect( Array.isArray( outcome.result.users ) ).toBe( true );
		expect( outcome.result.users.length ).toBeGreaterThan( 0 );
		expect(
			outcome.result.users.some( ( user ) => user.id === currentUser.id )
		).toBe( true );
	} );

	test( 'returns a users collection when input is omitted', async ( {
		page,
	} ) => {
		const outcome = await runCoreUsers( page );

		expect( outcome.ok ).toBe( true );
		expect( Array.isArray( outcome.result.users ) ).toBe( true );
		expect( outcome.result.users.length ).toBeGreaterThan( 0 );
		expect(
			outcome.result.users.some( ( user ) => user.id === currentUser.id )
		).toBe( true );
	} );

	test( 'limits each user to the requested fields', async ( { page } ) => {
		const outcome = await runCoreUsers( page, {
			fields: [ 'id', 'display_name' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.users.length ).toBeGreaterThan( 0 );
		for ( const user of outcome.result.users ) {
			expect( Object.keys( user ).sort() ).toEqual( [
				'display_name',
				'id',
			] );
		}
	} );

	test( 'limits collection results to included users', async ( { page } ) => {
		const outcome = await runCoreUsers( page, {
			include: [ currentUser.id ],
			fields: [ 'id' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.users ).toEqual( [ { id: currentUser.id } ] );
		expect( typeof outcome.result.total ).toBe( 'number' );
		expect( typeof outcome.result.total_pages ).toBe( 'number' );
	} );

	test( 'filters collection mode by role', async ( { page } ) => {
		const outcome = await runCoreUsers( page, {
			roles: [ 'administrator' ],
			fields: [ 'id', 'roles' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect(
			outcome.result.users.some( ( user ) => user.id === currentUser.id )
		).toBe( true );
		for ( const user of outcome.result.users ) {
			expect( user.roles ).toContain( 'administrator' );
		}
	} );
} );

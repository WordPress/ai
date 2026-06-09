/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Runs the `core/settings` ability through the client-side Abilities API
 * (`@wordpress/abilities`), exactly as a consumer would in the browser.
 *
 * Mirrors the plugin's own import sequence: wait for `@wordpress/core-abilities`
 * to be ready, then call `executeAbility` from `@wordpress/abilities`. When the
 * client modules are not enqueued (e.g. a WordPress build without the client-side
 * Abilities API), returns `{ unavailable: true }` so the test can skip.
 *
 * @param {import('@playwright/test').Page} page  The Playwright page.
 * @param {Object}                          input The ability input.
 * @return {Promise<Object>} `{ unavailable }`, `{ ok: true, result }`, or `{ ok: false, code }`.
 */
async function runCoreSettings( page, input ) {
	return page.evaluate( async ( abilityInput ) => {
		let api;
		try {
			const core = await import( '@wordpress/core-abilities' );
			if ( core && core.ready ) {
				await core.ready;
			}
			api = await import( '@wordpress/abilities' );
		} catch {
			api = window.wp && window.wp.abilities;
		}

		if ( ! api || typeof api.executeAbility !== 'function' ) {
			return { unavailable: true };
		}

		try {
			const result = await api.executeAbility(
				'core/settings',
				abilityInput
			);
			return { ok: true, result };
		} catch ( e ) {
			return { ok: false, code: e && e.code ? e.code : null };
		}
	}, input );
}

const SKIP_REASON =
	'The @wordpress/abilities client is not enqueued in this environment.';

test.describe( 'core/settings ability (client-side Abilities API)', () => {
	test.beforeEach( async ( { admin } ) => {
		// Load wp-admin so the Abilities API client modules and REST nonce are available.
		await admin.visitAdminPage( 'index.php' );
	} );

	test( 'returns a flat, correctly typed map of settings', async ( {
		page,
	} ) => {
		const outcome = await runCoreSettings( page, {} );
		test.skip( outcome.unavailable === true, SKIP_REASON );

		expect( outcome.ok ).toBe( true );
		// Flat map keyed by setting name (not grouped/nested).
		expect( typeof outcome.result.blogname ).toBe( 'string' );
		expect( typeof outcome.result.posts_per_page ).toBe( 'number' );
		expect( typeof outcome.result.use_smilies ).toBe( 'boolean' );
	} );

	test( 'filters by group', async ( { page } ) => {
		const outcome = await runCoreSettings( page, { group: 'reading' } );
		test.skip( outcome.unavailable === true, SKIP_REASON );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result ).toHaveProperty( 'posts_per_page' );
		// Settings from other groups (and schema defaults) must not leak in.
		expect( outcome.result ).not.toHaveProperty( 'blogname' );
		expect( outcome.result ).not.toHaveProperty( 'use_smilies' );
	} );

	test( 'filters by slugs', async ( { page } ) => {
		const outcome = await runCoreSettings( page, {
			slugs: [ 'blogname', 'posts_per_page' ],
		} );
		test.skip( outcome.unavailable === true, SKIP_REASON );

		expect( outcome.ok ).toBe( true );
		expect( Object.keys( outcome.result ).sort() ).toEqual( [
			'blogname',
			'posts_per_page',
		] );
	} );

	test( 'rejects group and slugs together (mutually exclusive)', async ( {
		page,
	} ) => {
		const outcome = await runCoreSettings( page, {
			group: 'reading',
			slugs: [ 'blogname' ],
		} );
		test.skip( outcome.unavailable === true, SKIP_REASON );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'ability_invalid_input' );
	} );
} );

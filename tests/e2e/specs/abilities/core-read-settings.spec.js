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
 * Runs the `core/read-settings` ability through the client-side Abilities API, exactly
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
 * @param {Object}                          input The ability input.
 * @return {Promise<Object>} `{ ok: true, result }` or `{ ok: false, code }`.
 */
async function runCoreReadSettings( page, input ) {
	return page.evaluate( async ( abilityInput ) => {
		const { ready } = await import( '@wordpress/core-abilities' );
		if ( ready ) {
			await ready;
		}

		const { executeAbility } = await import( '@wordpress/abilities' );

		try {
			const result = await executeAbility(
				'core/read-settings',
				abilityInput
			);
			return { ok: true, result };
		} catch ( e ) {
			return { ok: false, code: e && e.code ? e.code : null };
		}
	}, input );
}

test.describe( 'core/read-settings ability (client-side Abilities API)', () => {
	test.beforeEach( async ( { admin, page } ) => {
		// Enabling an experiment loads its block-editor script, which declares the
		// `@wordpress/abilities` + `@wordpress/core-abilities` modules as dependencies
		// and so adds them to the editor's import map.
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// Run from the block editor, where the abilities client modules are available.
		await admin.createNewPost( {
			postType: 'post',
			title: 'core/read-settings ability test',
		} );
	} );

	test( 'returns a flat, correctly typed map of settings', async ( {
		page,
	} ) => {
		const outcome = await runCoreReadSettings( page, {} );

		expect( outcome.ok ).toBe( true );
		// Flat map keyed by setting name (not grouped/nested).
		expect( typeof outcome.result.blogname ).toBe( 'string' );
		expect( typeof outcome.result.posts_per_page ).toBe( 'number' );
		expect( typeof outcome.result.use_smilies ).toBe( 'boolean' );
	} );

	test( 'filters by group', async ( { page } ) => {
		const outcome = await runCoreReadSettings( page, { group: 'reading' } );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result ).toHaveProperty( 'posts_per_page' );
		// Settings from other groups must not leak in.
		expect( outcome.result ).not.toHaveProperty( 'blogname' );
		expect( outcome.result ).not.toHaveProperty( 'use_smilies' );
	} );

	test( 'filters by fields', async ( { page } ) => {
		const outcome = await runCoreReadSettings( page, {
			fields: [ 'blogname', 'posts_per_page' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( Object.keys( outcome.result ).sort() ).toEqual( [
			'blogname',
			'posts_per_page',
		] );
	} );

	test( 'combines group and fields filters (intersection)', async ( {
		page,
	} ) => {
		// `blogname` is in the `general` group and `posts_per_page` in `reading`; only the
		// latter satisfies both filters.
		const outcome = await runCoreReadSettings( page, {
			group: 'reading',
			fields: [ 'blogname', 'posts_per_page' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( Object.keys( outcome.result ) ).toEqual( [ 'posts_per_page' ] );
	} );

	test( 'exposes a setting registered by another active plugin', async ( {
		page,
	} ) => {
		// Registered by the `e2e-testing` plugin (mapped in .wp-env.test.json)
		// with `show_in_abilities` and a default of `sample-default`.
		const outcome = await runCoreReadSettings( page, {
			fields: [ 'ai_e2e_sample_setting' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result ).toEqual( {
			ai_e2e_sample_setting: 'sample-default',
		} );
	} );
} );

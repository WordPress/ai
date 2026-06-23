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
 * Runs an ability through the client-side Abilities API, exactly as a consumer would in the
 * browser.
 *
 * Mirrors the plugin's own sequence in `src/utils/run-ability.ts`: importing
 * `@wordpress/core-abilities` initializes the client store (WordPress core's build runs
 * `initialize()` on load and exports the resulting `ready` promise), so we await `ready` before
 * calling `executeAbility` from `@wordpress/abilities`.
 *
 * The client modules are only present in the page's import map once an AI experiment is enabled
 * in the block editor (it declares them as `module_dependencies`), which is set up in
 * `beforeEach`.
 *
 * @param {import('@playwright/test').Page} page    The Playwright page.
 * @param {string}                          ability The ability name.
 * @param {Object}                          input   The ability input.
 * @return {Promise<Object>} `{ ok: true, result }` or `{ ok: false, code }`.
 */
async function runAbility( page, ability, input ) {
	return page.evaluate(
		async ( { abilityName, abilityInput } ) => {
			const { ready } = await import( '@wordpress/core-abilities' );
			if ( ready ) {
				await ready;
			}

			const { executeAbility } = await import( '@wordpress/abilities' );

			try {
				const result = await executeAbility(
					abilityName,
					abilityInput
				);
				return { ok: true, result };
			} catch ( e ) {
				return { ok: false, code: e && e.code ? e.code : null };
			}
		},
		{ abilityName: ability, abilityInput: input }
	);
}

test.describe( 'core/manage-settings ability (client-side Abilities API)', () => {
	test.beforeEach( async ( { admin, page } ) => {
		// Enabling an experiment loads its block-editor script, which declares the
		// `@wordpress/abilities` + `@wordpress/core-abilities` modules as dependencies
		// and so adds them to the editor's import map.
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// Run from the block editor, where the abilities client modules are available.
		await admin.createNewPost( {
			postType: 'post',
			title: 'core/manage-settings ability test',
		} );
	} );

	test( 'updates settings and persists the new values', async ( {
		page,
	} ) => {
		// Capture the originals so the test restores site state when it is done.
		const before = await runAbility( page, 'core/settings', {
			fields: [ 'blogname', 'posts_per_page' ],
		} );
		expect( before.ok ).toBe( true );
		const original = before.result;

		const updated = await runAbility( page, 'core/manage-settings', {
			blogname: 'Managed Settings E2E',
			posts_per_page: 13,
		} );

		expect( updated.ok ).toBe( true );
		expect( updated.result ).toEqual( {
			blogname: 'Managed Settings E2E',
			posts_per_page: 13,
		} );

		// Read back through the read ability to confirm the values were persisted.
		const after = await runAbility( page, 'core/settings', {
			fields: [ 'blogname', 'posts_per_page' ],
		} );
		expect( after.ok ).toBe( true );
		expect( after.result.blogname ).toBe( 'Managed Settings E2E' );
		expect( after.result.posts_per_page ).toBe( 13 );

		// Restore the original values.
		await runAbility( page, 'core/manage-settings', original );
	} );

	test( 'rejects an unknown setting', async ( { page } ) => {
		// `additionalProperties: false` makes an unregistered key invalid input.
		const outcome = await runAbility( page, 'core/manage-settings', {
			not_a_registered_setting: 'value',
		} );

		expect( outcome.ok ).toBe( false );
	} );

	test( 'writes a setting registered by another active plugin', async ( {
		page,
	} ) => {
		// Registered by the `e2e-testing` plugin (mapped in .wp-env.test.json) with
		// `show_in_abilities`, so it is both readable and writable.
		const before = await runAbility( page, 'core/settings', {
			fields: [ 'ai_e2e_sample_setting' ],
		} );
		expect( before.ok ).toBe( true );
		const original = before.result;

		const updated = await runAbility( page, 'core/manage-settings', {
			ai_e2e_sample_setting: 'managed-value',
		} );
		expect( updated.ok ).toBe( true );
		expect( updated.result ).toEqual( {
			ai_e2e_sample_setting: 'managed-value',
		} );

		const after = await runAbility( page, 'core/settings', {
			fields: [ 'ai_e2e_sample_setting' ],
		} );
		expect( after.result.ai_e2e_sample_setting ).toBe( 'managed-value' );

		// Restore the original value.
		await runAbility( page, 'core/manage-settings', original );
	} );
} );

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );

/**
 * Runs an ability through the client-side Abilities API, exactly as a consumer would in
 * the browser.
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

test.describe( 'core/settings-update ability (client-side Abilities API)', () => {
	test.beforeEach( async ( { admin, page } ) => {
		// Enabling an experiment loads its block-editor script, which declares the
		// `@wordpress/abilities` + `@wordpress/core-abilities` modules as dependencies
		// and so adds them to the editor's import map.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// The settings abilities are gated behind the Custom Abilities experiment, so
		// enable it to register them server-side.
		await enableExperiment( admin, page, 'Custom Abilities' );

		// Run from the block editor, where the abilities client modules are available.
		await admin.createNewPost( {
			postType: 'post',
			title: 'core/settings-update ability test',
		} );
	} );

	test( 'updates settings and returns every exposed setting', async ( {
		page,
	} ) => {
		// Capture the originals so the test restores site state when it is done.
		const before = await runAbility( page, 'core/settings-get', {
			fields: [ 'blogname', 'posts_per_page' ],
		} );
		expect( before.ok ).toBe( true );

		try {
			const updated = await runAbility( page, 'core/settings-update', {
				blogname: 'Settings Update E2E',
				posts_per_page: 13,
			} );

			expect( updated.ok ).toBe( true );
			expect( updated.result.blogname ).toBe( 'Settings Update E2E' );
			expect( updated.result.posts_per_page ).toBe( 13 );

			// The answer is the whole map `core/settings-get` returns, not just the written settings.
			const after = await runAbility( page, 'core/settings-get', {} );
			expect( after.ok ).toBe( true );
			expect( updated.result ).toEqual( after.result );
		} finally {
			// Restore the originals even when an assertion fails, so other specs see the usual site state.
			await runAbility( page, 'core/settings-update', before.result );
		}
	} );

	test( 'resets a setting to its default with null', async ( { page } ) => {
		// Registered by the `e2e-testing` plugin (mapped in .wp-env.test.json) with
		// `show_in_abilities` and a default of `sample-default`.
		try {
			const changed = await runAbility( page, 'core/settings-update', {
				ai_e2e_sample_setting: 'changed-value',
			} );
			expect( changed.ok ).toBe( true );
			expect( changed.result.ai_e2e_sample_setting ).toBe(
				'changed-value'
			);

			const reset = await runAbility( page, 'core/settings-update', {
				ai_e2e_sample_setting: null,
			} );
			expect( reset.ok ).toBe( true );
			expect( reset.result.ai_e2e_sample_setting ).toBe(
				'sample-default'
			);
		} finally {
			// Fall back to the default even when an assertion fails before the reset above.
			await runAbility( page, 'core/settings-update', {
				ai_e2e_sample_setting: null,
			} );
		}
	} );

	test( 'rejects an unknown setting', async ( { page } ) => {
		const outcome = await runAbility( page, 'core/settings-update', {
			not_a_registered_setting: 'value',
		} );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'ability_invalid_input' );
	} );
} );

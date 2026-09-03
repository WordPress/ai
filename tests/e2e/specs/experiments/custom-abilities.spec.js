/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	enableExperiment,
	enableExperiments,
} = require( '../../utils/helpers' );

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

test.describe( 'Custom Abilities Experiment', () => {
	let seededPostId;

	test.beforeAll( async ( { requestUtils } ) => {
		// The global setup deletes all `post` entries, so seed one to read.
		const post = await requestUtils.createPost( {
			title: 'Custom Abilities seeded post',
			content: 'Some content for the gated ability to read.',
			status: 'publish',
		} );
		seededPostId = post.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		if ( seededPostId ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/posts/${ seededPostId }`,
				params: { force: true },
			} );
		}
	} );

	test( 'Can enable and disable the Custom Abilities experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Toggle the experiment on and back off.
		await enableExperiment( admin, page, 'Custom Abilities' );
		await disableExperiment( admin, page, 'Custom Abilities' );
	} );

	test( 'Gated abilities are available when the experiment is enabled', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// A UI experiment loads the abilities client modules into the editor's
		// import map (needed to call executeAbility client-side).
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// The experiment under test registers the gated abilities server-side.
		await enableExperiment( admin, page, 'Custom Abilities' );

		// Run from the block editor, where the abilities client modules exist.
		await admin.editPost( seededPostId );

		const outcome = await runAbility( page, 'ai/get-post-details', {
			post_id: seededPostId,
			fields: [ 'title' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.title ).toBe( 'Custom Abilities seeded post' );
	} );

	test( 'Gated abilities are unavailable when the experiment is disabled', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Load the abilities client modules via a UI experiment, but leave the
		// Custom Abilities experiment off so the gated abilities are not registered.
		await enableExperiment( admin, page, 'Excerpt Generation' );
		await disableExperiment( admin, page, 'Custom Abilities' );

		await admin.editPost( seededPostId );

		const outcome = await runAbility( page, 'ai/get-post-details', {
			post_id: seededPostId,
			fields: [ 'title' ],
		} );

		// The ability is not registered, so the client call fails.
		expect( outcome.ok ).toBe( false );
	} );
} );

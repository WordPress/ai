/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );

/**
 * Runs the `core/templates-query` ability through the client-side Abilities API,
 * exactly as a consumer would in the browser.
 *
 * Follows the same sequence as `core-settings-get.spec.js`: importing
 * `@wordpress/core-abilities` initializes the client store, so we await `ready`
 * before calling `executeAbility` from `@wordpress/abilities`.
 *
 * @param {import('@playwright/test').Page} page The Playwright page.
 * @return {Promise<Object>} `{ ok: true, result }` or `{ ok: false, code, message }`.
 */
async function runCoreTemplatesQuery( page ) {
	return page.evaluate( async () => {
		const { ready } = await import( '@wordpress/core-abilities' );
		if ( ready ) {
			await ready;
		}

		const { executeAbility } = await import( '@wordpress/abilities' );

		try {
			const result = await executeAbility( 'core/templates-query', {} );
			return { ok: true, result };
		} catch ( e ) {
			return {
				ok: false,
				code: e && e.code ? e.code : null,
				message: e && e.message ? e.message : String( e ),
			};
		}
	} );
}

test.describe( 'core/templates-query ability (client-side Abilities API)', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		// A block theme that ships its templates as files.
		await requestUtils.activateTheme( 'twentytwentyfive' );
	} );

	test.beforeEach( async ( { admin, page } ) => {
		// Enabling an experiment loads its block-editor script, which declares the
		// `@wordpress/abilities` + `@wordpress/core-abilities` modules as dependencies
		// and so adds them to the editor's import map.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// The core/templates-query ability is gated behind the Custom Abilities
		// experiment, so enable it to register the ability server-side.
		await enableExperiment( admin, page, 'Custom Abilities' );

		// Run from the block editor, where the abilities client modules are available.
		await admin.createNewPost( {
			postType: 'post',
			title: 'core/templates-query ability test',
		} );
	} );

	test( 'reports the block theme and the templates it ships', async ( {
		page,
	} ) => {
		const outcome = await runCoreTemplatesQuery( page );

		expect( outcome.ok, outcome.message ).toBe( true );
		expect( outcome.result.block_theme ).toBe( true );
		expect( outcome.result.theme ).toBe( 'Twenty Twenty-Five' );

		const index = outcome.result.templates.find(
			( template ) => template.slug === 'index'
		);
		expect( index ).toEqual( {
			slug: 'index',
			title: expect.any( String ),
			source: 'theme',
			has_theme_file: true,
		} );
	} );

	test( 'lists the templates sorted by slug', async ( { page } ) => {
		const outcome = await runCoreTemplatesQuery( page );
		expect( outcome.ok, outcome.message ).toBe( true );

		const slugs = outcome.result.templates.map(
			( template ) => template.slug
		);
		expect( slugs ).toEqual( [ ...slugs ].sort() );
	} );
} );

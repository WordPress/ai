/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );

/**
 * Runs the `core/post-types-query` ability through the client-side Abilities API,
 * exactly as a consumer would in the browser.
 *
 * Mirrors the plugin's own sequence in `src/utils/run-ability.ts`: importing
 * `@wordpress/core-abilities` initializes the client store, so we await `ready` before
 * calling `executeAbility` from `@wordpress/abilities`.
 *
 * The client modules are only present in the page's import map once an AI experiment
 * is enabled in the block editor, which is set up in `beforeEach`.
 *
 * @param {import('@playwright/test').Page} page  The Playwright page.
 * @param {Object|undefined}                input The ability input, if any.
 * @return {Promise<Object>} `{ ok: true, result }` or `{ ok: false, code }`.
 */
async function runCorePostTypesQuery( page, input ) {
	return page.evaluate( async ( abilityInput ) => {
		const { ready } = await import( '@wordpress/core-abilities' );
		if ( ready ) {
			await ready;
		}

		const { executeAbility } = await import( '@wordpress/abilities' );

		try {
			const result = await executeAbility(
				'core/post-types-query',
				abilityInput
			);
			return { ok: true, result };
		} catch ( e ) {
			return { ok: false, code: e && e.code ? e.code : null };
		}
	}, input );
}

test.describe( 'core/post-types-query ability (client-side Abilities API)', () => {
	let post;

	test.beforeAll( async ( { requestUtils } ) => {
		// The abilities run from the block editor, so seed a post to open there.
		post = await requestUtils.createPost( {
			title: 'core/post-types-query seeded post',
			status: 'publish',
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove only the post seeded here, leaving any other specs' content alone.
		if ( post ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/posts/${ post.id }`,
				params: { force: true },
			} );
		}
	} );

	test.beforeEach( async ( { admin, page } ) => {
		// Enabling an experiment loads its block-editor script, which declares the
		// `@wordpress/abilities` + `@wordpress/core-abilities` modules as dependencies
		// and so adds them to the editor's import map.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// The core/post-types-query ability is gated behind the Custom Abilities
		// experiment, so enable it to register the ability server-side.
		await enableExperiment( admin, page, 'Custom Abilities' );

		// Open the seeded post rather than creating one, so no auto-draft is left behind.
		await admin.editPost( post.id );
	} );

	test( 'lists the exposed post types', async ( { page } ) => {
		const outcome = await runCorePostTypesQuery( page, undefined );

		expect( outcome.ok ).toBe( true );
		const slugs = outcome.result.post_types.map(
			( postType ) => postType.slug
		);
		// The e2e test plugin exposes its own post type too.
		expect( slugs ).toEqual( [ 'post', 'page', 'ai_e2e_sample' ] );
		for ( const postType of outcome.result.post_types ) {
			expect( Object.keys( postType ).sort() ).toEqual( [
				'description',
				'has_archive',
				'hierarchical',
				'icon',
				'name',
				'slug',
				'taxonomies',
				'template',
				'template_lock',
			] );
		}
	} );

	test( 'returns a single post type directly by slug', async ( { page } ) => {
		const outcome = await runCorePostTypesQuery( page, {
			slug: 'post',
			fields: [ 'name', 'slug', 'taxonomies', 'supports', 'viewable' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( Object.keys( outcome.result ) ).toEqual( [
			'viewable',
			'name',
			'slug',
			'supports',
			'taxonomies',
		] );
		expect( outcome.result.name ).toBe( 'Posts' );
		expect( outcome.result.slug ).toBe( 'post' );
		expect( outcome.result.viewable ).toBe( true );
		expect( outcome.result.taxonomies ).toEqual( [
			'category',
			'post_tag',
		] );
		expect( outcome.result.supports.title ).toBe( true );
	} );

	test( 'lists the labels of the post types the user can edit', async ( {
		page,
	} ) => {
		const outcome = await runCorePostTypesQuery( page, {
			fields: [ 'slug', 'labels' ],
		} );

		expect( outcome.ok ).toBe( true );
		const pageType = outcome.result.post_types.find(
			( postType ) => postType.slug === 'page'
		);
		expect( pageType.labels.singular_name ).toBe( 'Page' );
	} );

	test( 'rejects a post type that is not exposed', async ( { page } ) => {
		const outcome = await runCorePostTypesQuery( page, {
			slug: 'attachment',
		} );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'ability_invalid_input' );
	} );

	test( 'rejects a slug combined with other params', async ( { page } ) => {
		const outcome = await runCorePostTypesQuery( page, {
			slug: 'post',
			context: 'edit',
		} );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'ability_invalid_input' );
	} );
} );

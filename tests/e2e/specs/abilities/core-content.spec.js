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
 * Runs the `core/content` ability through the client-side Abilities API, exactly
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
async function runCoreContent( page, input ) {
	return page.evaluate( async ( abilityInput ) => {
		const { ready } = await import( '@wordpress/core-abilities' );
		if ( ready ) {
			await ready;
		}

		const { executeAbility } = await import( '@wordpress/abilities' );

		try {
			const result = await executeAbility( 'core/content', abilityInput );
			return { ok: true, result };
		} catch ( e ) {
			return { ok: false, code: e && e.code ? e.code : null };
		}
	}, input );
}

test.describe( 'core/content ability (client-side Abilities API)', () => {
	const seededPostIds = [];

	test.beforeAll( async ( { requestUtils } ) => {
		// The global setup deletes all `post` entries, so seed a few published
		// posts for the query-mode tests to retrieve.
		const posts = await Promise.all(
			[ 'one', 'two', 'three' ].map( ( suffix ) =>
				requestUtils.createPost( {
					title: `core/content seeded post ${ suffix }`,
					status: 'publish',
				} )
			)
		);
		seededPostIds.push( ...posts.map( ( post ) => post.id ) );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove only the posts seeded here, leaving any other specs' content alone.
		await Promise.all(
			seededPostIds.map( ( id ) =>
				requestUtils.rest( {
					method: 'DELETE',
					path: `/wp/v2/posts/${ id }`,
					params: { force: true },
				} )
			)
		);
		seededPostIds.length = 0;
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
			title: 'core/content ability test',
		} );
	} );

	test( 'returns a posts list of the requested post type', async ( {
		page,
	} ) => {
		const outcome = await runCoreContent( page, { post_type: 'post' } );

		expect( outcome.ok ).toBe( true );
		expect( Array.isArray( outcome.result.posts ) ).toBe( true );
		// Pagination totals travel in the body (and as X-WP-Total headers when core supports it).
		expect( typeof outcome.result.total ).toBe( 'number' );
		expect( typeof outcome.result.total_pages ).toBe( 'number' );
		for ( const post of outcome.result.posts ) {
			expect( post.type ).toBe( 'post' );
			expect( post.status ).toBe( 'publish' );
		}
	} );

	test( 'paginates with page and per_page', async ( { page } ) => {
		const outcome = await runCoreContent( page, {
			post_type: 'post',
			per_page: 1,
			page: 1,
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.posts.length ).toBeLessThanOrEqual( 1 );
	} );

	test( 'limits each post to the requested fields', async ( { page } ) => {
		const outcome = await runCoreContent( page, {
			post_type: 'post',
			fields: [ 'id', 'title' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.posts.length ).toBeGreaterThan( 0 );
		for ( const post of outcome.result.posts ) {
			expect( Object.keys( post ).sort() ).toEqual( [ 'id', 'title' ] );
		}
	} );

	test( 'rejects a slug query without a post type', async ( { page } ) => {
		const outcome = await runCoreContent( page, { slug: 'whatever' } );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'ability_invalid_input' );
	} );

	test( 'exposes a post type registered by another active plugin', async ( {
		page,
	} ) => {
		// The `e2e-testing` plugin (mapped in .wp-env.test.json) registers the
		// `ai_e2e_sample` post type with `show_in_abilities` and seeds a published post.
		const outcome = await runCoreContent( page, {
			post_type: 'ai_e2e_sample',
			slug: 'ai-e2e-sample-content',
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.posts ).toHaveLength( 1 );
		expect( outcome.result.posts[ 0 ].title ).toBe(
			'AI E2E Sample Content'
		);
		expect( outcome.result.posts[ 0 ].slug ).toBe(
			'ai-e2e-sample-content'
		);
	} );
} );

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );

/**
 * Runs the `core/content-query` ability through the client-side Abilities API, exactly
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
async function runCoreContentQuery( page, input ) {
	return page.evaluate( async ( abilityInput ) => {
		const { ready } = await import( '@wordpress/core-abilities' );
		if ( ready ) {
			await ready;
		}

		const { executeAbility } = await import( '@wordpress/abilities' );

		try {
			const result = await executeAbility(
				'core/content-query',
				abilityInput
			);
			return { ok: true, result };
		} catch ( e ) {
			return { ok: false, code: e && e.code ? e.code : null };
		}
	}, input );
}

test.describe( 'core/content-query ability (client-side Abilities API)', () => {
	const seededPostIds = [];

	test.beforeAll( async ( { requestUtils } ) => {
		// The global setup deletes all `post` entries, so seed a few published
		// posts for the query-mode tests to retrieve.
		const posts = await Promise.all(
			[ 'one', 'two', 'three' ].map( ( suffix ) =>
				requestUtils.createPost( {
					title: `core/content-query seeded post ${ suffix }`,
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
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// The core/content-query ability is gated behind the Custom Abilities
		// experiment, so enable it to register the ability server-side.
		await enableExperiment( admin, page, 'Custom Abilities' );

		// Run from the block editor, where the abilities client modules are available.
		// Open a seeded post rather than creating one, so no auto-draft is left behind.
		await admin.editPost( seededPostIds[ 0 ] );
	} );

	test( 'returns a page of posts of the requested post type', async ( {
		page,
	} ) => {
		const outcome = await runCoreContentQuery( page, {
			where: { type: 'post' },
		} );

		expect( outcome.ok ).toBe( true );
		expect( Array.isArray( outcome.result.items ) ).toBe( true );
		expect( typeof outcome.result.pagination.total ).toBe( 'number' );
		expect( typeof outcome.result.pagination.total_pages ).toBe( 'number' );

		// Without this the per-post assertions below pass vacuously on an empty list.
		expect( outcome.result.items.length ).toBeGreaterThan( 0 );
		expect( outcome.result.pagination.total ).toBeGreaterThanOrEqual(
			seededPostIds.length
		);

		for ( const post of outcome.result.items ) {
			expect( post.type ).toBe( 'post' );
			expect( post.status ).toBe( 'publish' );
			expect( Object.keys( post ).sort() ).toEqual( [
				'date',
				'id',
				'slug',
				'status',
				'title',
				'type',
			] );
			expect( Object.keys( post.title ) ).toEqual( [ 'rendered' ] );
		}
	} );

	test( 'paginates with page and per_page', async ( { page } ) => {
		const first = await runCoreContentQuery( page, {
			where: { type: 'post' },
			pagination: { per_page: 1, page: 1 },
		} );

		expect( first.ok ).toBe( true );
		expect( first.result.items ).toHaveLength( 1 );
		expect( first.result.pagination.total ).toBeGreaterThanOrEqual(
			seededPostIds.length
		);
		// One post per page, so there are as many pages as there are posts.
		expect( first.result.pagination.total_pages ).toBe(
			first.result.pagination.total
		);

		const second = await runCoreContentQuery( page, {
			where: { type: 'post' },
			pagination: { per_page: 1, page: 2 },
		} );

		expect( second.ok ).toBe( true );
		expect( second.result.items ).toHaveLength( 1 );
		expect( second.result.pagination.total ).toBe(
			first.result.pagination.total
		);
		expect( second.result.items[ 0 ].id ).not.toBe(
			first.result.items[ 0 ].id
		);
	} );

	test( 'rejects a page beyond the last one', async ( { page } ) => {
		const outcome = await runCoreContentQuery( page, {
			where: { type: 'post' },
			pagination: { per_page: 1, page: 999 },
		} );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'content_invalid_page_number' );
	} );

	test( 'limits each post to the requested fields', async ( { page } ) => {
		const outcome = await runCoreContentQuery( page, {
			where: { type: 'post' },
			fields: [ 'id', 'title.rendered' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.items.length ).toBeGreaterThan( 0 );
		for ( const post of outcome.result.items ) {
			expect( Object.keys( post ).sort() ).toEqual( [ 'id', 'title' ] );
			expect( Object.keys( post.title ) ).toEqual( [ 'rendered' ] );
		}
	} );

	test( 'limits results to the listed post IDs', async ( { page } ) => {
		const ids = [ seededPostIds[ 2 ], seededPostIds[ 0 ] ];
		const outcome = await runCoreContentQuery( page, {
			where: { type: 'post', ids },
			fields: [ 'id' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect(
			outcome.result.items
				.map( ( post ) => post.id )
				.sort( ( a, b ) => a - b )
		).toEqual( [ ...ids ].sort( ( a, b ) => a - b ) );
		expect( outcome.result.pagination ).toEqual( {
			total: 2,
			total_pages: 1,
		} );
	} );

	test( 'returns a single post by ID in the items list', async ( {
		page,
	} ) => {
		const outcome = await runCoreContentQuery( page, {
			where: { type: 'post', ids: [ seededPostIds[ 0 ] ] },
			fields: [ 'id', 'title.rendered' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.items ).toEqual( [
			{
				id: seededPostIds[ 0 ],
				title: { rendered: 'core/content-query seeded post one' },
			},
		] );
		expect( outcome.result.pagination ).toEqual( {
			total: 1,
			total_pages: 1,
		} );
	} );

	test( 'rejects a query without a post type', async ( { page } ) => {
		const outcome = await runCoreContentQuery( page, {
			where: { slugs: [ 'whatever' ] },
		} );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'ability_invalid_input' );
	} );

	test( 'rejects the old root-level filters', async ( { page } ) => {
		const outcome = await runCoreContentQuery( page, {
			post_type: 'post',
			slug: 'whatever',
		} );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'ability_invalid_input' );
	} );

	test( 'exposes a post type registered by another active plugin', async ( {
		page,
	} ) => {
		// The `e2e-testing` plugin (mapped in .wp-env.test.json) registers the
		// `ai_e2e_sample` post type with `show_in_abilities` and seeds a published post.
		const outcome = await runCoreContentQuery( page, {
			where: {
				type: 'ai_e2e_sample',
				slugs: [ 'ai-e2e-sample-content' ],
			},
			fields: [ 'slug', 'title.rendered' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.items ).toHaveLength( 1 );
		expect( outcome.result.items[ 0 ].title.rendered ).toBe(
			'AI E2E Sample Content'
		);
		expect( outcome.result.items[ 0 ].slug ).toBe(
			'ai-e2e-sample-content'
		);
	} );
} );

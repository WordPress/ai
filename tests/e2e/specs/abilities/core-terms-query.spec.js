/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );

/**
 * Runs the `core/terms-query` ability through the client-side Abilities API, exactly
 * as a consumer would in the browser.
 *
 * Mirrors the plugin's own sequence in `src/utils/run-ability.ts`: importing
 * `@wordpress/core-abilities` initializes the client store, so we await `ready` before
 * calling `executeAbility` from `@wordpress/abilities`.
 *
 * The client modules are only present in the page's import map once an AI experiment
 * is enabled in the block editor, which is set up in `beforeEach`.
 *
 * @param {import('@playwright/test').Page} page  The Playwright page.
 * @param {Object}                          input The ability input.
 * @return {Promise<Object>} `{ ok: true, result }` or `{ ok: false, code }`.
 */
async function runCoreTermsQuery( page, input ) {
	return page.evaluate( async ( abilityInput ) => {
		const { ready } = await import( '@wordpress/core-abilities' );
		if ( ready ) {
			await ready;
		}

		const { executeAbility } = await import( '@wordpress/abilities' );

		try {
			const result = await executeAbility(
				'core/terms-query',
				abilityInput
			);
			return { ok: true, result };
		} catch ( e ) {
			return { ok: false, code: e && e.code ? e.code : null };
		}
	}, input );
}

test.describe( 'core/terms-query ability (client-side Abilities API)', () => {
	// Unique names keep the seeded terms apart from leftovers of an interrupted run.
	const runId = Date.now().toString( 36 );
	let parent;
	let child;
	let tag;
	let post;

	test.beforeAll( async ( { requestUtils } ) => {
		parent = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/categories',
			data: { name: `core/terms-query parent ${ runId }` },
		} );
		child = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/categories',
			data: {
				name: `core/terms-query child ${ runId }`,
				parent: parent.id,
			},
		} );
		tag = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/tags',
			data: { name: `core/terms-query tag ${ runId }` },
		} );
		post = await requestUtils.createPost( {
			title: 'core/terms-query seeded post',
			status: 'publish',
			categories: [ child.id ],
			tags: [ tag.id ],
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove only the post and terms seeded here, leaving any other specs' content alone.
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/posts/${ post.id }`,
			params: { force: true },
		} );
		await Promise.all(
			[
				`/wp/v2/categories/${ child.id }`,
				`/wp/v2/categories/${ parent.id }`,
				`/wp/v2/tags/${ tag.id }`,
			].map( ( path ) =>
				requestUtils.rest( {
					method: 'DELETE',
					path,
					params: { force: true },
				} )
			)
		);
	} );

	test.beforeEach( async ( { admin, page } ) => {
		// Enabling an experiment loads its block-editor script, which declares the
		// `@wordpress/abilities` + `@wordpress/core-abilities` modules as dependencies
		// and so adds them to the editor's import map.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// The core/terms-query ability is gated behind the Custom Abilities
		// experiment, so enable it to register the ability server-side.
		await enableExperiment( admin, page, 'Custom Abilities' );

		// Run from the block editor, where the abilities client modules are available.
		// Open the seeded post rather than creating one, so no auto-draft is left behind.
		await admin.editPost( post.id );
	} );

	test( 'returns the terms of a taxonomy with totals', async ( { page } ) => {
		const outcome = await runCoreTermsQuery( page, {
			taxonomy: 'category',
			search: runId,
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.total ).toBe( 2 );
		expect( outcome.result.total_pages ).toBe( 1 );
		expect( outcome.result.terms.map( ( term ) => term.id ) ).toEqual( [
			child.id,
			parent.id,
		] );
		for ( const term of outcome.result.terms ) {
			expect( term.taxonomy ).toBe( 'category' );
			expect( Object.keys( term ).sort() ).toEqual( [
				'count',
				'id',
				'name',
				'parent',
				'slug',
				'taxonomy',
			] );
		}
	} );

	test( 'filters by parent', async ( { page } ) => {
		const outcome = await runCoreTermsQuery( page, {
			taxonomy: 'category',
			parent: parent.id,
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.terms.map( ( term ) => term.id ) ).toEqual( [
			child.id,
		] );
	} );

	test( 'returns the terms of a post', async ( { page } ) => {
		const outcome = await runCoreTermsQuery( page, {
			taxonomy: 'post_tag',
			post: post.id,
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.terms.map( ( term ) => term.id ) ).toEqual( [
			tag.id,
		] );
		expect( outcome.result.terms[ 0 ].count ).toBe( 1 );
		// Tags are not hierarchical, so they have no parent.
		expect( outcome.result.terms[ 0 ].parent ).toBeUndefined();
	} );

	test( 'returns a single term directly by ID', async ( { page } ) => {
		const outcome = await runCoreTermsQuery( page, {
			id: child.id,
			fields: [ 'name', 'parent', 'link' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result ).toEqual( {
			id: child.id,
			name: child.name,
			link: child.link,
			parent: parent.id,
		} );
		expect( outcome.result.terms ).toBeUndefined();
	} );

	test( 'returns a single term by taxonomy and slug', async ( { page } ) => {
		const outcome = await runCoreTermsQuery( page, {
			taxonomy: 'post_tag',
			slug: tag.slug,
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.id ).toBe( tag.id );
		expect( outcome.result.name ).toBe( tag.name );
	} );

	test( 'rejects a slug without a taxonomy', async ( { page } ) => {
		const outcome = await runCoreTermsQuery( page, { slug: tag.slug } );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'ability_invalid_input' );
	} );

	test( 'rejects an ID combined with query params', async ( { page } ) => {
		const outcome = await runCoreTermsQuery( page, {
			id: child.id,
			per_page: 5,
		} );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'ability_invalid_input' );
	} );

	test( 'denies a term that does not exist', async ( { page } ) => {
		const outcome = await runCoreTermsQuery( page, {
			taxonomy: 'category',
			slug: `missing-${ runId }`,
		} );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'rest_ability_cannot_execute' );
	} );
} );

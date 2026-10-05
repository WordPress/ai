/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );

/**
 * Runs an ability through the client-side Abilities API, exactly as a consumer
 * would in the browser.
 *
 * Mirrors the plugin's own sequence in `src/utils/run-ability.ts`: importing
 * `@wordpress/core-abilities` initializes the client store, so we await `ready`
 * before calling `executeAbility` from `@wordpress/abilities`. The client
 * modules are only present in the page's import map once an AI experiment is
 * enabled in the block editor, which is set up in `beforeEach`.
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

test.describe( 'core/term-create, core/term-update, and core/term-delete abilities (client-side Abilities API)', () => {
	// Unique names keep the terms apart from leftovers of an interrupted run.
	const runId = Date.now().toString( 36 );
	// Every record seeded or written here, removed in `afterAll`.
	const createdPaths = [];
	let parent;
	let post;

	test.beforeAll( async ( { requestUtils } ) => {
		parent = await requestUtils.createRecord( 'categories', {
			name: `core/term-write parent ${ runId }`,
		} );
		createdPaths.push( `/wp/v2/categories/${ parent.id }` );
		// The global setup deletes all `post` entries, so seed one to open the
		// block editor on; the abilities client modules are only loaded there.
		post = await requestUtils.createPost( {
			title: 'core/term-write host post',
			status: 'publish',
		} );
		createdPaths.push( `/wp/v2/posts/${ post.id }` );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove only the records written here, leaving any other specs' content
		// alone. Terms the lifecycle already deleted are skipped.
		await Promise.all(
			createdPaths.map( ( path ) =>
				requestUtils
					.rest( {
						method: 'DELETE',
						path,
						params: { force: true },
					} )
					.catch( () => {} )
			)
		);
		createdPaths.length = 0;
	} );

	test.beforeEach( async ( { admin, page } ) => {
		// Enabling an experiment loads its block-editor script, which declares the
		// `@wordpress/abilities` + `@wordpress/core-abilities` modules as dependencies
		// and so adds them to the editor's import map.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// The term abilities are gated behind the Custom Abilities experiment, so
		// enable it to register them server-side.
		await enableExperiment( admin, page, 'Custom Abilities' );

		// Run from the block editor, where the abilities client modules are available.
		await admin.editPost( post.id );
	} );

	test( 'creates, updates, and deletes a tag', async ( { page } ) => {
		const created = await runAbility( page, 'core/term-create', {
			taxonomy: 'post_tag',
			name: `core/term-write tag ${ runId }`,
			fields: [ 'name', 'taxonomy', 'parent' ],
		} );

		expect( created.ok ).toBe( true );
		createdPaths.push( `/wp/v2/tags/${ created.result.id }` );
		// Tags are not hierarchical, so they have no parent.
		expect( created.result ).toEqual( {
			id: created.result.id,
			name: `core/term-write tag ${ runId }`,
			taxonomy: 'post_tag',
		} );

		const updated = await runAbility( page, 'core/term-update', {
			id: created.result.id,
			name: `core/term-write renamed tag ${ runId }`,
			description: 'Updated by an ability.',
			fields: [ 'name', 'description' ],
		} );

		expect( updated.ok ).toBe( true );
		expect( updated.result ).toEqual( {
			id: created.result.id,
			name: `core/term-write renamed tag ${ runId }`,
			description: 'Updated by an ability.',
		} );

		// Terms cannot be trashed, so a deletion needs `force`.
		const trashed = await runAbility( page, 'core/term-delete', {
			id: created.result.id,
		} );

		expect( trashed.ok ).toBe( false );
		expect( trashed.code ).toBe( 'terms_trash_not_supported' );

		const deleted = await runAbility( page, 'core/term-delete', {
			id: created.result.id,
			force: true,
			fields: [ 'name' ],
		} );

		expect( deleted.ok ).toBe( true );
		expect( deleted.result ).toEqual( {
			deleted: true,
			previous: {
				id: created.result.id,
				name: `core/term-write renamed tag ${ runId }`,
			},
		} );

		// The tag is gone, so reading it is denied.
		const read = await runAbility( page, 'core/terms-query', {
			id: created.result.id,
		} );

		expect( read.ok ).toBe( false );
		expect( read.code ).toBe( 'rest_ability_cannot_execute' );
	} );

	test( 'creates, moves, and deletes a category under a parent', async ( {
		page,
	} ) => {
		const name = `core/term-write child ${ runId }`;
		const created = await runAbility( page, 'core/term-create', {
			taxonomy: 'category',
			name,
			parent: parent.id,
			fields: [ 'name', 'parent' ],
		} );

		expect( created.ok ).toBe( true );
		createdPaths.push( `/wp/v2/categories/${ created.result.id }` );
		expect( created.result ).toEqual( {
			id: created.result.id,
			name,
			parent: parent.id,
		} );

		// The name is taken under that parent.
		const duplicate = await runAbility( page, 'core/term-create', {
			taxonomy: 'category',
			name,
			parent: parent.id,
		} );

		// Record a wrongly created duplicate, so a failure leaves nothing behind.
		if ( duplicate.ok ) {
			createdPaths.push( `/wp/v2/categories/${ duplicate.result.id }` );
		}
		expect( duplicate.ok ).toBe( false );
		expect( duplicate.code ).toBe( 'term_exists' );

		const moved = await runAbility( page, 'core/term-update', {
			id: created.result.id,
			taxonomy: 'category',
			parent: 0,
			fields: [ 'parent' ],
		} );

		expect( moved.ok ).toBe( true );
		expect( moved.result ).toEqual( {
			id: created.result.id,
			parent: 0,
		} );

		const deleted = await runAbility( page, 'core/term-delete', {
			id: created.result.id,
			taxonomy: 'category',
			force: true,
			fields: [ 'name', 'parent' ],
		} );

		expect( deleted.ok ).toBe( true );
		expect( deleted.result ).toEqual( {
			deleted: true,
			previous: {
				id: created.result.id,
				name,
				parent: 0,
			},
		} );
	} );
} );

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

test.describe( 'core/content-create, core/content-update, and core/content-delete abilities (client-side Abilities API)', () => {
	let editorPostId;
	const createdPostIds = [];

	test.beforeAll( async ( { requestUtils } ) => {
		// The global setup deletes all `post` entries, so seed one to open the
		// block editor on; the abilities client modules are only loaded there.
		const post = await requestUtils.createPost( {
			title: 'core/content-write host post',
			status: 'publish',
		} );
		editorPostId = post.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove only the posts created here, leaving any other specs' content alone.
		await Promise.all(
			[ editorPostId, ...createdPostIds ].map( ( id ) =>
				requestUtils
					.rest( {
						method: 'DELETE',
						path: `/wp/v2/posts/${ id }`,
						params: { force: true },
					} )
					.catch( () => {} )
			)
		);
		createdPostIds.length = 0;
	} );

	test.beforeEach( async ( { admin, page } ) => {
		// Enabling an experiment loads its block-editor script, which declares the
		// `@wordpress/abilities` + `@wordpress/core-abilities` modules as dependencies
		// and so adds them to the editor's import map.
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// The content abilities are gated behind the Custom Abilities experiment,
		// so enable it to register them server-side.
		await enableExperiment( admin, page, 'Custom Abilities' );

		// Run from the block editor, where the abilities client modules are available.
		await admin.editPost( editorPostId );
	} );

	test( 'creates, updates, trashes, and deletes a post', async ( {
		page,
	} ) => {
		const fields = [ 'id', 'status', 'title_raw', 'content_raw' ];

		const created = await runAbility( page, 'core/content-create', {
			post_type: 'post',
			title: 'Written by an ability',
			content:
				'<!-- wp:paragraph --><p>Body written by an ability.</p><!-- /wp:paragraph -->',
			status: 'draft',
			fields,
		} );

		expect( created.ok ).toBe( true );
		expect( created.result.status ).toBe( 'draft' );
		expect( created.result.title_raw ).toBe( 'Written by an ability' );
		expect( Object.keys( created.result ).sort() ).toEqual(
			[ ...fields ].sort()
		);
		createdPostIds.push( created.result.id );

		const updated = await runAbility( page, 'core/content-update', {
			id: created.result.id,
			title: 'Updated by an ability',
			status: 'publish',
			fields,
		} );

		expect( updated.ok ).toBe( true );
		expect( updated.result.id ).toBe( created.result.id );
		expect( updated.result.status ).toBe( 'publish' );
		expect( updated.result.title_raw ).toBe( 'Updated by an ability' );
		// Omitted fields keep their current values.
		expect( updated.result.content_raw ).toBe( created.result.content_raw );

		const trashed = await runAbility( page, 'core/content-delete', {
			id: created.result.id,
			fields: [ 'id', 'status' ],
		} );

		expect( trashed.ok ).toBe( true );
		expect( trashed.result.id ).toBe( created.result.id );
		expect( trashed.result.status ).toBe( 'trash' );

		const trashedAgain = await runAbility( page, 'core/content-delete', {
			id: created.result.id,
		} );

		expect( trashedAgain.ok ).toBe( false );
		expect( trashedAgain.code ).toBe( 'content_already_trashed' );

		const deleted = await runAbility( page, 'core/content-delete', {
			id: created.result.id,
			force: true,
			fields: [ 'id', 'title_raw' ],
		} );

		expect( deleted.ok ).toBe( true );
		expect( deleted.result.deleted ).toBe( true );
		expect( deleted.result.previous.id ).toBe( created.result.id );
		expect( deleted.result.previous.title_raw ).toBe(
			'Updated by an ability'
		);

		// The post is gone, so reading it is denied.
		const read = await runAbility( page, 'core/content-query', {
			id: created.result.id,
		} );

		expect( read.ok ).toBe( false );
	} );

	test( 'rejects a field the post type does not support', async ( {
		page,
	} ) => {
		const outcome = await runAbility( page, 'core/content-create', {
			post_type: 'page',
			title: 'Sticky page',
			sticky: true,
		} );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'content_invalid_field' );
	} );

	test( 'rejects unknown properties', async ( { page } ) => {
		const outcome = await runAbility( page, 'core/content-create', {
			post_type: 'post',
			title: 'Read-only field',
			modified: '2010-06-01T02:00:00Z',
		} );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'ability_invalid_input' );
	} );

	test( 'writes a post type registered by another active plugin', async ( {
		page,
	} ) => {
		// The `e2e-testing` plugin (mapped in .wp-env.test.json) registers the
		// `ai_e2e_sample` post type with `show_in_abilities`.
		const created = await runAbility( page, 'core/content-create', {
			post_type: 'ai_e2e_sample',
			title: 'Sample written by an ability',
			status: 'publish',
			fields: [ 'id', 'post_type', 'title_rendered' ],
		} );

		expect( created.ok ).toBe( true );
		expect( created.result.post_type ).toBe( 'ai_e2e_sample' );
		expect( created.result.title_rendered ).toBe(
			'Sample written by an ability'
		);

		// The sample post type has no REST route, so clean up through the ability.
		const deleted = await runAbility( page, 'core/content-delete', {
			id: created.result.id,
			force: true,
		} );

		expect( deleted.ok ).toBe( true );
		expect( deleted.result.deleted ).toBe( true );
	} );
} );

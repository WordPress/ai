/**
 * External dependencies
 */
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );

// A test image (1x1 PNG), base64 encoded for the upload input.
const TEST_IMAGE_DATA = fs
	.readFileSync( path.join( __dirname, '../../../data/sample.png' ) )
	.toString( 'base64' );

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

test.describe( 'core/media-upload, core/media-update, and core/media-delete abilities (client-side Abilities API)', () => {
	let parentPostId;

	// Every media item written by this spec, removed in `afterAll`.
	const createdMediaIds = [];

	test.beforeAll( async ( { requestUtils } ) => {
		// A published post to attach the upload to and to open in the editor.
		const post = await requestUtils.createPost( {
			title: 'core/media-write parent post',
			status: 'publish',
		} );
		parentPostId = post.id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove only the media and post written here, leaving any other specs' content alone.
		await Promise.all(
			createdMediaIds.map( ( id ) =>
				requestUtils.deleteMedia( id ).catch( () => {} )
			)
		);
		createdMediaIds.length = 0;

		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/posts/${ parentPostId }`,
			params: { force: true },
		} );
	} );

	test.beforeEach( async ( { admin, page } ) => {
		// Enabling an experiment loads its block-editor script, which declares the
		// `@wordpress/abilities` + `@wordpress/core-abilities` modules as dependencies
		// and so adds them to the editor's import map.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// The media abilities are gated behind the Custom Abilities experiment,
		// so enable it to register them server-side.
		await enableExperiment( admin, page, 'Custom Abilities' );

		// Run from the block editor, where the abilities client modules are available.
		// Open the seeded post rather than creating one, so no auto-draft is left behind.
		await admin.editPost( parentPostId );
	} );

	test( 'uploads, updates, and deletes a media item', async ( { page } ) => {
		const uploaded = await runAbility( page, 'core/media-upload', {
			data: TEST_IMAGE_DATA,
			filename: 'core-media-write.png',
			title: 'Uploaded by an ability',
			alt_text: 'Alt text written by an ability',
			post: parentPostId,
			fields: [
				'id',
				'status',
				'title_raw',
				'alt_text',
				'media_type',
				'mime_type',
				'post',
				'source_url',
			],
		} );

		expect( uploaded.ok ).toBe( true );
		createdMediaIds.push( uploaded.result.id );
		expect( uploaded.result ).toEqual( {
			id: expect.any( Number ),
			status: 'inherit',
			title_raw: 'Uploaded by an ability',
			alt_text: 'Alt text written by an ability',
			media_type: 'image',
			mime_type: 'image/png',
			post: parentPostId,
			source_url: expect.stringMatching(
				/\/core-media-write(-\d+)?\.png$/
			),
		} );

		const updated = await runAbility( page, 'core/media-update', {
			id: uploaded.result.id,
			title: 'Updated by an ability',
			caption: { raw: 'Caption written by an ability' },
			post: 0,
			fields: [ 'id', 'title_raw', 'caption_raw', 'alt_text', 'post' ],
		} );

		expect( updated.ok ).toBe( true );
		expect( updated.result ).toEqual( {
			id: uploaded.result.id,
			title_raw: 'Updated by an ability',
			caption_raw: 'Caption written by an ability',
			// The omitted alt text keeps its current value.
			alt_text: 'Alt text written by an ability',
			post: null,
		} );

		// The media trash is off, so a deletion needs `force`.
		const trashed = await runAbility( page, 'core/media-delete', {
			id: uploaded.result.id,
		} );

		expect( trashed.ok ).toBe( false );
		expect( trashed.code ).toBe( 'media_trash_not_supported' );

		const deleted = await runAbility( page, 'core/media-delete', {
			id: uploaded.result.id,
			force: true,
			fields: [ 'id', 'title_raw' ],
		} );

		expect( deleted.ok ).toBe( true );
		expect( deleted.result ).toEqual( {
			deleted: true,
			previous: {
				id: uploaded.result.id,
				title_raw: 'Updated by an ability',
			},
		} );

		// The item is gone, so reading it is denied.
		const read = await runAbility( page, 'core/media-query', {
			id: uploaded.result.id,
		} );

		expect( read.ok ).toBe( false );
		expect( read.code ).toBe( 'rest_ability_cannot_execute' );
	} );

	test( 'rejects an upload that mixes file data and a URL', async ( {
		page,
	} ) => {
		const outcome = await runAbility( page, 'core/media-upload', {
			data: TEST_IMAGE_DATA,
			filename: 'core-media-write.png',
			url: 'https://example.com/photo.jpg',
		} );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'ability_invalid_input' );
	} );

	test( 'rejects a file type the site does not allow', async ( { page } ) => {
		const outcome = await runAbility( page, 'core/media-upload', {
			data: Buffer.from( '<?php echo "Hello";' ).toString( 'base64' ),
			filename: 'core-media-write.php',
		} );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'media_upload_sideload_error' );
	} );
} );

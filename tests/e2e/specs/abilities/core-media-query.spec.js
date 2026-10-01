/**
 * External dependencies
 */
const path = require( 'path' );

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );

// Path to a test image (1x1 PNG).
const TEST_IMAGE_PATH = path.join( __dirname, '../../../data/sample.png' );

/**
 * Runs the `core/media-query` ability through the client-side Abilities API, exactly
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
async function runCoreMediaQuery( page, input ) {
	return page.evaluate( async ( abilityInput ) => {
		const { ready } = await import( '@wordpress/core-abilities' );
		if ( ready ) {
			await ready;
		}

		const { executeAbility } = await import( '@wordpress/abilities' );

		try {
			const result = await executeAbility(
				'core/media-query',
				abilityInput
			);
			return { ok: true, result };
		} catch ( e ) {
			return { ok: false, code: e && e.code ? e.code : null };
		}
	}, input );
}

test.describe( 'core/media-query ability (client-side Abilities API)', () => {
	let parentPostId;
	const seededMediaIds = [];

	test.beforeAll( async ( { requestUtils } ) => {
		// A published post to attach an image to and to open in the editor.
		const post = await requestUtils.createPost( {
			title: 'core/media-query parent post',
			status: 'publish',
		} );
		parentPostId = post.id;

		const attached = await requestUtils.uploadMedia( TEST_IMAGE_PATH );
		const unattached = await requestUtils.uploadMedia( TEST_IMAGE_PATH );
		seededMediaIds.push( attached.id, unattached.id );

		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/media/${ attached.id }`,
			data: {
				title: 'core/media-query attached image',
				caption: 'Attached caption',
				alt_text: 'Attached alt text',
				post: parentPostId,
			},
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/media/${ unattached.id }`,
			data: { title: 'core/media-query unattached image' },
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Remove only the media and post seeded here, leaving any other specs' content alone.
		await Promise.all(
			seededMediaIds.map( ( id ) => requestUtils.deleteMedia( id ) )
		);
		seededMediaIds.length = 0;

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

		// The core/media-query ability is gated behind the Custom Abilities
		// experiment, so enable it to register the ability server-side.
		await enableExperiment( admin, page, 'Custom Abilities' );

		// Run from the block editor, where the abilities client modules are available.
		// Open the seeded post rather than creating one, so no auto-draft is left behind.
		await admin.editPost( parentPostId );
	} );

	test( 'returns a media list with the default fields', async ( {
		page,
	} ) => {
		const outcome = await runCoreMediaQuery( page, {
			include: seededMediaIds,
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.total ).toBe( 2 );
		expect( outcome.result.total_pages ).toBe( 1 );
		expect(
			outcome.result.media
				.map( ( item ) => item.id )
				.sort( ( a, b ) => a - b )
		).toEqual( [ ...seededMediaIds ].sort( ( a, b ) => a - b ) );

		for ( const item of outcome.result.media ) {
			expect( Object.keys( item ).sort() ).toEqual( [
				'alt_text',
				'date',
				'id',
				'media_type',
				'mime_type',
				'slug',
				'source_url',
				'title_rendered',
			] );
			expect( item.media_type ).toBe( 'image' );
			expect( item.mime_type ).toBe( 'image/png' );
		}
	} );

	test( 'returns a single item directly by ID', async ( { page } ) => {
		const outcome = await runCoreMediaQuery( page, {
			id: seededMediaIds[ 0 ],
			fields: [
				'title_rendered',
				'caption_raw',
				'alt_text',
				'post',
				'filename',
			],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result ).toEqual( {
			id: seededMediaIds[ 0 ],
			title_rendered: 'core/media-query attached image',
			caption_raw: 'Attached caption',
			alt_text: 'Attached alt text',
			post: parentPostId,
			filename: expect.stringMatching( /^sample(-\d+)?\.png$/ ),
		} );
	} );

	test( 'filters by parent post', async ( { page } ) => {
		const outcome = await runCoreMediaQuery( page, {
			parent: [ parentPostId ],
			fields: [ 'id' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.media ).toEqual( [
			{ id: seededMediaIds[ 0 ] },
		] );
	} );

	test( 'filters by media type and MIME type', async ( { page } ) => {
		const videos = await runCoreMediaQuery( page, {
			include: seededMediaIds,
			media_type: [ 'video' ],
		} );

		expect( videos.ok ).toBe( true );
		expect( videos.result.media ).toEqual( [] );
		expect( videos.result.total ).toBe( 0 );

		const images = await runCoreMediaQuery( page, {
			include: seededMediaIds,
			mime_type: [ 'image/png' ],
			fields: [ 'id' ],
		} );

		expect( images.ok ).toBe( true );
		expect( images.result.total ).toBe( 2 );
	} );

	test( 'searches titles', async ( { page } ) => {
		const outcome = await runCoreMediaQuery( page, {
			search: 'unattached image',
			fields: [ 'id' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.media ).toEqual( [
			{ id: seededMediaIds[ 1 ] },
		] );
	} );

	test( 'paginates with page and per_page', async ( { page } ) => {
		const outcome = await runCoreMediaQuery( page, {
			include: seededMediaIds,
			orderby: 'include',
			per_page: 1,
			page: 2,
			fields: [ 'id' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.media ).toEqual( [
			{ id: seededMediaIds[ 1 ] },
		] );
		expect( outcome.result.total ).toBe( 2 );
		expect( outcome.result.total_pages ).toBe( 2 );
	} );

	test( 'rejects a page beyond the last one', async ( { page } ) => {
		const outcome = await runCoreMediaQuery( page, {
			include: seededMediaIds,
			per_page: 1,
			page: 999,
		} );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'media_post_invalid_page_number' );
	} );

	test( 'rejects an ID combined with query parameters', async ( {
		page,
	} ) => {
		const outcome = await runCoreMediaQuery( page, {
			id: seededMediaIds[ 0 ],
			per_page: 1,
		} );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'ability_invalid_input' );
	} );

	test( 'denies a missing item', async ( { page } ) => {
		const outcome = await runCoreMediaQuery( page, { id: 999999999 } );

		expect( outcome.ok ).toBe( false );
		expect( outcome.code ).toBe( 'rest_ability_cannot_execute' );
	} );
} );

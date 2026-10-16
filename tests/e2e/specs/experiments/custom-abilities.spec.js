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
	runAbility,
} = require( '../../utils/helpers' );

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
		// Toggle the experiment on and back off.
		await enableExperiment( admin, page, 'Custom Abilities' );
		await disableExperiment( admin, page, 'Custom Abilities' );
	} );

	test( 'Gated abilities are available when the experiment is enabled', async ( {
		admin,
		page,
	} ) => {
		// A UI experiment loads the abilities client modules into the editor's
		// import map (needed to call executeAbility client-side).
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// The experiment under test registers the gated abilities server-side.
		await enableExperiment( admin, page, 'Custom Abilities' );

		// Run from the block editor, where the abilities client modules exist.
		await admin.editPost( seededPostId );

		const outcome = await runAbility( page, 'core/content-query', {
			id: seededPostId,
			fields: [ 'title_rendered' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.title_rendered ).toBe(
			'Custom Abilities seeded post'
		);
	} );

	test( 'Deprecated ability names keep working when the experiment is enabled', async ( {
		admin,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Excerpt Generation' );
		await enableExperiment( admin, page, 'Custom Abilities' );

		await admin.editPost( seededPostId );

		// `core/read-content` is a deprecated alias of `core/content-query`.
		const outcome = await runAbility( page, 'core/read-content', {
			id: seededPostId,
			fields: [ 'title_rendered' ],
		} );

		expect( outcome.ok ).toBe( true );
		expect( outcome.result.title_rendered ).toBe(
			'Custom Abilities seeded post'
		);
	} );

	test( 'Gated abilities are unavailable when the experiment is disabled', async ( {
		admin,
		page,
	} ) => {
		// Load the abilities client modules via a UI experiment, but leave the
		// Custom Abilities experiment off so the gated abilities are not registered.
		await enableExperiment( admin, page, 'Excerpt Generation' );
		await disableExperiment( admin, page, 'Custom Abilities' );

		await admin.editPost( seededPostId );

		const outcome = await runAbility( page, 'core/content-query', {
			id: seededPostId,
			fields: [ 'title_rendered' ],
		} );

		// The ability is not registered, so the client call fails.
		expect( outcome.ok ).toBe( false );
	} );
} );

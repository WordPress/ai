/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const { enableExperiment } = require( '../../utils/helpers' );

const EXPERIMENT_LABEL = 'Revision Change Summary';
const SUMMARY_TEXT = 'Updated the start and the end of the post.';

/**
 * Creates a post with three saved revisions.
 *
 * @param {Object} requestUtils Request utils.
 * @return {Promise<{postId: number, revisionIds: number[]}>} The post ID and revision IDs, newest first.
 */
async function createPostWithRevisions( requestUtils ) {
	const post = await requestUtils.createPost( {
		title: 'Revision Change Summary Test',
		content:
			'<!-- wp:paragraph --><p>First introduction.</p><!-- /wp:paragraph -->',
		status: 'draft',
	} );

	// The last version has the three changes the mocked summary lists.
	for ( const data of [
		{
			content:
				'<!-- wp:paragraph --><p>Second introduction.</p><!-- /wp:paragraph -->',
		},
		{
			title: 'Revision Change Summary Test 2.0',
			content:
				'<!-- wp:paragraph --><p>Third introduction.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Closing paragraph.</p><!-- /wp:paragraph -->',
		},
	] ) {
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ post.id }`,
			data,
		} );
	}

	const revisions = await requestUtils.rest( {
		path: `/wp/v2/posts/${ post.id }/revisions`,
	} );

	return {
		postId: post.id,
		revisionIds: revisions.map( ( revision ) => revision.id ),
	};
}

test.describe( 'Revision Change Summary Experiment', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'e2e-testing' );
	} );

	test( 'Summarizes changes in the visual revisions screen', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const { postId, revisionIds } =
			await createPostWithRevisions( requestUtils );

		// Opening the editor with a revision ID shows the visual revisions screen.
		await admin.visitAdminPage(
			'post.php',
			`post=${ postId }&action=edit&revision=${ revisionIds[ 0 ] }`
		);

		// The welcome guide modal would hide the rest of the editor.
		await page.waitForFunction( () => window?.wp?.data );
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/preferences' )
				.set( 'core/edit-post', 'welcomeGuide', false )
		);

		// The panel is shown in the Revision tab of the editor sidebar.
		const sidebar = page.getByRole( 'region', { name: 'Editor settings' } );
		const panelToggle = sidebar.getByRole( 'button', {
			name: 'Change summary',
		} );
		await expect( panelToggle ).toBeVisible();

		const summarizeButton = page.getByRole( 'button', {
			name: 'Summarize changes',
		} );
		await summarizeButton.click();

		await expect( page.getByText( SUMMARY_TEXT ) ).toBeVisible();
		await expect(
			page.getByText( 'Added a closing paragraph with a call to action.' )
		).toBeVisible();

		// The button is removed, so keyboard focus moves to the summary.
		await expect(
			page.locator( '.ai-revision-change-summary__result' )
		).toBeFocused();

		// The summary of fixed revisions is not generated again.
		await expect( summarizeButton ).toHaveCount( 0 );
	} );

	test( 'Summarizes changes in the classic revisions screen', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const { revisionIds } = await createPostWithRevisions( requestUtils );

		await admin.visitAdminPage(
			'revision.php',
			`revision=${ revisionIds[ 0 ] }`
		);

		const summarizeButton = page.getByRole( 'button', {
			name: 'Summarize changes',
		} );
		await summarizeButton.click();

		await expect( page.getByText( SUMMARY_TEXT ) ).toBeVisible();
		await expect( summarizeButton ).toHaveCount( 0 );
	} );

	test( 'Generates only one summary at a time', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const { postId } = await createPostWithRevisions( requestUtils );

		// One more revision, so the previous revision can be summarized too.
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ postId }`,
			data: {
				content:
					'<!-- wp:paragraph --><p>Fourth introduction.</p><!-- /wp:paragraph -->',
			},
		} );
		const revisions = await requestUtils.rest( {
			path: `/wp/v2/posts/${ postId }/revisions`,
		} );

		// Hold the first response so a second request could be started meanwhile.
		let requests = 0;
		let release;
		const released = new Promise( ( resolve ) => ( release = resolve ) );
		await page.route(
			/revision-change-summary(\/|%2F)run/i,
			async ( route ) => {
				requests++;
				await released;
				await route.continue();
			}
		);

		await admin.visitAdminPage(
			'revision.php',
			`revision=${ revisions[ 0 ].id }`
		);

		const summarizeButton = page.getByRole( 'button', {
			name: 'Summarize changes',
		} );
		await summarizeButton.click();
		await expect(
			page.getByRole( 'button', { name: 'Summarizing…' } )
		).toBeVisible();

		// Move to the previous revision while the summary is generated.
		await page.getByRole( 'button', { name: 'Previous' } ).click();

		await expect(
			page.getByText(
				'A summary of other revisions is still being generated.',
				{
					exact: false,
				}
			)
		).toBeVisible();
		await expect( summarizeButton ).toBeDisabled();

		release();

		// The finished summary is not shown for other revisions.
		await expect( summarizeButton ).toBeEnabled();
		await expect( page.getByText( SUMMARY_TEXT ) ).toHaveCount( 0 );

		// It is shown when moving back to its revisions.
		await page.getByRole( 'button', { name: 'Next' } ).click();
		await expect( page.getByText( SUMMARY_TEXT ) ).toBeVisible();

		expect( requests ).toBe( 1 );
	} );

	test( 'Disables the action for the oldest revision on the classic screen', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const { revisionIds } = await createPostWithRevisions( requestUtils );

		await admin.visitAdminPage(
			'revision.php',
			`revision=${ revisionIds[ revisionIds.length - 1 ] }`
		);

		await expect(
			page.getByText( 'There is no earlier revision to compare with.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Summarize changes' } )
		).toBeDisabled();
	} );

	test( 'Disables the action for the oldest revision on the visual screen', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		const { postId, revisionIds } =
			await createPostWithRevisions( requestUtils );

		await admin.visitAdminPage(
			'post.php',
			`post=${ postId }&action=edit&revision=${
				revisionIds[ revisionIds.length - 1 ]
			}`
		);

		await page.waitForFunction( () => window?.wp?.data );
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/preferences' )
				.set( 'core/edit-post', 'welcomeGuide', false )
		);

		// Same as the classic screen, before anything is generated.
		await expect(
			page.getByText( 'There is no earlier revision to compare with.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Summarize changes' } )
		).toBeDisabled();
	} );
} );

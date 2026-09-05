/**
 * WordPress dependencies
 */
import {
	test,
	expect,
	type RequestUtils,
} from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	activateMockScenario,
	deactivateMockScenario,
	disableExperiment,
	disableExperiments,
	enableExperiment,
	enableExperiments,
	getMockScenarioReport,
	seedCredentials,
} from '../../utils/helpers';

const EXPERIMENT_LABEL = 'AI Workspace';
const WORKSPACE_PAGE = 'tools.php?page=ai-workspace';

/*
 * These specs drive the workspace against sequenced Anthropic fixtures, which is
 * the only provider the mock has a tool-calling round trip for. Anthropic is
 * seeded exclusively so the turn cannot be answered by another configured
 * provider, and OpenAI is restored afterwards because the rest of the suite
 * assumes the state global setup left behind.
 *
 * Nothing here reaches the network: `pre_http_request` answers both the models
 * lookup and every round of the turn from
 * `tests/e2e-testing/responses/Anthropic/`.
 */
const seedAnthropic = ( requestUtils: RequestUtils ) =>
	seedCredentials( requestUtils, [ 'anthropic' ] );

const seedOpenAi = ( requestUtils: RequestUtils ) =>
	seedCredentials( requestUtils, [ 'openai' ] );

test.describe( 'AI Workspace tool loop', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await seedAnthropic( requestUtils );
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test.afterEach( async ( { admin, page, requestUtils } ) => {
		await deactivateMockScenario( requestUtils );
		await disableExperiment( admin, page, EXPERIMENT_LABEL );
		await disableExperiments( admin, page );
		await seedOpenAi( requestUtils );
	} );

	test( 'drives a full tool-calling round trip from sequenced fixtures', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await activateMockScenario( requestUtils, 'workspace-search' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'What sample content is on this site?' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		const turn = page.locator( '.ai-workspace__turn' ).first();

		// The answer comes from the second fixture entry, which the model can
		// only have reached by way of the tool call in the first one.
		await expect( turn ).toContainText(
			'The search returned the seeded fixture entry titled AI E2E Sample Content.',
			{ timeout: 30000 }
		);

		// The tool step records the ability that actually ran, not the fixture.
		const tools = turn.locator( '.ai-workspace__tools' );

		// The retrieval trace is the always-visible summary (U13/R24): it names
		// what the turn retrieved before the detail is opened. The count comes
		// from the server's own retrieval summary, so asserting it here proves
		// the whole path -- ability, turn response, transcript -- carried it.
		await expect( tools.locator( 'summary' ) ).toContainText( 'Searched' );
		await expect( tools.locator( 'summary' ) ).not.toContainText(
			'Looked up site content'
		);

		/*
		 * An administrator can read everything the fixture seeds, so nothing is
		 * withheld and the trace must stay silent about permissions rather than
		 * claim "none hidden". The withheld count itself is exercised
		 * server-side, where a role can actually be denied a row.
		 */
		await expect( tools.locator( 'summary' ) ).not.toContainText(
			'hidden by your permissions'
		);
		await expect( tools.locator( 'code' ) ).toHaveText(
			'ai/search-content'
		);
		await expect( tools ).toContainText( 'success' );

		// The ability's own rows are rendered, so the result reached the
		// transcript rather than only the model.
		await tools.locator( 'summary' ).click();
		await expect( tools ).toContainText( 'AI E2E Sample Content' );

		// Two entries of one fixture answered one turn: the sequence advanced.
		const report = await getMockScenarioReport( requestUtils );
		expect( report.scenario ).toBe( 'workspace-search' );
		expect( report.calls[ 'Anthropic/workspace-search' ] ).toBe( 2 );
	} );

	test( 'repeats the last entry once the sequence is exhausted', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await activateMockScenario( requestUtils, 'workspace-search' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		// The first turn consumes both entries of the two-entry sequence. The
		// second turn is served the last one again — a terminating message — so
		// it ends cleanly instead of falling through to the substring-matched
		// default and answering with something unrelated.
		for ( const prompt of [ 'First question.', 'Second question.' ] ) {
			await page.getByLabel( 'Message', { exact: true } ).fill( prompt );
			await page.getByRole( 'button', { name: 'Send' } ).click();
			await expect(
				page.locator( '.ai-workspace__turn' ).last()
			).toContainText( 'AI E2E Sample Content', { timeout: 30000 } );
		}

		const report = await getMockScenarioReport( requestUtils );
		expect(
			report.calls[ 'Anthropic/workspace-search' ]
		).toBeGreaterThanOrEqual( 3 );
	} );

	test( 'a stop on the first turn stays stopped rather than turning into an error', async ( {
		admin,
		page,
	} ) => {
		/*
		 * The turn is held open and never answered, so the only thing that can
		 * settle the request is the abort that Stop performs. That is the case
		 * the regression lives in: on a conversation's first turn there is no
		 * identifier to cancel with, so Stop closes the reader locally and the
		 * aborted request then settles on its own with a transport failure.
		 */
		await page.route(
			( url ) =>
				url.href.includes( 'workspace/messages' ) &&
				! url.href.includes( 'cancel' ),
			() => {
				// Deliberately never fulfilled.
			}
		);

		await admin.visitAdminPage( WORKSPACE_PAGE );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'A question I will stop.' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		const stopButton = page.getByRole( 'button', { name: 'Stop' } );
		await expect( stopButton ).toBeEnabled();
		await stopButton.click();

		const turn = page.locator( '.ai-workspace__turn' ).first();
		await expect( turn ).toContainText( 'You stopped this response.' );

		// The aborted request settles a moment later. The stopped state has to
		// survive it: the person stopped the turn, so nothing may report a
		// failure they did not cause.
		await page.waitForTimeout( 1000 );
		await expect( turn ).toContainText( 'You stopped this response.' );
		await expect( turn ).not.toContainText( 'Send this message again' );
		await expect( turn.locator( '.components-notice' ) ).toHaveCount( 0 );

		// The workspace's own live region reports the stop, not a failure, so a
		// screen reader is not told the turn broke.
		await expect(
			page.locator( '.screen-reader-text[aria-live="polite"]' )
		).toHaveText( 'Response stopped.' );
	} );
} );

test.describe( 'AI Workspace proposal confirmation', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await seedAnthropic( requestUtils );
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
		await activateMockScenario( requestUtils, 'workspace-proposal' );
	} );

	test.afterEach( async ( { admin, page, requestUtils } ) => {
		await deactivateMockScenario( requestUtils );
		await disableExperiment( admin, page, EXPERIMENT_LABEL );
		await disableExperiments( admin, page );
		await seedOpenAi( requestUtils );
		await requestUtils.deleteAllPosts();
	} );

	test( 'writes nothing until the proposal is approved, then creates the draft', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'Draft me something about the site.' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		const review = page.getByRole( 'region', {
			name: 'Review what will be created',
		} );
		await expect( review ).toBeVisible( { timeout: 30000 } );

		// The values shown are read back from the server's stored proposal.
		await expect( review ).toContainText( 'Proposed E2E Draft' );
		await expect( review ).toContainText( 'post — will be saved as draft' );

		// Proposing wrote nothing.
		expect( await draftTitles( requestUtils ) ).toEqual( [] );

		// Items start deselected, so the approve control is inert until one is
		// chosen deliberately.
		await expect(
			page.getByRole( 'button', { name: /^Create \d+ selected item/ } )
		).toBeDisabled();

		await review
			.getByRole( 'checkbox', { name: 'Proposed E2E Draft' } )
			.check();
		await page
			.getByRole( 'button', { name: 'Create 1 selected item' } )
			.click();

		await expect(
			page.getByText( '1 created, 0 not created.' )
		).toBeVisible();
		await expect(
			page.getByText( 'Proposed E2E Draft — Created' )
		).toBeVisible();

		// The draft is real, and carries the values the person approved.
		expect( await draftTitles( requestUtils ) ).toEqual( [
			'Proposed E2E Draft',
		] );
	} );

	test( 'creates nothing when the proposal is discarded', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'Draft me something about the site.' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		const review = page.getByRole( 'region', {
			name: 'Review what will be created',
		} );
		await expect( review ).toBeVisible( { timeout: 30000 } );

		await page.getByRole( 'button', { name: 'Discard' } ).click();

		await expect(
			page.locator( '.components-notice__content', {
				hasText: 'Discarded. Nothing was created.',
			} )
		).toBeVisible();
		expect( await draftTitles( requestUtils ) ).toEqual( [] );
	} );

	test( 'reports a proposal it could not load instead of spinning forever', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );

		// The read-back of the stored proposal fails at the transport, which is
		// what an offline tab or a dropped connection looks like. The dialog
		// must reach a terminal state rather than sit on its spinner.
		await page.route(
			( url ) => url.href.includes( 'workspace/proposals' ),
			( route ) => route.abort( 'failed' )
		);

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'Draft me something about the site.' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		await expect(
			page.locator( '.components-notice__content', {
				hasText: 'This proposal could not be loaded.',
			} )
		).toBeVisible( { timeout: 30000 } );

		await expect(
			page.getByText( 'Loading what the assistant proposes to write' )
		).toHaveCount( 0 );
	} );

	test( 'ends an approval that never reached the server in a terminal state', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'Draft me something about the site.' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		const review = page.getByRole( 'region', {
			name: 'Review what will be created',
		} );
		await expect( review ).toBeVisible( { timeout: 30000 } );

		// Only the execute call fails, so the proposal loaded normally and the
		// failure lands on the approval itself, where the controls are already
		// disabled and a lost rejection would leave them that way.
		await page.route(
			( url ) => url.href.includes( 'workspace/proposals' ),
			( route ) => route.abort( 'failed' )
		);

		await review
			.getByRole( 'checkbox', { name: 'Proposed E2E Draft' } )
			.check();
		await page
			.getByRole( 'button', { name: 'Create 1 selected item' } )
			.click();

		await expect(
			page.locator( '.components-notice__content', {
				hasText:
					'The request could not be sent, so nothing was created.',
			} )
		).toBeVisible();

		// No control is left disabled mid-flight, and nothing was written.
		await expect(
			page.getByRole( 'button', { name: /^Create \d+ selected item/ } )
		).toHaveCount( 0 );
		expect( await draftTitles( requestUtils ) ).toEqual( [] );
	} );
} );

/**
 * Reads back the titles of every draft post on the site.
 *
 * @param requestUtils The requestUtils fixture from the test context.
 * @return The draft titles.
 */
async function draftTitles( requestUtils: RequestUtils ): Promise< string[] > {
	const posts = ( await requestUtils.rest( {
		path: '/wp/v2/posts',
		method: 'GET',
		params: { status: 'draft', per_page: 100, context: 'edit' },
	} ) ) as Array< { title: { raw: string } } >;

	return posts.map( ( post ) => post.title.raw );
}

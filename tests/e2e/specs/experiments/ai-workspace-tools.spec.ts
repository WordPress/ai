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
		await expect( tools ).toContainText(
			'Looked up site content (1 steps)'
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

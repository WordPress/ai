/**
 * External dependencies
 */
import type { Page } from '@playwright/test';

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
	getMockScenarioReport,
	seedCredentials,
	setExperimentEnabled,
} from '../../utils/helpers';

const EXPERIMENT_ID = 'ai-workspace';
const WORKSPACE_PAGE = 'admin.php?page=ai-workspace';

// Only the Anthropic mock has tool-calling sequences; OpenAI is restored afterwards for the rest of the suite.
const seedAnthropic = ( requestUtils: RequestUtils ) =>
	seedCredentials( requestUtils, [ 'anthropic' ] );

const seedOpenAi = ( requestUtils: RequestUtils ) =>
	seedCredentials( requestUtils, [ 'openai' ] );

test.describe( 'AI Workspace tool loop', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await seedAnthropic( requestUtils );
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, true );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deactivateMockScenario( requestUtils );
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, false );
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

		// Only reachable through the tool call in the first fixture entry.
		await expect( turn ).toContainText(
			'The search returned the seeded fixture entry titled AI E2E Sample Content.',
			{ timeout: 30000 }
		);

		const tools = turn.locator( '.ai-workspace__tools' );

		await expect( tools.locator( 'summary' ) ).toContainText( 'Searched' );
		await expect( tools.locator( 'summary' ) ).not.toContainText(
			'Checked the site'
		);

		// Nothing is withheld from an admin, so the trace says nothing about permissions.
		await expect( tools.locator( 'summary' ) ).not.toContainText(
			'hidden by your permissions'
		);
		await expect( tools.locator( 'code' ) ).toHaveText(
			'ai/search-content'
		);
		await expect( tools ).toContainText( 'success' );

		await tools.locator( 'summary' ).click();
		await expect( tools ).toContainText( 'AI E2E Sample Content' );

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
		// A first turn has no ID to cancel, so Stop aborts locally and the request later fails on its own.
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

		// The stopped state must survive the aborted request settling.
		await page.waitForTimeout( 1000 );
		await expect( turn ).toContainText( 'You stopped this response.' );
		await expect( turn ).not.toContainText( 'Send this message again' );
		await expect( turn.locator( '.components-notice' ) ).toHaveCount( 0 );

		await expect(
			page.locator( '.screen-reader-text[aria-live="polite"]' )
		).toHaveText( 'Response stopped.' );
	} );
} );

// The question the `workspace-ask` scenario asks, and its reply to any answer.
const QUESTION = 'How would you like to go on?';
const ANSWER_REPLY = 'Got it. I will rework the series to match.';

test.describe( 'AI Workspace questions', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await seedAnthropic( requestUtils );
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, true );
		await activateMockScenario( requestUtils, 'workspace-ask' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'Plan a series about the block editor.' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		await expect(
			page.getByRole( 'group', { name: QUESTION } )
		).toBeVisible( { timeout: 30000 } );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deactivateMockScenario( requestUtils );
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, false );
		await seedOpenAi( requestUtils );
	} );

	test( 'asks with choices, and a click sends the choice as the answer', async ( {
		page,
		requestUtils,
	} ) => {
		const card = page.getByRole( 'group', { name: QUESTION } );
		const turns = page.locator( '.ai-workspace__turn' );

		// Asking ended the turn: one model call, and the card waits for an answer.
		await expect( turns ).toHaveCount( 1 );
		expect(
			( await getMockScenarioReport( requestUtils ) ).calls[
				'Anthropic/workspace-ask'
			]
		).toBe( 1 );
		// Three choices, then the card's own close control.
		await expect( card.getByRole( 'button' ) ).toHaveCount( 4 );
		await expect( card.getByRole( 'button' ).last() ).toHaveText(
			'Dismiss question'
		);
		await expect(
			card.getByRole( 'button', { name: 'Fewer parts' } )
		).toBeEnabled();

		await expect(
			turns.first().locator( '.ai-workspace__tools' )
		).toHaveCount( 0 );

		await card.getByRole( 'button', { name: 'Fewer parts' } ).click();

		await expect( turns ).toHaveCount( 2 );
		await expect(
			turns.nth( 1 ).locator( '.ai-workspace__message--user' )
		).toHaveText( 'Fewer parts' );
		await expect( turns.nth( 1 ) ).toContainText( ANSWER_REPLY, {
			timeout: 30000,
		} );

		for ( const choice of [
			'Create these 5 drafts',
			'Change the angle',
			'Fewer parts',
		] ) {
			await expect(
				card.getByRole( 'button', { name: choice } )
			).toBeDisabled();
		}
		await expect(
			card.getByRole( 'button', { name: 'Fewer parts' } )
		).toHaveAttribute( 'aria-pressed', 'true' );
		await expect(
			card.getByRole( 'button', { name: 'Change the angle' } )
		).toHaveAttribute( 'aria-pressed', 'false' );
	} );

	test( 'dismissing closes the question without a model call', async ( {
		page,
		requestUtils,
	} ) => {
		const card = page.getByRole( 'group', { name: QUESTION } );
		const turns = page.locator( '.ai-workspace__turn' );
		const calls = async () =>
			( await getMockScenarioReport( requestUtils ) ).calls[
				'Anthropic/workspace-ask'
			];

		await card.getByRole( 'button', { name: 'Dismiss question' } ).click();

		await expect( card ).toContainText( 'Dismissed' );
		await expect(
			card.getByRole( 'button', { name: 'Dismiss question' } )
		).toHaveCount( 0 );
		await expect( card.getByRole( 'button' ) ).toHaveCount( 3 );
		for ( const button of await card.getByRole( 'button' ).all() ) {
			await expect( button ).toBeDisabled();
		}
		await expect(
			page.getByLabel( 'Message', { exact: true } )
		).toBeFocused();
		await expect(
			page.locator( '.screen-reader-text[aria-live="polite"]' )
		).toHaveText( 'Question dismissed' );
		await expect( turns ).toHaveCount( 1 );
		expect( await calls() ).toBe( 1 );

		// A new message after closing is an ordinary turn, not an answer.
		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'Make it four parts' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		await expect( turns ).toHaveCount( 2 );
		await expect( turns.nth( 1 ) ).toContainText( ANSWER_REPLY, {
			timeout: 30000,
		} );
		expect( await calls() ).toBe( 2 );
		await expect( card ).toContainText( 'Dismissed' );
		for ( const button of await card.getByRole( 'button' ).all() ) {
			await expect( button ).toHaveAttribute( 'aria-pressed', 'false' );
		}
	} );

	test( 'a question answered elsewhere cannot be dismissed, and shows its answer', async ( {
		page,
		requestUtils,
	} ) => {
		const card = page.getByRole( 'group', { name: QUESTION } );
		const chat = new URL( page.url() ).searchParams.get( 'chat' ) ?? '';

		expect( chat ).not.toBe( '' );

		// Another window answers first.
		await requestUtils.rest( {
			path: '/ai/v1/workspace/messages',
			method: 'POST',
			data: { message: 'Fewer parts', conversation_id: chat },
		} );

		const refused = page.waitForResponse( ( response ) =>
			decodeURIComponent( response.url() ).includes( '/dismiss' )
		);
		await card.getByRole( 'button', { name: 'Dismiss question' } ).click();
		expect( ( await refused ).status() ).toBe( 409 );

		await expect(
			card.getByRole( 'button', { name: 'Fewer parts' } )
		).toHaveAttribute( 'aria-pressed', 'true' );
		await expect( card ).not.toContainText( 'Dismissed' );
		await expect( page.locator( '.ai-workspace__turn' ) ).toHaveCount( 2 );
	} );

	test( 'a failed dismissal leaves the question open and says so', async ( {
		page,
	} ) => {
		const card = page.getByRole( 'group', { name: QUESTION } );

		await page.route(
			( url ) => decodeURIComponent( url.href ).includes( '/dismiss' ),
			( route ) => route.abort()
		);

		await card.getByRole( 'button', { name: 'Dismiss question' } ).click();

		await expect( card ).toContainText(
			'The question could not be dismissed. Try again.'
		);
		await expect(
			card.getByRole( 'button', { name: 'Fewer parts' } )
		).toBeEnabled();
		await expect(
			card.getByRole( 'button', { name: 'Dismiss question' } )
		).toBeEnabled();
		await expect( card ).not.toContainText( 'Dismissed' );
	} );

	test( 'a typed reply also closes the question', async ( { page } ) => {
		const card = page.getByRole( 'group', { name: QUESTION } );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'Make it four parts' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		await expect(
			page.locator( '.ai-workspace__turn' ).nth( 1 )
		).toContainText( ANSWER_REPLY, { timeout: 30000 } );

		await expect( card.getByRole( 'button' ) ).toHaveCount( 3 );
		for ( const button of await card.getByRole( 'button' ).all() ) {
			await expect( button ).toBeDisabled();
			await expect( button ).toHaveAttribute( 'aria-pressed', 'false' );
		}
	} );
} );

test.describe( 'AI Workspace question asked alongside a proposal', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await seedAnthropic( requestUtils );
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, true );
		await activateMockScenario( requestUtils, 'workspace-ask-propose' );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deactivateMockScenario( requestUtils );
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, false );
		await seedOpenAi( requestUtils );
		await requestUtils.deleteAllPosts();
	} );

	test( 'settling the proposal does not close the question', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'Plan a series about the block editor.' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		const card = page.getByRole( 'group', { name: QUESTION } );
		await expect( card ).toBeVisible( { timeout: 30000 } );

		await page.getByRole( 'button', { name: 'Discard' } ).click();

		// The automatic follow-up is not an answer, so the question stays open.
		const turns = page.locator( '.ai-workspace__turn' );
		await expect( turns.nth( 1 ) ).toContainText(
			'Understood. The question above still stands.',
			{ timeout: 30000 }
		);
		await expect(
			card.getByRole( 'button', { name: 'Fewer parts' } )
		).toBeEnabled();

		await card.getByRole( 'button', { name: 'Fewer parts' } ).click();

		await expect(
			turns.nth( 2 ).locator( '.ai-workspace__message--user' )
		).toHaveText( 'Fewer parts' );
		await expect(
			card.getByRole( 'button', { name: 'Fewer parts' } )
		).toBeDisabled();
		await expect(
			card.getByRole( 'button', { name: 'Fewer parts' } )
		).toHaveAttribute( 'aria-pressed', 'true' );
	} );
} );

// The one follow-up reply the proposal scenario serves after a decision.
const FOLLOW_UP_REPLY =
	'Noted what you decided about “Proposed E2E Draft”. Want me to draft another post?';

test.describe( 'AI Workspace proposal confirmation', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await seedAnthropic( requestUtils );
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, true );
		await activateMockScenario( requestUtils, 'workspace-proposal' );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deactivateMockScenario( requestUtils );
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, false );
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
			name: 'Create 1 draft',
		} );
		await expect( review ).toBeVisible( { timeout: 30000 } );

		// Proposing ended the turn.
		const turns = page.locator( '.ai-workspace__turn' );
		await expect( turns ).toHaveCount( 1 );
		await expect( turns.first() ).toContainText(
			'I will propose one draft for you to review.'
		);
		expect(
			( await getMockScenarioReport( requestUtils ) ).calls[
				'Anthropic/workspace-proposal'
			]
		).toBe( 1 );

		await expect( review ).toContainText( 'Proposed E2E Draft' );
		await expect( review ).toContainText( 'post — will be saved as draft' );

		// Proposing wrote nothing.
		expect( await draftTitles( requestUtils ) ).toEqual( [] );

		await expect(
			review.getByRole( 'checkbox', { name: 'Proposed E2E Draft' } )
		).toBeChecked();
		await review.getByRole( 'button', { name: 'Create 1 draft' } ).click();

		const created = page.getByRole( 'region', { name: 'Created 1 draft' } );
		await expect( created ).toContainText( 'Proposed E2E Draft' );

		const [ draft ] = await drafts( requestUtils );
		expect( draft?.title ).toBe( 'Proposed E2E Draft' );
		await expect(
			created.getByRole( 'link', { name: 'Open draft' } )
		).toHaveAttribute( 'href', editLink( draft?.id ?? 0 ) );

		await expect( turns ).toHaveCount( 2 );
		await expect( turns.nth( 1 ) ).toContainText( FOLLOW_UP_REPLY, {
			timeout: 30000,
		} );
		await expect(
			turns.nth( 1 ).locator( '.ai-workspace__message--user' )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'link', { name: 'Open draft' } )
		).toBeVisible();

		// Exactly one follow-up: no further model call follows it.
		await page.waitForTimeout( 1000 );
		await expect( turns ).toHaveCount( 2 );
		expect(
			( await getMockScenarioReport( requestUtils ) ).calls[
				'Anthropic/workspace-proposal'
			]
		).toBe( 2 );
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
			name: 'Create 1 draft',
		} );
		await expect( review ).toBeVisible( { timeout: 30000 } );

		await page.getByRole( 'button', { name: 'Discard' } ).click();

		await expect(
			page.locator( '.components-notice__content', {
				hasText: 'Discarded. Nothing was created.',
			} )
		).toBeVisible();
		expect( await draftTitles( requestUtils ) ).toEqual( [] );

		const followUp = page.locator( '.ai-workspace__turn' ).nth( 1 );
		await expect( followUp ).toContainText( FOLLOW_UP_REPLY, {
			timeout: 30000,
		} );
		await expect(
			followUp.locator( '.ai-workspace__message--user' )
		).toHaveCount( 0 );
	} );

	test( 'keeps the outcome and says so when the follow-up fails', async ( {
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
			name: 'Create 1 draft',
		} );
		await expect( review ).toBeVisible( { timeout: 30000 } );

		// Only the follow-up request fails; the approval itself goes through.
		let followUps = 0;
		await page.route(
			( url ) => url.href.includes( 'workspace/messages' ),
			async ( route ) => {
				if ( route.request().postDataJSON()?.follow_up ) {
					followUps += 1;
					await route.abort( 'failed' );
					return;
				}

				await route.continue();
			}
		);

		await review.getByRole( 'button', { name: 'Create 1 draft' } ).click();

		await expect(
			page.getByRole( 'region', { name: 'Created 1 draft' } )
		).toBeVisible();

		const followUp = page.locator( '.ai-workspace__turn' ).nth( 1 );
		await expect( followUp ).toContainText(
			'The assistant couldn’t follow up on this.'
		);
		await expect(
			followUp.getByRole( 'button', { name: 'Send this message again' } )
		).toHaveCount( 0 );

		// Nothing is retried.
		await page.waitForTimeout( 1000 );
		expect( followUps ).toBe( 1 );
		expect( await draftTitles( requestUtils ) ).toEqual( [
			'Proposed E2E Draft',
		] );
	} );

	test( 'reports a proposal it could not load instead of spinning forever', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );

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
			name: 'Create 1 draft',
		} );
		await expect( review ).toBeVisible( { timeout: 30000 } );

		// Only execute fails; the card's read-back still finds the proposal pending.
		await page.route(
			( url ) =>
				url.href.includes( 'workspace/proposals' ) &&
				url.href.includes( '/execute' ),
			( route ) => route.abort( 'failed' )
		);

		await review.getByRole( 'button', { name: 'Create 1 draft' } ).click();

		await expect(
			page.locator( '.components-notice__content', {
				hasText:
					'The request could not be sent, so nothing was created.',
			} )
		).toBeVisible();

		await expect(
			page.getByRole( 'button', { name: /^Create \d+ draft/ } )
		).toHaveCount( 0 );
		expect( await draftTitles( requestUtils ) ).toEqual( [] );
	} );
} );

// The three drafts the `workspace-proposal-series` scenario proposes.
const SERIES = [
	'Getting Started with Blocks',
	'Building a Page Layout',
	'Reusing Your Best Blocks',
];

test.describe( 'AI Workspace proposal card', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await seedAnthropic( requestUtils );
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, true );
		await activateMockScenario( requestUtils, 'workspace-proposal-series' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'Plan a three-part series about the block editor.' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		await expect(
			page.getByRole( 'region', { name: 'Create 3 drafts' } )
		).toBeVisible( { timeout: 30000 } );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await deactivateMockScenario( requestUtils );
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, false );
		await seedOpenAi( requestUtils );
		await requestUtils.deleteAllPosts();
	} );

	test( 'starts a batch unchecked, and the button counts what is checked', async ( {
		page,
	} ) => {
		const card = page.getByRole( 'region', { name: 'Create 3 drafts' } );

		// Each item in a batch is chosen deliberately, so none starts checked,
		// and neither does "Select all".
		await expect(
			card.getByRole( 'checkbox', { name: 'Select all' } )
		).not.toBeChecked();
		for ( const title of SERIES ) {
			await expect(
				card.getByRole( 'checkbox', { name: title } )
			).not.toBeChecked();
		}

		const create = card.getByRole( 'button', {
			name: /^Create \d+ draft/,
		} );
		await expect( create ).toHaveText( 'Create 0 drafts' );
		await expect( create ).toBeDisabled();

		await card.getByRole( 'checkbox', { name: SERIES[ 1 ] } ).check();
		await expect( create ).toHaveText( 'Create 1 draft' );
		await expect( create ).toBeEnabled();

		await card.getByRole( 'checkbox', { name: SERIES[ 0 ] } ).check();
		await card.getByRole( 'checkbox', { name: SERIES[ 2 ] } ).check();
		await expect( create ).toHaveText( 'Create 3 drafts' );

		await card.getByRole( 'checkbox', { name: SERIES[ 0 ] } ).uncheck();
		await expect( create ).toHaveText( 'Create 2 drafts' );

		// "Select all" checks what is left, and clearing it unchecks everything.
		const all = card.getByRole( 'checkbox', { name: 'Select all' } );
		await expect( all ).not.toBeChecked();
		await all.check();
		await expect( create ).toHaveText( 'Create 3 drafts' );
		for ( const title of SERIES ) {
			await expect(
				card.getByRole( 'checkbox', { name: title } )
			).toBeChecked();
		}
		await all.uncheck();
		await expect( create ).toHaveText( 'Create 0 drafts' );

		await expect(
			card.getByText( 'Blocks are the pieces a page is built from.', {
				exact: false,
			} )
		).toBeHidden();
		await card.getByText( 'Preview' ).first().click();
		await expect(
			card.getByText( 'Blocks are the pieces a page is built from.', {
				exact: false,
			} )
		).toBeVisible();
	} );

	test( 'opens each created draft, or all of them in Posts', async ( {
		page,
		requestUtils,
	} ) => {
		await checkSeries( page );
		await page.getByRole( 'button', { name: 'Create 3 drafts' } ).click();

		const created = page.getByRole( 'region', {
			name: 'Created 3 drafts',
		} );
		await expect( created ).toBeVisible();

		const posts = await drafts( requestUtils );
		expect( posts.map( ( post ) => post.title ) ).toEqual( SERIES );

		for ( const post of posts ) {
			await expect(
				created.getByRole( 'link', { name: `Open “${ post.title }”` } )
			).toHaveAttribute( 'href', editLink( post.id ) );
		}

		const all = created.getByRole( 'link', { name: 'Open all in Posts' } );
		const href = new URL( ( await all.getAttribute( 'href' ) ) ?? '' );
		expect( href.pathname ).toBe( '/wp-admin/edit.php' );
		expect( href.searchParams.get( 'post_type' ) ).toBe( 'post' );
		expect( href.searchParams.get( 'post_status' ) ).toBe( 'draft' );
	} );

	test( 'says why an item was not created, and opens the rest', async ( {
		page,
	} ) => {
		// A mocked model can't force a failed or duplicate item, so the server's answer is rewritten.
		await page.route(
			( url ) => url.href.includes( '/execute' ),
			async ( route ) => {
				const response = await route.fetch();
				const body = await response.json();

				body.items[ 1 ] = {
					...body.items[ 1 ],
					outcome: 'failed',
					post_id: 0,
					edit_link: '',
					error_code: 'db_insert_error',
					error_message: 'Could not insert post into the database.',
				};
				body.items[ 2 ] = { ...body.items[ 2 ], outcome: 'duplicate' };
				body.created -= 2;
				body.failed += 1;
				body.duplicate += 1;

				await route.fulfill( { response, json: body } );
			}
		);

		await checkSeries( page );
		await page.getByRole( 'button', { name: 'Create 3 drafts' } ).click();

		const created = page.getByRole( 'region', { name: 'Created 1 draft' } );
		await expect( created ).toBeVisible();
		await expect(
			created.getByRole( 'link', { name: 'Open draft' } )
		).toBeVisible();

		await expect(
			created.getByRole( 'listitem' ).filter( { hasText: SERIES[ 1 ] } )
		).toContainText(
			'Not created: Could not insert post into the database.'
		);
		await expect(
			created.getByRole( 'link', { name: `Open “${ SERIES[ 1 ] }”` } )
		).toHaveCount( 0 );

		const duplicate = created
			.getByRole( 'listitem' )
			.filter( { hasText: SERIES[ 2 ] } );
		await expect( duplicate ).toContainText( 'Already created earlier' );
		await expect(
			duplicate.getByRole( 'link', { name: `Open “${ SERIES[ 2 ] }”` } )
		).toBeVisible();
	} );
} );

/**
 * Checks every item on the series card, which starts unchecked.
 *
 * @param page The page fixture from the test context.
 */
async function checkSeries( page: Page ): Promise< void > {
	const card = page.getByRole( 'region', { name: 'Create 3 drafts' } );

	for ( const title of SERIES ) {
		await card.getByRole( 'checkbox', { name: title } ).check();
	}
}

/**
 * Reads back the titles of every draft post on the site.
 *
 * @param requestUtils The requestUtils fixture from the test context.
 * @return The draft titles.
 */
async function draftTitles( requestUtils: RequestUtils ): Promise< string[] > {
	return ( await drafts( requestUtils ) ).map( ( post ) => post.title );
}

/**
 * Reads back every draft post on the site, oldest first.
 *
 * @param requestUtils The requestUtils fixture from the test context.
 * @return The drafts' IDs and titles.
 */
async function drafts(
	requestUtils: RequestUtils
): Promise< Array< { id: number; title: string } > > {
	const posts = ( await requestUtils.rest( {
		path: '/wp/v2/posts',
		method: 'GET',
		params: {
			status: 'draft',
			per_page: 100,
			context: 'edit',
			orderby: 'id',
			order: 'asc',
		},
	} ) ) as Array< { id: number; title: { raw: string } } >;

	return posts.map( ( post ) => ( { id: post.id, title: post.title.raw } ) );
}

/**
 * Matches a post's block editor link on any host.
 *
 * @param id The post ID.
 * @return A pattern for the edit link.
 */
function editLink( id: number ): RegExp {
	return new RegExp(
		`^https?://[^/]+/wp-admin/post\\.php\\?post=${ id }&action=edit$`
	);
}

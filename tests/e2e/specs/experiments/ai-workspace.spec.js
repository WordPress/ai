/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	disableExperiment,
	disableExperiments,
	enableExperiment,
	enableExperiments,
} from '../../utils/helpers';

const EXPERIMENT_LABEL = 'AI Workspace';
const WORKSPACE_PAGE = 'tools.php?page=ai-workspace';

// The mocked OpenAI responses fixture answers every workspace turn with this
// text (see tests/e2e-testing/responses/OpenAI/responses.json).
const MOCKED_RESPONSE =
	'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure';

test.describe( 'AI Workspace transcript', () => {
	test.beforeEach( async ( { admin, page } ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
		await admin.visitAdminPage( WORKSPACE_PAGE );
	} );

	test.afterEach( async ( { admin, page } ) => {
		await disableExperiment( admin, page, EXPERIMENT_LABEL );
		await disableExperiments( admin, page );
	} );

	test( 'renders the empty state before any turn is taken', async ( {
		page,
	} ) => {
		await expect( page.locator( '.ai-workspace__empty' ) ).toContainText(
			'Ask about your site to get started'
		);
		await expect( page.locator( '.ai-workspace__turn' ) ).toHaveCount( 0 );
	} );

	test( 'offers exactly the two context scopes', async ( { page } ) => {
		const scope = page.getByLabel( 'Context scope' );

		await expect( scope ).toBeVisible();
		await expect( scope.locator( 'option' ) ).toHaveText( [
			'Site Context',
			'General Knowledge',
		] );
	} );

	test( 'streams a turn into the transcript and clears it', async ( {
		page,
	} ) => {
		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'What should I write about next?' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		// The person's own message is echoed, and the assistant's answer lands
		// in the same turn. Whether it arrived over the streaming transport or
		// through the buffered fallback, the rendered result is the same.
		const turn = page.locator( '.ai-workspace__turn' ).first();
		await expect( turn ).toContainText( 'What should I write about next?' );
		await expect( turn ).toContainText( MOCKED_RESPONSE, {
			timeout: 30000,
		} );

		// The turn is terminated, so the in-progress state is gone.
		await expect( page.locator( '.ai-workspace__progress' ) ).toHaveCount(
			0
		);

		// Focus returns to the input when the turn completes.
		await expect(
			page.getByLabel( 'Message', { exact: true } )
		).toBeFocused();

		await page
			.getByRole( 'button', { name: 'Clear conversation' } )
			.click();

		await expect( page.locator( '.ai-workspace__turn' ) ).toHaveCount( 0 );
		await expect( page.locator( '.ai-workspace__empty' ) ).toBeVisible();
	} );

	test( 'reaches the scope control, the input and the turn controls by keyboard', async ( {
		page,
	} ) => {
		await page.getByLabel( 'Context scope' ).focus();

		const order = [];

		for ( let step = 0; step < 4; step++ ) {
			await page.keyboard.press( 'Tab' );

			const focused = page.locator( ':focus' );

			order.push(
				( await focused.getAttribute( 'aria-label' ) ) ||
					( await focused.textContent() ) ||
					( await focused.evaluate( ( node ) => node.tagName ) )
			);
		}

		expect( order.join( '|' ) ).toContain( 'Send' );
		expect( order.join( '|' ) ).toContain( 'Stop' );
		expect( order.join( '|' ) ).toContain( 'Clear conversation' );
	} );

	/*
	 * Stopping an in-flight turn is not covered here. The e2e environment mocks
	 * the provider with an instant canned response, so there is no window in
	 * which a turn is still running for a stop to interrupt; asserting it would
	 * mean asserting a race. The cancellation route itself is covered by the
	 * turn endpoint's PHP tests, and the stop control's own state transitions
	 * are unverified in this suite.
	 */
} );

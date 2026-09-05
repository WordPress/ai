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
			'What are we working on?'
		);
		await expect( page.locator( '.ai-workspace__suggestion' ) ).toHaveCount(
			4
		);
		await expect( page.locator( '.ai-workspace__turn' ) ).toHaveCount( 0 );
	} );

	test( 'a suggestion fills the composer without sending it', async ( {
		page,
	} ) => {
		await page.locator( '.ai-workspace__suggestion' ).first().click();

		// The prompt is a starting point to edit, so it lands in the input and
		// no turn is taken.
		await expect(
			page.getByLabel( 'Message', { exact: true } )
		).not.toHaveValue( '' );
		await expect( page.locator( '.ai-workspace__turn' ) ).toHaveCount( 0 );
	} );

	test( 'offers exactly the two context scopes', async ( { page } ) => {
		const scope = page.getByRole( 'button', { name: 'Context scope' } );

		// The control states the active scope on its face.
		await expect( scope ).toContainText( 'Site Context' );

		await scope.click();

		const options = page.getByRole( 'menuitemradio' );

		await expect( options ).toHaveCount( 2 );
		await expect(
			page.getByRole( 'menuitemradio', { name: /Site Context/ } )
		).toBeVisible();
		await expect(
			page.getByRole( 'menuitemradio', { name: /General Knowledge/ } )
		).toBeVisible();
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

		await page.getByRole( 'button', { name: 'New topic' } ).click();

		await expect( page.locator( '.ai-workspace__turn' ) ).toHaveCount( 0 );
		await expect( page.locator( '.ai-workspace__empty' ) ).toBeVisible();
	} );

	test( 'reaches the scope control, the input and the turn controls by keyboard', async ( {
		page,
	} ) => {
		// Clearing the conversation is a header action, reachable on its own.
		await expect(
			page.getByRole( 'button', { name: 'New topic' } )
		).toBeVisible();

		await page.getByLabel( 'Message', { exact: true } ).focus();

		const order = [];

		for ( let step = 0; step < 3; step++ ) {
			await page.keyboard.press( 'Tab' );

			const focused = page.locator( ':focus' );

			order.push(
				( await focused.getAttribute( 'aria-label' ) ) ||
					( await focused.textContent() ) ||
					( await focused.evaluate( ( node ) => node.tagName ) )
			);
		}

		expect( order.join( '|' ) ).toContain( 'Context scope' );
		expect( order.join( '|' ) ).toContain( 'Stop' );
		expect( order.join( '|' ) ).toContain( 'Send' );
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

/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { setExperimentEnabled } from '../../utils/helpers';

const EXPERIMENT_ID = 'ai-workspace';
const WORKSPACE_PAGE = 'admin.php?page=ai-workspace';

// The mocked OpenAI responses fixture answers every workspace turn with this text.
const MOCKED_RESPONSE =
	'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure';

test.describe( 'AI Workspace transcript', () => {
	test.beforeEach( async ( { admin, requestUtils } ) => {
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, true );
		await admin.visitAdminPage( WORKSPACE_PAGE );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, false );
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

		// The header offers no "New space"; the only one is the Chats panel's.
		await expect(
			page.getByRole( 'button', { name: 'New space' } )
		).toHaveCount( 1 );
		await expect(
			page
				.getByRole( 'complementary', { name: 'Spaces' } )
				.getByRole( 'button', { name: 'New space' } )
		).toBeVisible();
	} );

	test( 'the composer carries one hint that names the send shortcut', async ( {
		page,
	} ) => {
		const input = page.getByLabel( 'Message', { exact: true } );

		await expect( input ).toHaveAttribute(
			'placeholder',
			/⌘\/Ctrl \+ Enter to send/
		);

		await expect(
			page.locator(
				'.ai-workspace__composer .components-base-control__help'
			)
		).toHaveCount( 0 );

		await expect( input ).toHaveAccessibleDescription(
			'Press Command or Control and Enter to send.'
		);

		await input.fill( 'What should I write about next?' );
		await input.press( 'ControlOrMeta+Enter' );

		await expect( page.locator( '.ai-workspace__turn' ) ).toContainText(
			MOCKED_RESPONSE,
			{ timeout: 30000 }
		);
	} );

	test( 'a suggestion fills the composer without sending it', async ( {
		page,
	} ) => {
		await page.locator( '.ai-workspace__suggestion' ).first().click();

		await expect(
			page.getByLabel( 'Message', { exact: true } )
		).not.toHaveValue( '' );
		await expect( page.locator( '.ai-workspace__turn' ) ).toHaveCount( 0 );
	} );

	test( 'offers exactly the two context scopes', async ( { page } ) => {
		const scope = page.getByRole( 'button', { name: 'Context scope' } );

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

		const turn = page.locator( '.ai-workspace__turn' ).first();
		await expect( turn ).toContainText( 'What should I write about next?' );
		await expect( turn ).toContainText( MOCKED_RESPONSE, {
			timeout: 30000,
		} );

		await expect( page.locator( '.ai-workspace__progress' ) ).toHaveCount(
			0
		);

		await expect(
			page.getByLabel( 'Message', { exact: true } )
		).toBeFocused();

		await page.getByRole( 'button', { name: 'New space' } ).click();

		await expect( page.locator( '.ai-workspace__turn' ) ).toHaveCount( 0 );
		await expect( page.locator( '.ai-workspace__empty' ) ).toBeVisible();
		await expect( page.locator( '.ai-workspace__suggestion' ) ).toHaveCount(
			4
		);
		await expect(
			page.getByRole( 'button', { name: 'New space' } )
		).toHaveCount( 1 );
	} );

	test( 'reaches the scope control, the input and the turn controls by keyboard', async ( {
		page,
	} ) => {
		await expect(
			page.getByRole( 'button', { name: 'New space' } )
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
} );

/**
 * Holds every turn request open until it is released, since the mock answers instantly.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Promise<{ release: () => Promise<void> }>} Lets the held turns go on.
 */
async function holdTurns( page ) {
	const held = [];
	let isReleased = false;

	await page.route(
		( url ) =>
			url.href.includes( 'workspace/messages' ) &&
			! url.href.includes( 'cancel' ),
		( route ) => {
			if ( isReleased ) {
				return route.continue();
			}

			held.push( route );
		}
	);

	return {
		release: async () => {
			isReleased = true;
			await Promise.all( held.map( ( route ) => route.continue() ) );
		},
	};
}

/**
 * Reads a sprite's source as text, so a still frame can be told from a moving one.
 *
 * @param {import('@playwright/test').Locator} sprite The sprite image.
 * @return {Promise<string>} The decoded source.
 */
function spriteSource( sprite ) {
	return sprite.evaluate( ( node ) => decodeURIComponent( node.src ) );
}

/**
 * Whether a sprite image loaded and decoded.
 *
 * @param {import('@playwright/test').Locator} sprite The sprite image.
 * @return {Promise<boolean>} True when the image rendered.
 */
function spriteLoaded( sprite ) {
	return sprite.evaluate(
		( node ) => node.complete && node.naturalWidth > 0
	);
}

test.describe( 'AI Workspace Wapuu', () => {
	test.beforeEach( async ( { admin, requestUtils } ) => {
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, true );
		await admin.visitAdminPage( WORKSPACE_PAGE );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, false );
	} );

	test( 'a running turn shows the busy Wapuu beside "Responding…"', async ( {
		page,
	} ) => {
		// The shared Playwright config asks for reduced motion; this test needs motion on.
		await page.emulateMedia( { reducedMotion: 'no-preference' } );
		await holdTurns( page );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'What should I write about next?' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		const progress = page.locator( '.ai-workspace__progress' );
		const sprite = progress.locator( '.ai-workspace__wapuu--busy' );

		await expect( progress ).toContainText( 'Responding…' );
		await expect( sprite ).toBeVisible();
		await expect( sprite ).toHaveAttribute( 'alt', '' );
		expect( await spriteLoaded( sprite ) ).toBe( true );

		await expect( progress.locator( '.components-spinner' ) ).toHaveCount(
			0
		);
		expect( await spriteSource( sprite ) ).not.toContain(
			'animation:none'
		);
	} );

	test( 'with reduced motion the busy Wapuu is a still frame', async ( {
		page,
	} ) => {
		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await holdTurns( page );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'What should I write about next?' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		const sprite = page.locator(
			'.ai-workspace__progress .ai-workspace__wapuu--busy'
		);

		await expect( sprite ).toBeVisible();

		// An image's reduced-motion query runs in its own document, so check the source instead.
		expect( await spriteSource( sprite ) ).toContain( 'animation:none' );
		expect( await spriteLoaded( sprite ) ).toBe( true );
	} );

	test( 'a failed turn shows the error Wapuu with the error', async ( {
		page,
	} ) => {
		await page.route(
			( url ) =>
				url.href.includes( 'workspace/messages' ) &&
				! url.href.includes( 'cancel' ),
			( route ) => route.abort( 'failed' )
		);

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'What should I write about next?' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		const failure = page
			.locator( '.ai-workspace__turn' )
			.first()
			.locator( '.components-notice.is-error .ai-workspace__failure' );

		await expect( failure ).toBeVisible();
		await expect( failure ).not.toHaveText( '' );

		const sprite = failure.locator( '.ai-workspace__wapuu--error' );

		await expect( sprite ).toBeVisible();
		await expect( sprite ).toHaveAttribute( 'alt', '' );
		expect( await spriteLoaded( sprite ) ).toBe( true );

		await expect(
			page.locator( '.ai-workspace__wapuu--busy' )
		).toHaveCount( 0 );
	} );

	test( 'a finished turn shows the done Wapuu briefly', async ( {
		page,
	} ) => {
		const turns = await holdTurns( page );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'What should I write about next?' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		await expect(
			page.locator( '.ai-workspace__wapuu--busy' )
		).toBeVisible();

		await turns.release();

		const turn = page.locator( '.ai-workspace__turn' ).first();
		await expect( turn ).toContainText( MOCKED_RESPONSE, {
			timeout: 30000,
		} );

		const done = turn.locator( '.ai-workspace__wapuu--done' );

		await expect( done ).toBeVisible();
		await expect( done ).toHaveAttribute( 'alt', '' );
		await expect(
			page.locator( '.ai-workspace__wapuu--busy' )
		).toHaveCount( 0 );

		// It goes away on its own.
		await expect( done ).toHaveCount( 0, { timeout: 5000 } );
	} );
} );

test.describe( 'AI Workspace frame', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, true );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await setExperimentEnabled( requestUtils, EXPERIMENT_ID, false );
	} );

	for ( const viewport of [
		{ width: 1280, height: 800 },
		{ width: 390, height: 844 },
	] ) {
		test( `fills the viewport at ${ viewport.width }x${ viewport.height }, with the composer at the bottom`, async ( {
			admin,
			page,
		} ) => {
			await page.setViewportSize( viewport );
			await admin.visitAdminPage( WORKSPACE_PAGE );

			await expect(
				page.locator( '.ai-workspace__empty' )
			).toBeVisible();

			await expect( page.locator( '#wpfooter' ) ).toBeHidden();
			await expect( page.locator( '#adminmenumain' ) ).toBeAttached();

			const composer = await page
				.locator( '.ai-workspace__composer' )
				.boundingBox();

			expect( composer ).not.toBeNull();
			expect(
				Math.abs( composer.y + composer.height - viewport.height )
			).toBeLessThanOrEqual( 4 );

			// Nothing scrolls the document itself.
			expect(
				await page.evaluate(
					() =>
						document.scrollingElement.scrollHeight <=
						window.innerHeight + 1
				)
			).toBe( true );
		} );
	}
} );

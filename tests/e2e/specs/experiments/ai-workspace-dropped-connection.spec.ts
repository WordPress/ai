/**
 * End-to-end coverage for a turn whose response never reaches the browser.
 *
 * WordPress Playground gives up on any request after 25 seconds, and a proxy in
 * front of a site can give up sooner than PHP does. In both cases WordPress
 * keeps running the turn and saves it. Each test here lets the request reach
 * the server, waits for the server's answer, and then drops it or replaces it
 * with a gateway error, so the reply can only come from the saved chat.
 */
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
import { disableExperiment, enableExperiment } from '../../utils/helpers';

const EXPERIMENT_LABEL = 'AI Workspace';
const WORKSPACE_PAGE = 'tools.php?page=ai-workspace';

// The mocked OpenAI responses fixture answers every plain turn with this text.
const MOCKED_RESPONSE =
	'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure';

/**
 * Deletes every chat the current user has, active and archived.
 *
 * @param requestUtils The requestUtils fixture from the test context.
 */
async function deleteAllChats( requestUtils: RequestUtils ): Promise< void > {
	for ( const archived of [ false, true ] ) {
		const list = ( await requestUtils.rest( {
			path: '/ai/v1/workspace/conversations',
			params: { archived, per_page: 50 },
		} ) ) as { conversations: Array< { id: string } > };

		for ( const chat of list.conversations ) {
			await requestUtils.rest( {
				path: '/ai/v1/workspace/conversations/' + chat.id,
				method: 'DELETE',
			} );
		}
	}
}

/**
 * Lets the next turn reach the server, then loses its response on the way back.
 *
 * @param page    The page.
 * @param outcome Drop the connection, or answer as a gateway that timed out.
 */
async function loseNextTurnResponse(
	page: import('@playwright/test').Page,
	outcome: 'drop' | 'gateway-timeout'
): Promise< void > {
	await page.route(
		( url ) => {
			const href = decodeURIComponent( url.href );
			return (
				href.includes( '/workspace/messages' ) &&
				! href.includes( '/workspace/messages/cancel' )
			);
		},
		async ( route ) => {
			// The server runs and saves the turn.
			await route.fetch();

			if ( 'drop' === outcome ) {
				await route.abort( 'failed' );
				return;
			}

			await route.fulfill( {
				status: 504,
				contentType: 'text/html',
				body: '<html><body>504 Gateway Time-out</body></html>',
			} );
		},
		{ times: 1 }
	);
}

/**
 * Sends one message from the composer.
 *
 * @param page    The page.
 * @param message The message.
 */
async function send(
	page: import('@playwright/test').Page,
	message: string
): Promise< void > {
	await page.getByLabel( 'Message', { exact: true } ).fill( message );
	await page.getByRole( 'button', { name: 'Send' } ).click();
}

test.describe( 'AI Workspace turn with a dropped connection', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
		await deleteAllChats( requestUtils );
		await page.setViewportSize( { width: 1280, height: 900 } );
	} );

	test.afterEach( async ( { admin, page, requestUtils } ) => {
		await page.unrouteAll( { behavior: 'ignoreErrors' } );
		await deleteAllChats( requestUtils );
		await disableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'a first turn whose response is lost still shows its reply, in a chat that carries on', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );
		await loseNextTurnResponse( page, 'drop' );

		await send( page, 'Plan the spring newsletter' );

		const turns = page.locator( '.ai-workspace__turn' );

		await expect( turns ).toHaveCount( 1 );
		await expect( turns.last() ).toContainText( MOCKED_RESPONSE, {
			timeout: 30000,
		} );
		await expect( turns.last() ).not.toContainText( 'NetworkError' );
		await expect(
			page.getByRole( 'button', { name: 'Send this message again' } )
		).toHaveCount( 0 );

		// The next turn continues the same chat rather than starting another.
		await send( page, 'And the summer one' );
		await expect( turns ).toHaveCount( 2 );
		await expect( turns.last() ).toContainText( MOCKED_RESPONSE, {
			timeout: 30000,
		} );

		const panel = page.getByRole( 'complementary', { name: 'Chats' } );

		await expect(
			panel.getByRole( 'button', {
				name: 'Plan the spring newsletter',
				exact: true,
			} )
		).toHaveCount( 1 );
		await expect(
			panel.getByRole( 'button', {
				name: 'And the summer one',
				exact: true,
			} )
		).toHaveCount( 0 );
	} );

	test( 'a later turn whose response is lost still shows its reply', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );

		const turns = page.locator( '.ai-workspace__turn' );

		await send( page, 'Plan the spring newsletter' );
		await expect( turns.last() ).toContainText( MOCKED_RESPONSE, {
			timeout: 30000,
		} );

		await loseNextTurnResponse( page, 'drop' );
		await send( page, 'And the summer one' );

		await expect( turns ).toHaveCount( 2 );
		await expect( turns.last() ).toContainText( MOCKED_RESPONSE, {
			timeout: 30000,
		} );
		await expect(
			page.getByRole( 'button', { name: 'Send this message again' } )
		).toHaveCount( 0 );
	} );

	test( 'a gateway timeout still shows the reply the server saved', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );
		await loseNextTurnResponse( page, 'gateway-timeout' );

		await send( page, 'Plan the spring newsletter' );

		const turns = page.locator( '.ai-workspace__turn' );

		await expect( turns.last() ).toContainText( MOCKED_RESPONSE, {
			timeout: 30000,
		} );
		await expect(
			page.getByRole( 'button', { name: 'Send this message again' } )
		).toHaveCount( 0 );
	} );
} );

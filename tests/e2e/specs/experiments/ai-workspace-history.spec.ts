/**
 * End-to-end coverage for the AI Workspace chat history panel.
 *
 * The flow it guards is the one the review asked for: start a new chat, then
 * find the old one again. Every change here goes through the conversations
 * routes, so each assertion that survives a reload is about what the server
 * stored, not what the panel happens to be showing.
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
import {
	activateMockScenario,
	deactivateMockScenario,
	disableExperiment,
	enableExperiment,
	seedCredentials,
} from '../../utils/helpers';

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
 * Sends one message and waits for the reply.
 *
 * @param page    The page.
 * @param message The message.
 * @param reply   Text the reply contains.
 */
async function sendAndWait(
	page: import('@playwright/test').Page,
	message: string,
	reply: string = MOCKED_RESPONSE
): Promise< void > {
	const turns = page.locator( '.ai-workspace__turn' );
	const before = await turns.count();

	await page.getByLabel( 'Message', { exact: true } ).fill( message );
	await page.getByRole( 'button', { name: 'Send' } ).click();
	await expect( turns ).toHaveCount( before + 1 );
	await expect( turns.last() ).toContainText( reply, { timeout: 30000 } );
}

test.describe( 'AI Workspace chat history', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
		await deleteAllChats( requestUtils );
		await page.setViewportSize( { width: 1280, height: 900 } );
	} );

	test.afterEach( async ( { admin, page, requestUtils } ) => {
		await deleteAllChats( requestUtils );
		await disableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'shows the empty state when there are no chats', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );

		const panel = page.getByRole( 'complementary', { name: 'Chats' } );

		await expect( panel ).toContainText( 'No chats yet.' );
	} );

	test( 'starts a new chat, then finds the old one and carries on in it', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );

		await sendAndWait( page, 'Plan the spring newsletter' );
		await sendAndWait( page, 'And the summer one' );

		const panel = page.getByRole( 'complementary', { name: 'Chats' } );
		const row = panel.getByRole( 'button', {
			name: 'Plan the spring newsletter',
			exact: true,
		} );

		// The chat appears under the name it was given from its first message.
		await expect( row ).toBeVisible();

		await panel.getByRole( 'button', { name: 'New chat' } ).click();

		await expect( page.locator( '.ai-workspace__turn' ) ).toHaveCount( 0 );
		await expect( page.locator( '.ai-workspace__empty' ) ).toBeVisible();
		await expect( row ).toBeVisible();

		await row.click();

		const turns = page.locator( '.ai-workspace__turn' );

		await expect( turns ).toHaveCount( 2 );
		await expect( turns.first() ).toContainText(
			'Plan the spring newsletter'
		);
		await expect( turns.first() ).toContainText( MOCKED_RESPONSE );
		await expect( page ).toHaveURL( /[?&]chat=[A-Za-z0-9-]+/ );

		// A further message continues the same chat, not a new one.
		await sendAndWait( page, 'One more thing' );
		await expect( panel.getByRole( 'listitem' ) ).toHaveCount( 1 );

		// A reload reopens it, from the URL.
		await page.reload();
		await expect( turns ).toHaveCount( 3, { timeout: 30000 } );
	} );

	test( 'renames a chat, and the name survives a reload', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );
		await sendAndWait( page, 'Original name' );

		const panel = page.getByRole( 'complementary', { name: 'Chats' } );

		await panel
			.getByRole( 'button', { name: 'Actions for “Original name”' } )
			.click();
		await page.getByRole( 'menuitem', { name: 'Rename' } ).click();

		const field = panel.getByRole( 'textbox', { name: 'Chat name' } );

		await field.fill( 'Launch plan' );
		await field.press( 'Enter' );

		await expect(
			panel.getByRole( 'button', { name: 'Launch plan', exact: true } )
		).toBeVisible();

		// Leaving the name empty keeps the old one.
		await panel
			.getByRole( 'button', { name: 'Actions for “Launch plan”' } )
			.click();
		await page.getByRole( 'menuitem', { name: 'Rename' } ).click();
		await field.fill( '   ' );
		await field.press( 'Enter' );

		await expect(
			panel.getByRole( 'button', { name: 'Launch plan', exact: true } )
		).toBeVisible();

		await page.reload();
		await expect(
			panel.getByRole( 'button', { name: 'Launch plan', exact: true } )
		).toBeVisible();
	} );

	test( 'archives, shows and restores a chat', async ( { admin, page } ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );
		await sendAndWait( page, 'Old idea' );

		const panel = page.getByRole( 'complementary', { name: 'Chats' } );

		await panel
			.getByRole( 'button', { name: 'Actions for “Old idea”' } )
			.click();
		await page.getByRole( 'menuitem', { name: 'Archive' } ).click();

		await expect(
			panel.getByRole( 'button', { name: 'Old idea', exact: true } )
		).toHaveCount( 0 );

		await panel.getByRole( 'button', { name: 'Archived' } ).click();
		await expect(
			panel.getByRole( 'button', { name: 'Old idea', exact: true } )
		).toBeVisible();

		await panel
			.getByRole( 'button', { name: 'Actions for “Old idea”' } )
			.click();
		await page.getByRole( 'menuitem', { name: 'Restore' } ).click();

		await expect( panel ).toContainText( 'No archived chats.' );

		await panel.getByRole( 'button', { name: 'Archived' } ).click();
		await expect(
			panel.getByRole( 'button', { name: 'Old idea', exact: true } )
		).toBeVisible();
	} );

	test( 'deletes a chat only once confirmed', async ( { admin, page } ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );
		await sendAndWait( page, 'Throwaway' );

		const panel = page.getByRole( 'complementary', { name: 'Chats' } );
		const row = panel.getByRole( 'button', {
			name: 'Throwaway',
			exact: true,
		} );
		const actions = panel.getByRole( 'button', {
			name: 'Actions for “Throwaway”',
		} );

		await actions.click();
		await page.getByRole( 'menuitem', { name: 'Delete' } ).click();
		await page.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( row ).toBeVisible();

		await actions.click();
		await page.getByRole( 'menuitem', { name: 'Delete' } ).click();
		await page
			.getByRole( 'dialog' )
			.getByRole( 'button', { name: 'Delete' } )
			.click();

		await expect( row ).toHaveCount( 0 );
		await expect( panel ).toContainText( 'No chats yet.' );

		// The chat on screen was the one deleted, so the screen starts afresh.
		await expect( page.locator( '.ai-workspace__empty' ) ).toBeVisible();
	} );

	test( 'a chat that is not there says so', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			WORKSPACE_PAGE + '&chat=00000000-0000-4000-8000-000000000000'
		);

		await expect(
			page.locator( '.components-notice__content', {
				hasText: 'That chat was not found.',
			} )
		).toBeVisible( { timeout: 30000 } );
		await expect( page.locator( '.ai-workspace__empty' ) ).toBeVisible();
	} );

	test( 'opens and closes as a drawer by keyboard on a narrow screen', async ( {
		admin,
		page,
	} ) => {
		await page.setViewportSize( { width: 390, height: 800 } );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		const toggle = page.getByRole( 'button', { name: 'Chats' } );
		const panel = page.getByRole( 'complementary', { name: 'Chats' } );

		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		await expect( panel ).toBeHidden();

		await toggle.focus();
		await page.keyboard.press( 'Enter' );

		await expect( panel ).toBeVisible();
		await expect(
			panel.getByRole( 'button', { name: 'New chat' } )
		).toBeFocused();

		await page.keyboard.press( 'Escape' );

		await expect( panel ).toBeHidden();
		await expect( toggle ).toBeFocused();
	} );
} );

test.describe( 'AI Workspace chat history with proposals', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await seedCredentials( requestUtils, [ 'anthropic' ] );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
		await deleteAllChats( requestUtils );
		await activateMockScenario( requestUtils, 'workspace-proposal' );
		await page.setViewportSize( { width: 1280, height: 900 } );
	} );

	test.afterEach( async ( { admin, page, requestUtils } ) => {
		await deactivateMockScenario( requestUtils );
		await deleteAllChats( requestUtils );
		await disableExperiment( admin, page, EXPERIMENT_LABEL );
		await seedCredentials( requestUtils, [ 'openai' ] );
		await requestUtils.deleteAllPosts();
	} );

	test( 'a reopened chat shows the drafts it created, with their links', async ( {
		admin,
		page,
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
		await review.getByRole( 'checkbox' ).first().check();
		await review.getByRole( 'button', { name: /^Create / } ).click();
		await expect(
			page.getByRole( 'link', { name: 'Open in the editor' } )
		).toBeVisible();

		const panel = page.getByRole( 'complementary', { name: 'Chats' } );

		await panel.getByRole( 'button', { name: 'New chat' } ).click();
		await panel
			.getByRole( 'button', {
				name: 'Draft me something about the site.',
				exact: true,
			} )
			.click();

		// The outcome comes back from the chat itself, link and all.
		await expect(
			page.getByRole( 'link', { name: 'Open in the editor' } )
		).toBeVisible( { timeout: 30000 } );
		await expect( page.getByRole( 'checkbox' ) ).toHaveCount( 0 );
	} );
} );

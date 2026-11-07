/**
 * End-to-end coverage for finding an admin screen from the AI Workspace.
 *
 * "Where do I change the timezone?" runs `ai/find-admin-screens`, and the
 * answer links Settings → General. The link is on the site's own admin host,
 * so the transcript renders it as a live anchor, and following it opens the
 * screen.
 */
/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	activateMockScenario,
	deactivateMockScenario,
	disableExperiment,
	enableExperiment,
	getMockScenarioReport,
	seedCredentials,
} from '../../utils/helpers';

const EXPERIMENT_LABEL = 'AI Workspace';
const WORKSPACE_PAGE = 'tools.php?page=ai-workspace';

test.describe( 'AI Workspace navigation', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await seedCredentials( requestUtils, [ 'anthropic' ] );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test.afterEach( async ( { admin, page, requestUtils } ) => {
		await deactivateMockScenario( requestUtils );
		await disableExperiment( admin, page, EXPERIMENT_LABEL );
		await seedCredentials( requestUtils, [ 'openai' ] );
	} );

	test( 'asking where to change the timezone links Settings → General, and the link opens it', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await activateMockScenario( requestUtils, 'workspace-navigate' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'Where do I change the timezone?' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		const turn = page.locator( '.ai-workspace__turn' ).first();
		const link = turn.getByRole( 'link', { name: 'Settings → General' } );

		await expect( link ).toBeVisible( { timeout: 30000 } );
		await expect( link ).toHaveAttribute(
			'href',
			/\/wp-admin\/options-general\.php$/
		);

		// The screen finder ran on the server and succeeded.
		const tools = turn.locator( '.ai-workspace__tools' );
		await tools.locator( 'summary' ).click();
		await expect( tools.locator( 'code' ) ).toHaveText(
			'ai/find-admin-screens'
		);
		await expect( tools ).toContainText( 'success' );

		// Both fixture entries answered the turn: the tool round trip happened.
		expect(
			( await getMockScenarioReport( requestUtils ) ).calls[
				'Anthropic/workspace-navigate'
			]
		).toBe( 2 );

		await link.click();

		await expect( page ).toHaveURL( /\/wp-admin\/options-general\.php$/ );
		await expect(
			page.getByRole( 'heading', { name: 'General Settings', level: 1 } )
		).toBeVisible();
		await expect( page.getByLabel( 'Timezone' ) ).toBeVisible();
	} );
} );

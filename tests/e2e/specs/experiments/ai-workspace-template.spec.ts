/**
 * End-to-end coverage for AI Workspace template proposals.
 *
 * A template is live for visitors as soon as it is created, so the card shows
 * the markup without a click, says what it replaces, and nothing is written
 * until the person approves it.
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
	getMockScenarioReport,
	seedCredentials,
} from '../../utils/helpers';

const EXPERIMENT_LABEL = 'AI Workspace';
const WORKSPACE_PAGE = 'tools.php?page=ai-workspace';
const BLOCK_THEME = 'twentytwentyfive';
const CLASSIC_THEME = 'twentytwentyone';

/**
 * Reads back the user templates for the active theme.
 *
 * @param requestUtils The requestUtils fixture from the test context.
 * @return The slugs of templates someone has created or edited.
 */
async function customTemplateSlugs(
	requestUtils: RequestUtils
): Promise< string[] > {
	const templates = ( await requestUtils.rest( {
		path: '/wp/v2/templates',
		method: 'GET',
	} ) ) as Array< { slug: string; source: string } >;

	return templates
		.filter( ( template ) => 'custom' === template.source )
		.map( ( template ) => template.slug );
}

test.describe( 'AI Workspace template proposals', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await requestUtils.activateTheme( BLOCK_THEME );
		await requestUtils.deleteAllTemplates( 'wp_template' );
		await seedCredentials( requestUtils, [ 'anthropic' ] );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
		await activateMockScenario( requestUtils, 'workspace-template' );
	} );

	test.afterEach( async ( { admin, page, requestUtils } ) => {
		await deactivateMockScenario( requestUtils );
		await disableExperiment( admin, page, EXPERIMENT_LABEL );
		await seedCredentials( requestUtils, [ 'openai' ] );
		await requestUtils.deleteAllTemplates( 'wp_template' );
		await requestUtils.activateTheme( CLASSIC_THEME );
	} );

	test( 'shows the template before approval, then creates it and opens the Site Editor', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'Make a 404 page that helps visitors find things.' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		const card = page.getByRole( 'region', {
			name: 'Create template: Page not found',
		} );
		await expect( card ).toBeVisible( { timeout: 30000 } );

		// Proposing ended the turn.
		expect(
			( await getMockScenarioReport( requestUtils ) ).calls[
				'Anthropic/workspace-template'
			]
		).toBe( 1 );

		// The card says what it is, what it replaces, and that it goes live.
		await expect( card ).toContainText(
			'Template 404, for the theme Twenty Twenty-Five.'
		);
		await expect( card ).toContainText(
			'Replaces your theme’s 404 template.'
		);
		await expect( card ).toContainText( 'live for visitors' );

		// The markup is shown without any interaction, and there is no checkbox.
		await expect( card.locator( 'code' ) ).toBeVisible();
		await expect( card.locator( 'code' ) ).toContainText(
			'We could not find that page'
		);
		await expect( card.getByRole( 'checkbox' ) ).toHaveCount( 0 );

		// Proposing wrote nothing.
		expect( await customTemplateSlugs( requestUtils ) ).toEqual( [] );

		await card.getByRole( 'button', { name: 'Create template' } ).click();

		const created = page.getByRole( 'region', {
			name: 'Created 1 template',
		} );
		await expect( created ).toContainText( 'Page not found' );
		expect( await customTemplateSlugs( requestUtils ) ).toEqual( [
			'404',
		] );

		const open = created.getByRole( 'link', { name: 'Open template' } );
		await expect( open ).toHaveAttribute(
			'href',
			/\/wp-admin\/site-editor\.php\?/
		);

		// The assistant follows up once.
		await expect( page.locator( '.ai-workspace__turn' ) ).toHaveCount( 2 );
		await expect(
			page.locator( '.ai-workspace__turn' ).nth( 1 )
		).toContainText( 'Your 404 template is in place.', { timeout: 30000 } );

		// The link lands on the new template in the Site Editor, which titles
		// the page after the template it opened.
		await open.click();
		await expect( page ).toHaveURL( /site-editor\.php/ );
		await expect( page ).toHaveTitle( /^Page not found ‹ Template/, {
			timeout: 30000,
		} );
	} );

	test( 'creates nothing when the template is discarded', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'Make a 404 page that helps visitors find things.' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		const card = page.getByRole( 'region', {
			name: 'Create template: Page not found',
		} );
		await expect( card ).toBeVisible( { timeout: 30000 } );

		await card.getByRole( 'button', { name: 'Discard' } ).click();

		await expect(
			page.locator( '.components-notice__content', {
				hasText: 'Discarded. Nothing was created.',
			} )
		).toBeVisible();
		expect( await customTemplateSlugs( requestUtils ) ).toEqual( [] );
	} );
} );

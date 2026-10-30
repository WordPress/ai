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

/*
 * The `workspace-code` scenario answers with one 40-line html block and one
 * 5-line php block (tests/e2e-testing/responses/Anthropic/scenarios/). Like
 * the tool loop specs, it is served by the Anthropic mock, so Anthropic is
 * seeded alone and OpenAI is restored afterwards.
 */
const seedAnthropic = ( requestUtils: RequestUtils ) =>
	seedCredentials( requestUtils, [ 'anthropic' ] );

const seedOpenAi = ( requestUtils: RequestUtils ) =>
	seedCredentials( requestUtils, [ 'openai' ] );

test.describe( 'AI Workspace code blocks', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await seedAnthropic( requestUtils );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
		await activateMockScenario( requestUtils, 'workspace-code' );
		await admin.visitAdminPage( WORKSPACE_PAGE );

		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( 'Write a template' );
		await page.getByRole( 'button', { name: 'Send' } ).click();

		await expect( page.locator( '.ai-workspace__code' ) ).toHaveCount( 2, {
			timeout: 30000,
		} );
	} );

	test.afterEach( async ( { admin, page, requestUtils } ) => {
		await deactivateMockScenario( requestUtils );
		await disableExperiment( admin, page, EXPERIMENT_LABEL );
		await seedOpenAi( requestUtils );
	} );

	test( 'collapses a long block behind a toggle that shows every line', async ( {
		page,
	} ) => {
		const block = page.locator( '.ai-workspace__code' ).first();
		const pre = block.locator( 'pre' );
		const toggle = block.locator( '.ai-workspace__code-toggle' );

		await expect( toggle ).toHaveAccessibleName( 'Show all 40 lines' );
		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );

		// Collapsed, the block shows only part of its text.
		const clipped = await pre.evaluate(
			( node ) => node.scrollHeight > node.clientHeight + 1
		);
		expect( clipped ).toBe( true );

		await toggle.click();

		await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		await expect( toggle ).toHaveAccessibleName( 'Show fewer lines' );
		expect(
			await pre.evaluate(
				( node ) => node.scrollHeight <= node.clientHeight + 1
			)
		).toBe( true );
	} );

	test( 'leaves a short block alone', async ( { page } ) => {
		const block = page.locator( '.ai-workspace__code' ).nth( 1 );

		await expect( block ).toContainText( "echo 'hi';" );
		await expect(
			block.getByRole( 'button', { name: /^Show all/ } )
		).toHaveCount( 0 );
	} );

	test( 'labels a block with its language', async ( { page } ) => {
		await expect(
			page.locator( '.ai-workspace__code-language' ).first()
		).toHaveText( 'html' );
		await expect(
			page.locator( '.ai-workspace__code-language' ).nth( 1 )
		).toHaveText( 'php' );
	} );

	test( 'copies the whole block from an icon in its top corner', async ( {
		context,
		page,
	} ) => {
		await context.grantPermissions( [
			'clipboard-read',
			'clipboard-write',
		] );

		const block = page.locator( '.ai-workspace__code' ).first();
		const copy = block.getByRole( 'button', { name: 'Copy code' } );

		await expect( copy ).toBeVisible();

		// The control sits in the block's top-right corner, not under the code.
		const blockBox = await block.boundingBox();
		const copyBox = await copy.boundingBox();

		if ( ! blockBox || ! copyBox ) {
			throw new Error( 'The code block or its copy control has no box.' );
		}

		expect( copyBox.y - blockBox.y ).toBeLessThanOrEqual( 16 );
		expect(
			blockBox.x + blockBox.width - ( copyBox.x + copyBox.width )
		).toBeLessThanOrEqual( 16 );

		// The block is still collapsed, and the copy takes every line anyway.
		await copy.click();

		const copied = await page.evaluate( () =>
			navigator.clipboard.readText()
		);

		expect( copied.split( '\n' ) ).toHaveLength( 40 );
		expect( copied.startsWith( '<!-- wp:group -->' ) ).toBe( true );
		expect( copied.endsWith( '</div>' ) ).toBe( true );

		await expect( page.locator( '#a11y-speak-polite' ) ).toContainText(
			'Copied'
		);

		// The control itself shows the copy took, for anyone not using a
		// screen reader.
		await expect(
			block.getByRole( 'button', { name: 'Copied' } )
		).toBeVisible();
	} );
} );

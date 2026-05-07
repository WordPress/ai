/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	enableExperiment,
	enableExperiments,
	purgeRequestLogs,
	visitAdminPage,
	visitRequestLogsPage,
} = require( '../../utils/helpers' );

const EXPERIMENT_LABEL = 'AI Request Logging';
const PAGE_HEADING = 'AI Request Logs';

test.describe( 'AI Request Logging Experiment', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'e2e-test-request-mocking' );
	} );

	test( 'Can enable the experiment and reach the AI Request Logs page', async ( {
		admin,
		page,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		await visitRequestLogsPage( admin );

		await expect(
			page.getByRole( 'heading', { name: PAGE_HEADING } )
		).toBeVisible();

		// Confirm the sidebar purge action is visible
		await expect(
			page.getByRole( 'button', { name: 'Purge All Logs' } )
		).toBeVisible();

		// Period selector defaults to "Last 24 Hours"
		await expect(
			page.getByRole( 'combobox' ).filter( { hasText: 'Last 24 Hours' } )
		).toBeVisible();
	} );

	test( 'Tools menu does not list AI Request Logs when the experiment is disabled', async ( {
		admin,
		page,
	} ) => {
		await enableExperiments( admin, page );
		await disableExperiment( admin, page, EXPERIMENT_LABEL );

		await visitAdminPage( admin, 'index.php' );

		// No menu link
		await expect(
			page.locator( '#menu-tools a[href*="page=ai-request-logs"]' )
		).toHaveCount( 0 );

		// Direct navigation does nothing
		await admin.visitAdminPage( 'tools.php', 'page=ai-request-logs' );
		await expect(
			page.getByRole( 'heading', { name: PAGE_HEADING } )
		).toHaveCount( 0 );
	} );

	test( 'Triggering an AI request creates a visible log row', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
		await enableExperiment( admin, page, 'Title Generation' );

		// Start from a known-empty table
		await purgeRequestLogs( requestUtils );

		// Trigger an AI request through the Title Generation flow
		await admin.createNewPost( {
			postType: 'post',
			title: '',
			content:
				'This is some test content for the AI Request Logging spec.',
		} );
		await editor.saveDraft();
		await editor.canvas.locator( '.editor-post-title__input' ).click();
		await editor.canvas
			.locator( '.ai-title-toolbar-container button' )
			.click();
		await page
			.locator( '.ai-title-generation-modal' )
			.getByRole( 'button', { name: 'Insert' } )
			.click();
		await expect(
			page.locator( '.ai-title-generation-modal' )
		).not.toBeVisible();

		await visitRequestLogsPage( admin );

		// Confirm the log row is visible
		await expect(
			page.locator( 'code', { hasText: 'openai:responses' } ).first()
		).toBeVisible( { timeout: 10000 } );
	} );

	test( 'Purge All Logs clears the table', async ( { admin, page } ) => {
		await visitRequestLogsPage( admin );

		// Make sure there is at least one row in the table (from previous test)
		await expect(
			page.locator( 'code', { hasText: 'openai:responses' } ).first()
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Purge All Logs' } ).click();
		await page.getByRole( 'button', { name: 'Yes, Purge All' } ).click();

		// Make sure the table is empty
		await expect( page.getByText( 'No results' ) ).toBeVisible();
		await expect(
			page.locator( 'code', { hasText: 'openai:responses' } )
		).toHaveCount( 0 );
	} );
} );

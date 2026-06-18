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
		await expect( page.getByLabel( 'Time period' ) ).toHaveValue( 'day' );
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
				'Artificial intelligence is rapidly changing how content is created, edited, and published across the web today. Writers increasingly rely on automated tools to draft outlines, summarize research, and suggest improvements to their work. These systems analyze large amounts of text and surface patterns that would take a human many hours to find on their own. As the technology matures, editors are learning to combine their own judgment with machine generated suggestions to produce stronger results. This paragraph exists only to provide enough words for the title generation experiment to run, because the feature now requires a reasonable amount of content before it will offer to generate a brand new title for the post.',
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

	test( 'Period selector switches the table data window', async ( {
		admin,
		page,
	} ) => {
		await visitRequestLogsPage( admin );

		const periodSelect = page.getByLabel( 'Time period' );

		// Make sure the request is for the logs list, not the filters or summary.
		const isLogsListRequest = ( req ) => {
			const url = decodeURIComponent( req.url() );
			return (
				url.includes( 'ai/v1/logs' ) &&
				! url.includes( '/logs/summary' ) &&
				! url.includes( '/logs/filters' )
			);
		};

		// Check if the request includes the date_from parameter
		const isDateFromRequest = ( req ) => {
			const url = decodeURIComponent( req.url() );
			return url.includes( 'date_from=' );
		};

		// Switching to "Last Minute" should fire a new logs request scoped by date_from
		const minuteRequestPromise = page.waitForRequest(
			( req ) => isLogsListRequest( req ) && isDateFromRequest( req )
		);
		await periodSelect.selectOption( 'minute' );
		const minuteRequest = await minuteRequestPromise;
		expect( decodeURIComponent( minuteRequest.url() ) ).toContain(
			'date_from='
		);

		// Switching to "All Time" should fire a request without date_from
		const allTimeRequestPromise = page.waitForRequest(
			( req ) => isLogsListRequest( req ) && ! isDateFromRequest( req )
		);
		await periodSelect.selectOption( 'all' );
		const allTimeRequest = await allTimeRequestPromise;
		expect( decodeURIComponent( allTimeRequest.url() ) ).not.toContain(
			'date_from='
		);
	} );

	test( 'Search box filters table rows', async ( { admin, page } ) => {
		await visitRequestLogsPage( admin );

		// Make sure there is at least one row in the table (from previous test)
		await expect(
			page.locator( 'code', { hasText: 'openai:responses' } ).first()
		).toBeVisible();

		const searchBox = page.getByRole( 'searchbox' );

		// Search for an operation that doesn't exist
		await searchBox.fill( 'this-operation-does-not-exist' );

		await expect( page.getByText( 'No results' ) ).toBeVisible();
		await expect(
			page.locator( 'code', { hasText: 'openai:responses' } )
		).toHaveCount( 0 );

		// Clearing the search restores the row
		await searchBox.clear();

		await expect(
			page.locator( 'code', { hasText: 'openai:responses' } ).first()
		).toBeVisible();
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

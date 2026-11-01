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
	purgeRequestLogs,
	seedRequestLog,
	visitAdminPage,
	visitRequestLogsPage,
} = require( '../../utils/helpers' );

const EXPERIMENT_LABEL = 'AI Request Logging';
const PAGE_HEADING = 'AI Request Logs';

test.describe( 'AI Request Logging Experiment', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'e2e-testing' );
	} );

	test( 'Can enable the experiment and reach the AI Request Logs page', async ( {
		admin,
		page,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		await visitRequestLogsPage( admin );

		await expect(
			page.getByRole( 'heading', { name: PAGE_HEADING } )
		).toBeVisible();

		// Confirm the sidebar delete controls are visible, defaulting to the least destructive option
		await expect( page.getByLabel( 'Logs to delete' ) ).toHaveValue(
			'365'
		);
		await expect(
			page.getByRole( 'button', { name: 'Delete', exact: true } )
		).toBeVisible();

		// Period selector defaults to "Last 24 Hours"
		await expect( page.getByLabel( 'Time period' ) ).toHaveValue( 'day' );
	} );

	test( 'Tools menu does not list AI Request Logs when the experiment is disabled', async ( {
		admin,
		page,
	} ) => {
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

	test( 'Deleting all logs clears the table', async ( { admin, page } ) => {
		await visitRequestLogsPage( admin );

		// Make sure there is at least one row in the table (from previous test)
		await expect(
			page.locator( 'code', { hasText: 'openai:responses' } ).first()
		).toBeVisible();

		await page.getByLabel( 'Logs to delete' ).selectOption( 'All logs' );

		const deleteButton = page.getByRole( 'button', {
			name: 'Delete',
			exact: true,
		} );
		await deleteButton.click();

		// The confirmation names the chosen scope and focuses Cancel
		await expect( page.getByText( 'Delete all logs?' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Cancel' } )
		).toBeFocused();

		const deleteResponsePromise = page.waitForResponse( ( response ) =>
			decodeURIComponent( response.url() ).includes( 'before_days=' )
		);
		await page.getByRole( 'button', { name: 'Yes, Delete' } ).click();

		// "All logs" sends before_days=0, which deletes everything
		const deleteResponse = await deleteResponsePromise;
		expect( decodeURIComponent( deleteResponse.url() ) ).toContain(
			'before_days=0'
		);
		expect( deleteResponse.ok() ).toBe( true );
		await expect(
			page
				.locator( '.components-snackbar' )
				.getByText( 'All logs have been deleted.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Delete', exact: true } )
		).toBeFocused();

		// Make sure the table is empty
		await expect( page.getByText( 'No results' ) ).toBeVisible();
		await expect(
			page.locator( 'code', { hasText: 'openai:responses' } )
		).toHaveCount( 0 );
	} );

	test( 'Deleting logs older than a chosen age keeps newer logs', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		await purgeRequestLogs( requestUtils );
		await seedRequestLog( requestUtils, 400, 'e2e:year-old' );
		await seedRequestLog( requestUtils, 100, 'e2e:months-old' );

		await visitRequestLogsPage( admin );

		const deleteButton = page.getByRole( 'button', {
			name: 'Delete',
			exact: true,
		} );

		// Old logs can be deleted even when the default "Last 24 Hours" view is empty
		await expect( page.getByText( 'No results' ) ).toBeVisible();
		await expect( deleteButton ).toBeEnabled();

		await seedRequestLog( requestUtils, 0, 'e2e:recent' );
		await page.reload();

		// Show every log so old entries are visible in the table
		await page.getByLabel( 'Time period' ).selectOption( 'all' );

		const table = page.locator( '.ai-request-logs__main' );
		for ( const operation of [
			'e2e:year-old',
			'e2e:months-old',
			'e2e:recent',
		] ) {
			await expect(
				table.locator( 'code', { hasText: operation } )
			).toBeVisible();
		}

		await page
			.getByLabel( 'Logs to delete' )
			.selectOption( 'Older than 90 days' );

		// Cancelling keeps every log and returns focus to the Delete button
		await deleteButton.click();
		await expect(
			page.getByText( 'Delete logs older than 90 days?' )
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( deleteButton ).toBeFocused();
		await expect(
			table.locator( 'code', { hasText: 'e2e:year-old' } )
		).toBeVisible();

		// Confirming deletes only the logs older than 90 days
		await deleteButton.click();
		const deleteResponsePromise = page.waitForResponse( ( response ) =>
			decodeURIComponent( response.url() ).includes( 'before_days=' )
		);
		await page.getByRole( 'button', { name: 'Yes, Delete' } ).click();

		const deleteResponse = await deleteResponsePromise;
		expect( decodeURIComponent( deleteResponse.url() ) ).toContain(
			'before_days=90'
		);
		expect( ( await deleteResponse.json() ).deleted ).toBe( 2 );
		await expect(
			page
				.locator( '.components-snackbar' )
				.getByText( '2 log entries have been deleted.' )
		).toBeVisible();
		await expect( deleteButton ).toBeFocused();

		await expect(
			table.locator( 'code', { hasText: 'e2e:year-old' } )
		).toHaveCount( 0 );
		await expect(
			table.locator( 'code', { hasText: 'e2e:months-old' } )
		).toHaveCount( 0 );
		await expect(
			table.locator( 'code', { hasText: 'e2e:recent' } )
		).toBeVisible();

		await purgeRequestLogs( requestUtils );
	} );
} );

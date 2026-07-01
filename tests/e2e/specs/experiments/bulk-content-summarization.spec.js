/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	clearCredentials,
	disableExperiment,
	enableExperiment,
	enableExperiments,
	seedCredentials,
} = require( '../../utils/helpers' );

const LONG_CONTENT =
	'This is test content for the Bulk Content Summarization feature. ' +
	'The content needs to be long enough for the summarization feature to work properly. ' +
	'Adding more sentences ensures we meet the minimum content threshold. ' +
	'The bulk summarization feature allows administrators to generate summaries for multiple posts at once. ' +
	'This saves significant time compared to opening each post individually and clicking Generate Summary. ' +
	'By selecting multiple posts in the posts list and applying the bulk action, the process is automated. ' +
	'Summaries are generated sequentially using the configured AI provider and stored as post meta.';

test.describe( 'Bulk Content Summarization', () => {
	test( 'Bulk action appears in the posts list', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Summarization Experiment.
		await enableExperiment( admin, page, 'Content Summarization' );

		// Create a post so the list is not empty.
		await requestUtils.createPost( {
			title: 'Bulk Summary Presence Test',
			content: LONG_CONTENT,
			status: 'publish',
		} );

		// Navigate to the posts list.
		await admin.visitAdminPage( 'edit.php' );

		// Verify the bulk actions dropdown contains the Generate Summary option.
		const bulkSelect = page.locator( '#bulk-action-selector-top' );
		await expect( bulkSelect ).toBeVisible();
		await expect(
			bulkSelect.locator( 'option[value="wpai_generate_summary"]' )
		).toHaveCount( 1 );
	} );

	test( 'Bulk action generates summaries for selected posts', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Summarization Experiment.
		await enableExperiment( admin, page, 'Content Summarization' );

		// Create two posts with enough content for summarization.
		await requestUtils.createPost( {
			title: 'Bulk Summary Test Post One',
			content: LONG_CONTENT,
			status: 'publish',
		} );
		await requestUtils.createPost( {
			title: 'Bulk Summary Test Post Two',
			content: LONG_CONTENT,
			status: 'publish',
		} );

		// Navigate to the posts list.
		await admin.visitAdminPage( 'edit.php' );

		// Select all items via the header checkbox.
		await page.locator( '#cb-select-all-1' ).check();

		// Choose the bulk action.
		await page
			.locator( '#bulk-action-selector-top' )
			.selectOption( 'wpai_generate_summary' );

		// Click Apply.
		await page.locator( '#doaction' ).click();

		// After redirect, the progress notice should appear immediately, then
		// update to the completion message once all posts are processed.
		await expect(
			page.locator( '.notice p', {
				hasText: /Generating summaries|Summary generated/,
			} )
		).toBeVisible( { timeout: 30000 } );

		// Wait for the completion message.
		await expect(
			page.locator( '.notice p', {
				hasText: /Summary generated/,
			} )
		).toBeVisible( { timeout: 60000 } );

		// Verify query args have been stripped from the URL.
		expect( page.url() ).not.toContain( 'wpai_bulk_summary' );
		expect( page.url() ).not.toContain( 'wpai_post_ids' );
	} );

	test( 'Bulk action shows an error notice when no provider is configured', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		await clearCredentials( requestUtils );

		try {
			// Globally turn on Experiments.
			await enableExperiments( admin, page );

			// Enable the Content Summarization Experiment.
			await enableExperiment( admin, page, 'Content Summarization' );

			// Create a post to select.
			await requestUtils.createPost( {
				title: 'Bulk Summary No Provider Test',
				content: LONG_CONTENT,
				status: 'publish',
			} );

			// Navigate to the posts list.
			await admin.visitAdminPage( 'edit.php' );

			// Select all items via the header checkbox.
			await page.locator( '#cb-select-all-1' ).check();

			// Choose the bulk action.
			await page
				.locator( '#bulk-action-selector-top' )
				.selectOption( 'wpai_generate_summary' );

			// Click Apply.
			await page.locator( '#doaction' ).click();

			await expect(
				page.locator( '.notice-error p', {
					hasText:
						'This feature requires a valid AI Connector to function properly.',
				} )
			).toBeVisible( { timeout: 30000 } );

			// Verify query args have been stripped from the URL.
			expect( page.url() ).not.toContain( 'wpai_bulk_summary' );
			expect( page.url() ).not.toContain( 'wpai_post_ids' );
		} finally {
			await seedCredentials( requestUtils );
		}
	} );

	test( 'Bulk action is not visible when experiment is disabled', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Content Summarization Experiment.
		await disableExperiment( admin, page, 'Content Summarization' );

		// Create a post so the list is not empty.
		await requestUtils.createPost( {
			title: 'Bulk Summary Disabled Test',
			content: LONG_CONTENT,
			status: 'publish',
		} );

		// Navigate to the posts list.
		await admin.visitAdminPage( 'edit.php' );

		// Verify the bulk actions dropdown does NOT contain the Generate Summary option.
		const bulkSelect = page.locator( '#bulk-action-selector-top' );
		await expect( bulkSelect ).toBeVisible();
		await expect(
			bulkSelect.locator( 'option[value="wpai_generate_summary"]' )
		).toHaveCount( 0 );
	} );
} );

/**
 * External dependencies
 */
const path = require( 'path' );

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
} = require( '../../utils/helpers' );

// Path to a test image (1x1 PNG) used for media upload in E2E tests.
const TEST_IMAGE_PATH = path.join( __dirname, '../../../data/sample.png' );

test.describe( 'Bulk Alt Text Generation', () => {
	test.beforeEach( async ( { admin, page } ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'Alt Text Generation' );
	} );

	test( 'Bulk action appears in the Media Library list view', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Upload a test image.
		await requestUtils.uploadMedia( TEST_IMAGE_PATH );

		// Navigate to Media Library in list mode.
		await admin.visitAdminPage( 'upload.php', 'mode=list' );

		// Verify the bulk actions dropdown contains the Generate Alt Text option.
		const bulkSelect = page.locator( '#bulk-action-selector-top' );
		await expect( bulkSelect ).toBeVisible();
		await expect(
			bulkSelect.locator( 'option[value="wpai_generate_alt_text"]' )
		).toHaveCount( 1 );
	} );

	test( 'Bulk action generates alt text for selected images', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Upload two test images.
		await requestUtils.uploadMedia( TEST_IMAGE_PATH );
		await requestUtils.uploadMedia( TEST_IMAGE_PATH );

		// Navigate to Media Library in list mode.
		await admin.visitAdminPage( 'upload.php', 'mode=list' );

		// Select all items via the header checkbox.
		await page.locator( '#cb-select-all-1' ).check();

		// Choose the bulk action.
		await page
			.locator( '#bulk-action-selector-top' )
			.selectOption( 'wpai_generate_alt_text' );

		// Click Apply.
		await page.locator( '#doaction' ).click();

		// After redirect, the progress notice should appear.
		await expect(
			page.locator( '.notice p', {
				hasText: /Generating alt text|Alt text generated/,
			} )
		).toBeVisible( { timeout: 30000 } );

		// Wait for the completion message.
		await expect(
			page.locator( '.notice p', {
				hasText: /Alt text generated/,
			} )
		).toBeVisible( { timeout: 60000 } );

		// Verify query args have been stripped from the URL.
		expect( page.url() ).not.toContain( 'wpai_bulk_alt_text' );
		expect( page.url() ).not.toContain( 'wpai_attachment_ids' );
	} );

	test( 'Query args are stripped from URL after generation completes', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Upload a test image.
		await requestUtils.uploadMedia( TEST_IMAGE_PATH );

		// Navigate to Media Library in list mode.
		await admin.visitAdminPage( 'upload.php', 'mode=list' );

		// Select all items.
		await page.locator( '#cb-select-all-1' ).check();

		// Choose the bulk action and apply.
		await page
			.locator( '#bulk-action-selector-top' )
			.selectOption( 'wpai_generate_alt_text' );
		await page.locator( '#doaction' ).click();

		// Wait for completion.
		await expect(
			page.locator( '.notice p', {
				hasText: /Alt text generated/,
			} )
		).toBeVisible( { timeout: 60000 } );

		// Confirm query args are removed — refreshing should not re-trigger generation.
		const currentUrl = page.url();
		expect( currentUrl ).not.toContain( 'wpai_bulk_alt_text' );
		expect( currentUrl ).not.toContain( 'wpai_attachment_ids' );
	} );

	test( 'Bulk action is not visible when experiment is disabled', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Disable the alt text generation experiment.
		await disableExperiment( admin, page, 'Alt Text Generation' );

		// Upload a test image.
		await requestUtils.uploadMedia( TEST_IMAGE_PATH );

		// Navigate to Media Library in list mode.
		await admin.visitAdminPage( 'upload.php', 'mode=list' );

		// Verify the bulk actions dropdown does NOT contain the Generate Alt Text option.
		const bulkSelect = page.locator( '#bulk-action-selector-top' );
		await expect( bulkSelect ).toBeVisible();
		await expect(
			bulkSelect.locator( 'option[value="wpai_generate_alt_text"]' )
		).toHaveCount( 0 );
	} );
} );

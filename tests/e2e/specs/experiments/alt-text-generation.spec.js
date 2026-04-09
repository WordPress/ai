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
	disableExperiments,
	enableExperiment,
	enableExperiments,
} = require( '../../utils/helpers' );

// Path to a test image (1x1 PNG) used for media upload in E2E tests.
const TEST_IMAGE_PATH = path.join( __dirname, '../../../data/sample.png' );

test.describe( 'Alt Text Generation Experiment', () => {
	test( 'Can enable the alt text generation experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Alt Text Generation Experiment.
		await enableExperiment( admin, page, 'Alt Text Generation' );
	} );

	test( 'Can use the Alt Text Generation Experiment in the Media Library', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Alt Text Generation Experiment.
		await enableExperiment( admin, page, 'Alt Text Generation' );

		// Upload a test image.
		await requestUtils.uploadMedia( TEST_IMAGE_PATH );

		// Go to the Media Library.
		await admin.visitAdminPage( 'upload.php' );

		// Click on the first image in the Media Library.
		await page
			.locator( '.media-frame-content ul.attachments li:first-child' )
			.click();

		// Ensure the alt text generation button is visible and says Generate
		await expect(
			page.locator( '#ai-alt-text-generate-button', {
				hasText: 'Generate',
			} )
		).toBeVisible();

		// Click the alt text generation button.
		await page.locator( '#ai-alt-text-generate-button' ).click();

		// Ensure the alt text generation button now says Regenerate
		await expect(
			page.locator( '#ai-alt-text-generate-button', {
				hasText: 'Regenerate',
			} )
		).toBeVisible();

		// Ensure the alt text textarea is visible.
		const altTextarea = page
			.locator( '#attachment-details-two-column-alt-text' )
			.first();
		await expect( altTextarea ).toBeVisible();

		// Ensure it has the generated alt text (value from mocked AI response).
		await expect( altTextarea ).toHaveValue(
			/Edit or Delete Your First WordPress Post/
		);
	} );

	test( 'Can use the Alt Text Generation Experiment in the editor', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Alt Text Generation Experiment.
		await enableExperiment( admin, page, 'Alt Text Generation' );

		// Upload a test image so we have a URL the editor can load.
		await requestUtils.uploadMedia( TEST_IMAGE_PATH );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Alt Text Generation Experiment',
			content:
				'This is some test content for the Alt Text Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Insert a blank image block.
		await editor.insertBlock( {
			name: 'core/image',
		} );

		// Click the Media Library button in the image block.
		const imageBlock = editor.canvas.locator( '.wp-block-image' ).first();
		const mediaLibraryButton = imageBlock
			.locator( 'button', { hasText: 'Media Library' } )
			.first();
		await mediaLibraryButton.click();

		// Click on the first image in the Media Library.
		await page
			.locator( '.media-frame-content ul.attachments li:first-child' )
			.click();

		// Ensure the alt text generation button is visible and says Generate
		await expect(
			page.locator( '#ai-alt-text-generate-button', {
				hasText: 'Generate',
			} )
		).toBeVisible();

		// Click the alt text generation button.
		await page.locator( '#ai-alt-text-generate-button' ).click();

		// Ensure the alt text generation button now says Regenerate
		await expect(
			page.locator( '#ai-alt-text-generate-button', {
				hasText: 'Regenerate',
			} )
		).toBeVisible();

		// Ensure the alt text textarea is visible.
		const altTextarea = page
			.locator( '#attachment-details-alt-text' )
			.first();
		await expect( altTextarea ).toBeVisible();

		// Ensure it has the generated alt text (value from mocked AI response).
		await expect( altTextarea ).toHaveValue(
			/Edit or Delete Your First WordPress Post/
		);

		// Click the Select button.
		await page
			.locator( '.media-frame-toolbar button', { hasText: 'Select' } )
			.click();

		// Clear the alt text textarea.
		await page
			.locator( '.components-tools-panel textarea' )
			.first()
			.fill( '' );

		// Ensure the Generate button is visible in the sidebar.
		await expect(
			page.locator( '.ai-alt-text-controls button', {
				hasText: 'Generate Alt Text',
			} )
		).toBeVisible();

		// Click the Generate button.
		await page.locator( '.ai-alt-text-controls button' ).click();

		// Ensure the generated alt text shows in the textarea.
		await expect(
			page.locator( '.ai-alt-text-controls textarea' )
		).toHaveValue( /Edit or Delete Your First WordPress Post/ );

		// Click the Apply button.
		await page
			.locator( '.ai-alt-text-controls button', { hasText: 'Apply' } )
			.click();

		// Ensure the generated alt text shows in the textarea.
		await expect(
			page.locator( '.components-tools-panel textarea' ).first()
		).toHaveValue( /Edit or Delete Your First WordPress Post/ );

		// Ensure the generate button text is updated.
		await expect(
			page.locator( '.ai-alt-text-controls button', {
				hasText: 'Regenerate Alt Text',
			} )
		).toBeVisible();

		// Remove alt text.
		await page
			.locator( '.components-tools-panel textarea' )
			.first()
			.fill( '' );

		// Ensure the generate button text is updated.
		await expect(
			page.locator( '.ai-alt-text-controls button', {
				hasText: 'Generate Alt Text',
			} )
		).toBeVisible();

		// Generate alt text again.
		await page.locator( '.ai-alt-text-controls button' ).click();

		// Click the Dismiss button.
		await page
			.locator( '.ai-alt-text-controls button', { hasText: 'Dismiss' } )
			.click();

		// Ensure the generated alt text is not visible.
		await expect(
			page.locator( '.ai-alt-text-controls textarea' )
		).not.toBeVisible();

		// Save the post.
		await editor.saveDraft();
	} );

	test( 'Ensure the Alt Text Generation Experiment UI is not visible when Experiments are globally disabled', async ( {
		admin,
		editor,
		requestUtils,
		page,
	} ) => {
		// Enable the Alt Text Generation Experiment.
		await enableExperiment( admin, page, 'Alt Text Generation' );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Upload a test image.
		await requestUtils.uploadMedia( TEST_IMAGE_PATH );

		// Go to the Media Library.
		await admin.visitAdminPage( 'upload.php' );

		// Click on the first image in the Media Library.
		await page
			.locator( '.media-frame-content ul.attachments li:first-child' )
			.click();

		// Ensure the alt text generation button is not visible.
		await expect(
			page.locator( '#ai-alt-text-generate-button' )
		).not.toBeVisible();

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Alt Text Generation Experiment Globally Disabled',
			content:
				'This is some test content for the Alt Text Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Insert a blank image block.
		await editor.insertBlock( {
			name: 'core/image',
		} );

		// Click the Media Library button in the image block.
		const imageBlock = editor.canvas.locator( '.wp-block-image' ).first();
		const mediaLibraryButton = imageBlock
			.locator( 'button', { hasText: 'Media Library' } )
			.first();
		await mediaLibraryButton.click();

		// Click on the first image in the Media Library.
		await page
			.locator( '.media-frame-content ul.attachments li:first-child' )
			.click();

		// Ensure the alt text generation button is not visible.
		await expect(
			page.locator( '#ai-alt-text-generate-button' )
		).not.toBeVisible();

		// Click the Select button.
		await page
			.locator( '.media-frame-toolbar button', { hasText: 'Select' } )
			.click();

		// Ensure the Generate button is not visible in the sidebar.
		await expect(
			page.locator( '.ai-alt-text-controls button' )
		).not.toBeVisible();

		await editor.saveDraft();
	} );

	test( 'Ensure the Alt Text Generation Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		editor,
		requestUtils,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Alt Text Generation Experiment.
		await disableExperiment( admin, page, 'Alt Text Generation' );

		// Upload a test image.
		await requestUtils.uploadMedia( TEST_IMAGE_PATH );

		// Go to the Media Library.
		await admin.visitAdminPage( 'upload.php' );

		// Click on the first image in the Media Library.
		await page
			.locator( '.media-frame-content ul.attachments li:first-child' )
			.click();

		// Ensure the alt text generation button is not visible.
		await expect(
			page.locator( '#ai-alt-text-generate-button' )
		).not.toBeVisible();

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Alt Text Generation Experiment Globally Disabled',
			content:
				'This is some test content for the Alt Text Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Insert a blank image block.
		await editor.insertBlock( {
			name: 'core/image',
		} );

		// Click the Media Library button in the image block.
		const imageBlock = editor.canvas.locator( '.wp-block-image' ).first();
		const mediaLibraryButton = imageBlock
			.locator( 'button', { hasText: 'Media Library' } )
			.first();
		await mediaLibraryButton.click();

		// Click on the first image in the Media Library.
		await page
			.locator( '.media-frame-content ul.attachments li:first-child' )
			.click();

		// Ensure the alt text generation button is not visible.
		await expect(
			page.locator( '#ai-alt-text-generate-button' )
		).not.toBeVisible();

		// Click the Select button.
		await page
			.locator( '.media-frame-toolbar button', { hasText: 'Select' } )
			.click();

		// Ensure the Generate button is not visible in the sidebar.
		await expect(
			page.locator( '.ai-alt-text-controls button' )
		).not.toBeVisible();

		await editor.saveDraft();
	} );

	test( 'Bulk action appears in the Media Library list view', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Alt Text Generation Experiment.
		await enableExperiment( admin, page, 'Alt Text Generation' );

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
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Alt Text Generation Experiment.
		await enableExperiment( admin, page, 'Alt Text Generation' );

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
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Alt Text Generation Experiment.
		await enableExperiment( admin, page, 'Alt Text Generation' );

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
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

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

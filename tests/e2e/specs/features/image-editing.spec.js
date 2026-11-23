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
	clearConnector,
	enableExperiment,
	visitAdminPage,
	visitConnectorsPage,
} = require( '../../utils/helpers' );

// Path to a test image (1x1 PNG) used for media upload in E2E tests.
const TEST_IMAGE_PATH = path.join( __dirname, '../../../data/sample.png' );

test.describe( 'Image Editing Feature', () => {
	test( 'Can enable the image generation/editing Feature', async ( {
		admin,
		page,
	} ) => {
		// Enable the Image Generation Feature (which contains editing).
		await enableExperiment( admin, page, 'Image Generation and Editing' );

		await visitConnectorsPage( admin );

		const googleConnector = page.locator( '[role="listitem"]', {
			has: page.getByRole( 'heading', { name: 'Google', exact: true } ),
		} );

		// Add dummy credentials for Google.
		await googleConnector
			.getByRole( 'button', { name: /Set up|Edit/i } )
			.click();
		await googleConnector
			.getByRole( 'textbox' )
			.first()
			.fill( 'valid-api-key' );

		// Save the credentials.
		await googleConnector
			.getByRole( 'button', { name: /Save|Update/i } )
			.click();
	} );

	test( 'Can refine an image within a block', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Image Generation Feature.
		await enableExperiment( admin, page, 'Image Generation and Editing' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Inline Image Editing Feature',
			content: 'This is some test content for the Image Editing Feature.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Insert a blank image block.
		await editor.insertBlock( {
			name: 'core/image',
		} );

		// Find the inline Generate Image button.
		const generateImageButton = editor.canvas
			.getByRole( 'document', { name: 'Block: Image' } )
			.getByRole( 'button', { name: 'Generate Image' } );
		await expect( generateImageButton ).toBeVisible();

		// Click the generate image inline button.
		await generateImageButton.click();

		const modal = page.getByRole( 'dialog', { name: 'Generate Image' } );

		// Add a prompt and generate the image.
		await modal
			.getByRole( 'textbox', { name: 'Prompt' } )
			.fill( 'A smiling face emoji' );
		await modal
			.getByRole( 'button', { name: 'Generate', exact: true } )
			.click();

		// Ensure the Refine Image button is visible.
		const refineButton = modal.getByRole( 'button', {
			name: 'Refine Image',
		} );
		await expect( refineButton ).toBeVisible();

		// Click the Refine Image button.
		await refineButton.click();

		// Ensure the modal is in the refining state.
		await expect(
			page.locator( '.ai-image-generation__refining' )
		).toBeVisible();

		// Ensure the refine prompt textarea is visible.
		const refineTextarea = modal.getByRole( 'textbox', {
			name: 'Describe the refinements you want to make to the image.',
		} );
		await expect( refineTextarea ).toBeVisible();

		// Add our refine prompt and generate the image.
		await refineTextarea.fill( 'Add a red hat' );
		await modal
			.getByRole( 'button', { name: 'Refine', exact: true } )
			.click();

		// Ensure the images are visible in the modal.
		await expect(
			modal.locator( '.ai-image-generation__preview-image' )
		).toHaveCount( 2 );

		// Click the previous image navigation arrow.
		const previousBtn = modal.getByRole( 'button', {
			name: 'Previous version',
		} );
		await previousBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			modal.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '1 / 2' );

		// Click the next image navigation arrow.
		const nextBtn = modal.getByRole( 'button', {
			name: 'Next version',
		} );
		await nextBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			modal.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '2 / 2' );

		const useImageButton = modal.getByRole( 'button', {
			name: 'Use Image',
		} );
		await expect( useImageButton ).toBeVisible();

		await useImageButton.click();

		// Ensure the image is inserted into the block.
		await expect(
			editor.canvas
				.getByRole( 'document', { name: 'Block: Image' } )
				.getByRole( 'img' )
		).toBeVisible();

		// Ensure the image is in the Media Library.
		await visitAdminPage( admin, 'upload.php', 'mode=grid' );

		const imageItem = page
			.getByRole( 'checkbox', {
				name: 'Add a red hat',
				exact: true,
			} )
			.first();

		await expect( imageItem ).toBeVisible();
		await expect( imageItem.locator( 'img' ) ).toBeVisible();
	} );

	test( 'Can refine an image in the stand-alone Generate Image page', async ( {
		admin,
		page,
	} ) => {
		// Enable the Image Generation Feature.
		await enableExperiment( admin, page, 'Image Generation and Editing' );

		// Visit the Media Library.
		await visitAdminPage( admin, 'upload.php', 'mode=grid' );

		// Click the Generate Image button.
		await page.locator( '.ai-generate-image-btn' ).click();

		// Add a prompt and generate the image.
		await page
			.getByRole( 'textbox', { name: 'Prompt' } )
			.fill( 'A smiley face' );
		await page
			.getByRole( 'button', { name: 'Generate', exact: true } )
			.click();

		const refineButton = page.getByRole( 'button', {
			name: 'Refine Image',
		} );
		await expect( refineButton ).toBeVisible();

		// Click the refine button.
		await refineButton.click();

		// Ensure the modal is in the refining state.
		await expect(
			page.locator( '.ai-image-generation__refining' )
		).toBeVisible();

		// Ensure the refine prompt textarea is visible.
		const refineTextarea = page.getByRole( 'textbox', {
			name: 'Describe the refinements you want to make to the image.',
		} );
		await expect( refineTextarea ).toBeVisible();

		// Add our refine prompt and generate the image.
		await refineTextarea.fill( 'Add a red hat' );
		await page
			.getByRole( 'button', { name: 'Refine', exact: true } )
			.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-image-generation__preview-image' )
		).toHaveCount( 2 );

		// Click the previous image navigation arrow.
		const previousBtn = page.getByRole( 'button', {
			name: 'Previous version',
		} );
		await previousBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '1 / 2' );

		// Click the next image navigation arrow.
		const nextBtn = page.getByRole( 'button', {
			name: 'Next version',
		} );
		await nextBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '2 / 2' );

		const saveImageButton = page.getByRole( 'button', {
			name: 'Save to Media Library',
		} );
		await expect( saveImageButton ).toBeVisible();

		await saveImageButton.click();

		// Ensure a success message is visible.
		await expect(
			page.locator( '.components-notice.is-success' )
		).toBeVisible();

		// View the image in the Media Library.
		await page
			.getByRole( 'link', { name: 'View in Media Library' } )
			.click();

		// Ensure alt text is set.
		await expect(
			page.getByRole( 'textbox', {
				name: 'Alternative Text',
			} )
		).toHaveValue( 'Add a red hat' );
	} );

	test( 'Can refine an existing image in the Media Library', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Enable the Image Generation Feature.
		await enableExperiment( admin, page, 'Image Generation and Editing' );

		// Upload a test image so we have a URL the editor can load.
		await requestUtils.uploadMedia( TEST_IMAGE_PATH );

		// Visit the Media Library.
		await visitAdminPage( admin, 'upload.php', 'mode=grid' );

		// Click on the first image in the Media Library.
		await page
			.locator( '.media-frame-content ul.attachments li:first-child' )
			.click();

		// Find the Edit Image button and click on it.
		const editImageButton = page
			.getByRole( 'button', { name: 'Edit Image' } )
			.first();
		await editImageButton.click();

		// Find and click on the Refine Image button.
		const refineImageButton = page.getByRole( 'button', {
			name: 'Refine Image',
		} );
		await refineImageButton.click();

		// Add a prompt and generate the image.
		const refineTextarea = page.getByRole( 'textbox', {
			name: 'Describe the refinements you want to make to the image',
		} );
		await refineTextarea.fill( 'A smiley face' );
		await page
			.getByRole( 'button', { name: 'Generate', exact: true } )
			.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-media-library-editor__preview-image' )
		).toHaveCount( 2 );

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-media-library-editor__actions button' )
		).toHaveCount( 4 );

		const saveImageBtn = page.getByRole( 'button', {
			name: 'Save to Media Library',
		} );
		await expect( saveImageBtn ).toBeVisible();

		const refineBtn = page.getByRole( 'button', {
			name: 'Refine Image',
		} );
		await expect( refineBtn ).toBeVisible();

		const generateAnotherBtn = page.getByRole( 'button', {
			name: 'Generate Another Image',
		} );
		await expect( generateAnotherBtn ).toBeVisible();

		// Ensure there's a Start over button.
		const startOverBtn = page.getByRole( 'button', {
			name: 'Start over',
		} );
		await expect( startOverBtn ).toBeVisible();

		await startOverBtn.click();

		// Add a prompt and generate the image.
		await refineTextarea.fill( 'A smiley face' );
		await page
			.getByRole( 'button', { name: 'Generate', exact: true } )
			.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-media-library-editor__preview-image' )
		).toHaveCount( 2 );

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-media-library-editor__actions button' )
		).toHaveCount( 4 );

		await expect( saveImageBtn ).toBeVisible();
		await saveImageBtn.click();

		// Ensure a success message is visible.
		await expect(
			page.locator( '.components-notice.is-success' )
		).toBeVisible();

		// View the image in the Media Library.
		await page.getByRole( 'link', { name: 'View new image' } ).click();

		// Ensure alt text is set.
		await expect(
			page.getByRole( 'textbox', {
				name: 'Alternative Text',
			} )
		).toHaveValue( 'A smiley face' );
	} );

	test( 'Can use preset refine buttons on an existing image in the Media Library', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Enable the Image Generation Feature.
		await enableExperiment( admin, page, 'Image Generation and Editing' );

		// Upload a test image so we have a URL the editor can load.
		await requestUtils.uploadMedia( TEST_IMAGE_PATH );

		// Visit the Media Library.
		await visitAdminPage( admin, 'upload.php', 'mode=grid' );

		// Click on the first image in the Media Library.
		await page
			.locator( '.media-frame-content ul.attachments li:first-child' )
			.click();

		// Find the Edit Image button and click on it.
		const editImageButton = page
			.getByRole( 'button', { name: 'Edit Image' } )
			.first();
		await editImageButton.click();

		// Ensure there are five preset buttons.
		await expect(
			page.locator( '.ai-media-library-editor__presets button' )
		).toHaveCount( 5 );

		// Find the Expand Background button and click on it.
		const expandBGBtn = page.getByRole( 'button', {
			name: 'Expand Background',
		} );
		await expandBGBtn.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-media-library-editor__preview-image' )
		).toHaveCount( 2 );

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-media-library-editor__actions button' )
		).toHaveCount( 4 );

		// Ensure there's a Start over button.
		const startOverBtn = page.getByRole( 'button', {
			name: 'Start over',
		} );
		await expect( startOverBtn ).toBeVisible();

		await startOverBtn.click();

		// Find the Remove Item button and click on it.
		const removeItemBtn = page.getByRole( 'button', {
			name: 'Remove Item',
		} );
		await removeItemBtn.click();

		// Wait a second.
		await page.waitForTimeout( 1000 );

		// Ensure the mask canvas is visible.
		await expect(
			page.locator( '.ai-media-library-editor__masking' )
		).toBeVisible();

		// Click the Cancel button.
		const cancelBtn = page.getByRole( 'button', {
			name: 'Cancel',
			exact: true,
		} );
		await cancelBtn.click();

		// Find the Replace Item button and click on it.
		const replaceItemBtn = page.getByRole( 'button', {
			name: 'Replace Item',
		} );
		await replaceItemBtn.click();

		// Wait a second.
		await page.waitForTimeout( 1000 );

		// Ensure the mask canvas is visible.
		await expect(
			page.locator( '.ai-media-library-editor__masking' )
		).toBeVisible();

		// Click the Cancel button.
		await cancelBtn.click();

		// Find the Remove background button and click on it.
		const removeBGBtn = page.getByRole( 'button', {
			name: 'Remove Background',
		} );
		await removeBGBtn.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-media-library-editor__preview-image' )
		).toHaveCount( 2 );

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-media-library-editor__actions button' )
		).toHaveCount( 4 );

		const generateAnotherBtn = page.getByRole( 'button', {
			name: 'Generate Another Image',
		} );
		await expect( generateAnotherBtn ).toBeVisible();

		await generateAnotherBtn.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-media-library-editor__preview-image' )
		).toHaveCount( 2 );

		// Ensure generation has completed and history is updated.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '2 / 2' );

		// Click the previous image navigation arrow.
		const previousImgBtn = page.getByRole( 'button', {
			name: 'Previous version',
		} );
		await previousImgBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '1 / 2' );

		// Click the next image navigation arrow.
		const nextImgBtn = page.getByRole( 'button', {
			name: 'Next version',
		} );
		await nextImgBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '2 / 2' );

		// Generate another image.
		await generateAnotherBtn.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-media-library-editor__preview-image' )
		).toHaveCount( 2 );

		// Click the previous image navigation arrow.
		await previousImgBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '2 / 3' );

		// Click the next image navigation arrow.
		await nextImgBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '3 / 3' );

		await clearConnector( admin, page, 'ai-provider-for-google' );
	} );
} );

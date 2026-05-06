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
	enableExperiments,
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
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

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
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

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
		const generateImageButton = editor.canvas.locator(
			'.wp-block-image button',
			{
				hasText: 'Generate Image',
			}
		);
		await expect( generateImageButton ).toBeVisible();

		// Click the generate image inline button.
		await generateImageButton.click();

		// Add a prompt and generate the image.
		await page
			.locator( '.ai-image-generation__idle textarea' )
			.fill( 'A smiling face emoji' );
		await page.locator( '.ai-image-generation__idle button' ).click();

		// Ensure the Refine Image button is visible.
		const refineButton = page.locator(
			'.ai-image-generation__actions button',
			{
				hasText: 'Refine Image',
			}
		);
		await expect( refineButton ).toBeVisible();

		// Click the Refine Image button.
		await refineButton.click();

		// Ensure the modal is in the refining state.
		await expect(
			page.locator( '.ai-image-generation__refining' )
		).toBeVisible();

		// Ensure the refine prompt textarea is visible.
		await expect(
			page.locator( '.ai-image-generation__refining textarea' )
		).toBeVisible();

		// Add our refine prompt and generate the image.
		await page
			.locator( '.ai-image-generation__refining textarea' )
			.fill( 'Add a red hat' );
		await page
			.locator( '.ai-image-generation__refining button' )
			.filter( { hasText: 'Refine' } )
			.first()
			.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-image-generation__preview-image' )
		).toHaveCount( 2 );

		// Click the previous image navigation arrow.
		const previousBtn = page
			.locator( '.ai-image-history-nav' )
			.getByRole( 'button', {
				name: 'Previous version',
			} );
		await previousBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '1 / 2' );

		// Click the next image navigation arrow.
		const nextBtn = page
			.locator( '.ai-image-history-nav' )
			.getByRole( 'button', {
				name: 'Next version',
			} );
		await nextBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '2 / 2' );

		const useImageButton = page.locator(
			'.ai-image-generation__actions button',
			{
				hasText: 'Use Image',
			}
		);
		await expect( useImageButton ).toBeVisible();

		useImageButton.click();

		// Ensure the image is inserted into the block.
		await expect(
			editor.canvas.locator( '.wp-block-image img' )
		).toBeVisible();

		// Ensure the image is in the Media Library.
		await visitAdminPage( admin, 'upload.php', 'mode=grid' );

		const imageContainer = page
			.locator( '.attachments-wrapper li' )
			.first();

		await expect( imageContainer ).toHaveAttribute(
			'aria-label',
			'Add a red hat'
		);

		await expect( imageContainer.locator( 'img' ) ).toBeVisible();
	} );

	test( 'Can refine an image in the stand-alone Generate Image page', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Image Generation Feature.
		await enableExperiment( admin, page, 'Image Generation and Editing' );

		// Visit the Media Library.
		await visitAdminPage( admin, 'upload.php', 'mode=grid' );

		// Click the Generate Image button.
		await page.locator( '.ai-generate-image-btn' ).click();

		// Add a prompt and generate the image.
		await page
			.locator( '.ai-image-generation__idle textarea' )
			.fill( 'A smiley face' );
		await page.locator( '.ai-image-generation__actions button' ).click();

		const refineButton = page.locator(
			'.ai-image-generation__actions button',
			{
				hasText: 'Refine Image',
			}
		);
		await expect( refineButton ).toBeVisible();

		// Click the refine button.
		await refineButton.click();

		// Ensure the modal is in the refining state.
		await expect(
			page.locator( '.ai-image-generation__refining' )
		).toBeVisible();

		// Ensure the refine prompt textarea is visible.
		await expect(
			page.locator( '.ai-image-generation__refining textarea' )
		).toBeVisible();

		// Add our refine prompt and generate the image.
		await page
			.locator( '.ai-image-generation__refining textarea' )
			.fill( 'Add a red hat' );
		await page
			.locator( '.ai-image-generation__refining button' )
			.filter( { hasText: 'Refine' } )
			.first()
			.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-image-generation__preview-image' )
		).toHaveCount( 2 );

		// Click the previous image navigation arrow.
		const previousBtn = page
			.locator( '.ai-image-history-nav' )
			.getByRole( 'button', {
				name: 'Previous version',
			} );
		await previousBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '1 / 2' );

		// Click the next image navigation arrow.
		const nextBtn = page
			.locator( '.ai-image-history-nav' )
			.getByRole( 'button', {
				name: 'Next version',
			} );
		await nextBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '2 / 2' );

		const saveImageButton = page.locator(
			'.ai-image-generation__actions button',
			{
				hasText: 'Save to Media Library',
			}
		);
		await expect( saveImageButton ).toBeVisible();

		saveImageButton.click();

		// Ensure a success message is visible.
		await expect(
			page.locator( '.components-notice.is-success' )
		).toBeVisible();

		// View the image in the Media Library.
		page.locator( '.components-notice.is-success a' ).click();

		// Ensure alt text is set.
		await expect(
			page.locator( '#attachment-details-two-column-alt-text' )
		).toHaveValue( 'Add a red hat' );
	} );

	test( 'Can refine an existing image in the Media Library', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

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
			.locator( '.attachment-actions button', { hasText: 'Edit Image' } )
			.first();
		await editImageButton.click();

		// Find and click on the Refine Image button.
		const refineImageButton = page.locator(
			'.ai-media-library-editor__presets button',
			{
				hasText: 'Refine Image',
			}
		);
		await refineImageButton.click();

		// Add a prompt and generate the image.
		await page
			.locator( '.ai-media-library-editor__idle textarea' )
			.fill( 'A smiley face' );
		await page
			.locator( '.ai-media-library-editor__actions button' )
			.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-media-library-editor__preview-image' )
		).toHaveCount( 2 );

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-media-library-editor__actions button' )
		).toHaveCount( 4 );

		let saveImageBtn = page.locator(
			'.ai-media-library-editor__actions button',
			{
				hasText: 'Save to Media Library',
			}
		);
		await expect( saveImageBtn ).toBeVisible();

		const refineBtn = page.locator(
			'.ai-media-library-editor__actions button',
			{
				hasText: 'Refine Image',
			}
		);
		await expect( refineBtn ).toBeVisible();

		const generateAnotherBtn = page.locator(
			'.ai-media-library-editor__actions button',
			{
				hasText: 'Generate Another Image',
			}
		);
		await expect( generateAnotherBtn ).toBeVisible();

		// Ensure there's a Start over button.
		const startOverBtn = page.locator(
			'.ai-media-library-editor__actions button',
			{
				hasText: 'Start over',
			}
		);
		await expect( startOverBtn ).toBeVisible();

		await startOverBtn.click();

		// Add a prompt and generate the image.
		await page
			.locator( '.ai-media-library-editor__idle textarea' )
			.fill( 'A smiley face' );
		await page
			.locator( '.ai-media-library-editor__actions button' )
			.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-media-library-editor__preview-image' )
		).toHaveCount( 2 );

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-media-library-editor__actions button' )
		).toHaveCount( 4 );

		saveImageBtn = page.locator(
			'.ai-media-library-editor__actions button',
			{
				hasText: 'Save to Media Library',
			}
		);
		await expect( saveImageBtn ).toBeVisible();

		saveImageBtn.click();

		// Ensure a success message is visible.
		await expect(
			page.locator( '.components-notice.is-success' )
		).toBeVisible();

		// View the image in the Media Library.
		page.locator( '.components-notice.is-success a' ).click();

		// Ensure alt text is set.
		await expect(
			page.locator( '#attachment-details-two-column-alt-text' )
		).toHaveValue( 'A smiley face' );
	} );

	test( 'Can use preset refine buttons on an existing image in the Media Library', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

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
			.locator( '.attachment-actions button', { hasText: 'Edit Image' } )
			.first();
		await editImageButton.click();

		// Ensure there are five preset buttons.
		await expect(
			page.locator( '.ai-media-library-editor__presets button' )
		).toHaveCount( 5 );

		// Find the Expand Background button and click on it.
		const expandBGBtn = page
			.locator( '.ai-media-library-editor__presets button', {
				hasText: 'Expand Background',
			} )
			.first();
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
		const startOverBtn = page.locator(
			'.ai-media-library-editor__actions button',
			{
				hasText: 'Start over',
			}
		);
		await expect( startOverBtn ).toBeVisible();

		await startOverBtn.click();

		// Find the Remove Item button and click on it.
		const removeItemBtn = page
			.locator( '.ai-media-library-editor__presets button', {
				hasText: 'Remove Item',
			} )
			.first();
		await removeItemBtn.click();

		// Wait a second.
		await page.waitForTimeout( 1000 );

		// Ensure the mask canvas is visible.
		await expect(
			page.locator( '.ai-media-library-editor__masking' )
		).toBeVisible();

		// Click the Cancel button.
		let cancelBtn = page.locator(
			'.ai-media-library-editor__masking-sidebar-actions button',
			{
				hasText: 'Cancel',
			}
		);
		await cancelBtn.click();

		// Find the Replace Item button and click on it.
		const replaceItemBtn = page
			.locator( '.ai-media-library-editor__presets button', {
				hasText: 'Replace Item',
			} )
			.first();
		await replaceItemBtn.click();

		// Wait a second.
		await page.waitForTimeout( 1000 );

		// Ensure the mask canvas is visible.
		await expect(
			page.locator( '.ai-media-library-editor__masking' )
		).toBeVisible();

		// Click the Cancel button.
		cancelBtn = page.locator(
			'.ai-media-library-editor__masking-sidebar-actions button',
			{
				hasText: 'Cancel',
			}
		);
		await cancelBtn.click();

		// Find the Remove background button and click on it.
		const removeBGBtn = page
			.locator( '.ai-media-library-editor__presets button', {
				hasText: 'Remove Background',
			} )
			.first();
		await removeBGBtn.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-media-library-editor__preview-image' )
		).toHaveCount( 2 );

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-media-library-editor__actions button' )
		).toHaveCount( 4 );

		const generateAnotherBtn = page.locator(
			'.ai-media-library-editor__actions button',
			{
				hasText: 'Generate Another Image',
			}
		);
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
		let previousImgBtn = page
			.locator( '.ai-image-history-nav' )
			.getByRole( 'button', {
				name: 'Previous version',
			} );
		await previousImgBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '1 / 2' );

		// Click the next image navigation arrow.
		let nextImgBtn = page
			.locator( '.ai-image-history-nav' )
			.getByRole( 'button', {
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
		previousImgBtn = page
			.locator( '.ai-image-history-nav' )
			.getByRole( 'button', {
				name: 'Previous version',
			} );
		await previousImgBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '2 / 3' );

		// Click the next image navigation arrow.
		nextImgBtn = page
			.locator( '.ai-image-history-nav' )
			.getByRole( 'button', {
				name: 'Next version',
			} );
		await nextImgBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '3 / 3' );

		await clearConnector( admin, page, 'ai-provider-for-google' );
	} );
} );

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

test.describe( 'Image Editing Experiment', () => {
	test.skip( 'Can enable the image generation/editing experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Image Generation Experiment (which contains editing).
		await enableExperiment( admin, page, 'image-generation' );

		await visitConnectorsPage( admin );

		// Add dummy credentials for Google.
		await page
			.locator( '.connector-item--ai-provider-for-google button' )
			.click();
		await page
			.locator(
				'.connector-item--ai-provider-for-google input[type="text"]'
			)
			.fill( 'valid-api-key' );

		// Save the credentials.
		await page
			.locator(
				'.connector-item--ai-provider-for-google .connector-settings button'
			)
			.click();
	} );

	test.skip( 'Can refine an image within a block', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Image Generation Experiment.
		await enableExperiment( admin, page, 'image-generation' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Inline Image Editing Experiment',
			content:
				'This is some test content for the Image Editing Experiment.',
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
			.locator( '.ai-generate-image-inline-modal__idle textarea' )
			.fill( 'A smiling face emoji' );
		await page
			.locator( '.ai-generate-image-inline-modal__idle button' )
			.click();

		// Ensure the Refine Image button is visible.
		const refineButton = page.locator(
			'.ai-generate-image-inline-modal__actions button',
			{
				hasText: 'Refine Image',
			}
		);
		await expect( refineButton ).toBeVisible();

		// Click the Refine Image button.
		await refineButton.click();

		// Ensure the modal is in the refining state.
		await expect(
			page.locator( '.ai-generate-image-inline-modal__refining' )
		).toBeVisible();

		// Ensure the refine prompt textarea is visible.
		await expect(
			page.locator( '.ai-generate-image-inline-modal__refining textarea' )
		).toBeVisible();

		// Add our refine prompt and generate the image.
		await page
			.locator( '.ai-generate-image-inline-modal__refining textarea' )
			.fill( 'Add a red hat' );
		await page
			.locator( '.ai-generate-image-inline-modal__refining button' )
			.filter( { hasText: 'Refine' } )
			.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-generate-image-inline-modal__preview-image' )
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
			'.ai-generate-image-inline-modal__actions button',
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
		await visitAdminPage( admin, 'upload.php' );

		const imageContainer = page
			.locator( '.attachments-wrapper li' )
			.first();

		await expect( imageContainer ).toHaveAttribute(
			'aria-label',
			'Add a red hat'
		);

		await expect( imageContainer.locator( 'img' ) ).toBeVisible();
	} );

	test.skip( 'Can refine an image in the stand-alone Generate Image page', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Image Generation Experiment.
		await enableExperiment( admin, page, 'image-generation' );

		// Visit the Media Library.
		await visitAdminPage( admin, 'upload.php' );

		// Click the Generate Image button.
		await page.locator( '.ai-generate-image-btn' ).click();

		// Add a prompt and generate the image.
		await page
			.locator( '.ai-generate-image-standalone__idle textarea' )
			.fill( 'A smiley face' );
		await page
			.locator( '.ai-generate-image-standalone__actions button' )
			.click();

		const refineButton = page.locator(
			'.ai-generate-image-standalone__actions button',
			{
				hasText: 'Refine Image',
			}
		);
		await expect( refineButton ).toBeVisible();

		// Click the refine button.
		await refineButton.click();

		// Ensure the modal is in the refining state.
		await expect(
			page.locator( '.ai-generate-image-standalone__refining' )
		).toBeVisible();

		// Ensure the refine prompt textarea is visible.
		await expect(
			page.locator( '.ai-generate-image-standalone__refining textarea' )
		).toBeVisible();

		// Add our refine prompt and generate the image.
		await page
			.locator( '.ai-generate-image-standalone__refining textarea' )
			.fill( 'Add a red hat' );
		await page
			.locator( '.ai-generate-image-standalone__refining button' )
			.filter( { hasText: 'Refine' } )
			.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-generate-image-standalone__preview-image' )
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
			'.ai-generate-image-standalone__actions button',
			{
				hasText: 'Save to Media Library',
			}
		);
		await expect( saveImageButton ).toBeVisible();

		saveImageButton.click();

		// Ensure a success message is visible.
		await expect(
			page.locator( '.ai-generate-image-standalone__success' )
		).toBeVisible();

		// Ensure we have two new buttons.
		await expect(
			page.locator( '.ai-generate-image-standalone__success button' )
		).toHaveCount( 1 );

		await expect(
			page.locator( '.ai-generate-image-standalone__success a' )
		).toHaveCount( 1 );

		// View the image in the Media Library.
		page.locator( '.ai-generate-image-standalone__success a' ).click();

		// Ensure alt text is set.
		await expect(
			page.locator( '#attachment-details-two-column-alt-text' )
		).toHaveValue( 'Add a red hat' );
	} );

	test.skip( 'Can refine an existing image in the Media Library', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Image Generation Experiment.
		await enableExperiment( admin, page, 'image-generation' );

		// Upload a test image so we have a URL the editor can load.
		await requestUtils.uploadMedia( TEST_IMAGE_PATH );

		// Visit the Media Library.
		await visitAdminPage( admin, 'upload.php' );

		// Click on the first image in the Media Library.
		await page
			.locator( '.media-frame-content ul.attachments li:first-child' )
			.click();

		// Find the Edit Image button and click on it.
		const editImageButton = page
			.locator( '.attachment-actions button', { hasText: 'Edit Image' } )
			.first();
		await editImageButton.click();

		// Find and click on the AI Edit button.
		await page.locator( '.ai-media-library-editor__toggle-btn' ).click();

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

		// Click the previous image navigation arrow.
		const previousImgBtn = page
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
		const nextImgBtn = page
			.locator( '.ai-image-history-nav' )
			.getByRole( 'button', {
				name: 'Next version',
			} );
		await nextImgBtn.click();

		// Ensure the navigation shows correctly.
		await expect(
			page.locator( '.ai-image-history-nav__counter' )
		).toHaveText( '2 / 2' );

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

		startOverBtn.click();

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

	test.skip( 'Can use preset refine buttons on an existing image in the Media Library', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Image Generation Experiment.
		await enableExperiment( admin, page, 'image-generation' );

		// Upload a test image so we have a URL the editor can load.
		await requestUtils.uploadMedia( TEST_IMAGE_PATH );

		// Visit the Media Library.
		await visitAdminPage( admin, 'upload.php' );

		// Click on the first image in the Media Library.
		await page
			.locator( '.media-frame-content ul.attachments li:first-child' )
			.click();

		// Find the Edit Image button and click on it.
		const editImageButton = page
			.locator( '.attachment-actions button', { hasText: 'Edit Image' } )
			.first();
		await editImageButton.click();

		// Find and click on the AI Edit button.
		await page.locator( '.ai-media-library-editor__toggle-btn' ).click();

		// Ensure there are two preset buttons.
		await expect(
			page.locator( '.ai-media-library-editor__presets button' )
		).toHaveCount( 2 );

		// Find the Expand Background button and click on it.
		const expandBGBtn = page
			.locator( '.ai-media-library-editor__presets button', {
				hasText: 'Expand background',
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

		startOverBtn.click();

		// Find the Remove background button and click on it.
		const removeBGBtn = page
			.locator( '.ai-media-library-editor__presets button', {
				hasText: 'Remove background',
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

		generateAnotherBtn.click();

		// Ensure the images are visible in the modal.
		await expect(
			page.locator( '.ai-media-library-editor__preview-image' )
		).toHaveCount( 2 );

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

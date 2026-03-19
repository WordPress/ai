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
	visitAdminPage,
} = require( '../../utils/helpers' );

test.describe( 'Image Generation Experiment', () => {
	test( 'Can enable the image generation experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Image Generation Experiment.
		await enableExperiment( admin, page, 'image-generation' );
	} );

	test( 'Can generate a Featured Image', async ( {
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
			title: 'Test Featured Image Generation Experiment',
			content:
				'This is some test content for the Image Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the generate featured image button exists.
		await expect(
			page.locator(
				'.ai-featured-image .ai-featured-image__container button',
				{
					hasText: 'Generate featured image',
				}
			)
		).toBeVisible();

		// Click the generate featured image button.
		await page
			.locator(
				'.ai-featured-image .ai-featured-image__container button'
			)
			.click();

		// Ensure the generated image is visible.
		await expect(
			page.locator(
				'.editor-post-featured-image .editor-post-featured-image__container img'
			)
		).toBeVisible();

		// Save the post.
		await editor.saveDraft();

		// Ensure the image is in the Media Library.
		await visitAdminPage( admin, 'upload.php' );

		const imageContainer = page
			.locator( '.attachments-wrapper li' )
			.first();

		await expect( imageContainer ).toHaveAttribute(
			'aria-label',
			'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure'
		);

		await expect( imageContainer.locator( 'img' ) ).toBeVisible();
	} );

	test( 'Can generate an image using the inline button', async ( {
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
			title: 'Test Inline Image Generation Experiment',
			content:
				'This is some test content for the Image Generation Experiment.',
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

		// Ensure the modal is visible.
		await expect(
			page.locator( '.ai-generate-image-inline-modal' )
		).toBeVisible();

		// Add a prompt and generate the image.
		await page
			.locator( '.ai-generate-image-inline-modal__idle textarea' )
			.fill( 'A smiling face emoji' );
		await page
			.locator( '.ai-generate-image-inline-modal__idle button' )
			.click();

		// Ensure the image is visible in the modal.
		await expect(
			page.locator( '.ai-generate-image-inline-modal__preview-image' )
		).toBeVisible();

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-generate-image-inline-modal__actions button' )
		).toHaveCount( 4 );

		let useImageButton = page.locator(
			'.ai-generate-image-inline-modal__actions button',
			{
				hasText: 'Use Image',
			}
		);
		await expect( useImageButton ).toBeVisible();

		const refineButton = page.locator(
			'.ai-generate-image-inline-modal__actions button',
			{
				hasText: 'Refine Image',
			}
		);
		await expect( refineButton ).toBeVisible();

		const generateAnotherButton = page.locator(
			'.ai-generate-image-inline-modal__actions button',
			{
				hasText: 'Generate Another Image',
			}
		);
		await expect( generateAnotherButton ).toBeVisible();

		const editPromptButton = page.locator(
			'.ai-generate-image-inline-modal__actions button',
			{
				hasText: 'Edit Prompt',
			}
		);
		await expect( editPromptButton ).toBeVisible();

		// Click the Edit Prompt button.
		await editPromptButton.click();

		// Ensure the modal is in the idle state.
		await expect(
			page.locator( '.ai-generate-image-inline-modal__idle' )
		).toBeVisible();

		// Ensure the prompt textarea is visible.
		await expect(
			page.locator( '.ai-generate-image-inline-modal__idle textarea' )
		).toBeVisible();

		// Ensure the prompt textarea has the correct value.
		await expect(
			page.locator( '.ai-generate-image-inline-modal__idle textarea' )
		).toHaveValue( 'A smiling face emoji' );

		// Add another prompt and generate the image.
		await page
			.locator( '.ai-generate-image-inline-modal__idle textarea' )
			.fill( 'A smiling face' );
		await page
			.locator( '.ai-generate-image-inline-modal__idle button' )
			.click();

		// Ensure the image is visible in the modal.
		await expect(
			page.locator( '.ai-generate-image-inline-modal__preview-image' )
		).toBeVisible();

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-generate-image-inline-modal__actions button' )
		).toHaveCount( 4 );

		useImageButton = page.locator(
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
			'A smiling face'
		);

		await expect( imageContainer.locator( 'img' ) ).toBeVisible();
	} );

	test( 'Can generate an image using the toolbar button', async ( {
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
			title: 'Test Toolbar Image Generation Experiment',
			content:
				'This is some test content for the Image Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Insert a blank image block.
		await editor.insertBlock( {
			name: 'core/image',
		} );

		// Find the toolbar Add image button (aria-label is the accessible name).
		const addImageButton = page
			.locator( '.block-editor-block-toolbar' )
			.getByRole( 'button', {
				name: 'Add image',
			} );
		await expect( addImageButton ).toBeVisible();

		// Click the Add image toolbar button.
		await addImageButton.click();

		// Ensure the menu dropdown shows with our Generate Image option.
		const generateImageButton = page.locator(
			'.block-editor-media-replace-flow__options button',
			{
				hasText: 'Generate Image',
			}
		);
		await expect( generateImageButton ).toBeVisible();

		// Click the Generate Image button.
		await generateImageButton.click();

		// Ensure the modal is visible.
		await expect(
			page.locator( '.ai-generate-image-inline-modal' )
		).toBeVisible();

		// Add a prompt and generate the image.
		await page
			.locator( '.ai-generate-image-inline-modal__idle textarea' )
			.fill( 'A smiley face' );
		await page
			.locator( '.ai-generate-image-inline-modal__idle button' )
			.click();

		// Ensure the image is visible in the modal.
		await expect(
			page.locator( '.ai-generate-image-inline-modal__preview-image' )
		).toBeVisible();

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-generate-image-inline-modal__actions button' )
		).toHaveCount( 4 );

		let useImageButton = page.locator(
			'.ai-generate-image-inline-modal__actions button',
			{
				hasText: 'Use Image',
			}
		);
		await expect( useImageButton ).toBeVisible();

		const refineButton = page.locator(
			'.ai-generate-image-inline-modal__actions button',
			{
				hasText: 'Refine Image',
			}
		);
		await expect( refineButton ).toBeVisible();

		const generateAnotherButton = page.locator(
			'.ai-generate-image-inline-modal__actions button',
			{
				hasText: 'Generate Another Image',
			}
		);
		await expect( generateAnotherButton ).toBeVisible();

		// Ensure there's a close button in the modal.
		const closeButton = page
			.locator( '.ai-generate-image-inline-modal' )
			.getByRole( 'button', { name: 'Close' } );
		await expect( closeButton ).toBeVisible();

		// Click the close button.
		await closeButton.click();

		// Ensure the modal is closed.
		await expect(
			page.locator( '.ai-generate-image-inline-modal' )
		).not.toBeVisible();

		// Click the generate image toolbar button again to open the modal.
		await generateImageButton.click();

		// Add another prompt and generate the image.
		await page
			.locator( '.ai-generate-image-inline-modal__idle textarea' )
			.fill( 'A smiley face' );
		await page
			.locator( '.ai-generate-image-inline-modal__idle button' )
			.click();

		// Ensure the image is visible in the modal.
		await expect(
			page.locator( '.ai-generate-image-inline-modal__preview-image' )
		).toBeVisible();

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-generate-image-inline-modal__actions button' )
		).toHaveCount( 4 );

		useImageButton = page.locator(
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
			'A smiley face'
		);

		await expect( imageContainer.locator( 'img' ) ).toBeVisible();
	} );

	test( 'Can generate an image directly in the Media Library', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Image Generation Experiment.
		await enableExperiment( admin, page, 'image-generation' );

		// Visit the Media Library.
		await visitAdminPage( admin, 'upload.php' );

		// Ensure there's a Generate Image link in the sidebar.
		await expect(
			page.locator( '.wp-menu-open .wp-submenu a', {
				hasText: 'Generate Image',
			} )
		).toBeVisible();

		// Ensure the Generate Image button is visible.
		await expect(
			page.locator( '.ai-generate-image-btn', {
				hasText: 'Generate Image',
			} )
		).toBeVisible();

		// Click the Generate Image button.
		await page.locator( '.ai-generate-image-btn' ).click();

		// Ensure the page loads.
		await expect( page.locator( '.wrap h1' ) ).toHaveText(
			'Generate Image'
		);

		// Add a prompt and generate the image.
		await page
			.locator( '.ai-generate-image-standalone__idle textarea' )
			.fill( 'A smiley face' );
		await page
			.locator( '.ai-generate-image-standalone__actions button' )
			.click();

		// Ensure the image is visible.
		await expect(
			page.locator( '.ai-generate-image-standalone__preview-image' )
		).toBeVisible();

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-generate-image-standalone__actions button' )
		).toHaveCount( 5 );

		let saveImageButton = page.locator(
			'.ai-generate-image-standalone__actions button',
			{
				hasText: 'Save to Media Library',
			}
		);
		await expect( saveImageButton ).toBeVisible();

		const refineButton = page.locator(
			'.ai-generate-image-standalone__actions button',
			{
				hasText: 'Refine Image',
			}
		);
		await expect( refineButton ).toBeVisible();

		const generateAnotherButton = page.locator(
			'.ai-generate-image-standalone__actions button',
			{
				hasText: 'Generate Another Image',
			}
		);
		await expect( generateAnotherButton ).toBeVisible();

		// Ensure there's a cancel button.
		const cancelButton = page.locator(
			'.ai-generate-image-standalone__actions button',
			{
				hasText: 'Cancel',
			}
		);
		await expect( cancelButton ).toBeVisible();

		// Click the cancel button.
		await cancelButton.click();

		// Add another prompt and generate the image.
		await page
			.locator( '.ai-generate-image-standalone__idle textarea' )
			.fill( 'A smiley face' );
		await page
			.locator( '.ai-generate-image-standalone__idle button' )
			.click();

		// Ensure the image is visible.
		await expect(
			page.locator( '.ai-generate-image-standalone__preview-image' )
		).toBeVisible();

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-generate-image-standalone__actions button' )
		).toHaveCount( 5 );

		saveImageButton = page.locator(
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
		).toHaveValue( 'A smiley face' );
	} );

	test( 'Ensure the Image Generation Experiment UI is not visible when Experiments are globally disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Image Generation Experiment.
		await enableExperiment( admin, page, 'image-generation' );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Image Generation Experiment Globally Disabled',
			content:
				'This is some test content for the Image Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the generate featured image button doesn't exist.
		await expect(
			page.locator(
				'.ai-featured-image .ai-featured-image__container button'
			)
		).not.toBeVisible();

		// Visit the Media Library.
		await visitAdminPage( admin, 'upload.php' );

		// Ensure there's not a Generate Image link in the sidebar.
		await expect(
			page.locator( '.wp-menu-open .wp-submenu a', {
				hasText: 'Generate Image',
			} )
		).not.toBeVisible();

		// Ensure the Generate Image button is not visible.
		await expect(
			page.locator( '.ai-generate-image-btn' )
		).not.toBeVisible();
	} );

	test( 'Ensure the Image Generation Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Image Generation Experiment.
		await disableExperiment( admin, page, 'image-generation' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Image Generation Experiment Disabled',
			content:
				'This is some test content for the Image Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the generate featured image button doesn't exist.
		await expect(
			page.locator(
				'.ai-featured-image .ai-featured-image__container button'
			)
		).not.toBeVisible();

		// Visit the Media Library.
		await visitAdminPage( admin, 'upload.php' );

		// Ensure there's not a Generate Image link in the sidebar.
		await expect(
			page.locator( '.wp-menu-open .wp-submenu a', {
				hasText: 'Generate Image',
			} )
		).not.toBeVisible();

		// Ensure the Generate Image button is not visible.
		await expect(
			page.locator( '.ai-generate-image-btn' )
		).not.toBeVisible();
	} );
} );

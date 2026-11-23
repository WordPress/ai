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
	visitAdminPage,
} = require( '../../utils/helpers' );

test.describe( 'Image Generation Feature', () => {
	test( 'Can enable the image generation feature', async ( {
		admin,
		page,
	} ) => {
		// Enable the Image Generation Feature.
		await enableExperiment( admin, page, 'Image Generation and Editing' );
	} );

	test( 'Can generate a Featured Image', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Image Generation Feature.
		await enableExperiment( admin, page, 'Image Generation and Editing' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Featured Image Generation Feature',
			content:
				'This is some test content for the Image Generation Feature.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		const generateFaturedImageButton = page.getByRole( 'button', {
			name: 'Generate featured image',
		} );

		// Ensure the generate featured image button exists.
		await expect( generateFaturedImageButton ).toBeVisible();

		// Click the generate featured image button.
		await generateFaturedImageButton.click();

		// Ensure the generated image is visible.
		await expect(
			page
				.getByRole( 'button', {
					name: 'Edit or replace the featured image',
				} )
				.getByRole( 'img' )
		).toBeVisible();

		// Save the post.
		await editor.saveDraft();

		// Ensure the image is in the Media Library.
		await visitAdminPage( admin, 'upload.php', 'mode=grid' );

		const imageItem = page
			.getByRole( 'checkbox', {
				name: 'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure',
			} )
			.first();

		await expect( imageItem ).toBeVisible();
		await expect( imageItem.locator( 'img' ) ).toBeVisible();
	} );

	test( 'Can generate an image using the inline button', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Image Generation Feature.
		await enableExperiment( admin, page, 'Image Generation and Editing' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Inline Image Generation Feature',
			content:
				'This is some test content for the Image Generation Feature.',
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

		// Ensure the modal is visible.
		const modal = page.getByRole( 'dialog', { name: 'Generate Image' } );
		await expect( modal ).toBeVisible();

		// Add a prompt and generate the image.
		await modal
			.getByRole( 'textbox', { name: 'Prompt' } )
			.fill( 'A smiling face emoji' );
		await modal
			.getByRole( 'button', { name: 'Generate', exact: true } )
			.click();

		// Ensure the image is visible in the modal.
		await expect(
			modal.getByRole( 'img', { name: 'A smiling face emoji' } )
		).toBeVisible();

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-image-generation__actions button' )
		).toHaveCount( 4 );

		const useImageButton = modal.getByRole( 'button', {
			name: 'Use Image',
		} );
		await expect( useImageButton ).toBeVisible();

		const refineButton = modal.getByRole( 'button', {
			name: 'Refine Image',
		} );
		await expect( refineButton ).toBeVisible();

		const generateAnotherButton = modal.getByRole( 'button', {
			name: 'Generate Another Image',
		} );
		await expect( generateAnotherButton ).toBeVisible();

		const editPromptButton = modal.getByRole( 'button', {
			name: 'Edit Prompt',
		} );
		await expect( editPromptButton ).toBeVisible();

		// Click the Edit Prompt button.
		await editPromptButton.click();

		// Ensure the modal is in the idle state.
		await expect(
			page.locator( '.ai-image-generation__idle' )
		).toBeVisible();

		// Ensure the prompt textarea is visible.
		await expect(
			modal.getByRole( 'textbox', { name: 'Prompt' } )
		).toBeVisible();

		// Ensure the prompt textarea has the correct value.
		await expect(
			modal.getByRole( 'textbox', { name: 'Prompt' } )
		).toHaveValue( 'A smiling face emoji' );

		// Add another prompt and generate the image.
		await modal
			.getByRole( 'textbox', { name: 'Prompt' } )
			.fill( 'A smiling face' );
		await modal
			.getByRole( 'button', { name: 'Generate', exact: true } )
			.click();

		// Ensure the image is visible in the modal.
		await expect(
			modal.getByRole( 'img', { name: 'A smiling face' } )
		).toBeVisible();

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-image-generation__actions button' )
		).toHaveCount( 4 );

		// Ensure "Use Image" button is visible, and click it.
		await expect( useImageButton ).toBeVisible();
		useImageButton.click();

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
				name: 'A smiling face',
				exact: true,
			} )
			.first();

		await expect( imageItem ).toBeVisible();
		await expect( imageItem.locator( 'img' ) ).toBeVisible();
	} );

	test( 'Can generate an image using the toolbar button', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Image Generation Feature.
		await enableExperiment( admin, page, 'Image Generation and Editing' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Toolbar Image Generation Feature',
			content:
				'This is some test content for the Image Generation Feature.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Insert a blank image block.
		await editor.insertBlock( {
			name: 'core/image',
		} );

		// Find the toolbar Add image button (aria-label is the accessible name).
		const addImageButton = page
			.getByRole( 'toolbar', {
				name: 'Block tools',
			} )
			.getByRole( 'button', { name: 'Add image' } );
		await expect( addImageButton ).toBeVisible();

		// Click the Add image toolbar button.
		await addImageButton.click();

		// Ensure the menu dropdown shows with our Generate Image option.
		const generateImageButton = page.getByRole( 'menuitem', {
			name: 'Generate Image',
		} );
		await expect( generateImageButton ).toBeVisible();

		// Click the Generate Image button.
		await generateImageButton.click();

		// Ensure the modal is visible.
		const modal = page.getByRole( 'dialog', { name: 'Generate Image' } );
		await expect( modal ).toBeVisible();

		// Add a prompt and generate the image.
		await modal
			.getByRole( 'textbox', { name: 'Prompt' } )
			.fill( 'A smiley face' );
		await modal
			.getByRole( 'button', { name: 'Generate', exact: true } )
			.click();

		// Ensure the image is visible in the modal.
		await expect(
			modal.getByRole( 'img', { name: 'A smiley face' } )
		).toBeVisible();

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-image-generation__actions button' )
		).toHaveCount( 4 );

		const useImageButton = modal.getByRole( 'button', {
			name: 'Use Image',
		} );
		await expect( useImageButton ).toBeVisible();

		const refineButton = modal.getByRole( 'button', {
			name: 'Refine Image',
		} );
		await expect( refineButton ).toBeVisible();

		const generateAnotherButton = modal.getByRole( 'button', {
			name: 'Generate Another Image',
		} );
		await expect( generateAnotherButton ).toBeVisible();

		// Ensure there's a close button in the modal.
		const closeButton = modal.getByRole( 'button', { name: 'Close' } );
		await expect( closeButton ).toBeVisible();

		// Click the close button.
		await closeButton.click();

		// Ensure the modal is closed.
		await expect( modal ).not.toBeVisible();

		// Click the Add image toolbar button.
		await addImageButton.click();

		// Click the generate image toolbar button again to open the modal.
		await generateImageButton.click();

		// Add another prompt and generate the image.
		await modal
			.getByRole( 'textbox', { name: 'Prompt' } )
			.fill( 'A smiley face' );
		await modal
			.getByRole( 'button', { name: 'Generate', exact: true } )
			.click();

		// Ensure the image is visible in the modal.
		await expect(
			modal.getByRole( 'img', { name: 'A smiley face' } )
		).toBeVisible();

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-image-generation__actions button' )
		).toHaveCount( 4 );

		await expect( useImageButton ).toBeVisible();
		useImageButton.click();

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
				name: 'A smiley face',
			} )
			.first();

		await expect( imageItem ).toBeVisible();
		await expect( imageItem.locator( 'img' ) ).toBeVisible();
	} );

	test( 'Can generate an image directly in the Media Library', async ( {
		admin,
		page,
	} ) => {
		// Enable the Image Generation Feature.
		await enableExperiment( admin, page, 'Image Generation and Editing' );

		// Visit the Media Library.
		await visitAdminPage( admin, 'upload.php', 'mode=grid' );

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
			.getByRole( 'textbox', { name: 'Prompt' } )
			.fill( 'A smiley face' );
		await page
			.getByRole( 'button', { name: 'Generate', exact: true } )
			.click();

		// Ensure the image is visible.
		await expect(
			page.getByRole( 'img', { name: 'A smiley face' } )
		).toBeVisible();

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-image-generation__actions button' )
		).toHaveCount( 5 );

		const saveImageButton = page.getByRole( 'button', {
			name: 'Save to Media Library',
		} );
		await expect( saveImageButton ).toBeVisible();

		const refineButton = page.getByRole( 'button', {
			name: 'Refine Image',
		} );
		await expect( refineButton ).toBeVisible();

		const generateAnotherButton = page.getByRole( 'button', {
			name: 'Generate Another Image',
		} );
		await expect( generateAnotherButton ).toBeVisible();

		// Ensure there's a cancel button.
		const cancelButton = page.getByRole( 'button', {
			name: 'Cancel',
			exact: true,
		} );
		await expect( cancelButton ).toBeVisible();

		// Click the cancel button.
		await cancelButton.click();

		// Add another prompt and generate the image.
		await page
			.getByRole( 'textbox', { name: 'Prompt' } )
			.fill( 'A smiley face' );
		await page
			.getByRole( 'button', { name: 'Generate', exact: true } )
			.click();

		// Ensure the image is visible.
		await expect(
			page.getByRole( 'img', { name: 'A smiley face' } )
		).toBeVisible();

		// Ensure the buttons we want are there.
		await expect(
			page.locator( '.ai-image-generation__actions button' )
		).toHaveCount( 5 );

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
		).toHaveValue( 'A smiley face' );
	} );

	test( 'Ensure the Image Generation Feature UI is not visible when the Feature is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Disable the Image Generation Feature.
		await disableExperiment( admin, page, 'Image Generation and Editing' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Image Generation Feature Disabled',
			content:
				'This is some test content for the Image Generation Feature.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the generate featured image button doesn't exist.
		await expect(
			page.getByRole( 'button', { name: 'Generate featured image' } )
		).not.toBeVisible();

		// Visit the Media Library.
		await visitAdminPage( admin, 'upload.php', 'mode=grid' );

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

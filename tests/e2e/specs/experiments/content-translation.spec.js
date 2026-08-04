/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	enableExperiment,
	enableExperiments,
	disableExperiments,
	disableExperiment,
} = require( '../../utils/helpers' );

const MOCKED_RESPONSE =
	'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure';

test.describe( 'Content Translation Experiment', () => {
	test( 'Can enable the content translation experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Translation Experiment.
		await enableExperiment( admin, page, 'Content Translation' );
	} );

	test( 'Can use the Content Translation Experiment', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Translation Experiment.
		await enableExperiment( admin, page, 'Content Translation' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Content Translation Experiment',
		} );

		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content:
					'This is some test content for the Content Translation Experiment. It needs to have enough words to meet the minimum content length requirement for translation to be enabled. The translation feature requires a substantial amount of text before it will allow the user to generate a translation of the post content. This ensures that the generated translation is meaningful and provides value to readers who want to read the post content in a different language. Adding more words here to make sure we exceed the minimum threshold that is configured for this experiment in the plugin settings and server side filters.',
			},
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Switch to the Post tab (if not already on it).
		await page.getByRole( 'tab', { name: 'Post' } ).click();

		// Ensure the Generate Translation button exists, is visible, and has the correct text.
		const generateButton = page.getByRole( 'button', {
			name: 'Generate Translation',
		} );

		await expect( generateButton ).toBeVisible();

		// Initiate the translation process.
		await generateButton.click();

		// Fill up the modal with the required information.
		await page.getByLabel( 'Translate to' ).selectOption( {
			label: 'French',
		} );

		await page.getByLabel( 'Also translate the title' ).check();

		// Click the Translate button.
		await page.getByRole( 'button', { name: 'Translate' } ).click();

		// Ensure the generated translation is replaced at both the post title, and the first paragraph.
		await expect(
			editor.canvas.getByRole( 'textbox', { name: 'Add title' } )
		).toHaveText( MOCKED_RESPONSE );

		await expect(
			editor.canvas
				.getByRole( 'document', { name: 'Block: Paragraph' } )
				.first()
		).toHaveText( MOCKED_RESPONSE );

		// Save the post.
		await editor.saveDraft();
	} );

	test( 'Ensure the Content Translation UI is not visible when Experiments are globally disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Translation Experiment.
		await enableExperiment( admin, page, 'Content Translation' );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Content Translation Experiment Globally Disabled',
			content:
				'This is some test content for the Content Translation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the Generate Translation button doesn't exist.
		await expect(
			page.getByRole( 'button', {
				name: 'Generate Translation',
			} )
		).not.toBeVisible();
	} );

	test( 'Translation button is disabled when content is shorter than the minimum length', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Translation Experiment.
		await enableExperiment( admin, page, 'Content Translation' );

		// Create a new post with content shorter than the minimum length.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Short Content',
		} );

		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content: 'A',
			},
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Switch to the Post tab (if not already on it).
		await page.getByRole( 'tab', { name: 'Post' } ).click();

		// Ensure the Generate Translation button is visible but disabled.
		const generateButton = page.getByRole( 'button', {
			name: 'Generate Translation',
		} );
		await expect( generateButton ).toBeVisible();
		await expect( generateButton ).toBeDisabled();
	} );

	test( 'Ensure the Content Translation Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Content Translation Experiment.
		await disableExperiment( admin, page, 'Content Translation' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Content Translation Experiment Disabled',
			content:
				'This is some test content for the Content Translation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the Generate Translation button doesn't exist.
		await expect(
			page.getByRole( 'button', { name: 'Generate Translation' } )
		).toHaveCount( 0 );
	} );

	test( 'Shows an error when the post has no translatable blocks', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Translation Experiment.
		await enableExperiment( admin, page, 'Content Translation' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Content Translation No Translatable Blocks',
		} );

		// A Code block carries enough characters to pass the post-level minimum
		// but is not one of the supported block types.
		await editor.insertBlock( {
			name: 'core/code',
			attributes: {
				content:
					'const translate = ( content ) => content; // Not a translatable block type.',
			},
		} );

		await editor.saveDraft();

		await editor.openDocumentSettingsSidebar();
		await page.getByRole( 'tab', { name: 'Post' } ).click();

		const generateButton = page.getByRole( 'button', {
			name: 'Generate Translation',
		} );

		// The post-level content check passes, so the button is available.
		await expect( generateButton ).toBeEnabled();
		await generateButton.click();

		await page.getByLabel( 'Translate to' ).selectOption( {
			label: 'French',
		} );

		await page.getByRole( 'button', { name: 'Translate' } ).click();

		// The translation should report that there was nothing to translate.
		await expect(
			page.locator( '.components-notice', {
				hasText: 'No translatable content found in the post.',
			} )
		).toBeVisible();
	} );

	test( 'Skips blocks that are shorter than the minimum length', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Translation Experiment.
		await enableExperiment( admin, page, 'Content Translation' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Content Translation Skips Short Blocks',
		} );

		// A block long enough to translate.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content:
					'This paragraph is comfortably longer than the minimum content length required for translation, so it should be translated and replaced with the generated content.',
			},
		} );

		// A block below the minimum content length, which should be skipped
		// client-side rather than sent and reported as a failure.
		await editor.insertBlock( {
			name: 'core/heading',
			attributes: {
				content: 'FAQ',
			},
		} );

		await editor.saveDraft();

		await editor.openDocumentSettingsSidebar();
		await page.getByRole( 'tab', { name: 'Post' } ).click();

		await page
			.getByRole( 'button', { name: 'Generate Translation' } )
			.click();

		await page.getByLabel( 'Translate to' ).selectOption( {
			label: 'French',
		} );

		await page.getByRole( 'button', { name: 'Translate' } ).click();

		// The long paragraph is translated.
		await expect(
			editor.canvas
				.getByRole( 'document', { name: 'Block: Paragraph' } )
				.first()
		).toHaveText( MOCKED_RESPONSE );

		// The short heading is left untouched.
		await expect(
			editor.canvas.getByRole( 'document', { name: 'Block: Heading' } )
		).toHaveText( 'FAQ' );

		// The skip is reported as a skip, not as a failure.
		const notice = page.locator( '.components-notice', {
			hasText: 'Skipped 1 block',
		} );
		await expect( notice ).toBeVisible();
		await expect( notice ).not.toContainText( 'Failed to translate' );
	} );
} );

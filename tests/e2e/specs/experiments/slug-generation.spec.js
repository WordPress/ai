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

const LONG_CONTENT =
	'Artificial intelligence is rapidly changing how content is created, edited, and published across the web today. Writers increasingly rely on automated tools to draft outlines, summarize research, and suggest improvements to their work. These systems analyze large amounts of text and surface patterns that would take a human many hours to find on their own. As the technology matures, editors are learning to combine their own judgment with machine generated suggestions to produce stronger results. This paragraph exists only to provide enough characters for the slug generation experiment to run, because the feature now requires a reasonable amount of content before it will offer to generate slug suggestions for the post.';

/**
 * Opens the permalink popover in the post settings sidebar.
 *
 * Ensures the document sidebar is visible, then clicks the URL / permalink
 * section to reveal the popover where the "Generate Slug" button is injected.
 *
 * @param {Object} editor The editor fixture from the test context.
 * @param {Object} page   The Playwright page object.
 */
const openPermalinkPopover = async ( editor, page ) => {
	// Ensure the sidebar is visible.
	await editor.openDocumentSettingsSidebar();

	const popoverLocator = page.locator(
		'.components-popover .editor-post-url, .components-dropdown__content .editor-post-url'
	);

	// Only click toggle if popover is not already visible.
	if ( ! ( await popoverLocator.first().isVisible() ) ) {
		// The permalink section is accessed via the "Link" or "URL" panel in the
		// post settings sidebar. In the block editor it renders as a button that
		// toggles the popover.
		const linkButton = page.locator(
			'.editor-post-url__toggle, .editor-post-url__toggle-button, button.editor-post-url__hostname, button.editor-post-url__panel-toggle, .editor-post-url button, [aria-label*="URL"], [aria-label*="Permalink"], [aria-label*="Link"]'
		);

		// Wait for the slug panel toggle to render (it may take a moment after save).
		await expect( linkButton.first() ).toBeVisible( { timeout: 10000 } );
		await linkButton.first().click();
	}

	// Wait for the popover content to appear.
	await expect(
		page
			.locator(
				'.components-popover .editor-post-url, .components-dropdown__content .editor-post-url, .editor-post-url'
			)
			.first()
	).toBeVisible( {
		timeout: 10000,
	} );
};

test.describe( 'Slug Generation Experiment', () => {
	test( 'Can enable the slug generation experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Slug Generation Experiment.
		await enableExperiment( admin, page, 'Slug Generation' );
	} );

	test( 'Can use slug generation from the permalink popover', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Slug Generation Experiment.
		await enableExperiment( admin, page, 'Slug Generation' );

		// Create a new post with sufficient content.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Slug Generation',
			content: LONG_CONTENT,
		} );

		// Save the post so a permalink / slug section is generated.
		await editor.saveDraft();

		// Open the permalink popover.
		await openPermalinkPopover( editor, page );

		// Ensure the "Generate Slug" or "Regenerate Slug" button is visible.
		const generateButton = page.getByRole( 'button', {
			name: /Generate Slug|Regenerate Slug/i,
		} );
		await expect( generateButton.first() ).toBeVisible( {
			timeout: 10000,
		} );
		await expect( generateButton.first() ).toBeEnabled();

		// Click the Generate Slug button.
		await generateButton.first().click();

		// The slug generation modal should appear.
		const modal = page.getByRole( 'dialog', {
			name: 'Slug suggestions',
		} );
		await expect( modal ).toBeVisible( { timeout: 10000 } );

		// Wait for suggestions to load (the spinner should disappear).
		await expect(
			modal.getByText( 'Generating suggestions…' )
		).not.toBeVisible( { timeout: 15000 } );

		// Verify suggestion buttons are rendered.
		await expect( modal.getByText( 'Suggested Slugs' ) ).toBeVisible();

		// Verify the "Selected slug" text control is pre-filled with the first suggestion.
		const selectedSlugInput = modal.getByLabel( 'Selected slug' );
		await expect( selectedSlugInput ).toBeVisible();
		await expect( selectedSlugInput ).not.toHaveValue( '' );

		// Click Insert to apply the generated slug.
		await modal.getByRole( 'button', { name: 'Insert' } ).click();

		// Ensure the modal closes.
		await expect( modal ).not.toBeVisible();

		// Save the post.
		await editor.saveDraft();
	} );

	test( 'Generate Slug button is disabled when there is not enough content', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Slug Generation Experiment.
		await enableExperiment( admin, page, 'Slug Generation' );

		// Create a new post with content well below the minimum length.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Slug Too Short',
			content: 'Too short.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Open the permalink popover.
		await openPermalinkPopover( editor, page );

		// The Generate/Regenerate Slug button should be visible but disabled.
		const generateButton = page.getByRole( 'button', {
			name: /Generate Slug|Regenerate Slug|Slug suggestions will be available/i,
		} );
		await expect( generateButton.first() ).toBeVisible( {
			timeout: 10000,
		} );
		await expect( generateButton.first() ).toBeDisabled();
	} );

	test( 'Ensure the Slug Generation Experiment UI is not visible when Experiments are globally disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Slug Generation Experiment first.
		await enableExperiment( admin, page, 'Slug Generation' );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Slug Generation Globally Disabled',
			content: LONG_CONTENT,
		} );

		// Save the post.
		await editor.saveDraft();

		// Open the permalink popover.
		await openPermalinkPopover( editor, page );

		// The slug generation container should not be present.
		await expect(
			page.locator( '.ai-slug-generation-container' )
		).not.toBeVisible();
	} );

	test( 'Ensure the Slug Generation Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Slug Generation Experiment.
		await disableExperiment( admin, page, 'Slug Generation' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Slug Generation Experiment Disabled',
			content: LONG_CONTENT,
		} );

		// Save the post.
		await editor.saveDraft();

		// Open the permalink popover.
		await openPermalinkPopover( editor, page );

		// The slug generation container should not be present.
		await expect(
			page.locator( '.ai-slug-generation-container' )
		).not.toBeVisible();
	} );
} );

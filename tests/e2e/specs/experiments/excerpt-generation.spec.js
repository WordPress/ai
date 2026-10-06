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
} = require( '../../utils/helpers' );

// Long enough (>250 characters) to satisfy the excerpt generation minimum content length.
const LONG_CONTENT =
	'Artificial intelligence is rapidly changing how content is created, edited, and published across the web today. Writers increasingly rely on automated tools to draft outlines, summarize research, and suggest improvements to their work. These systems analyze large amounts of text and surface patterns that would take a human many hours to find on their own. As the technology matures, editors are learning to combine their own judgment with machine generated suggestions to produce stronger results. This paragraph exists only to provide enough characters for the excerpt generation experiment to run, because the feature now requires a reasonable amount of content before it will offer to generate a brand new excerpt for the post.';

// The default mock response text from responses.json / completions.json.
const MOCK_EXCERPT =
	'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure';

// The sidebar panel registered via PluginDocumentSettingPanel.
const PANEL_SELECTOR = '.ai-excerpt-generation-panel';

/**
 * Opens the Post sidebar and expands the Excerpt generation panel.
 *
 * The panel is a PluginDocumentSettingPanel which renders as a collapsible
 * panel in the document sidebar. This helper ensures the sidebar is on the
 * Post tab and the panel is expanded before interacting.
 *
 * @param {Object} editor The editor fixture.
 * @param {Object} page   The page object.
 */
async function openExcerptGenerationPanel( editor, page ) {
	await editor.openDocumentSettingsSidebar();

	// Switch to the Post tab if the Block tab is active.
	const postTab = page.getByRole( 'tab', { name: 'Post' } );
	if ( ( await postTab.count() ) > 0 ) {
		await postTab.click();
	}

	// Expand the panel if it is collapsed.
	const panelToggle = page.getByRole( 'button', {
		name: 'Excerpt generation',
		exact: true,
	} );

	if ( ( await panelToggle.count() ) > 0 ) {
		const isExpanded = await panelToggle.getAttribute( 'aria-expanded' );
		if ( isExpanded === 'false' ) {
			await panelToggle.click();
		}
	}
}

test.describe( 'Excerpt Generation Experiment', () => {
	test( 'Can enable the excerpt generation experiment', async ( {
		admin,
		page,
	} ) => {
		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );
	} );

	test( 'Can use the Excerpt Generation Experiment', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt Generation Experiment',
			content: LONG_CONTENT,
		} );

		// Save the post.
		await editor.saveDraft();

		// Open the Excerpt generation panel.
		await openExcerptGenerationPanel( editor, page );

		// Ensure the generate excerpt panel button exists.
		const panelButton = page
			.locator( PANEL_SELECTOR )
			.getByRole( 'button', { name: 'Generate excerpt' } );
		await expect( panelButton ).toBeVisible();

		// Click the generate excerpt button.
		await panelButton.click();

		// Ensure the panel shows a success notice with the result.
		const panel = page.locator( PANEL_SELECTOR );
		await expect(
			panel.getByText( 'Excerpt generated', { exact: true } )
		).toBeVisible();
		const preview = panel.getByText( MOCK_EXCERPT );
		await expect( preview ).toBeVisible();

		// Ensure the generated excerpt was written to the post. Checked through
		// the store so the test does not depend on either inspector's excerpt UI.
		expect(
			await page.evaluate( () =>
				window.wp.data
					.select( 'core/editor' )
					.getEditedPostAttribute( 'excerpt' )
			)
		).toBe( MOCK_EXCERPT );

		// The preview survives the panel being collapsed and expanded again.
		const panelToggle = page.getByRole( 'button', {
			name: 'Excerpt generation',
			exact: true,
		} );
		await panelToggle.click();
		await panelToggle.click();
		await expect( preview ).toBeVisible();

		// Editing the excerpt retires the preview. Edited through the store for
		// the same reason.
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/editor' )
				.editPost( { excerpt: 'A manually edited excerpt.' } )
		);
		await expect( preview ).toBeHidden();

		// Ensure the panel button text is updated.
		await expect(
			page
				.locator( PANEL_SELECTOR )
				.getByRole( 'button', { name: 'Regenerate excerpt' } )
		).toBeVisible();

		// Save the post.
		await editor.saveDraft();
	} );

	test( 'Generate excerpt button is disabled when there is not enough content', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		// Create a new post with content well below the minimum length.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt Generation Minimum Length',
			content: 'Too short.',
		} );

		// Open the Excerpt generation panel.
		await openExcerptGenerationPanel( editor, page );

		const panelButton = page
			.locator( PANEL_SELECTOR )
			.getByRole( 'button', {
				name: /Excerpt generation will be available when the post content has at least/i,
			} );
		await expect( panelButton ).toBeVisible();
		await expect( panelButton ).toBeDisabled();
	} );

	test( 'Shows an error notice and keeps the excerpt when generation fails', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt Generation Failure',
			content: LONG_CONTENT,
			excerpt: 'An existing excerpt.',
		} );

		// Make the ability request fail. Use a URL predicate instead of a regex
		// so the match is reliable regardless of URL encoding.
		await page.route(
			( url ) =>
				url.href.includes( 'wp-abilities' ) &&
				url.href.includes( 'excerpt-generation' ),
			async ( route ) => {
				await route.fulfill( {
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify( {
						code: 'internal_error',
						message: 'AI service unavailable',
						data: { status: 500 },
					} ),
				} );
			}
		);

		await openExcerptGenerationPanel( editor, page );
		const panel = page.locator( PANEL_SELECTOR );
		const panelButton = panel.getByRole( 'button', {
			name: 'Regenerate excerpt',
		} );
		await panelButton.click();

		// The error surfaces as an editor notice.
		await expect(
			page
				.locator( '.components-notice' )
				.filter( { hasText: 'AI service unavailable' } )
		).toBeVisible();

		// The existing excerpt is untouched and no success notice appears.
		expect(
			await page.evaluate( () =>
				window.wp.data
					.select( 'core/editor' )
					.getEditedPostAttribute( 'excerpt' )
			)
		).toBe( 'An existing excerpt.' );
		await expect(
			panel.getByText( 'Excerpt generated', { exact: true } )
		).toBeHidden();

		// The button is usable again.
		await expect( panelButton ).toBeEnabled();
	} );

	test( 'Panel is hidden when the core Excerpt panel is disabled in Preferences', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt Generation Panel Preference',
			content: LONG_CONTENT,
		} );

		// The panel is there while core's Excerpt panel is enabled.
		await openExcerptGenerationPanel( editor, page );
		await expect( page.locator( PANEL_SELECTOR ) ).toBeVisible();

		// Turn off core's Excerpt panel, as a user would from Preferences > Panels.
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/editor' )
				.toggleEditorPanelEnabled( 'post-excerpt' )
		);

		try {
			await expect( page.locator( PANEL_SELECTOR ) ).toBeHidden();
		} finally {
			// Restore the preference so later tests see the default state.
			await page.evaluate( () =>
				window.wp.data
					.dispatch( 'core/editor' )
					.toggleEditorPanelEnabled( 'post-excerpt' )
			);
		}

		await expect( page.locator( PANEL_SELECTOR ) ).toBeVisible();
	} );

	test( 'Ensure the Excerpt Generation Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Disable the Excerpt Generation Experiment.
		await disableExperiment( admin, page, 'Excerpt Generation' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt Generation Experiment Disabled',
			content:
				'This is some test content for the Excerpt Generation Experiment.',
		} );

		// Save the post.
		await editor.saveDraft();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Ensure the Excerpt generation panel doesn't exist.
		await expect( page.locator( PANEL_SELECTOR ) ).not.toBeVisible();

		// Ensure no generate excerpt button exists anywhere in the editor.
		await expect(
			page.getByRole( 'button', { name: 'Generate excerpt' } )
		).not.toBeVisible();
	} );
} );

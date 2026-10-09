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

// The modal opened from the panel button.
const MODAL_SELECTOR = '.ai-excerpt-generation-modal';

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

/**
 * Reads the post's current excerpt from the editor store.
 *
 * @param {Object} page The page object.
 * @return {Promise<string>} The edited excerpt.
 */
function getStoredExcerpt( page ) {
	return page.evaluate( () =>
		window.wp.data
			.select( 'core/editor' )
			.getEditedPostAttribute( 'excerpt' )
	);
}

test.describe( 'Excerpt Generation Experiment', () => {
	test( 'Can enable the excerpt generation experiment', async ( {
		admin,
		page,
	} ) => {
		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );
	} );

	test( 'Generates an excerpt and applies it from the modal', async ( {
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

		// Click the generate excerpt button.
		const panel = page.locator( PANEL_SELECTOR );
		await panel.getByRole( 'button', { name: 'Generate excerpt' } ).click();

		// The modal opens and the textarea is populated with the suggestion.
		const modal = page.locator( MODAL_SELECTOR );
		await expect( modal ).toBeVisible();
		await expect( modal.getByRole( 'textbox' ) ).toHaveValue(
			MOCK_EXCERPT,
			{ timeout: 10000 }
		);

		// Apply it.
		await modal
			.getByRole( 'button', { name: 'Apply', exact: true } )
			.click();
		await expect( modal ).not.toBeVisible();

		// The excerpt was written to the post and the button label updated.
		expect( await getStoredExcerpt( page ) ).toBe( MOCK_EXCERPT );
		await expect(
			panel.getByRole( 'button', { name: 'Regenerate excerpt' } )
		).toBeVisible();

		// Save the post.
		await editor.saveDraft();
	} );

	test( 'Can edit the suggestion before applying it', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt Generation Edit',
			content: LONG_CONTENT,
		} );

		await openExcerptGenerationPanel( editor, page );
		await page
			.locator( PANEL_SELECTOR )
			.getByRole( 'button', { name: 'Generate excerpt' } )
			.click();

		const modal = page.locator( MODAL_SELECTOR );
		await expect( modal.getByRole( 'textbox' ) ).toHaveValue(
			MOCK_EXCERPT,
			{ timeout: 10000 }
		);

		// Edit the suggestion and apply the edited text.
		await modal
			.getByRole( 'textbox' )
			.fill( 'A custom excerpt for testing purposes.' );
		await modal
			.getByRole( 'button', { name: 'Apply', exact: true } )
			.click();

		expect( await getStoredExcerpt( page ) ).toBe(
			'A custom excerpt for testing purposes.'
		);
	} );

	test( 'Canceling the modal leaves the excerpt untouched', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt Generation Cancel',
			content: LONG_CONTENT,
			excerpt: 'An existing excerpt.',
		} );

		await openExcerptGenerationPanel( editor, page );

		// With an excerpt in place the button offers to regenerate.
		await page
			.locator( PANEL_SELECTOR )
			.getByRole( 'button', { name: 'Regenerate excerpt' } )
			.click();

		const modal = page.locator( MODAL_SELECTOR );
		await expect( modal.getByRole( 'textbox' ) ).toHaveValue(
			MOCK_EXCERPT,
			{ timeout: 10000 }
		);

		// Cancel instead of applying.
		await modal
			.getByRole( 'button', { name: 'Cancel', exact: true } )
			.click();
		await expect( modal ).not.toBeVisible();

		expect( await getStoredExcerpt( page ) ).toBe( 'An existing excerpt.' );
	} );

	test( 'Canceling generation stops loading and closes the modal', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Excerpt Generation Experiment.
		await enableExperiment( admin, page, 'Excerpt Generation' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt Generation Cancel Loading',
			content: LONG_CONTENT,
		} );

		await openExcerptGenerationPanel( editor, page );

		// Hold the ability request until the test releases it.
		let resolveRequest;
		const requestPromise = new Promise( ( resolve ) => {
			resolveRequest = resolve;
		} );
		const routeMatcher = ( url ) => {
			const decoded = decodeURIComponent( url.href );
			return (
				decoded.includes( 'wp-abilities' ) &&
				decoded.includes( 'excerpt-generation' )
			);
		};
		await page.route( routeMatcher, async ( route ) => {
			await requestPromise;
			await route.continue().catch( () => {} );
		} );

		const generateButton = page.locator(
			'.ai-excerpt-generation-panel__generate-button'
		);
		await generateButton.click();

		// The modal opens and both buttons show the loading state.
		const modal = page.locator( MODAL_SELECTOR );
		await expect( modal ).toBeVisible();
		await expect( generateButton ).toHaveText( /Generating/ );
		await expect( generateButton ).toBeDisabled();
		await expect(
			modal.getByRole( 'button', { name: /Generating/ } )
		).toBeVisible();

		// Cancel from the modal.
		await modal
			.getByRole( 'button', { name: 'Cancel', exact: true } )
			.click();
		await expect( modal ).not.toBeVisible();

		// The panel button stops loading immediately.
		await expect( generateButton ).toHaveText( 'Generate excerpt' );
		await expect( generateButton ).toBeEnabled();

		// No error notice was created.
		const errorNotice = await page.evaluate( () =>
			window.wp.data
				.select( 'core/notices' )
				.getNotices()
				.find(
					( notice ) => notice.id === 'ai_excerpt_generation_error'
				)
		);
		expect( errorNotice ).toBeUndefined();

		// Release the pending request.
		resolveRequest();
		await page.unroute( routeMatcher );
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

		const panel = page.locator( PANEL_SELECTOR );
		const panelButton = panel.getByRole( 'button', {
			name: /Excerpt generation will be available when the post content has at least/i,
		} );
		await expect( panelButton ).toBeVisible();
		await expect( panelButton ).toBeDisabled();

		// The reason is also shown as a hint below the button.
		await expect(
			panel.getByText( /at least 250 characters/ )
		).toBeVisible();
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
		const panelButton = page
			.locator( PANEL_SELECTOR )
			.getByRole( 'button', { name: 'Regenerate excerpt' } );
		await panelButton.click();

		// The error surfaces as an editor notice.
		await page.waitForFunction( () =>
			window.wp.data
				.select( 'core/notices' )
				.getNotices()
				.some(
					( notice ) =>
						notice.id === 'ai_excerpt_generation_error' &&
						notice.content === 'AI service unavailable'
				)
		);

		// The modal still holds the existing excerpt; cancel leaves it untouched.
		const modal = page.locator( MODAL_SELECTOR );
		await expect( modal.getByRole( 'textbox' ) ).toHaveValue(
			'An existing excerpt.'
		);
		await modal
			.getByRole( 'button', { name: 'Cancel', exact: true } )
			.click();
		expect( await getStoredExcerpt( page ) ).toBe( 'An existing excerpt.' );

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

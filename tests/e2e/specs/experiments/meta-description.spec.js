/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { disableExperiment, enableExperiment } from '../../utils/helpers';

const EXPERIMENT_LABEL = 'Meta Description Generation';

// The default mock response text from responses.json / completions.json.
const MOCK_DESCRIPTION_PATTERN =
	/Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure/;

const LONG_CONTENT =
	'Artificial intelligence is rapidly changing how content is created, edited, and published across the web today. Writers increasingly rely on automated tools to draft outlines, summarize research, and suggest improvements to their work. These systems analyze large amounts of text and surface patterns that would take a human many hours to find on their own. As the technology matures, editors are learning to combine their own judgment with machine generated suggestions to produce stronger results. This paragraph exists only to provide enough characters for the meta description experiment to run, because the feature now requires a reasonable amount of content before it will offer to generate a brand new description for the post.';

/**
 * Opens the Post sidebar and expands the Meta Description panel.
 *
 * The Meta Description panel is a PluginDocumentSettingPanel which renders
 * as a collapsible panel in the document sidebar. This helper ensures the
 * sidebar is on the Post tab and the panel is expanded before interacting.
 *
 * @param {Object} editor The editor fixture.
 * @param {Object} page   The page object.
 */
async function openMetaDescriptionPanel( editor, page ) {
	await editor.openDocumentSettingsSidebar();

	// Switch to the Post tab if the Block tab is active.
	const postTab = page.getByRole( 'tab', { name: 'Post' } );
	if ( ( await postTab.count() ) > 0 ) {
		await postTab.click();
	}

	// Expand the Meta Description panel if it is collapsed.
	const panelToggle = page.getByRole( 'button', {
		name: 'Meta Description',
		exact: true,
	} );

	if ( ( await panelToggle.count() ) > 0 ) {
		const isExpanded = await panelToggle.getAttribute( 'aria-expanded' );
		if ( isExpanded === 'false' ) {
			await panelToggle.click();
		}
	}
}

test.describe( 'Meta Description Experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
		// Enable the Meta Description Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'Shows the Generate Meta Description button in the sidebar panel', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Meta Description Button Test',
			content: LONG_CONTENT,
		} );

		await editor.saveDraft();

		// Open the Meta Description panel.
		await openMetaDescriptionPanel( editor, page );

		// The generate button should be visible.
		await expect(
			page.locator( '.ai-meta-description-panel' ).getByRole( 'button', {
				name: 'Generate Meta Description',
				exact: true,
			} )
		).toBeVisible();
	} );

	test( 'Generate Meta Description button is disabled when there is not enough content', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Create a new post with content well below the minimum length.
		await admin.createNewPost( {
			title: 'Meta Description Minimum Length Test',
			content: 'Too short.',
		} );

		await editor.saveDraft();

		// Open the Meta Description panel.
		await openMetaDescriptionPanel( editor, page );

		const generateButton = page
			.locator( '.ai-meta-description-panel' )
			.getByRole( 'button', {
				name: 'Meta Description generation will be available when the post content has at least 250 characters.',
				exact: true,
			} );
		await expect( generateButton ).toBeVisible();
		await expect( generateButton ).toHaveAttribute(
			'aria-disabled',
			'true'
		);
	} );

	test( 'Generates and applies a meta description', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Meta Description Generate Test',
			content: LONG_CONTENT,
		} );

		await editor.saveDraft();
		await page.reload();

		// Open the Meta Description panel.
		await openMetaDescriptionPanel( editor, page );

		// Click the Generate Meta Description button.
		await page
			.locator( '.ai-meta-description-panel' )
			.getByRole( 'button', {
				name: 'Generate Meta Description',
				exact: true,
			} )
			.click();

		// The modal should open.
		await expect(
			page.locator( '.ai-meta-description-modal' )
		).toBeVisible();

		// Wait for the textarea to be populated with the generated description.
		await expect(
			page.locator( '.ai-meta-description-modal' ).getByRole( 'textbox' )
		).toHaveValue( MOCK_DESCRIPTION_PATTERN, {
			timeout: 10000,
		} );

		// The character count should be visible in the modal.
		await expect(
			page.locator(
				'.ai-meta-description-modal .ai-meta-description__char-count'
			)
		).toBeVisible();

		// Click Apply.
		await page
			.locator( '.ai-meta-description-modal' )
			.getByRole( 'button', { name: 'Apply', exact: true } )
			.click();

		// The modal should close.
		await expect(
			page.locator( '.ai-meta-description-modal' )
		).not.toBeVisible();

		// The description should be displayed in the panel.
		await expect(
			page.locator( '.ai-meta-description-panel__text' )
		).toHaveText( MOCK_DESCRIPTION_PATTERN );

		// The character count should be visible in the panel.
		await expect(
			page.locator(
				'.ai-meta-description-panel .ai-meta-description__char-count'
			)
		).toBeVisible();

		await editor.saveDraft();
	} );

	test( 'Shows Edit and Regenerate actions after applying a description', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Meta Description Regenerate Test',
			content: LONG_CONTENT,
		} );

		await editor.saveDraft();
		await page.reload();

		// Open the Meta Description panel.
		await openMetaDescriptionPanel( editor, page );

		// Generate and apply a description.
		await page
			.locator( '.ai-meta-description-panel' )
			.getByRole( 'button', {
				name: 'Generate Meta Description',
				exact: true,
			} )
			.click();

		await expect(
			page.locator( '.ai-meta-description-modal' ).getByRole( 'textbox' )
		).toHaveValue( MOCK_DESCRIPTION_PATTERN, {
			timeout: 10000,
		} );

		await page
			.locator( '.ai-meta-description-modal' )
			.getByRole( 'button', { name: 'Apply', exact: true } )
			.click();

		// The Edit description link should be visible.
		await expect(
			page.locator( '.ai-meta-description-panel' ).getByRole( 'button', {
				name: 'Edit description',
				exact: true,
			} )
		).toBeVisible();

		// The Regenerate button should be visible.
		await expect(
			page.locator( '.ai-meta-description-panel' ).getByRole( 'button', {
				name: 'Regenerate meta description',
				exact: true,
			} )
		).toBeVisible();

		// Click the regenerate button.
		await page
			.locator( '.ai-meta-description-panel' )
			.getByRole( 'button', {
				name: 'Regenerate meta description',
				exact: true,
			} )
			.click();

		// The modal should open with a Regenerate button (not Generate).
		await expect(
			page.locator( '.ai-meta-description-modal' )
		).toBeVisible();

		await expect(
			page.locator( '.ai-meta-description-modal' ).getByRole( 'textbox' )
		).toHaveValue( MOCK_DESCRIPTION_PATTERN, {
			timeout: 10000,
		} );

		await expect(
			page
				.locator( '.ai-meta-description-modal' )
				.getByRole( 'button', { name: 'Regenerate', exact: true } )
		).toBeVisible();
	} );

	test( 'Can edit description text and apply changes', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Meta Description Edit Test',
			content: LONG_CONTENT,
		} );

		await editor.saveDraft();
		await page.reload();

		// Open the Meta Description panel.
		await openMetaDescriptionPanel( editor, page );

		// Generate and apply a description.
		await page
			.locator( '.ai-meta-description-panel' )
			.getByRole( 'button', {
				name: 'Generate Meta Description',
				exact: true,
			} )
			.click();

		await expect(
			page.locator( '.ai-meta-description-modal' ).getByRole( 'textbox' )
		).toHaveValue( MOCK_DESCRIPTION_PATTERN, {
			timeout: 10000,
		} );

		await page
			.locator( '.ai-meta-description-modal' )
			.getByRole( 'button', { name: 'Apply', exact: true } )
			.click();

		// Click the Edit description link.
		await page
			.locator( '.ai-meta-description-panel' )
			.getByRole( 'button', { name: 'Edit description', exact: true } )
			.click();

		// The modal should open.
		await expect(
			page.locator( '.ai-meta-description-modal' )
		).toBeVisible();

		// Clear and type a custom description.
		await page
			.locator( '.ai-meta-description-modal textarea' )
			.fill( 'A custom meta description for testing purposes.' );

		// The character count should update.
		await expect(
			page.locator(
				'.ai-meta-description-modal .ai-meta-description__char-count'
			)
		).toHaveText( /47 characters/ );

		// Click Apply.
		await page
			.locator( '.ai-meta-description-modal' )
			.getByRole( 'button', { name: 'Apply', exact: true } )
			.click();

		// The panel should show the updated description.
		await expect(
			page.locator( '.ai-meta-description-panel__text' )
		).toHaveText( 'A custom meta description for testing purposes.' );

		await editor.saveDraft();
	} );

	test( 'Editing after canceling a regenerated suggestion shows the saved description', async ( {
		admin,
		editor,
		page,
	} ) => {
		const savedDescription =
			'A custom saved description that should remain editable.';

		await admin.createNewPost( {
			title: 'Meta Description Cancel Regenerate Test',
			content: LONG_CONTENT,
		} );

		await editor.saveDraft();
		await page.reload();

		// Open the Meta Description panel.
		await openMetaDescriptionPanel( editor, page );

		// Generate and apply the initial description so the edit actions appear.
		await page
			.locator( '.ai-meta-description-panel' )
			.getByRole( 'button', {
				name: 'Generate Meta Description',
				exact: true,
			} )
			.click();

		await expect(
			page.locator( '.ai-meta-description-modal' ).getByRole( 'textbox' )
		).toHaveValue( MOCK_DESCRIPTION_PATTERN, {
			timeout: 10000,
		} );

		await page
			.locator( '.ai-meta-description-modal' )
			.getByRole( 'button', { name: 'Apply', exact: true } )
			.click();

		// Replace it with a custom saved value that differs from the mock.
		await page
			.locator( '.ai-meta-description-panel' )
			.getByRole( 'button', { name: 'Edit description', exact: true } )
			.click();

		await page
			.locator( '.ai-meta-description-modal textarea' )
			.fill( savedDescription );

		await page
			.locator( '.ai-meta-description-modal' )
			.getByRole( 'button', { name: 'Apply', exact: true } )
			.click();

		await expect(
			page.locator( '.ai-meta-description-panel__text' )
		).toHaveText( savedDescription );

		// Generate a new suggestion, but cancel without applying it.
		await page
			.locator( '.ai-meta-description-panel' )
			.getByRole( 'button', {
				name: 'Regenerate meta description',
				exact: true,
			} )
			.click();

		await expect(
			page.locator( '.ai-meta-description-modal' ).getByRole( 'textbox' )
		).toHaveValue( MOCK_DESCRIPTION_PATTERN, {
			timeout: 10000,
		} );

		await page
			.locator( '.ai-meta-description-modal' )
			.getByRole( 'button', { name: 'Cancel', exact: true } )
			.click();

		// Opening Edit should show the saved value, not the canceled suggestion.
		await page
			.locator( '.ai-meta-description-panel' )
			.getByRole( 'button', { name: 'Edit description', exact: true } )
			.click();

		await expect(
			page.locator( '.ai-meta-description-modal' ).getByRole( 'textbox' )
		).toHaveValue( savedDescription );
	} );

	test( 'Canceling generation stops loading and closes the modal', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Meta Description Cancel Loading Test',
			content: LONG_CONTENT,
		} );

		await editor.saveDraft();
		await page.reload();

		// Open the Meta Description panel.
		await openMetaDescriptionPanel( editor, page );

		// Set up a deferred promise to intercept and hold the Ability request.
		let resolveRequest;
		const requestPromise = new Promise( ( resolve ) => {
			resolveRequest = resolve;
		} );

		const routeMatcher = ( url ) => {
			const decoded = decodeURIComponent( url.href );
			return (
				decoded.includes( 'wp-abilities' ) &&
				decoded.includes( 'meta-description' )
			);
		};

		await page.route( routeMatcher, async ( route ) => {
			await requestPromise;
			await route.continue().catch( () => {} );
		} );

		const generateButton = page.locator(
			'.ai-meta-description-panel__generate-button'
		);

		await expect( generateButton ).toBeVisible();
		await generateButton.click();

		// The modal should open.
		const modal = page.locator( '.ai-meta-description-modal' );
		await expect( modal ).toBeVisible();

		// The button on the panel should be in loading state.
		await expect( generateButton ).toHaveText( /Generating/ );
		await expect( generateButton ).toHaveClass( /is-busy/ );
		await expect( generateButton ).toBeDisabled();

		// In the modal, the generate action should also indicate loading state.
		await expect(
			modal.getByRole( 'button', { name: /Generating/ } )
		).toBeVisible();

		// Cancel the generation from the modal.
		await modal
			.getByRole( 'button', { name: 'Cancel', exact: true } )
			.click();

		// The modal should close.
		await expect( modal ).not.toBeVisible();

		// The button in the panel should immediately stop loading and become enabled.
		await expect( generateButton ).toBeVisible();
		await expect( generateButton ).toHaveText(
			'Generate Meta Description'
		);
		await expect( generateButton ).toBeEnabled();
		await expect( generateButton ).not.toHaveClass( /is-busy/ );

		// No error notice should be created.
		const errorNotice = await page.evaluate( () => {
			const notices = window.wp?.data
				?.select( 'core/notices' )
				?.getNotices();
			return notices?.find(
				( notice ) => notice.id === 'ai_meta_description_error'
			);
		} );
		expect( errorNotice ).toBeUndefined();

		// Finish the pending request and unroute.
		resolveRequest();
		await page.unroute( routeMatcher );
	} );

	test( 'Shows Copy to clipboard button in the modal', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Meta Description Copy Test',
			content: LONG_CONTENT,
		} );

		await editor.saveDraft();
		await page.reload();

		// Open the Meta Description panel.
		await openMetaDescriptionPanel( editor, page );

		// Generate a description.
		await page
			.locator( '.ai-meta-description-panel' )
			.getByRole( 'button', {
				name: 'Generate Meta Description',
				exact: true,
			} )
			.click();

		await expect(
			page.locator( '.ai-meta-description-modal' ).getByRole( 'textbox' )
		).toHaveValue( MOCK_DESCRIPTION_PATTERN, {
			timeout: 10000,
		} );

		// The Copy to clipboard button should be visible and enabled.
		const copyButton = page
			.locator( '.ai-meta-description-modal' )
			.getByRole( 'button', { name: 'Copy to clipboard', exact: true } );
		await expect( copyButton ).toBeVisible();
		await expect( copyButton ).toBeEnabled();
	} );

	test( 'UI is hidden when experiment is individually disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Disable the Meta Description Experiment.
		await disableExperiment( admin, page, EXPERIMENT_LABEL );

		await admin.createNewPost( {
			title: 'Meta Description Disabled Test',
			content: LONG_CONTENT,
		} );

		await editor.saveDraft();
		await editor.openDocumentSettingsSidebar();

		// The Meta Description panel should not be present.
		await expect(
			page.locator( '.ai-meta-description-settings-panel' )
		).toHaveCount( 0 );
	} );
} );

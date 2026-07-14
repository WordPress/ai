/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	disableAdvancedSettings,
	disableExperiment,
	disableExperiments,
	enableAdvancedSettings,
	enableExperiments,
	enableExperiment,
} from '../../utils/helpers';

const EXPERIMENT_LABEL = 'Content Classification';

// Content Classification has a 250 character minimum requirement.
const LONG_CONTENT =
	'Artificial intelligence is transforming the technology landscape at an unprecedented pace. ' +
	'From machine learning algorithms that power recommendation engines to natural language processing ' +
	'systems that understand human speech, AI is reshaping how we interact with computers and the world ' +
	'around us. In the field of healthcare, AI-driven diagnostics are helping doctors detect diseases ' +
	'earlier and more accurately than ever before. In finance, algorithmic trading systems process vast ' +
	'amounts of data to make split-second decisions. The automotive industry is leveraging AI for ' +
	'self-driving vehicles that promise to revolutionize transportation. Education is being transformed ' +
	'through personalized learning platforms that adapt to each student. Meanwhile, creative industries ' +
	'are exploring how generative AI can assist with writing, music composition, and visual art. ' +
	'As these technologies continue to evolve, important questions about ethics, privacy, and the future ' +
	'of work demand careful consideration from policymakers, technologists, and society at large. ' +
	'The potential benefits are enormous, but so are the challenges we must navigate together.';

/**
 * Opens the Post sidebar and expands a taxonomy panel by its header label.
 *
 * Taxonomy panels in the Gutenberg sidebar are collapsible. The content
 * classification component only renders when the panel is expanded.
 *
 * @param {Object} editor     The editor fixture.
 * @param {Object} page       The page object.
 * @param {string} panelLabel The panel header label to expand (e.g. "Tags").
 */
async function openTaxonomyPanel( editor, page, panelLabel ) {
	await editor.openDocumentSettingsSidebar();

	// Switch to the Post tab if the Block tab is active.
	const postTab = page.getByRole( 'tab', { name: 'Post' } );
	if ( ( await postTab.count() ) > 0 ) {
		await postTab.click();
	}

	// Expand the taxonomy panel if it is collapsed.
	const panelToggle = page.getByRole( 'button', {
		name: panelLabel,
		exact: true,
	} );

	if ( ( await panelToggle.count() ) > 0 ) {
		// Check if the panel is collapsed by looking at the aria-expanded attribute.
		const isExpanded = await panelToggle.getAttribute( 'aria-expanded' );
		if ( isExpanded === 'false' ) {
			await panelToggle.click();
		}
	}
}

/**
 * Sets the strategy option for the content classification experiment
 * via the settings page.
 *
 * @param {Object} admin    The admin fixture.
 * @param {Object} page     The page object.
 * @param {string} strategy The strategy value ('existing_only' or 'allow_new').
 */
async function setStrategy( admin, page, strategy ) {
	await admin.visitAdminPage( 'options-general.php?page=ai-wp-admin' );

	await enableAdvancedSettings( page );

	const strategySelect = page.getByLabel( 'Taxonomy strategy' );
	await expect( strategySelect ).toBeVisible( { timeout: 10000 } );

	// Nothing to do if the strategy is already set.
	if ( ( await strategySelect.inputValue() ) === strategy ) {
		return;
	}

	await strategySelect.selectOption( strategy );

	const saveButton = page.getByRole( 'button', {
		name: /Save Content Classification/,
	} );
	await saveButton.click();

	await expect(
		page.getByTestId( 'snackbar' ).filter( {
			hasText: /Content Classification settings saved\./,
		} )
	).toBeVisible();
}

test.describe( 'Content Classification Experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Classification Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test.afterEach( async ( { admin, page } ) => {
		// Disable Advanced Settings to restore the default state for the next test.
		await admin.visitAdminPage( 'options-general.php?page=ai-wp-admin' );
		await disableAdvancedSettings( page );
	} );

	test( 'Shows the "Suggest Tags" button in the Tags panel', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Content Classification Test' } );

		// Add enough content to enable suggestions.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: LONG_CONTENT },
		} );

		// Open the Tags panel.
		await openTaxonomyPanel( editor, page, 'Tags' );

		// The suggest button should be visible within the Tags panel.
		await expect(
			page.getByRole( 'button', { name: 'Suggest Tags' } )
		).toBeVisible();
	} );

	test( 'Shows the "Suggest Categories" button in the Categories panel', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Content Classification Categories Test',
		} );

		// Add enough content to enable suggestions.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: LONG_CONTENT },
		} );

		// Open the Categories panel.
		await openTaxonomyPanel( editor, page, 'Categories' );

		// The suggest button should be visible within the Categories panel.
		await expect(
			page.getByRole( 'button', { name: 'Suggest Categories' } )
		).toBeVisible();
	} );

	test( 'Shows hint text when content is insufficient', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Content Classification Hint Test',
		} );

		// Add a short paragraph (well under 250 characters).
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: 'This is a short paragraph.' },
		} );

		// Open the Tags panel.
		await openTaxonomyPanel( editor, page, 'Tags' );

		const suggestButton = page.getByRole( 'button', {
			name: 'Suggest Tags',
		} );

		// The hint should be visible.
		await expect( suggestButton ).toHaveAccessibleDescription(
			/Content Classification will be available when the post content has at least 250 characters/
		);

		// The suggest button should be disabled.
		await expect( suggestButton ).toBeDisabled();
	} );

	test( 'Enables suggestions once content reaches the minimum length', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Content Classification Minimum Length Test',
		} );

		// Start with content well under the 250-character minimum.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: 'This is a short paragraph.' },
		} );

		// Open the Tags panel.
		await openTaxonomyPanel( editor, page, 'Tags' );

		const suggestButton = page.getByRole( 'button', {
			name: 'Suggest Tags',
		} );

		// The hint surfaces the configured minimum content length, and the
		// suggest button is disabled.
		await expect( suggestButton ).toHaveAccessibleDescription(
			/Content Classification will be available when the post content has at least 250 characters/
		);
		await expect( suggestButton ).toBeDisabled();

		// Add enough content to cross the minimum threshold.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: LONG_CONTENT },
		} );

		// The hint disappears and the suggest button becomes enabled.
		await expect( suggestButton ).not.toHaveAccessibleDescription(
			/Content Classification will be available when the post content has at least 250 characters/
		);
		await expect( suggestButton ).toBeEnabled();
	} );

	test( 'Generates and displays suggestion pills', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Set strategy to allow_new so mock suggestions (new terms) pass through.
		await setStrategy( admin, page, 'allow_new' );

		await admin.createNewPost( {
			title: 'Content Classification Generate Test',
		} );

		// Add enough content.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: LONG_CONTENT },
		} );

		await editor.saveDraft();
		await page.reload();

		// Open the Tags panel.
		await openTaxonomyPanel( editor, page, 'Tags' );

		// Click the Suggest Tags button.
		await page.getByRole( 'button', { name: 'Suggest Tags' } ).click();

		// Verify suggestion pills are rendered.
		const suggestions = page
			.getByRole( 'list', { name: 'Suggested Tags' } )
			.getByRole( 'listitem' );

		await expect( suggestions ).not.toHaveCount( 0 );

		// Verify the "Suggest again" and "Dismiss all" actions are visible.
		await expect(
			page
				.getByRole( 'button', { name: 'Suggest again', exact: true } )
				.first()
		).toBeVisible();

		await expect(
			page
				.getByRole( 'button', { name: 'Dismiss all', exact: true } )
				.first()
		).toBeVisible();
	} );

	test( 'Dismiss all clears all suggestion pills', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Set strategy to allow_new so mock suggestions (new terms) pass through.
		await setStrategy( admin, page, 'allow_new' );

		await admin.createNewPost( {
			title: 'Content Classification Dismiss All Test',
		} );

		// Add enough content.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: LONG_CONTENT },
		} );

		await editor.saveDraft();
		await page.reload();

		// Open the Tags panel.
		await openTaxonomyPanel( editor, page, 'Tags' );

		// Generate suggestions.
		await page.getByRole( 'button', { name: 'Suggest Tags' } ).click();

		// Wait for suggestions to appear.
		const suggestions = page
			.getByRole( 'list', { name: 'Suggested Tags' } )
			.getByRole( 'listitem' );

		await expect( suggestions ).not.toHaveCount( 0 );

		// Click "Dismiss all".
		await page
			.getByRole( 'button', { name: 'Dismiss all', exact: true } )
			.click();

		// Suggestions should be cleared and the generate button should reappear.
		await expect(
			page.getByRole( 'list', { name: 'Suggested Tags' } )
		).toHaveCount( 0 );

		await expect(
			page.getByRole( 'button', { name: 'Suggest Tags' } )
		).toBeVisible();
	} );

	test( 'UI is hidden when experiments are globally disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Create a new post with content.
		await admin.createNewPost( {
			title: 'Content Classification Globally Disabled Test',
		} );

		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: LONG_CONTENT },
		} );

		// Open the Tags panel.
		await openTaxonomyPanel( editor, page, 'Tags' );

		// The content classification UI should not be present.
		await expect(
			page.getByRole( 'button', { name: 'Suggest Tags' } )
		).toHaveCount( 0 );
	} );

	test( 'UI is hidden when experiment is individually disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Disable the Content Classification Experiment.
		await disableExperiment( admin, page, EXPERIMENT_LABEL );

		// Create a new post with content.
		await admin.createNewPost( {
			title: 'Content Classification Disabled Test',
		} );

		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: LONG_CONTENT },
		} );

		// Open the Tags panel.
		await openTaxonomyPanel( editor, page, 'Tags' );

		// The content classification UI should not be present.
		await expect(
			page.getByRole( 'button', { name: 'Suggest Tags' } )
		).toHaveCount( 0 );
	} );

	test( 'Suggested pills persist when switching sidebar tabs', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Set strategy to allow_new so mock suggestions (new terms) pass through.
		await setStrategy( admin, page, 'allow_new' );

		await admin.createNewPost( {
			title: 'Content Classification Cache Persistence Test',
		} );

		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: LONG_CONTENT },
		} );

		// Open the Tags panel
		await openTaxonomyPanel( editor, page, 'Tags' );

		// Click the Suggest Tags button.
		await page.getByRole( 'button', { name: 'Suggest Tags' } ).click();

		// Verify suggestion pills are rendered.
		const suggestions = page
			.getByRole( 'list', { name: 'Suggested Tags' } )
			.getByRole( 'listitem' );

		await expect( suggestions ).not.toHaveCount( 0 );

		// Switch to the Block tab.
		const blockTab = page.getByRole( 'tab', { name: 'Block' } );
		await blockTab.click();

		// Open the Tags panel again (this switches back to the Post tab and expands Tags).
		await openTaxonomyPanel( editor, page, 'Tags' );

		// Verify suggestion pills are STILL rendered and visible.
		await expect( suggestions ).not.toHaveCount( 0 );
	} );

	test( 'Restores suggestion pill to original position if tag addition fails', async ( {
		admin,
		editor,
		page,
	} ) => {
		await setStrategy( admin, page, 'allow_new' );

		await admin.createNewPost( {
			title: 'Content Classification Pill Restoration Failure Test',
		} );

		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: LONG_CONTENT },
		} );

		// Open the Tags panel and suggest.
		await openTaxonomyPanel( editor, page, 'Tags' );
		await page.getByRole( 'button', { name: 'Suggest Tags' } ).click();

		await expect(
			page
				.getByRole( 'list', { name: 'Suggested Tags' } )
				.getByRole( 'listitem' )
				.first()
		).toBeVisible();

		let resolveRoute;
		const routePromise = new Promise( ( resolve ) => {
			resolveRoute = resolve;
		} );

		// Intercept tag creation/search REST calls to fail.
		await page.route( /\/wp\/v2\/tags/, async ( route ) => {
			await routePromise;
			await route.fulfill( {
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify( {
					code: 'internal_server_error',
					message: 'Database connection failed.',
				} ),
			} );
		} );

		const firstPill = page
			.getByRole( 'list', { name: 'Suggested Tags' } )
			.getByRole( 'listitem' )
			.first();
		const pillText = await firstPill
			.getByRole( 'button', { name: /^Add ".+"$/ } )
			.textContent();

		// Click to accept a term.
		await firstPill.getByRole( 'button', { name: /^Add ".+"$/ } ).click();

		// Pill should disappear immediately.
		await expect(
			firstPill.getByRole( 'button', { name: /^Add ".+"$/ } )
		).not.toHaveText( pillText );

		// Resolve the promise to return the failure response.
		resolveRoute();

		// Since request fails, the pill should be restored to its original (first) position.
		await expect(
			firstPill.getByRole( 'button', { name: /^Add ".+"$/ } )
		).toHaveText( pillText );
	} );

	test( 'Does not restore suggestion pill if it belongs to a stale generation session', async ( {
		admin,
		editor,
		page,
	} ) => {
		await setStrategy( admin, page, 'allow_new' );

		await admin.createNewPost( {
			title: 'Content Classification Stale Generation Test',
		} );

		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: LONG_CONTENT },
		} );

		// Open the Tags panel and suggest.
		await openTaxonomyPanel( editor, page, 'Tags' );
		await page.getByRole( 'button', { name: 'Suggest Tags' } ).click();

		await expect(
			page
				.getByRole( 'list', { name: 'Suggested Tags' } )
				.getByRole( 'listitem' )
				.first()
		).toBeVisible();

		let resolveRoute;
		const routePromise = new Promise( ( resolve ) => {
			resolveRoute = resolve;
		} );

		// Intercept tag creation/search REST calls to fail.
		await page.route( /\/wp\/v2\/tags/, async ( route ) => {
			await routePromise;
			await route.fulfill( {
				status: 500,
				contentType: 'application/json',
				body: JSON.stringify( {
					code: 'internal_server_error',
					message: 'Database connection failed.',
				} ),
			} );
		} );

		const firstPill = page
			.getByRole( 'list', { name: 'Suggested Tags' } )
			.getByRole( 'listitem' )
			.first();
		const pillText = await firstPill
			.getByRole( 'button', { name: /^Add ".+"$/ } )
			.textContent();

		// Click to accept a term.
		await firstPill.getByRole( 'button', { name: /^Add ".+"$/ } ).click();

		// Pill should disappear immediately.
		await expect(
			firstPill.getByRole( 'button', { name: /^Add ".+"$/ } )
		).not.toHaveText( pillText );

		// While API call is pending, click "Dismiss all" to clear suggestions and increment session count.
		await page
			.getByRole( 'button', { name: 'Dismiss all', exact: true } )
			.click();

		// Resolve the promise to return the failure response.
		resolveRoute();

		// Since the session is now stale, the suggestions UI should remain cleared/empty.
		await expect(
			page.getByRole( 'list', { name: 'Suggested Tags' } )
		).toHaveCount( 0 );
	} );
} );

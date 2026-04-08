/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	disableExperiment,
	disableExperiments,
	enableExperiments,
	enableExperiment,
} from '../../utils/helpers';

const EXPERIMENT_LABEL = 'Content Classification';

// Content Classification has a 150 word minimum requirement.
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
	const panelToggle = page.locator( '.components-panel__body-toggle', {
		hasText: panelLabel,
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

	const strategySelect = page.getByLabel( 'Taxonomy strategy' );
	await expect( strategySelect ).toBeVisible( { timeout: 10000 } );
	await strategySelect.selectOption( strategy );

	const saveButton = page
		.locator( '.ai-feature-settings-form' )
		.getByRole( 'button', { name: 'Save' } );
	await saveButton.click();

	await expect(
		page.getByText( 'Content Classification settings saved.' )
	).toBeVisible();
}

test.describe( 'Content Classification Experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Classification Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
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
			page.locator( '.ai-content-classification button', {
				hasText: 'Suggest Tags',
			} )
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
			page.locator( '.ai-content-classification button', {
				hasText: 'Suggest Categories',
			} )
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

		// Add a short paragraph (well under 150 words).
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: 'This is a short paragraph.' },
		} );

		// Open the Tags panel.
		await openTaxonomyPanel( editor, page, 'Tags' );

		// The hint should be visible.
		await expect(
			page.locator( '.ai-content-classification__hint', {
				hasText: 'Add more content to enable AI suggestions',
			} )
		).toBeVisible();

		// The suggest button should be disabled.
		await expect(
			page
				.locator( '.ai-content-classification__generate-button' )
				.first()
		).toBeDisabled();
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
		await page
			.locator( '.ai-content-classification button', {
				hasText: 'Suggest Tags',
			} )
			.first()
			.click();

		// Wait for suggestions to appear.
		await expect(
			page.locator( '.ai-content-classification__suggestions' ).first()
		).toBeVisible();

		// Verify suggestion pills are rendered.
		await expect(
			page.locator( '.ai-content-classification__pill' ).first()
		).toBeVisible();

		// Verify the "Suggest again" and "Dismiss all" actions are visible.
		await expect(
			page
				.locator( '.ai-content-classification__actions button', {
					hasText: 'Suggest again',
				} )
				.first()
		).toBeVisible();

		await expect(
			page
				.locator( '.ai-content-classification__actions button', {
					hasText: 'Dismiss all',
				} )
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
		await page
			.locator( '.ai-content-classification button', {
				hasText: 'Suggest Tags',
			} )
			.first()
			.click();

		// Wait for suggestions to appear.
		await expect(
			page.locator( '.ai-content-classification__suggestions' ).first()
		).toBeVisible();

		// Click "Dismiss all".
		await page
			.locator( '.ai-content-classification__actions button', {
				hasText: 'Dismiss all',
			} )
			.first()
			.click();

		// Suggestions should be cleared and the generate button should reappear.
		await expect(
			page.locator( '.ai-content-classification__suggestions' ).first()
		).not.toBeVisible();

		await expect(
			page
				.locator( '.ai-content-classification button', {
					hasText: 'Suggest Tags',
				} )
				.first()
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
			page.locator( '.ai-content-classification' )
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
			page.locator( '.ai-content-classification' )
		).toHaveCount( 0 );
	} );
} );

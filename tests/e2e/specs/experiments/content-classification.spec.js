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
	disableModelSelection,
	enableAdvancedSettings,
	enableExperiment,
	enableModelSelection,
	visitSettingsPage,
} from '../../utils/helpers';

const EXPERIMENT_LABEL = 'Content Classification';

const METHOD_OPTION = 'wpai_feature_content-classification_field_method';
const EMBEDDING_MODEL_OPTION =
	'wpai_feature_content-classification_field_embedding_model';
const EMBEDDING_STATUS_PATH =
	'/ai/v1/embeddings/consumers/content-classification';

// A post about coffee. The mock embeddings API scores it close to a "Coffee"
// tag and far from a "Travel" tag.
const COFFEE_CONTENT =
	'Every morning starts with coffee. We grind the beans, pull a shot of espresso and steam ' +
	'milk for a latte while the kitchen is still quiet. A good barista knows that coffee is ' +
	'about patience: the grind size, the water temperature and the timing of each espresso ' +
	'shot all change how the coffee tastes. This guide walks through brewing better coffee at ' +
	'home, from choosing beans to cleaning the espresso machine afterwards.';

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

/**
 * Saves the suggestion method and embedding model over REST.
 *
 * @param {Object} requestUtils The requestUtils fixture.
 * @param {string} method       'llm' or 'embeddings'.
 * @param {Object} model        The embedding model, e.g. { provider, model }.
 */
async function setSuggestionMethod( requestUtils, method, model = {} ) {
	await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/settings',
		data: {
			[ METHOD_OPTION ]: method,
			[ EMBEDDING_MODEL_OPTION ]: model,
		},
	} );
}

/**
 * Runs the embedding sync worker until the backfill completes.
 *
 * WP-Cron does not run reliably in the test environment, so the worker is
 * run through a test-only endpoint instead.
 *
 * @param {Object} requestUtils The requestUtils fixture.
 */
async function runEmbeddingSync( requestUtils ) {
	await expect
		.poll(
			async () => {
				await requestUtils.rest( {
					method: 'POST',
					path: '/ai-e2e/v1/embeddings/run-sync',
				} );

				const status = await requestUtils.rest( {
					path: EMBEDDING_STATUS_PATH,
				} );

				return (
					status.backfill?.status === 'complete' &&
					status.totals.indexable > 0 &&
					status.totals.indexed === status.totals.indexable
				);
			},
			{ timeout: 30000 }
		)
		.toBe( true );
}

/**
 * Deletes any tags with the given names.
 *
 * @param {Object}   requestUtils The requestUtils fixture.
 * @param {string[]} names        Tag names.
 */
async function deleteTags( requestUtils, names ) {
	for ( const name of names ) {
		const tags = await requestUtils.rest( {
			path: '/wp/v2/tags',
			params: { search: name },
		} );

		for ( const tag of tags ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/tags/${ tag.id }`,
				params: { force: true },
			} );
		}
	}
}

test.describe( 'Content Classification Experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
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

	test( 'Adds a suggested tag that already exists with an ampersand in its name', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		// WordPress stores this name as "News &amp; Events".
		const tag = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/tags',
			data: { name: 'News & Events' },
		} );

		try {
			await admin.createNewPost( {
				title: 'Content Classification Existing Ampersand Tag Test',
			} );

			await editor.insertBlock( {
				name: 'core/paragraph',
				attributes: { content: LONG_CONTENT },
			} );

			// Suggest the existing tag by its decoded name.
			await page.route(
				/abilities(\/|%2F)ai(\/|%2F)content-classification(\/|%2F)run/,
				( route ) =>
					route.fulfill( {
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify( {
							suggestions: [
								{
									term: 'News & Events',
									confidence: 0.95,
									is_new: true,
								},
							],
						} ),
					} )
			);

			await openTaxonomyPanel( editor, page, 'Tags' );
			await page.getByRole( 'button', { name: 'Suggest Tags' } ).click();

			await page
				.getByRole( 'list', { name: 'Suggested Tags' } )
				.getByRole( 'button', { name: 'Add "News & Events"' } )
				.click();

			await expect
				.poll( () =>
					page.evaluate( () =>
						window.wp.data
							.select( 'core/editor' )
							.getEditedPostAttribute( 'tags' )
					)
				)
				.toEqual( [ tag.id ] );

			await expect(
				page.getByRole( 'list', { name: 'Suggested Tags' } )
			).toHaveCount( 0 );
		} finally {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/tags/${ tag.id }`,
				params: { force: true },
			} );
		}
	} );

	test.describe( 'Embedding mode', () => {
		test.afterEach( async ( { requestUtils } ) => {
			// Switching back to an empty model also deletes the stored vectors.
			await setSuggestionMethod( requestUtils, 'llm', {} );
			await requestUtils.rest( {
				method: 'POST',
				path: '/ai-e2e/v1/mocked-requests/stop',
			} );
		} );

		test( 'switching to similarity matching shows the model picker and disables strategy', async ( {
			admin,
			page,
		} ) => {
			await admin.visitAdminPage(
				'options-general.php?page=ai-wp-admin'
			);
			await enableAdvancedSettings( page );

			const strategySelect = page.getByLabel( 'Taxonomy strategy' );
			await expect( strategySelect ).toBeEnabled( { timeout: 10000 } );
			await expect(
				page.getByRole( 'group', { name: 'Embedding model' } )
			).toHaveCount( 0 );

			await page
				.getByLabel( 'Suggestion method' )
				.selectOption( 'embeddings' );

			const modelField = page.getByRole( 'group', {
				name: 'Embedding model',
			} );
			await expect( modelField ).toBeVisible();
			await expect( modelField.getByLabel( 'Provider' ) ).toBeVisible();

			await expect( strategySelect ).toBeDisabled();
			await expect(
				page.getByText(
					'Similarity matching can only suggest existing terms.'
				)
			).toBeVisible();

			// The panel can't start until the model is chosen and saved.
			const panel = page.locator( '.ai-embedding-sync-panel' );
			await expect(
				panel.getByText( 'Choose an embedding model to continue.' )
			).toBeVisible();
			await expect(
				panel.getByRole( 'button', { name: 'Generate embeddings' } )
			).toBeDisabled();
		} );

		test( 'generating embeddings shows progress then completion', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			await admin.visitAdminPage(
				'options-general.php?page=ai-wp-admin'
			);
			await enableAdvancedSettings( page );

			await page
				.getByLabel( 'Suggestion method' )
				.selectOption( 'embeddings' );

			const modelField = page.getByRole( 'group', {
				name: 'Embedding model',
			} );
			await modelField.getByLabel( 'Provider' ).selectOption( 'openai' );
			await modelField
				.getByLabel( 'Model' )
				.selectOption( 'text-embedding-3-small' );

			// Scoped to the panel: its messages are also announced to screen readers.
			const panel = page.locator( '.ai-embedding-sync-panel' );

			await expect(
				panel.getByText(
					'Save your settings before generating embeddings.'
				)
			).toBeVisible();

			await page
				.getByRole( 'button', { name: /Save Content Classification/ } )
				.click();
			await expect(
				page.getByTestId( 'snackbar' ).filter( {
					hasText: /Content Classification settings saved\./,
				} )
			).toBeVisible();

			// Saved, so the panel shows what needs embedding.
			await expect(
				panel.getByText( /^0 of \d+ terms? embedded\.$/ )
			).toBeVisible();

			await panel
				.getByRole( 'button', { name: 'Generate embeddings' } )
				.click();

			const dialog = page.getByRole( 'alertdialog', {
				name: 'Generate embeddings',
			} );
			await expect( dialog ).toContainText(
				/Generate embeddings for \d+ terms?\?/
			);
			await dialog
				.getByRole( 'button', { name: 'Generate embeddings' } )
				.click();

			// Relies on the WP-Cron loopback not firing in wp-env, so the
			// backfill stays running until run-sync is called below.
			await expect(
				panel.getByText(
					/^Generating embeddings: \d+ of \d+ terms? done\.$/
				)
			).toBeVisible();
			await expect(
				panel.getByRole( 'button', { name: 'Cancel' } )
			).toBeVisible();

			await runEmbeddingSync( requestUtils );

			// The panel polls while running and picks up the completed backfill.
			await expect(
				panel.getByText( /^All \d+ terms? (is|are) embedded\.$/ )
			).toBeVisible( { timeout: 20000 } );
			await expect(
				panel.getByRole( 'button', { name: 'Re-run' } )
			).toBeVisible();
		} );

		test( 'suggest buttons are disabled with a notice until embeddings exist', async ( {
			admin,
			editor,
			page,
			requestUtils,
		} ) => {
			await setSuggestionMethod( requestUtils, 'embeddings', {
				provider: 'openai',
				model: 'text-embedding-3-small',
			} );

			await admin.createNewPost( {
				title: 'Content Classification Embeddings Not Ready Test',
			} );

			await editor.insertBlock( {
				name: 'core/paragraph',
				attributes: { content: COFFEE_CONTENT },
			} );

			await openTaxonomyPanel( editor, page, 'Tags' );

			const suggestButton = page.getByRole( 'button', {
				name: 'Suggest Tags',
			} );

			await expect( suggestButton ).toBeDisabled();
			await expect( suggestButton ).toHaveAccessibleDescription(
				/Suggestions will be available once term embeddings finish generating\./
			);

			await openTaxonomyPanel( editor, page, 'Categories' );

			await expect(
				page.getByRole( 'button', { name: 'Suggest Categories' } )
			).toBeDisabled();
		} );

		test( 'suggests existing tags from embeddings', async ( {
			admin,
			editor,
			page,
			requestUtils,
		} ) => {
			const tagNames = [ 'Coffee', 'Travel' ];
			const postIds = [];

			await deleteTags( requestUtils, tagNames );

			try {
				for ( const name of tagNames ) {
					const tag = await requestUtils.rest( {
						method: 'POST',
						path: '/wp/v2/tags',
						data: { name },
					} );

					// Embedding mode only suggests terms used on at least two
					// published posts. Both tags qualify, so Travel is left out
					// by its score alone. The content has no topic keywords, so
					// it doesn't change which tags the mock vectors favour.
					for ( let i = 1; i <= 2; i++ ) {
						const post = await requestUtils.rest( {
							method: 'POST',
							path: '/wp/v2/posts',
							data: {
								title: `Term count fixture ${ i }`,
								content: 'A placeholder post.',
								status: 'publish',
								tags: [ tag.id ],
							},
						} );
						postIds.push( post.id );
					}
				}

				await setSuggestionMethod( requestUtils, 'embeddings', {
					provider: 'openai',
					model: 'text-embedding-3-small',
				} );

				await requestUtils.rest( {
					method: 'POST',
					path: `${ EMBEDDING_STATUS_PATH }/backfill`,
				} );
				await runEmbeddingSync( requestUtils );

				await requestUtils.rest( {
					method: 'POST',
					path: '/ai-e2e/v1/mocked-requests/start',
				} );

				await admin.createNewPost( {
					title: 'Brewing better coffee at home',
				} );

				await editor.insertBlock( {
					name: 'core/paragraph',
					attributes: { content: COFFEE_CONTENT },
				} );

				await openTaxonomyPanel( editor, page, 'Tags' );
				await page
					.getByRole( 'button', { name: 'Suggest Tags' } )
					.click();

				const suggestions = page.getByRole( 'list', {
					name: 'Suggested Tags',
				} );

				await expect(
					suggestions.getByRole( 'button', { name: 'Add "Coffee"' } )
				).toBeVisible();
				await expect(
					suggestions.getByRole( 'button', { name: 'Add "Travel"' } )
				).toHaveCount( 0 );

				// The suggestion came from the embeddings API, not a language model.
				const requests = await requestUtils.rest( {
					path: '/ai-e2e/v1/mocked-requests',
				} );

				// The model list lookup (`/v1/models`, uncached after an env
				// reset) sends no content, so it is left out. Every other call
				// must be an embeddings request.
				const contentRequests = requests.filter(
					( url ) => ! url.endsWith( '/v1/models' )
				);

				expect( contentRequests.length ).toBeGreaterThan( 0 );
				expect(
					contentRequests.filter(
						( url ) => ! url.includes( '/v1/embeddings' )
					)
				).toEqual( [] );
			} finally {
				for ( const id of postIds ) {
					await requestUtils.rest( {
						method: 'DELETE',
						path: `/wp/v2/posts/${ id }`,
						params: { force: true },
					} );
				}
				await deleteTags( requestUtils, tagNames );
			}
		} );

		test( 'developer model dropdowns are disabled in embedding mode', async ( {
			admin,
			page,
		} ) => {
			await visitSettingsPage( admin );
			await enableAdvancedSettings( page );
			await enableModelSelection( page );

			try {
				const developerFields = page
					.locator( '.ai-feature-toggle-with-settings' )
					.filter( {
						has: page.getByRole( 'checkbox', {
							name: EXPERIMENT_LABEL,
						} ),
					} )
					.locator( '.ai-developer-mode-fields' );

				const providerSelect = developerFields.getByLabel( 'Provider' );
				const note = /uses the Embedding model setting above/;

				await expect( providerSelect ).toBeEnabled( {
					timeout: 10000,
				} );
				await expect( developerFields.getByText( note ) ).toHaveCount(
					0
				);

				// The lock follows the unsaved method.
				await page
					.getByLabel( 'Suggestion method' )
					.selectOption( 'embeddings' );

				await expect( providerSelect ).toBeDisabled();
				await expect( developerFields.getByText( note ) ).toBeVisible();

				await page
					.getByLabel( 'Suggestion method' )
					.selectOption( 'llm' );

				await expect( providerSelect ).toBeEnabled();
			} finally {
				await disableModelSelection( page );
			}
		} );
	} );
} );

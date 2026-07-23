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

const EXPERIMENT_LABEL = 'Internal Link Suggestions';

const LONG_CONTENT =
	'Artificial intelligence is rapidly changing how content is created and published across the web today. Writers use automated tools to learn more about the WordPress REST API for content management. This paragraph provides enough characters for the internal link suggestions experiment to run properly because the feature requires a minimum content length before offering suggestions.';

test.describe( 'Internal Link Suggestions Experiment', () => {
	test( 'Can enable the internal link suggestions experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Internal Link Suggestions Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'Can use the Internal Link Suggestions Experiment in the block editor', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Internal Link Suggestions Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		// Create a target post so it is in the site index.
		await requestUtils.createPost( {
			title: 'Target Post for Internal Linking',
			status: 'publish',
		} );

		// Create a new post to edit.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Internal Link Suggestions',
			content: LONG_CONTENT,
		} );

		await editor.saveDraft();

		// Open document settings sidebar.
		await editor.openDocumentSettingsSidebar();

		const suggestButton = page.getByRole( 'button', {
			name: 'Suggest Internal Links',
		} );

		await expect( suggestButton ).toBeVisible();
		await expect( suggestButton ).toBeEnabled();

		await suggestButton.click();

		// Ensure suggestions list is displayed with header.
		await expect(
			page.locator( '.ai-internal-links__suggestions-header' )
		).toBeVisible( { timeout: 10000 } );
		await expect(
			page.locator( '.ai-internal-links__suggestions-header' )
		).toHaveText( '1 suggestion(s) found.' );

		const suggestionItem = page.locator( '.ai-internal-links__suggestion' );
		await expect( suggestionItem ).toBeVisible();
		await expect( suggestionItem ).toContainText( '"WordPress REST API"' );

		// Accept the suggestion.
		const acceptButton = suggestionItem.getByRole( 'button', {
			name: 'Accept',
		} );
		await expect( acceptButton ).toBeVisible();
		await acceptButton.click();

		// Verify success notice.
		await expect(
			page.locator( '.components-snackbar__content', {
				hasText: 'Internal link applied.',
			} )
		).toBeVisible();

		// Save the post.
		await editor.saveDraft();
	} );

	test( 'Suggest Internal Links button is disabled when there is not enough content', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Internal Link Suggestions Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		// Create a new post with content below minimum length (<75 chars).
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Internal Links Short Content',
			content: 'Too short.',
		} );

		await editor.saveDraft();

		// Open document settings sidebar.
		await editor.openDocumentSettingsSidebar();

		const suggestButton = page.getByRole( 'button', {
			name: 'Suggest Internal Links',
		} );

		await expect( suggestButton ).toBeVisible();
		await expect( suggestButton ).toBeDisabled();

		await expect(
			page.locator( '.ai-internal-links__plugin-description' )
		).toHaveText(
			'Internal Link Suggestions will be available when the post content has at least 75 characters.'
		);
	} );

	test( 'Ensure the Internal Link Suggestions Experiment UI is not visible when Experiments are globally disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Enable the Internal Link Suggestions Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Internal Links Globally Disabled',
			content: LONG_CONTENT,
		} );

		await editor.saveDraft();

		// Open document settings sidebar.
		await editor.openDocumentSettingsSidebar();

		await expect(
			page.getByRole( 'button', { name: 'Suggest Internal Links' } )
		).not.toBeVisible();
	} );

	test( 'Ensure the Internal Link Suggestions Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Internal Link Suggestions Experiment.
		await disableExperiment( admin, page, EXPERIMENT_LABEL );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Internal Links Experiment Disabled',
			content: LONG_CONTENT,
		} );

		await editor.saveDraft();

		// Open document settings sidebar.
		await editor.openDocumentSettingsSidebar();

		await expect(
			page.getByRole( 'button', { name: 'Suggest Internal Links' } )
		).not.toBeVisible();
	} );
} );

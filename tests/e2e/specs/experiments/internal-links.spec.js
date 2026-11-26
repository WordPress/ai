/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	disableAllFeatures,
	enableExperiment,
	seedCredentials,
} = require( '../../utils/helpers' );

const EXPERIMENT_LABEL = 'Internal Link Suggestions';

const LONG_CONTENT =
	'Artificial intelligence is rapidly changing how content is created and published across the web today. Writers use automated tools to learn more about the WordPress REST API for content management. This paragraph provides enough characters for the internal link suggestions experiment to run properly because the feature requires a minimum content length before offering suggestions.';

test.describe( 'Internal Link Suggestions Experiment', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await disableAllFeatures( requestUtils );
		await seedCredentials( requestUtils );
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: {
				'wpai_feature_internal-links_field_embedding': {
					provider: 'openai',
					model: 'text-embedding-3-small',
				},
			},
		} );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: { 'wpai_feature_internal-links_field_embedding': {} },
		} );
	} );

	test( 'Can enable the internal link suggestions experiment', async ( {
		admin,
		page,
	} ) => {
		// Enable the Internal Link Suggestions Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'Can use the Internal Link Suggestions Experiment in the block editor', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		// Enable the Internal Link Suggestions Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		// Create a target post so it is in the site index.
		const targetPost = await requestUtils.createPost( {
			title: 'Target Post for Internal Linking',
			content:
				'Learn about the WordPress REST API for content management.',
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
		).toBeVisible( { timeout: 15000 } );
		await expect(
			page.locator( '.ai-internal-links__suggestions-header' )
		).toHaveText( '1 suggestion found.' );

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

		// Verify the accepted link is persisted to the intended target.
		await editor.saveDraft();
		const postId = new URL( page.url() ).searchParams.get( 'post' );
		const savedPost = await requestUtils.rest( {
			path: `/wp/v2/posts/${ postId }`,
			params: { context: 'edit' },
		} );
		expect( savedPost.content.raw ).toContain(
			`<a href="${ targetPost.link }">WordPress REST API</a>`
		);
	} );

	test( 'Suggest Internal Links button is disabled when there is not enough content', async ( {
		admin,
		editor,
		page,
	} ) => {
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
			'Internal Link Suggestions will be available when the content has at least 75 characters.'
		);
	} );

	test( 'Ensure the Internal Link Suggestions Experiment UI is not visible when all features are disabled', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		// Enable the Internal Link Suggestions Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );

		// Disable all feature toggles using the current settings API.
		await disableAllFeatures( requestUtils );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Internal Links All Features Disabled',
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

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	clearCredentials,
	seedCredentials,
	enableExperiment,
	enableExperiments,
} = require( '../../utils/helpers' );

const NOTICE_TEXT =
	'This feature requires an AI Connector to function properly.';
const MANAGE_CONNECTORS_TEXT = 'Manage Connectors';

async function openMetaDescriptionPanel( editor, page ) {
	await editor.openDocumentSettingsSidebar();

	const postTab = page.getByRole( 'tab', { name: 'Post' } );
	if ( ( await postTab.count() ) > 0 ) {
		await postTab.click();
	}

	const panelToggle = page.locator( '.components-panel__body-toggle', {
		hasText: 'Meta Description',
	} );

	if ( ( await panelToggle.count() ) > 0 ) {
		const isExpanded = await panelToggle.getAttribute( 'aria-expanded' );
		if ( isExpanded === 'false' ) {
			await panelToggle.click();
		}
	}
}

async function expectProviderNotice( page ) {
	const notice = page.locator( '.components-notice', {
		hasText: NOTICE_TEXT,
	} );
	await expect( notice ).toBeVisible( { timeout: 5000 } );
	await expect(
		notice.getByRole( 'link', { name: MANAGE_CONNECTORS_TEXT } )
	).toBeVisible();
}

test.describe( 'Graceful degradation when no AI provider is configured', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await clearCredentials( requestUtils );
		await enableExperiments( admin, page );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await seedCredentials( requestUtils );
	} );

	test( 'Title Generation shows notice when clicking Generate without a provider', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Title Generation' );

		await admin.createNewPost( {
			postType: 'post',
			title: '',
			content: 'Test content for title generation without a provider.',
		} );
		await editor.saveDraft();

		// Click into the title field to show the toolbar.
		await editor.canvas.locator( '.editor-post-title__input' ).click();

		// The Generate button should still be visible.
		const generateButton = editor.canvas.locator(
			'.ai-title-toolbar-container button'
		);
		await expect( generateButton ).toBeVisible();

		// Click Generate — should show notice instead of making API call.
		await generateButton.click();

		await expectProviderNotice( page );

		// The modal should NOT open.
		await expect(
			page.locator( '.ai-title-generation-modal' )
		).not.toBeVisible();
	} );

	test( 'Excerpt Generation shows notice when clicking Generate without a provider', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Excerpt Generation' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Excerpt No Provider',
			content: 'Test content for excerpt generation without a provider.',
		} );
		await editor.saveDraft();
		await editor.openDocumentSettingsSidebar();

		const inlineButton = page.locator(
			'.editor-post-excerpt__dropdown .ai-excerpt-inline-wrapper .ai-excerpt-inline-button'
		);
		await expect( inlineButton ).toBeVisible( { timeout: 5000 } );
		await inlineButton.click();

		await expectProviderNotice( page );
	} );

	test( 'Content Summarization shows notice when clicking Generate Summary without a provider', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Content Summarization' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Summarization No Provider',
			content:
				'This is some test content for the Content Summarization Experiment. It needs to be at least one hundred characters long.',
		} );
		await editor.saveDraft();
		await editor.openDocumentSettingsSidebar();

		const generateButton = page.locator(
			'.ai-summarization-plugin-container button'
		);
		await expect( generateButton ).toBeVisible();
		await generateButton.click();

		await expectProviderNotice( page );
	} );

	test( 'Featured Image Generation shows notice when clicking Generate without a provider', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Image Generation and Editing' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Featured Image No Provider',
			content:
				'Test content for featured image generation without a provider.',
		} );
		await editor.saveDraft();
		await editor.openDocumentSettingsSidebar();

		const generateButton = page.locator( '.ai-featured-image button', {
			hasText: 'Generate featured image',
		} );
		await expect( generateButton ).toBeVisible( { timeout: 5000 } );
		await generateButton.click();

		await expectProviderNotice( page );
	} );

	test( 'Meta Description shows notice without opening the modal when no provider exists', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Meta Description Generation' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Meta Description No Provider',
			content:
				'Test content for meta description generation without a provider.',
		} );
		await editor.saveDraft();

		await openMetaDescriptionPanel( editor, page );

		const generateButton = page.locator(
			'.ai-meta-description-panel button',
			{
				hasText: 'Generate Meta Description',
			}
		);
		await expect( generateButton ).toBeVisible( { timeout: 5000 } );
		await generateButton.click();

		await expect(
			page.locator( '.ai-meta-description-modal' )
		).not.toBeVisible();

		await expectProviderNotice( page );
	} );

	test( 'Review Notes shows notice when clicking Generate Review Notes without a provider', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Review Notes' );

		await admin.createNewPost( {
			title: 'Test Review Notes No Provider',
		} );

		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content:
					'This paragraph contains content that is long enough for the AI review system to analyze and provide feedback about.',
			},
		} );

		// After inserting a block, the sidebar switches to Block tab.
		// Switch back to Post tab where the Generate Review Notes button lives.
		await editor.openDocumentSettingsSidebar();
		const postTab = page.getByRole( 'tab', { name: 'Post' } );
		if ( ( await postTab.count() ) > 0 ) {
			await postTab.click();
		}

		const reviewButton = page.getByRole( 'button', {
			name: 'Generate Review Notes',
		} );
		await expect( reviewButton ).toBeVisible( { timeout: 5000 } );
		await reviewButton.click();

		await expectProviderNotice( page );
	} );

	test( 'Comment Moderation bulk action option remains visible when no provider', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, 'Comment Moderation' );

		// Create a post with a comment so the bulk actions selector renders.
		const post = await requestUtils.createPost( {
			title: 'No Provider Comment Test',
			status: 'publish',
		} );
		await requestUtils.createComment( {
			content: 'Test comment for no-provider degradation.',
			post: post.id,
		} );

		await admin.visitAdminPage( 'edit-comments.php' );

		// The bulk action option should still be present even without a provider.
		const bulkSelect = page.locator( '#bulk-action-selector-top' );
		await expect( bulkSelect ).toBeVisible();

		const analyzeOption = bulkSelect.locator(
			'option[value="wpai_analyze"]'
		);
		await expect( analyzeOption ).toBeAttached();
	} );

	test( 'Comment Moderation inline action remains visible when no provider', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await enableExperiment( admin, page, 'Comment Moderation' );

		// Create a post with a comment so the inline actions render.
		const post = await requestUtils.createPost( {
			title: 'No Provider Inline Comment Test',
			status: 'publish',
		} );
		await requestUtils.createComment( {
			content: 'Test comment for inline action no-provider check.',
			post: post.id,
		} );

		await admin.visitAdminPage( 'edit-comments.php' );

		// Hover on the first comment row to reveal row actions.
		const firstRow = page.locator( '#the-comment-list tr' ).first();
		await expect( firstRow ).toBeVisible();
		await firstRow.hover();

		// The inline "Analyze" action should be present.
		const analyzeLink = firstRow.locator( '.row-actions .wpai_analyze a' );
		await expect( analyzeLink ).toBeAttached();
	} );

	test( 'Notice is dismissible', async ( { admin, editor, page } ) => {
		await enableExperiment( admin, page, 'Excerpt Generation' );

		await admin.createNewPost( {
			postType: 'post',
			title: 'Dismissible Notice Test',
			content: 'Test content for dismissible notice test.',
		} );
		await editor.saveDraft();
		await editor.openDocumentSettingsSidebar();

		const inlineButton = page.locator(
			'.editor-post-excerpt__dropdown .ai-excerpt-inline-wrapper .ai-excerpt-inline-button'
		);
		await expect( inlineButton ).toBeVisible( { timeout: 5000 } );
		await inlineButton.click();

		const notice = page.locator( '.components-notice', {
			hasText: NOTICE_TEXT,
		} );
		await expect( notice ).toBeVisible( { timeout: 5000 } );

		// Use the CSS class selector with force click — the dismiss button
		// can be obscured by editor chrome in some viewport sizes.
		await notice
			.locator( '.components-notice__dismiss' )
			.click( { force: true } );

		await expect( notice ).not.toBeVisible();
	} );

	test( 'Repeated clicks do not create duplicate notices', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Title Generation' );

		await admin.createNewPost( {
			postType: 'post',
			title: '',
			content: 'Test content for duplicate notice test.',
		} );
		await editor.saveDraft();

		await editor.canvas.locator( '.editor-post-title__input' ).click();

		const generateButton = editor.canvas.locator(
			'.ai-title-toolbar-container button'
		);

		// Click Generate multiple times.
		await generateButton.click();
		await page.waitForTimeout( 500 );
		await generateButton.click();
		await page.waitForTimeout( 500 );

		// There should be exactly one notice, not multiple.
		const notices = page.locator( '.components-notice', {
			hasText: NOTICE_TEXT,
		} );
		await expect( notices ).toHaveCount( 1 );
	} );
} );

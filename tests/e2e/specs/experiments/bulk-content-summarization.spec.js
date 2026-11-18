/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	clearCredentials,
	disableExperiment,
	enableExperiment,
	seedCredentials,
} = require( '../../utils/helpers' );

const LONG_CONTENT =
	'This is test content for the Bulk Content Summarization feature. ' +
	'The content needs to be long enough for the summarization feature to work properly. ' +
	'Adding more sentences ensures we meet the minimum content threshold. ' +
	'The bulk summarization feature allows administrators to generate summaries for multiple posts at once. ' +
	'This saves significant time compared to opening each post individually and clicking Generate Summary. ' +
	'By selecting multiple posts in the posts list and applying the bulk action, the process is automated. ' +
	'Summaries are generated sequentially using the configured AI provider and stored as post meta.';

test.describe( 'Bulk Content Summarization', () => {
	test( 'Bulk action appears in the posts list', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Enable the Content Summarization Experiment.
		await enableExperiment( admin, page, 'Content Summarization' );

		// Create a post so the list is not empty.
		await requestUtils.createPost( {
			title: 'Bulk Summary Presence Test',
			content: LONG_CONTENT,
			status: 'publish',
		} );

		// Navigate to the posts list.
		await admin.visitAdminPage( 'edit.php' );

		// Verify the bulk actions dropdown contains the Generate Summary option.
		const bulkSelect = page
			.getByRole( 'combobox', {
				name: 'Select bulk action',
				exact: true,
			} )
			.first();
		await expect( bulkSelect ).toBeVisible();
		await expect(
			bulkSelect.getByRole( 'option', {
				name: 'Generate Summary',
				exact: true,
				includeHidden: true,
			} )
		).toHaveCount( 1 );
	} );

	test( 'Bulk action generates summaries for selected posts', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Enable the Content Summarization Experiment.
		await enableExperiment( admin, page, 'Content Summarization' );

		// Create two posts with enough content for summarization.
		await requestUtils.createPost( {
			title: 'Bulk Summary Test Post One',
			content: LONG_CONTENT,
			status: 'publish',
		} );
		await requestUtils.createPost( {
			title: 'Bulk Summary Test Post Two',
			content: LONG_CONTENT,
			status: 'publish',
		} );

		// Navigate to the posts list.
		await admin.visitAdminPage( 'edit.php' );

		// Select all items via the header checkbox.
		await page
			.getByRole( 'checkbox', { name: 'Select All', exact: true } )
			.first()
			.check();

		// Choose the bulk action.
		await page
			.getByRole( 'combobox', {
				name: 'Select bulk action',
				exact: true,
			} )
			.first()
			.selectOption( { label: 'Generate Summary' } );

		// Click Apply.
		await page
			.getByRole( 'button', { name: 'Apply', exact: true } )
			.first()
			.click();

		// After redirect, the progress notice should appear immediately, then
		// update to the completion message once all posts are processed.
		await expect(
			page
				.getByRole( 'main' )
				.getByText( /Generating summaries|Summary generated/ )
		).toBeVisible( { timeout: 30000 } );

		// Wait for the completion message.
		await expect(
			page.getByRole( 'main' ).getByText( /Summary generated/ )
		).toBeVisible( { timeout: 60000 } );

		// Verify query args have been stripped from the URL.
		expect( page.url() ).not.toContain( 'wpai_bulk_summary' );
		expect( page.url() ).not.toContain( 'wpai_post_ids' );
	} );

	test( 'Bulk action skips posts whose content is shorter than the minimum length', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Enable the Content Summarization Experiment.
		await enableExperiment( admin, page, 'Content Summarization' );

		// Create one post with enough content, and one with content shorter
		// than the minimum length required for summarization.
		await requestUtils.createPost( {
			title: 'Bulk Summary Sufficient Content Post',
			content: LONG_CONTENT,
			status: 'publish',
		} );
		const shortPost = await requestUtils.createPost( {
			title: 'Bulk Summary Too Short Post',
			content: 'Too short.',
			status: 'publish',
		} );

		// Navigate to the posts list.
		await admin.visitAdminPage( 'edit.php' );

		// Select all items via the header checkbox.
		await page
			.getByRole( 'checkbox', { name: 'Select All', exact: true } )
			.first()
			.check();

		// Choose the bulk action.
		await page
			.getByRole( 'combobox', {
				name: 'Select bulk action',
				exact: true,
			} )
			.first()
			.selectOption( { label: 'Generate Summary' } );

		// Click Apply.
		await page
			.getByRole( 'button', { name: 'Apply', exact: true } )
			.first()
			.click();

		// Wait for the completion message, which should call out the skipped post.
		await expect(
			page.getByRole( 'main' ).getByText( /too short to summarize/ )
		).toBeVisible( { timeout: 60000 } );

		// Verify the short post's content was left untouched (no summary block added).
		const shortPostContent = await requestUtils.rest( {
			path: `/wp/v2/posts/${ shortPost.id }`,
			params: { context: 'edit' },
		} );
		expect( shortPostContent.content.raw ).not.toContain(
			'ai-summarization-summary'
		);
	} );

	test( 'Bulk action shows an error notice when no provider is configured', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		await clearCredentials( requestUtils );

		try {
			// Enable the Content Summarization Experiment.
			await enableExperiment( admin, page, 'Content Summarization' );

			// Create a post to select.
			await requestUtils.createPost( {
				title: 'Bulk Summary No Provider Test',
				content: LONG_CONTENT,
				status: 'publish',
			} );

			// Navigate to the posts list.
			await admin.visitAdminPage( 'edit.php' );

			// Select all items via the header checkbox.
			await page
				.getByRole( 'checkbox', { name: 'Select All', exact: true } )
				.first()
				.check();

			// Choose the bulk action.
			await page
				.getByRole( 'combobox', {
					name: 'Select bulk action',
					exact: true,
				} )
				.first()
				.selectOption( { label: 'Generate Summary' } );

			// Click Apply.
			await page
				.getByRole( 'button', { name: 'Apply', exact: true } )
				.first()
				.click();

			await expect(
				page
					.getByRole( 'main' )
					.getByText(
						'This feature requires a valid AI Connector to function properly.'
					)
			).toBeVisible( { timeout: 30000 } );

			// Verify query args have been stripped from the URL.
			expect( page.url() ).not.toContain( 'wpai_bulk_summary' );
			expect( page.url() ).not.toContain( 'wpai_post_ids' );
		} finally {
			await seedCredentials( requestUtils );
		}
	} );

	test( 'Bulk action generates summaries for a custom post type without a rest_base', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Enable the Content Summarization Experiment.
		await enableExperiment( admin, page, 'Content Summarization' );

		// The e2e-testing plugin registers `ai_e2e_sample` without a `rest_base`,
		// so its REST route is /wp/v2/ai_e2e_sample.
		const samplePost = await requestUtils.rest( {
			path: '/wp/v2/ai_e2e_sample',
			method: 'POST',
			data: {
				title: 'Bulk Summary Custom Post Type Test',
				content: LONG_CONTENT,
				status: 'publish',
			},
		} );

		try {
			await admin.visitAdminPage( 'edit.php', 'post_type=ai_e2e_sample' );

			await page
				.getByRole( 'checkbox', {
					name: 'Select Bulk Summary Custom Post Type Test',
				} )
				.check();
			await page
				.getByRole( 'combobox', {
					name: 'Select bulk action',
					exact: true,
				} )
				.first()
				.selectOption( { label: 'Generate Summary' } );
			await page
				.getByRole( 'button', { name: 'Apply', exact: true } )
				.first()
				.click();

			await expect(
				page
					.getByRole( 'main' )
					.getByText( 'Summary generated for 1 post.' )
			).toBeVisible( { timeout: 60000 } );

			const updatedPost = await requestUtils.rest( {
				path: `/wp/v2/ai_e2e_sample/${ samplePost.id }`,
				params: { context: 'edit' },
			} );
			expect( updatedPost.content.raw ).toContain(
				'ai-summarization-summary'
			);
		} finally {
			await requestUtils.rest( {
				path: `/wp/v2/ai_e2e_sample/${ samplePost.id }`,
				method: 'DELETE',
				params: { force: true },
			} );
		}
	} );

	test( 'Sorting and paginating after a bulk run does not re-trigger generation', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Enable the Content Summarization Experiment.
		await enableExperiment( admin, page, 'Content Summarization' );

		// Create two posts with enough content for summarization.
		await requestUtils.createPost( {
			title: 'Bulk Summary Retrigger Test Post One',
			content: LONG_CONTENT,
			status: 'publish',
		} );
		await requestUtils.createPost( {
			title: 'Bulk Summary Retrigger Test Post Two',
			content: LONG_CONTENT,
			status: 'publish',
		} );

		// Navigate to the posts list and run the bulk action.
		await admin.visitAdminPage( 'edit.php' );
		await page
			.getByRole( 'checkbox', { name: 'Select All', exact: true } )
			.first()
			.check();
		await page
			.getByRole( 'combobox', {
				name: 'Select bulk action',
				exact: true,
			} )
			.first()
			.selectOption( { label: 'Generate Summary' } );
		await page
			.getByRole( 'button', { name: 'Apply', exact: true } )
			.first()
			.click();

		// Wait for the completion message.
		await expect(
			page.getByRole( 'main' ).getByText( /Summary generated/ )
		).toBeVisible( { timeout: 60000 } );

		// The links the list table rendered must not carry the bulk trigger
		// params, otherwise every sort and pagination click re-runs generation.
		const sortLink = page
			.getByRole( 'columnheader', { name: /^Title/ } )
			.first()
			.getByRole( 'link' );
		await expect( sortLink ).not.toHaveAttribute(
			'href',
			/wpai_bulk_summary/
		);
		await expect( sortLink ).not.toHaveAttribute( 'href', /wpai_post_ids/ );

		// Click the sort header. The resulting page must not carry the trigger
		// params, which is what would re-run generation; asserting on the URL is
		// deterministic, unlike waiting for the absence of a notice.
		await sortLink.click();
		await page.waitForURL( /orderby=title/ );

		expect( page.url() ).not.toContain( 'wpai_bulk_summary' );
		expect( page.url() ).not.toContain( 'wpai_post_ids' );
	} );

	test( 'Bulk action is not visible when experiment is disabled', async ( {
		admin,
		requestUtils,
		page,
	} ) => {
		// Disable the Content Summarization Experiment.
		await disableExperiment( admin, page, 'Content Summarization' );

		// Create a post so the list is not empty.
		await requestUtils.createPost( {
			title: 'Bulk Summary Disabled Test',
			content: LONG_CONTENT,
			status: 'publish',
		} );

		// Navigate to the posts list.
		await admin.visitAdminPage( 'edit.php' );

		// Verify the bulk actions dropdown does NOT contain the Generate Summary option.
		const bulkSelect = page
			.getByRole( 'combobox', {
				name: 'Select bulk action',
				exact: true,
			} )
			.first();
		await expect( bulkSelect ).toBeVisible();
		await expect(
			bulkSelect.getByRole( 'option', {
				name: 'Generate Summary',
				exact: true,
				includeHidden: true,
			} )
		).toHaveCount( 0 );
	} );
} );

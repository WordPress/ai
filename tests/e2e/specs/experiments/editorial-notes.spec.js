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

const EXPERIMENT_LABEL = 'Editorial Notes';

test.describe( 'AI Editorial Notes Experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Editorial Notes Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'Shows the "Generate Editorial Notes" button in the post editor sidebar', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Editorial Notes Test' } );

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// The button should be visible in the post status info panel.
		await expect(
			page.getByRole( 'button', { name: 'Generate Editorial Notes' } )
		).toBeVisible();
	} );

	test( 'Disables Editorial Notes until the post content reaches the minimum length', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Short Editorial Notes Test',
			content: 'Too short.',
		} );

		await editor.openDocumentSettingsSidebar();

		const reviewButton = page.getByRole( 'button', {
			name: 'Generate Editorial Notes',
		} );
		await expect( reviewButton ).toBeVisible();
		await expect( reviewButton ).toBeDisabled();

		await expect( reviewButton ).toHaveAccessibleDescription(
			/Editorial Notes will be available when the post content has at least 75 characters./
		);
	} );

	test( 'Enables Editorial Notes once the post content meets the minimum length', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Long Editorial Notes Test',
			content:
				'This paragraph contains enough content for the Editorial Notes feature to become available and analyze the post block-by-block.',
		} );

		await editor.openDocumentSettingsSidebar();

		const reviewButton = page.getByRole( 'button', {
			name: 'Generate Editorial Notes',
		} );
		await expect( reviewButton ).toBeVisible();
		await expect( reviewButton ).toBeEnabled();

		await expect( reviewButton ).not.toHaveAccessibleDescription(
			/at least 75 characters./
		);
	} );

	test( 'Shows the "Review with AI" button in the block toolbar', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Editorial Notes Test' } );

		// Add reviewable blocks.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content:
					'This paragraph contains content that is long enough for the AI review system to analyze and provide feedback about.',
			},
		} );

		// Click into the more menu for the block.
		await editor.clickBlockToolbarButton( 'Options' );

		// The button should be visible in the block toolbar.
		await expect(
			page.getByRole( 'menuitem', {
				name: 'Generate Editorial Note',
				exact: true,
			} )
		).toBeVisible();
	} );

	test( 'Disables single-block Editorial Notes when the post content is shorter than the minimum length', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Short Single Block Review Test',
		} );

		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content: 'Tiny text.',
			},
		} );

		await editor.clickBlockToolbarButton( 'Options' );

		await expect(
			page.getByRole( 'menuitem', { name: 'Generate Editorial Note' } )
		).toBeDisabled();
	} );

	test( 'Shows suggestion count after a successful review', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Suggestion Count Test' } );

		// Add reviewable blocks.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content:
					'This paragraph contains content that is long enough for the AI review system to analyze and provide feedback about.',
			},
		} );

		await editor.saveDraft();

		// Reload the page
		await page.reload();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// Run review.
		await page
			.getByRole( 'button', { name: 'Generate Editorial Notes' } )
			.click();

		// Wait for completion and check for suggestion count feedback.
		await expect(
			page.getByRole( 'status' ).filter( {
				hasText: /1 suggestion added/,
			} )
		).toBeVisible();
	} );

	test( 'Shows suggestion count after a successful single block review', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Single Block Suggestion Count Test',
		} );

		// Add reviewable block.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content:
					'This paragraph contains content that is long enough for the AI review system to analyze and provide feedback about.',
			},
		} );

		// Run review on the single block.
		await editor.clickBlockOptionsMenuItem( 'Generate Editorial Note' );

		// Wait for completion and check for suggestion count feedback.
		await expect(
			page.getByTestId( 'snackbar' ).filter( {
				hasText: /1 suggestion added/,
			} )
		).toBeVisible();
	} );

	test( 'Disables Editorial Notes when post content is below the minimum length', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Create a post with content below the minimum threshold.
		await admin.createNewPost( { title: 'Empty Post Test' } );

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		const reviewButton = page.getByRole( 'button', {
			name: 'Generate Editorial Notes',
		} );
		await expect( reviewButton ).toBeDisabled();

		// The descriptive text should explain when the button becomes available.
		await expect( reviewButton ).toHaveAccessibleDescription(
			/Editorial Notes will be available when the post content has at least 75 characters./
		);
	} );

	test( 'Button is hidden when experiments are globally disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Create a new post and verify button is absent.
		await admin.createNewPost( { title: 'Disabled Experiment Test' } );

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		await expect(
			page.getByRole( 'button', { name: 'Generate Editorial Notes' } )
		).toHaveCount( 0 );

		// Add reviewable blocks.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content:
					'This paragraph contains content that is long enough for the AI review system to analyze and provide feedback about.',
			},
		} );

		// Click into the more menu for the block.
		await editor.clickBlockToolbarButton( 'Options' );

		// The button should not be visible in the block toolbar.
		await expect(
			page.getByRole( 'menuitem', {
				name: 'Generate Editorial Note',
				exact: true,
			} )
		).not.toBeVisible();
	} );

	test( 'Button is hidden when experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Disable the Editorial Notes Experiment.
		await disableExperiment( admin, page, EXPERIMENT_LABEL );

		// Create a new post and verify button is absent.
		await admin.createNewPost( { title: 'Disabled Experiment Test' } );

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		await expect(
			page.getByRole( 'button', { name: 'Generate Editorial Notes' } )
		).toHaveCount( 0 );

		// Add reviewable blocks.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content:
					'This paragraph contains content that is long enough for the AI review system to analyze and provide feedback about.',
			},
		} );

		// Click into the more menu for the block.
		await editor.clickBlockToolbarButton( 'Options' );

		// The button should be visible in the block toolbar.
		await expect(
			page.getByRole( 'menuitem', {
				name: 'Generate Editorial Note',
				exact: true,
			} )
		).not.toBeVisible();
	} );

	test( 'Only shows reviewing spinner on the block currently being reviewed, and shows busy notice on others', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Multi-Block Editorial Notes Test',
		} );

		// Insert Paragraph Block 1
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content:
					'This is paragraph number one. It contains enough content for AI review to run.',
			},
		} );

		// Insert Paragraph Block 2
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content:
					'This is paragraph number two. It also contains enough content for AI review.',
			},
		} );

		// Set up a deferred promise to intercept and hold the Ability request.
		let resolveRequest;
		const requestPromise = new Promise( ( resolve ) => {
			resolveRequest = resolve;
		} );

		// Intercept the modern WP Abilities REST endpoint.
		await page.route(
			/wp-json\/wp-abilities\/v1\/abilities\/ai\/editorial-notes\/run/,
			async ( route ) => {
				await requestPromise;
				await route.continue();
			}
		);

		// Select the first block and open options menu
		const paragraphs = editor.canvas.locator( '.wp-block-paragraph' );
		await paragraphs.nth( 0 ).click();
		// Click into the more menu for the block.
		await editor.clickBlockToolbarButton( 'Options' );

		// Trigger editorial note generation on block 1
		await page
			.getByRole( 'menuitem', {
				name: 'Generate Editorial Note',
				exact: true,
			} )
			.click();

		// Verify it shows "Reviewing..." with a spinner.
		const menuitem1 = page.getByRole( 'menuitem', {
			name: 'Reviewing…',
			exact: true,
		} );

		await expect( menuitem1 ).toBeVisible();
		await expect( menuitem1 ).toBeDisabled();
		await expect(
			menuitem1.locator( '.components-spinner' )
		).toBeVisible();

		// Select second block and open options menu
		await paragraphs.nth( 1 ).click();
		await editor.clickBlockToolbarButton( 'Options' );

		// Verify block 2 displays the help text/info and no spinner.
		// We set exact: false here because Gutenberg renders the busy helper description text
		// inside the menu item, which changes the computed accessible name of the element.
		const menuitem2 = page.getByRole( 'menuitem', {
			name: 'Generate Editorial Note',
			exact: false,
		} );
		await expect( menuitem2 ).toBeVisible();
		await expect( menuitem2 ).toBeDisabled();
		await expect( menuitem2.locator( '.components-spinner' ) ).toHaveCount(
			0
		);
		await expect(
			page.locator( '.components-menu-item__info', {
				hasText: 'Another block is currently being reviewed.',
			} )
		).toBeVisible();

		// Finish the pending request
		resolveRequest();
	} );
} );

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

const EXPERIMENT_ID = 'review-notes';

test.describe( 'AI Review Notes Experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Review Notes Experiment.
		await enableExperiment( admin, page, EXPERIMENT_ID );
	} );

	test( 'Shows the "Generate Review Notes" button in the post editor sidebar', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Review Notes Test' } );

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// The button should be visible in the post status info panel.
		await expect(
			page.getByRole( 'button', { name: 'Generate Review Notes' } )
		).toBeVisible();
	} );

	test( 'Shows the "Review with AI" button in the block toolbar', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Review Notes Test' } );

		// Add reviewable blocks.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content:
					'This paragraph contains content that is long enough for the AI review system to analyze and provide feedback about.',
			},
		} );

		// The button should be visible in the block toolbar.
		await expect(
			page.getByRole( 'button', { name: 'Review block with AI' } )
		).toBeVisible();
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
			.getByRole( 'button', { name: 'Generate Review Notes' } )
			.click();

		// Wait for completion and check for suggestion count feedback.
		await expect(
			page.locator( '.description', {
				hasText: '1 suggestion added',
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
		await page
			.getByRole( 'button', { name: 'Review block with AI' } )
			.click();

		// Wait for completion and check for suggestion count feedback.
		await expect(
			page.locator( '.components-snackbar', {
				hasText: '1 suggestion added',
			} )
		).toBeVisible();
	} );

	test( 'Does nothing when post has no reviewable blocks', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Create a post with no content blocks.
		await admin.createNewPost( { title: 'Empty Post Test' } );

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		const reviewButton = page.getByRole( 'button', {
			name: 'Generate Review Notes',
		} );
		await expect( reviewButton ).toBeVisible();

		await reviewButton.click();

		// Button should remain enabled immediately (no blocks to process).
		await expect( reviewButton ).toBeEnabled();

		// "No new suggestions found" should appear.
		await expect(
			page.locator( '.description', { hasText: 'No new suggestions' } )
		).toBeVisible();
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
			page.getByRole( 'button', { name: 'Generate Review Notes' } )
		).toHaveCount( 0 );

		// Add reviewable blocks.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content:
					'This paragraph contains content that is long enough for the AI review system to analyze and provide feedback about.',
			},
		} );

		// The button should not be visible in the block toolbar.
		await expect(
			page.getByRole( 'button', { name: 'Review block with AI' } )
		).toHaveCount( 0 );
	} );

	test( 'Button is hidden when experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Disable the Review Notes Experiment.
		await disableExperiment( admin, page, EXPERIMENT_ID );

		// Create a new post and verify button is absent.
		await admin.createNewPost( { title: 'Disabled Experiment Test' } );

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		await expect(
			page.getByRole( 'button', { name: 'Generate Review Notes' } )
		).toHaveCount( 0 );

		// Add reviewable blocks.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content:
					'This paragraph contains content that is long enough for the AI review system to analyze and provide feedback about.',
			},
		} );

		// The button should not be visible in the block toolbar.
		await expect(
			page.getByRole( 'button', { name: 'Review block with AI' } )
		).toHaveCount( 0 );
	} );
} );

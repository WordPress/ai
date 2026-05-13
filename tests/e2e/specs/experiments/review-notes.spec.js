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

const EXPERIMENT_LABEL = 'Review Notes';

test.describe( 'AI Review Notes Experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Review Notes Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
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

	test( 'Disables Review Notes until the post content reaches the minimum length', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Short Review Notes Test',
			content: 'Too short.',
		} );

		await editor.openDocumentSettingsSidebar();

		const reviewButton = page.getByRole( 'button', {
			name: 'Generate Review Notes',
		} );
		await expect( reviewButton ).toBeVisible();
		await expect( reviewButton ).toBeDisabled();

		await expect(
			page.locator( '.description', {
				hasText:
					'Review Notes will be available when the post content has at least 100 characters.',
			} )
		).toBeVisible();
	} );

	test( 'Enables Review Notes once the post content meets the minimum length', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Long Review Notes Test',
			content:
				'This paragraph contains enough content for the Review Notes feature to become available and analyze the post block-by-block.',
		} );

		await editor.openDocumentSettingsSidebar();

		const reviewButton = page.getByRole( 'button', {
			name: 'Generate Review Notes',
		} );
		await expect( reviewButton ).toBeVisible();
		await expect( reviewButton ).toBeEnabled();

		await expect(
			page.locator( '.description', {
				hasText: 'at least 100 characters',
			} )
		).toHaveCount( 0 );
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

		// Click into the more menu for the block.
		await editor.clickBlockToolbarButton( 'Options' );

		// The button should be visible in the block toolbar.
		await expect(
			page.locator( 'button', {
				hasText: 'Generate Review Note',
			} )
		).toBeVisible();
	} );

	test( 'Disables single-block Review Notes when the post content is shorter than the minimum length', async ( {
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
			page.getByRole( 'menuitem', { name: 'Generate Review Note' } )
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
		await editor.clickBlockOptionsMenuItem( 'Generate Review Note' );

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

		// Click into the more menu for the block.
		await editor.clickBlockToolbarButton( 'Options' );

		// The button should not be visible in the block toolbar.
		await expect(
			page.locator( 'button', {
				hasText: 'Generate Review Note',
			} )
		).not.toBeVisible();
	} );

	test( 'Button is hidden when experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Disable the Review Notes Experiment.
		await disableExperiment( admin, page, EXPERIMENT_LABEL );

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

		// Click into the more menu for the block.
		await editor.clickBlockToolbarButton( 'Options' );

		// The button should be visible in the block toolbar.
		await expect(
			page.locator( 'button', {
				hasText: 'Generate Review Note',
			} )
		).not.toBeVisible();
	} );
} );

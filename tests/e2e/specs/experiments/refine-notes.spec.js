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

const EXPERIMENT_ID = 'refine-notes';

test.describe( 'AI Refine from Notes Experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Refine Notes Experiment.
		await enableExperiment( admin, page, EXPERIMENT_ID );
	} );

	test( 'Button is hidden when there are no notes', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Refine Notes Test' } );

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// The button should NOT be visible initially since there are no notes.
		await expect(
			page.getByRole( 'button', { name: 'Refine from Notes' } )
		).toBeHidden();
	} );

	test( 'Shows the button and runs refinement when pending notes exist', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Refine with Notes Test' } );

		// Add a block to attach a note to.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: {
				content: 'This paragraph needs refinement.',
			},
		} );
		await editor.saveDraft();

		// Create a note via API and attach it to the block
		await page.evaluate( async () => {
			const postId = window.wp.data
				.select( 'core/editor' )
				.getCurrentPostId();
			const blocks = window.wp.data
				.select( 'core/block-editor' )
				.getBlocks();
			const blockClientId = blocks[ 0 ].clientId;

			const note = await window.wp.apiFetch( {
				path: '/wp/v2/comments',
				method: 'POST',
				data: {
					post: postId,
					content: 'Make this better.',
					type: 'note',
					status: 'hold',
				},
			} );

			window.wp.data
				.dispatch( 'core/block-editor' )
				.updateBlockAttributes( blockClientId, {
					metadata: { noteId: note.id },
				} );
		} );

		await editor.saveDraft();
		await page.reload();

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		// The button should be visible now.
		const refineButton = page.getByRole( 'button', {
			name: 'Refine from Notes',
		} );
		await expect( refineButton ).toBeVisible();

		// Click the button and check for loading text.
		await refineButton.click();
		await expect( refineButton ).toHaveText( 'Refining blocks…' );
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
			page.getByRole( 'button', { name: 'Refine from Notes' } )
		).toHaveCount( 0 );
	} );

	test( 'Button is hidden when experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Disable the Refine Notes Experiment.
		await disableExperiment( admin, page, EXPERIMENT_ID );

		// Create a new post and verify button is absent.
		await admin.createNewPost( { title: 'Disabled Experiment Test' } );

		// Ensure the sidebar is visible.
		await editor.openDocumentSettingsSidebar();

		await expect(
			page.getByRole( 'button', { name: 'Refine from Notes' } )
		).toHaveCount( 0 );
	} );
} );

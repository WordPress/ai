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
	enableExperiment,
	enableExperiments,
	selectFirstParagraph,
} from '../../utils/helpers';

const EXPERIMENT_LABEL = 'Content Resizing';

// Long enough to satisfy the 5-word minimum for the Shorten action.
const SAMPLE_PARAGRAPH =
	'This paragraph contains enough words for the resize toolbar to work against.';

// The mocked OpenAI response returns this string for the generic completions fixture
// (see tests/e2e-testing/responses/OpenAI/completions.json).
const MOCKED_RESPONSE =
	'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure';

test.describe( 'Content Resizing Experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Resizing Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'Toolbar dropdown is visible on a selected paragraph block', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Content Resizing Toolbar Test' } );

		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: SAMPLE_PARAGRAPH },
		} );

		await selectFirstParagraph( editor );

		// The Gutenberg block toolbar renders in the parent document (top
		// chrome), not inside the canvas iframe, so all block-toolbar lookups
		// use `page` rather than `editor.canvas`.
		await expect(
			page.getByRole( 'button', { name: 'Resize Content' } )
		).toBeVisible();
	} );

	test( 'Toolbar is hidden when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		await disableExperiment( admin, page, EXPERIMENT_LABEL );

		await admin.createNewPost( {
			title: 'Content Resizing Disabled Test',
		} );
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: SAMPLE_PARAGRAPH },
		} );

		await selectFirstParagraph( editor );

		await expect(
			page.getByRole( 'button', { name: 'Resize Content' } )
		).toHaveCount( 0 );
	} );

	test( 'Toolbar is hidden when experiments are globally disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		await disableExperiments( admin, page );

		await admin.createNewPost( {
			title: 'Content Resizing Global Disabled Test',
		} );
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: SAMPLE_PARAGRAPH },
		} );

		await selectFirstParagraph( editor );

		await expect(
			page.getByRole( 'button', { name: 'Resize Content' } )
		).toHaveCount( 0 );
	} );

	test( 'Rephrase flow shows both panels, replaces content, and flags the block as resized', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Content Resizing Rephrase Test',
		} );

		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: SAMPLE_PARAGRAPH },
		} );

		const paragraph = await selectFirstParagraph( editor );

		// Block toolbar lives in the parent document, not the iframe canvas.
		await page.getByRole( 'button', { name: 'Resize Content' } ).click();

		// DropdownMenu items render via a Popover portal attached to the parent
		// document, so the menuitems are also on `page`.
		await page.getByRole( 'menuitem', { name: 'Rephrase' } ).click();

		// Wait for the modal to be visible.
		const modal = page.locator( '.ai-content-resizing-modal' );
		await expect( modal ).toBeVisible();

		// Both the original and suggested panels should be visible.
		const originalPanel = modal.getByRole( 'region', {
			name: 'Original content',
		} );
		const suggestedPanel = modal.getByRole( 'region', {
			name: 'Suggested content',
		} );
		await expect( originalPanel ).toBeVisible();
		await expect( suggestedPanel ).toBeVisible();

		// Original panel should reflect the current block content.
		await expect( originalPanel ).toContainText( SAMPLE_PARAGRAPH );

		// Suggested panel should reflect the mocked AI response.
		await expect( suggestedPanel ).toContainText( MOCKED_RESPONSE, {
			timeout: 15000,
		} );

		// Word-diff chip should appear once the suggestion is loaded.
		await expect(
			modal.locator( '.ai-content-resizing-modal__diff' )
		).toBeVisible();

		// Accept the suggestion.
		await modal.getByRole( 'button', { name: 'Accept' } ).click();

		// Modal closes and the block content is replaced.
		await expect( modal ).toBeHidden();
		await expect( paragraph ).toContainText( MOCKED_RESPONSE );

		// The aiResized attribute should now be true on the paragraph block.
		const aiResized = await page.evaluate( () => {
			const blocks = window.wp.data
				.select( 'core/block-editor' )
				.getBlocks();
			return blocks[ 0 ]?.attributes?.aiResized ?? false;
		} );
		expect( aiResized ).toBe( true );

		// The toolbar group should carry the accent-color modifier.
		await expect(
			page.locator( '.ai-content-resizing-toolbar--has-changes' )
		).toBeVisible();
	} );

	test( 'Shorten action with too few words shows an error notice without opening the modal', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Content Resizing Shorten Error Test',
		} );

		// Fewer than 5 words so the client-side validation fires.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: 'Too few words.' },
		} );

		await selectFirstParagraph( editor );

		await page.getByRole( 'button', { name: 'Resize Content' } ).click();
		await page.getByRole( 'menuitem', { name: 'Shorten' } ).click();

		// The modal must not open on a client-side validation failure.
		await expect(
			page.locator( '.ai-content-resizing-modal' )
		).toHaveCount( 0 );

		// The error notice should be registered in the core/notices store.
		const errorNotice = await page.evaluate( () => {
			const notices = window.wp.data
				.select( 'core/notices' )
				.getNotices();
			return notices.find(
				( notice ) => notice.id === 'ai_content_resizing_error'
			);
		} );
		expect( errorNotice ).toBeDefined();
		expect( errorNotice.status ).toBe( 'error' );
	} );

	test( 'Shorten opens the modal when the block meets the minimum length', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Content Resizing Minimum Length Test',
		} );

		// SAMPLE_PARAGRAPH has more than the 5-word minimum, so Shorten is allowed.
		await editor.insertBlock( {
			name: 'core/paragraph',
			attributes: { content: SAMPLE_PARAGRAPH },
		} );

		await selectFirstParagraph( editor );

		await page.getByRole( 'button', { name: 'Resize Content' } ).click();
		await page.getByRole( 'menuitem', { name: 'Shorten' } ).click();

		// The minimum-length gate passes, so the modal opens.
		const modal = page.locator( '.ai-content-resizing-modal' );
		await expect( modal ).toBeVisible();

		// The suggested panel renders the exact mocked AI response (await the
		// async generation), and the original panel renders the exact block text.
		await expect(
			modal.locator(
				'.ai-content-resizing-modal__text:not(.ai-content-resizing-modal__text--original)'
			)
		).toHaveText( MOCKED_RESPONSE, { timeout: 15000 } );
		await expect(
			modal.locator( '.ai-content-resizing-modal__text--original' )
		).toHaveText( SAMPLE_PARAGRAPH );

		// No client-side validation error notice should be registered.
		const errorNotice = await page.evaluate( () => {
			const notices = window.wp.data
				.select( 'core/notices' )
				.getNotices();
			return notices.find(
				( notice ) => notice.id === 'ai_content_resizing_error'
			);
		} );
		expect( errorNotice ).toBeUndefined();
	} );
} );

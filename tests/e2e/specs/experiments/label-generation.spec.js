/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	enableExperiment,
	openListView,
	seedCredentials,
} = require( '../../utils/helpers' );

const SUGGESTION =
	'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure';

const LONG_TEXT =
	'This is a paragraph with enough content inside the group block for label generation to analyze and suggest a concise title.';

const SHORT_TEXT = 'Short';

/**
 * Inserts a group block with a paragraph child into the post.
 *
 * @param {Object} editor  The editor fixture.
 * @param {string} content The paragraph text content.
 */
async function insertGroupWithParagraph( editor, content ) {
	await editor.insertBlock( {
		name: 'core/group',
		innerBlocks: [
			{
				name: 'core/paragraph',
				attributes: {
					content,
				},
			},
		],
	} );
	await editor.saveDraft();
}

test.describe( 'Label Generation Experiment', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await seedCredentials( requestUtils );
	} );

	test( 'Can enable the label generation experiment', async ( {
		admin,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Label Generation' );
	} );

	test( 'Can generate block labels in List View for container blocks', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Label Generation' );

		await admin.createNewPost();
		await insertGroupWithParagraph( editor, LONG_TEXT );

		// Open List View.
		await openListView( page );

		const generateButton = page
			.locator( '.ai-label-generation-list-view-header__button' )
			.filter( { hasText: 'Generate Block Labels' } );

		await expect( generateButton ).toBeVisible();
		await expect( generateButton ).toBeEnabled();

		// Click the Generate Block Labels button.
		await generateButton.click();

		// Ensure the generated label appears for the container block in List View.
		const groupRow = page
			.locator( '.editor-list-view-sidebar__list-view-container' )
			.getByText( SUGGESTION );

		await expect( groupRow ).toBeVisible( { timeout: 15000 } );
	} );

	test( 'Generate button in List View is disabled when container block content is too short', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Label Generation' );

		await admin.createNewPost();
		await insertGroupWithParagraph( editor, SHORT_TEXT );

		// Open List View.
		await openListView( page );

		const generateButton = page
			.locator( '.ai-label-generation-list-view-header__button' )
			.filter( { hasText: 'Generate Block Labels' } );

		await expect( generateButton ).toBeVisible();
		await expect( generateButton ).toBeDisabled();
	} );

	test( 'Can generate a block label within the Rename modal', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Label Generation' );

		await admin.createNewPost();
		await insertGroupWithParagraph( editor, LONG_TEXT );

		// Select the container group block in the canvas.
		const groupBlock = editor.canvas
			.locator( '[data-type="core/group"]' )
			.first();
		await editor.selectBlocks( groupBlock );

		// Open the block options menu and click Rename.
		await editor.clickBlockOptionsMenuItem( 'Rename' );

		const modal = page.getByRole( 'dialog', { name: 'Rename' } );
		await expect( modal ).toBeVisible();

		const generateBtn = modal.locator(
			'.ai-label-generation-rename-button'
		);
		await expect( generateBtn ).toBeVisible();
		await expect( generateBtn ).toHaveText( 'Generate label' );
		await expect( generateBtn ).toBeEnabled();

		const nameInput = modal.getByRole( 'textbox', { name: 'Name' } );

		// Click the Generate label button inside the Rename modal.
		await generateBtn.click();

		// The input should be populated with the generated suggestion.
		await expect( nameInput ).toHaveValue( SUGGESTION, {
			timeout: 15000,
		} );

		// Button should update to "Regenerate label".
		await expect( generateBtn ).toHaveText( 'Regenerate label' );

		// Save the renamed block.
		await modal.getByRole( 'button', { name: 'Save' } ).click();

		// The modal should close.
		await expect( modal ).not.toBeVisible();

		// Save the post draft.
		await editor.saveDraft();

		// Verify the updated label appears in List View.
		await openListView( page );
		const renamedRow = page
			.locator( '.editor-list-view-sidebar__list-view-container' )
			.getByText( SUGGESTION );
		await expect( renamedRow ).toBeVisible();
	} );

	test( 'Generate button in Rename modal is disabled when block content is too short', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiment( admin, page, 'Label Generation' );

		await admin.createNewPost();
		await insertGroupWithParagraph( editor, SHORT_TEXT );

		// Select the container group block in the canvas.
		const groupBlock = editor.canvas
			.locator( '[data-type="core/group"]' )
			.first();
		await editor.selectBlocks( groupBlock );

		// Open the block options menu and click Rename.
		await editor.clickBlockOptionsMenuItem( 'Rename' );

		const modal = page.getByRole( 'dialog', { name: 'Rename' } );
		await expect( modal ).toBeVisible();

		const generateBtn = modal.locator(
			'.ai-label-generation-rename-button'
		);
		await expect( generateBtn ).toBeVisible();
		await expect( generateBtn ).toBeDisabled();

		// Close the modal.
		await modal.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( modal ).not.toBeVisible();
	} );

	test( 'Ensure the Label Generation Experiment UI is not visible when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		await disableExperiment( admin, page, 'Label Generation' );

		await admin.createNewPost();
		await insertGroupWithParagraph( editor, LONG_TEXT );

		// Open List View and verify the header button is not visible.
		await openListView( page );
		await expect(
			page.locator( '.ai-label-generation-list-view-header' )
		).not.toBeVisible();

		// Select the container block and open the Rename modal.
		const groupBlock = editor.canvas
			.locator( '[data-type="core/group"]' )
			.first();
		await editor.selectBlocks( groupBlock );
		await editor.clickBlockOptionsMenuItem( 'Rename' );

		const modal = page.getByRole( 'dialog', { name: 'Rename' } );
		await expect( modal ).toBeVisible();

		// The Generate label button must not be present in the Rename modal.
		await expect(
			modal.locator( '.ai-label-generation-rename-button' )
		).not.toBeVisible();

		// Close the modal.
		await modal.getByRole( 'button', { name: 'Cancel' } ).click();
	} );
} );

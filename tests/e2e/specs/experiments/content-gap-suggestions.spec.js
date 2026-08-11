/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { enableExperiment, enableExperiments } from '../../utils/helpers';

const EXPERIMENT_LABEL = 'Content Gap Suggestions';
const SUGGESTION_TITLE = "How to Start a Vegetable Garden: A Beginner's Guide";

test.describe( 'Content Gap Suggestions Experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Content Gap Suggestions Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'Shows the Content Opportunities widget on the dashboard', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'index.php' );

		await expect(
			page.getByRole( 'heading', { name: 'Content Opportunities' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Generate suggestions' } )
		).toBeVisible();
	} );

	test( 'Generates a suggestion from search pattern data', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'index.php' );

		await page
			.getByRole( 'button', { name: 'Generate suggestions' } )
			.click();

		await expect( page.getByText( SUGGESTION_TITLE ) ).toBeVisible( {
			timeout: 15000,
		} );
		await expect(
			page.getByRole( 'button', { name: 'Create Draft' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Dismiss' } )
		).toBeVisible();
	} );

	test( 'Dismissing a suggestion removes it from the list', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'index.php' );

		await page
			.getByRole( 'button', { name: 'Generate suggestions' } )
			.click();

		await expect( page.getByText( SUGGESTION_TITLE ) ).toBeVisible( {
			timeout: 15000,
		} );

		await page.getByRole( 'button', { name: 'Dismiss' } ).click();

		await expect( page.getByText( SUGGESTION_TITLE ) ).toBeHidden();
	} );

	test( 'Create Draft opens a new draft post in the editor, never publishing it', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.visitAdminPage( 'index.php' );

		await page
			.getByRole( 'button', { name: 'Generate suggestions' } )
			.click();

		await expect( page.getByText( SUGGESTION_TITLE ) ).toBeVisible( {
			timeout: 15000,
		} );

		await Promise.all( [
			page.waitForURL( /action=edit/ ),
			page.getByRole( 'button', { name: 'Create Draft' } ).click(),
		] );

		// Dismiss the "Welcome to the editor" guide if it appears - it
		// aria-hides the rest of the page (including the Publish button)
		// while open.
		await page.keyboard.press( 'Escape' );

		await expect(
			editor.canvas.getByRole( 'textbox', { name: 'Add title' } )
		).toHaveText( SUGGESTION_TITLE );

		// The draft is created directly via the REST API with status: draft,
		// so it must never be published automatically.
		await expect(
			page.getByRole( 'button', { name: 'Publish', exact: true } )
		).toBeVisible( { timeout: 15000 } );
	} );
} );

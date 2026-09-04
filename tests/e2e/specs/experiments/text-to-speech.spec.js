/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	disableExperiment,
	enableExperiment,
	enableExperiments,
} from '../../utils/helpers';

const EXPERIMENT_LABEL = 'Text to Speech';

const CONTENT =
	'Artificial intelligence is rapidly changing how content is created, edited, and published across the web today. Writers increasingly rely on automated tools to draft outlines, summarize research, and suggest improvements to their work.';

/**
 * Opens the Post sidebar and expands the Text to Speech panel.
 *
 * @param {Object} editor The editor fixture.
 * @param {Object} page   The page object.
 */
async function openTextToSpeechPanel( editor, page ) {
	await editor.openDocumentSettingsSidebar();

	// Switch to the Post tab if the Block tab is active.
	const postTab = page.getByRole( 'tab', { name: 'Post' } );
	if ( ( await postTab.count() ) > 0 ) {
		await postTab.click();
	}

	// Expand the Text to Speech panel if it is collapsed.
	const panelToggle = page.getByRole( 'button', {
		name: 'Text to Speech',
		exact: true,
	} );

	if ( ( await panelToggle.count() ) > 0 ) {
		const isExpanded = await panelToggle.getAttribute( 'aria-expanded' );
		if ( isExpanded === 'false' ) {
			await panelToggle.click();
		}
	}
}

test.describe( 'Text to Speech Experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Text to Speech Experiment.
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'Shows the Generate Audio button in the sidebar panel', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			title: 'Text to Speech Button Test',
			content: CONTENT,
		} );

		await editor.saveDraft();

		await openTextToSpeechPanel( editor, page );

		await expect(
			page
				.locator( '.ai-text-to-speech-panel' )
				.getByRole( 'button', { name: 'Generate Audio', exact: true } )
		).toBeVisible();
	} );

	test( 'Does not show the panel when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		await disableExperiment( admin, page, EXPERIMENT_LABEL );

		await admin.createNewPost( {
			title: 'Text to Speech Disabled Test',
			content: CONTENT,
		} );

		await editor.openDocumentSettingsSidebar();

		await expect(
			page.getByRole( 'button', { name: 'Text to Speech', exact: true } )
		).toHaveCount( 0 );
	} );
} );

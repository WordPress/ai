/**
 * WordPress dependencies
 */
import { test, expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { disableExperiment, enableExperiment } from '../../utils/helpers';

const EXPERIMENT_LABEL = 'Personas';
const POST_TYPE = 'wpai_persona';

/**
 * Opens the document sidebar and expands a panel by its title.
 *
 * @param {Object} editor The editor fixture.
 * @param {Object} page   The page object.
 * @param {string} title  The panel title.
 */
async function openPanel( editor, page, title ) {
	await editor.openDocumentSettingsSidebar();

	// The document tab is labelled after the post type, so match any tab that
	// is not the Block tab.
	const documentTab = page
		.getByRole( 'tab' )
		.filter( { hasNotText: 'Block' } )
		.first();
	if ( ( await documentTab.count() ) > 0 ) {
		await documentTab.click();
	}

	const panelToggle = page.getByRole( 'button', {
		name: title,
		exact: true,
	} );

	if (
		( await panelToggle.count() ) > 0 &&
		( await panelToggle.getAttribute( 'aria-expanded' ) ) === 'false'
	) {
		await panelToggle.click();
	}
}

/**
 * Deletes every persona so tests do not leak definitions into each other.
 *
 * @param {Object} requestUtils The request utils fixture.
 */
async function deleteAllPersonas( requestUtils ) {
	const personas = await requestUtils.rest( {
		path: `/wp/v2/${ POST_TYPE }`,
		params: { per_page: 100, status: 'publish,draft' },
	} );

	for ( const persona of personas ) {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/${ POST_TYPE }/${ persona.id }`,
			params: { force: true },
		} );
	}
}

/**
 * Creates a persona with a role and audience, then checks it is selectable.
 *
 * @param {Object} admin  The admin fixture.
 * @param {Object} editor The editor fixture.
 * @param {Object} page   The page object.
 */
async function definePersonaAndSelectIt( admin, editor, page ) {
	await admin.createNewPost( {
		postType: POST_TYPE,
		title: 'House Voice',
		content: 'Direct and unfussy.',
	} );
	await openPanel( editor, page, 'Persona details' );

	const details = page.locator( '.ai-persona-details-panel' );
	await details
		.getByRole( 'textbox', { name: 'Role' } )
		.fill( 'An editor at a small publication.' );
	await details
		.getByRole( 'textbox', { name: 'Audience' } )
		.fill( 'Readers who want the point quickly.' );

	await editor.publishPost();
	await page.reload();
	await openPanel( editor, page, 'Persona details' );

	await expect(
		page
			.locator( '.ai-persona-details-panel' )
			.getByRole( 'textbox', { name: 'Role' } )
	).toHaveValue( 'An editor at a small publication.' );

	// The new persona is now selectable on content posts.
	await admin.createNewPost( { title: 'Custom Persona Option Test' } );
	await openPanel( editor, page, 'Persona' );

	await expect(
		page
			.locator( '.ai-personas-panel' )
			.getByRole( 'combobox', { name: 'Persona' } )
			.locator( 'option', { hasText: 'House Voice' } )
	).toHaveCount( 1 );
}

test.describe( 'Personas Experiment', () => {
	test.beforeEach( async ( { admin, page } ) => {
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test( 'Shows the persona picker with the built-in personas', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Persona Picker Test' } );
		await openPanel( editor, page, 'Persona' );

		const select = page
			.locator( '.ai-personas-panel' )
			.getByRole( 'combobox', { name: 'Persona' } );
		await expect( select ).toBeVisible();

		const options = await select.locator( 'option' ).allTextContents();
		expect( options[ 0 ] ).toMatch( /^Site default/ );
		expect( options ).toContain( 'Professional' );
		expect( options ).toContain( 'Journalistic' );
		expect( options ).toContain( 'No persona' );
	} );

	test( 'Saves the selected persona with the post', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { title: 'Persona Persistence Test' } );
		await openPanel( editor, page, 'Persona' );

		await page
			.locator( '.ai-personas-panel' )
			.getByRole( 'combobox', { name: 'Persona' } )
			.selectOption( 'technical' );

		await editor.saveDraft();
		await page.reload();
		await openPanel( editor, page, 'Persona' );

		await expect(
			page
				.locator( '.ai-personas-panel' )
				.getByRole( 'combobox', { name: 'Persona' } )
		).toHaveValue( 'technical' );
	} );

	test( 'Defines a persona with a role and audience', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		// The only test that creates personas cleans them up itself, while the
		// experiment (and so the personas REST route) is still enabled.
		try {
			await definePersonaAndSelectIt( admin, editor, page );
		} finally {
			await deleteAllPersonas( requestUtils );
		}
	} );

	test( 'Lists personas under Posts', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'edit.php', `post_type=${ POST_TYPE }` );

		await expect(
			page.getByRole( 'heading', { name: 'Personas', level: 1 } )
		).toBeVisible();
		await expect(
			page
				.locator( '#menu-posts' )
				.getByRole( 'link', { name: 'Personas', exact: true } )
		).toBeVisible();
	} );

	test( 'UI is hidden when experiment is individually disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		await disableExperiment( admin, page, EXPERIMENT_LABEL );

		await admin.createNewPost( { title: 'Personas Disabled Test' } );
		await editor.openDocumentSettingsSidebar();

		await expect( page.locator( '.ai-personas-panel' ) ).toHaveCount( 0 );
		await expect(
			page.getByRole( 'button', { name: 'Persona', exact: true } )
		).toHaveCount( 0 );
	} );
} );

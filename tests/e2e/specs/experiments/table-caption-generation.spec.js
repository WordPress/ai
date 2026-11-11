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
	seedCredentials,
} = require( '../../utils/helpers' );

const EXPERIMENT = 'Table Caption Generation';
const ORIGINAL_CAPTION = 'Original hosting plan caption.';
const SUGGESTION =
	'Edit or Delete Your First WordPress Post to Begin Your Blogging Adventure';

/**
 * Creates a saved table and opens its block settings.
 *
 * @param {Object}  admin  The admin fixture.
 * @param {Object}  editor The editor fixture.
 * @param {boolean} empty  Whether to create an empty table.
 */
async function openTable( admin, editor, empty = false ) {
	await admin.createNewPost( { title: 'Table caption generation test' } );
	await editor.insertBlock( {
		name: 'core/paragraph',
		attributes: { content: 'Monthly hosting prices are in US dollars.' },
	} );
	await editor.insertBlock( {
		name: 'core/table',
		attributes: {
			head: empty
				? []
				: [
						{
							cells: [
								{ content: 'Plan', tag: 'th' },
								{ content: 'Price (USD)', tag: 'th' },
							],
						},
				  ],
			body: [
				{
					cells: [
						{ content: empty ? '' : 'Starter', tag: 'td' },
						{ content: empty ? '' : '$10', tag: 'td' },
					],
				},
			],
			caption: ORIGINAL_CAPTION,
		},
	} );
	await editor.saveDraft();
	await editor.openDocumentSettingsSidebar();
	const table = editor.canvas.locator( '[data-type="core/table"]' );
	await editor.selectBlocks( table );
	return table;
}

test.describe( 'Table Caption Generation Experiment', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await seedCredentials( requestUtils );
	} );

	test( 'Can enable the table caption generation experiment', async ( {
		admin,
		page,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT );
	} );

	test( 'Can use the table caption generation experiment', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT );
		const table = await openTable( admin, editor );
		const markup = await table.locator( 'table' ).innerHTML();
		const caption = table.locator( 'figcaption' );
		await page
			.getByRole( 'button', { name: 'Generate caption', exact: true } )
			.click();

		const preview = page.getByRole( 'textbox', {
			name: 'Suggested caption',
		} );
		await expect( preview ).toHaveValue( SUGGESTION );
		await expect( caption ).toHaveText( ORIGINAL_CAPTION );

		const apply = page.getByRole( 'button', {
			name: 'Apply caption',
			exact: true,
		} );
		await preview.fill( '   ' );
		await expect( apply ).toBeDisabled();
		const edited = 'Hosting plans & prices with <basic> storage options.';
		await preview.fill( edited );
		await apply.click();

		await expect( preview ).toHaveCount( 0 );
		await expect( caption ).toHaveText( edited );
		await expect( table.locator( 'table' ) ).toHaveJSProperty(
			'innerHTML',
			markup
		);
		await editor.saveDraft();
		await page.reload();
		await expect( caption ).toHaveText( edited );
		await expect( table.locator( 'table' ) ).toHaveJSProperty(
			'innerHTML',
			markup
		);
	} );

	test( 'Shows an error for an empty table without replacing its caption', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiment( admin, page, EXPERIMENT );
		const table = await openTable( admin, editor, true );
		await page
			.getByRole( 'button', { name: 'Generate caption', exact: true } )
			.click();
		await expect(
			page
				.getByRole( 'tabpanel', { name: 'Settings', exact: true } )
				.getByText(
					'Add text to the table before generating a caption.',
					{ exact: true }
				)
		).toBeVisible();
		await expect(
			page.getByRole( 'textbox', { name: 'Suggested caption' } )
		).toHaveCount( 0 );
		await expect( table.locator( 'figcaption' ) ).toHaveText(
			ORIGINAL_CAPTION
		);
		await expect(
			page.getByRole( 'button', {
				name: 'Generate caption',
				exact: true,
			} )
		).toBeEnabled();
	} );

	test( 'Hides the controls when the experiment is disabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		await disableExperiment( admin, page, EXPERIMENT );
		await openTable( admin, editor );
		await expect(
			page.getByRole( 'button', {
				name: 'AI table caption',
				exact: true,
			} )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'button', {
				name: 'Generate caption',
				exact: true,
			} )
		).toHaveCount( 0 );
	} );
} );

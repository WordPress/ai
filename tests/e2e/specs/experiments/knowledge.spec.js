/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	enableExperiment,
	enableExperiments,
} = require( '../../utils/helpers' );

const EXPERIMENT_LABEL = 'Knowledge and Guidelines';
const SETTINGS_PAGE_PATH = 'options-general.php';
const GUIDELINES_PAGE_QUERY = 'page=guidelines-wp-admin';
const KNOWLEDGE_REST_BASE = '/wp/v2/knowledge';
const SCOPES_FILTER_REST_PATH = '/ai-e2e/v1/guideline-scopes-filter';
const SEED_BLOCKS = [
	'core/paragraph',
	'core/heading',
	'core/code',
	'core/list',
];

// Wait for the Guidelines app to mount and finish its first data load. The app
// boots asynchronously after the admin page itself has loaded.
async function waitForGuidelinesApp( page ) {
	await expect( page.locator( '.guidelines__content' ) ).toBeVisible();
	await expect( getSectionCard( page, 'copy' ) ).toBeVisible();
}

async function visitGuidelinesPage( page, admin ) {
	await admin.visitAdminPage( SETTINGS_PAGE_PATH, GUIDELINES_PAGE_QUERY );
	await waitForGuidelinesApp( page );
}

function getSectionCard( page, slug ) {
	return page.locator( `.guidelines__list-item[data-slug="${ slug }"]` );
}

// Expand a section accordion, fill its textarea, then click Save and wait for
// the success snackbar.
async function saveSectionGuidelines( page, slug, title, text ) {
	const card = getSectionCard( page, slug );

	const trigger = card.getByRole( 'button', { name: title, exact: true } );
	if ( ( await trigger.getAttribute( 'aria-expanded' ) ) !== 'true' ) {
		await trigger.click();
	}

	const textarea = card.getByRole( 'textbox', {
		name: `${ title } guidelines`,
	} );
	await expect( textarea ).toBeVisible();
	await textarea.fill( text );

	await card.getByRole( 'button', { name: 'Save', exact: true } ).click();

	await expect(
		page
			.getByTestId( 'snackbar' )
			.filter( { hasText: 'Guidelines saved.' } )
	).toBeVisible();
}

// Expand a section and return its textarea.
async function openSectionTextarea( page, slug, title ) {
	const card = getSectionCard( page, slug );
	await card.getByRole( 'button', { name: title, exact: true } ).click();
	return card.getByRole( 'textbox', { name: `${ title } guidelines` } );
}

function blockGuidelineSlug( blockName ) {
	return `guideline-block-${ blockName.replace( '/', '_' ) }`;
}

async function seedBlockGuidelines( requestUtils, blockNames ) {
	for ( const name of blockNames ) {
		await requestUtils.rest( {
			path: KNOWLEDGE_REST_BASE,
			method: 'POST',
			data: {
				slug: blockGuidelineSlug( name ),
				title: name,
				content: `Guidance for ${ name }.`,
				status: 'publish',
			},
		} );
	}
}

async function clearKnowledgeRows( requestUtils ) {
	const rows = await requestUtils.rest( {
		path: KNOWLEDGE_REST_BASE,
		params: {
			context: 'edit',
			per_page: 100,
			status: 'any',
		},
	} );

	for ( const row of rows ) {
		await requestUtils.rest( {
			path: `${ KNOWLEDGE_REST_BASE }/${ row.id }`,
			method: 'DELETE',
			params: { force: true },
		} );
	}
}

async function openBlocksSection( page, count ) {
	const blocksCard = getSectionCard( page, 'blocks' );
	await blocksCard
		.getByRole( 'button', { name: 'Blocks', exact: true } )
		.click();
	await expect( blocksCard.getByRole( 'row' ) ).toHaveCount( count );
	return blocksCard;
}

function blockRowLabels( blocksCard ) {
	return blocksCard
		.locator( '.dataviews-view-list__title-field' )
		.allInnerTexts();
}

function blockRowItem( blocksCard, label ) {
	return blocksCard
		.getByRole( 'row' )
		.filter( { hasText: label } )
		.locator( '.dataviews-view-list__item' );
}

async function removeBlockRow( page, blocksCard, label ) {
	await blocksCard
		.getByRole( 'row' )
		.filter( { hasText: label } )
		.getByRole( 'button', { name: 'Actions' } )
		.click();
	await page.getByRole( 'menuitem', { name: 'Remove' } ).click();
	await page
		.getByRole( 'dialog', { name: 'Remove block guideline' } )
		.getByRole( 'button', { name: 'Remove', exact: true } )
		.click();
}

test.describe( 'Knowledge and Guidelines', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
		await clearKnowledgeRows( requestUtils );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await clearKnowledgeRows( requestUtils );
	} );

	test( 'shows a Guidelines link in the Settings menu', async ( {
		page,
		admin,
	} ) => {
		await admin.visitAdminPage( SETTINGS_PAGE_PATH );

		const guidelinesLink = page
			.getByRole( 'navigation', { name: 'Main menu' } )
			.getByRole( 'link', { name: 'Guidelines' } );
		await expect( guidelinesLink ).toBeVisible();
		await expect( guidelinesLink ).toHaveAttribute(
			'href',
			`${ SETTINGS_PAGE_PATH }?${ GUIDELINES_PAGE_QUERY }`
		);
	} );

	test( 'renders the registry sections and the import and export actions', async ( {
		page,
		admin,
	} ) => {
		await visitGuidelinesPage( page, admin );

		// Sections come from the wp_guideline_scopes registry, including the
		// Blocks scope the client renders as the per-block section.
		await expect( getSectionCard( page, 'site' ) ).toBeVisible();
		await expect( getSectionCard( page, 'copy' ) ).toBeVisible();
		await expect( getSectionCard( page, 'images' ) ).toBeVisible();
		await expect( getSectionCard( page, 'blocks' ) ).toBeVisible();
		await expect( getSectionCard( page, 'additional' ) ).toBeVisible();

		// The Actions card offers Import and Export, but no revision history.
		await expect(
			page.getByRole( 'button', { name: 'Upload guidelines' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Download guidelines' } )
		).toBeVisible();
		await expect( page.getByText( 'Revert' ) ).toHaveCount( 0 );
		await expect(
			page.getByRole( 'button', { name: 'View history' } )
		).toHaveCount( 0 );
	} );

	test( 'uses the correct heading hierarchy and accessible control labels', async ( {
		page,
		admin,
	} ) => {
		await visitGuidelinesPage( page, admin );

		await expect(
			page.getByRole( 'heading', { name: 'Actions', level: 2 } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Actions', level: 3 } )
		).toHaveCount( 0 );

		const copyCard = getSectionCard( page, 'copy' );
		const textarea = await openSectionTextarea( page, 'copy', 'Copy' );
		await expect( textarea ).toBeVisible();

		const label = copyCard.locator( 'label', {
			hasText: 'Copy guidelines',
		} );
		await expect( label ).toHaveText( 'Copy guidelines' );
		await expect( label ).not.toHaveAttribute( 'data-visually-hidden' );
		await expect(
			copyCard.getByRole( 'button', { name: 'Clear', exact: true } )
		).toBeVisible();
		await expect(
			copyCard.getByRole( 'button', { name: 'Save', exact: true } )
		).toBeVisible();
	} );

	test( 'persists Copy and Images guidelines across a refresh', async ( {
		page,
		admin,
	} ) => {
		const copyText = 'Use plain, active language.';
		const imagesText = 'Always include descriptive alt text.';

		await visitGuidelinesPage( page, admin );

		await saveSectionGuidelines( page, 'copy', 'Copy', copyText );
		await saveSectionGuidelines( page, 'images', 'Images', imagesText );

		await page.reload();
		await waitForGuidelinesApp( page );

		// Reading back from the UI verifies the full round trip: a per-scope
		// wp_knowledge row was created, the REST collection served it, and
		// core-data hydrated the form.
		await expect(
			await openSectionTextarea( page, 'copy', 'Copy' )
		).toHaveValue( copyText );
		await expect(
			await openSectionTextarea( page, 'images', 'Images' )
		).toHaveValue( imagesText );
	} );

	test( 'edits a scope guideline after a reload', async ( {
		page,
		admin,
	} ) => {
		await visitGuidelinesPage( page, admin );

		await saveSectionGuidelines( page, 'copy', 'Copy', 'First version.' );

		// After a reload the row is only known from the collection request.
		// Editing it must still work.
		await page.reload();
		await waitForGuidelinesApp( page );

		await saveSectionGuidelines( page, 'copy', 'Copy', 'Second version.' );

		await page.reload();
		await waitForGuidelinesApp( page );
		await expect(
			await openSectionTextarea( page, 'copy', 'Copy' )
		).toHaveValue( 'Second version.' );
	} );

	test( 'reclaims an existing non-public row on save instead of duplicating', async ( {
		page,
		admin,
		requestUtils,
	} ) => {
		// Seed a private row that already owns the canonical slug. The page
		// reads only published rows, so the Copy section starts empty.
		await requestUtils.rest( {
			path: KNOWLEDGE_REST_BASE,
			method: 'POST',
			data: {
				slug: 'guideline-copy',
				content: 'Old private guidance.',
				status: 'private',
			},
		} );

		await visitGuidelinesPage( page, admin );
		await expect(
			await openSectionTextarea( page, 'copy', 'Copy' )
		).toHaveValue( '' );

		// Saving reclaims the private row instead of creating a second one.
		await saveSectionGuidelines( page, 'copy', 'Copy', 'New guidance.' );

		const rows = await requestUtils.rest( {
			path: KNOWLEDGE_REST_BASE,
			params: {
				slug: 'guideline-copy',
				status: 'any',
				context: 'edit',
				per_page: 100,
			},
		} );
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ].status ).toBe( 'publish' );
		expect( rows[ 0 ].content.raw ).toBe( 'New guidance.' );
	} );

	test( 'clears a scope guideline', async ( { page, admin } ) => {
		await visitGuidelinesPage( page, admin );

		await saveSectionGuidelines(
			page,
			'copy',
			'Copy',
			'Temporary copy guidance.'
		);

		await getSectionCard( page, 'copy' )
			.getByRole( 'button', { name: 'Clear', exact: true } )
			.click();
		await page
			.getByRole( 'dialog', { name: 'Clear Copy guideline' } )
			.getByRole( 'button', { name: 'Clear', exact: true } )
			.click();

		await expect(
			page
				.getByTestId( 'snackbar' )
				.filter( { hasText: 'Guidelines cleared.' } )
		).toBeVisible();

		await page.reload();
		await waitForGuidelinesApp( page );
		await expect(
			await openSectionTextarea( page, 'copy', 'Copy' )
		).toHaveValue( '' );
	} );

	test( 'adds a block guideline', async ( { page, admin } ) => {
		await visitGuidelinesPage( page, admin );

		const blocksCard = await openBlocksSection( page, 0 );
		await blocksCard
			.getByRole( 'button', { name: 'Add', exact: true } )
			.click();

		const dialog = page.getByRole( 'dialog', { name: 'Add guideline' } );
		await expect( dialog ).toBeVisible();

		const combobox = dialog.getByRole( 'combobox', { name: 'Block' } );
		await combobox.click();
		await combobox.fill( 'Paragraph' );
		await page
			.getByRole( 'option', { name: 'Paragraph', exact: true } )
			.click();

		await dialog
			.getByRole( 'textbox', { name: 'Guideline text' } )
			.fill( 'Keep paragraphs short.' );
		await dialog.getByRole( 'button', { name: 'Save' } ).click();

		await expect(
			page
				.getByTestId( 'snackbar' )
				.filter( { hasText: 'Guideline saved.' } )
		).toBeVisible();
		await expect( blocksCard.getByText( 'Paragraph' ) ).toBeVisible();
	} );

	test( 'exports and re-imports guidelines', async ( {
		page,
		admin,
		requestUtils,
	} ) => {
		const copyText = 'Round-trip copy guidance.';

		await visitGuidelinesPage( page, admin );
		await saveSectionGuidelines( page, 'copy', 'Copy', copyText );

		const downloadPromise = page.waitForEvent( 'download' );
		await page
			.getByRole( 'button', { name: 'Download guidelines' } )
			.click();
		const download = await downloadPromise;
		const exportPath = await download.path();

		// Wipe everything, then import the file back.
		await clearKnowledgeRows( requestUtils );
		await page.reload();
		await waitForGuidelinesApp( page );

		const fileChooserPromise = page.waitForEvent( 'filechooser' );
		await page.getByRole( 'button', { name: 'Upload guidelines' } ).click();
		const fileChooser = await fileChooserPromise;
		await fileChooser.setFiles( exportPath );

		await page
			.getByRole( 'dialog', { name: 'Import guidelines' } )
			.getByRole( 'button', { name: 'Continue' } )
			.click();

		await expect(
			page
				.getByTestId( 'snackbar' )
				.filter( { hasText: 'Guidelines imported.' } )
		).toBeVisible();

		await expect(
			await openSectionTextarea( page, 'copy', 'Copy' )
		).toHaveValue( copyText );
	} );

	test.describe( 'with the scopes registry filtered by a plugin', () => {
		// The E2E Testing plugin filters wp_guideline_scopes while this flag
		// is on: it adds a custom scope and removes the built-in `blocks`
		// scope. Sections are registry-driven, so the page must grow the
		// custom section and drop the Blocks section.
		test.beforeEach( async ( { requestUtils } ) => {
			await requestUtils.rest( {
				path: SCOPES_FILTER_REST_PATH,
				method: 'POST',
				data: { enabled: true },
			} );
		} );

		test.afterEach( async ( { requestUtils } ) => {
			await requestUtils.rest( {
				path: SCOPES_FILTER_REST_PATH,
				method: 'POST',
				data: { enabled: false },
			} );
		} );

		test( 'renders a custom scope and hides the removed Blocks section', async ( {
			page,
			admin,
		} ) => {
			await visitGuidelinesPage( page, admin );

			await expect( getSectionCard( page, 'site' ) ).toBeVisible();
			await expect( getSectionCard( page, 'additional' ) ).toBeVisible();

			const customCard = getSectionCard( page, 'e2e-custom' );
			await expect( customCard ).toBeVisible();
			await expect(
				await openSectionTextarea( page, 'e2e-custom', 'E2E Custom' )
			).toBeVisible();

			await expect( getSectionCard( page, 'blocks' ) ).toHaveCount( 0 );
		} );
	} );

	test.describe( 'block guideline removal focus', () => {
		test( 'moves focus to Add after removing a block guideline', async ( {
			page,
			admin,
			requestUtils,
		} ) => {
			await seedBlockGuidelines( requestUtils, SEED_BLOCKS );
			await visitGuidelinesPage( page, admin );
			const blocksCard = await openBlocksSection(
				page,
				SEED_BLOCKS.length
			);
			const labels = await blockRowLabels( blocksCard );

			await removeBlockRow( page, blocksCard, labels[ 1 ] );

			await expect(
				page
					.getByTestId( 'snackbar' )
					.filter( { hasText: 'Guideline removed.' } )
			).toBeVisible();
			await expect(
				blocksCard.getByRole( 'button', { name: 'Add', exact: true } )
			).toBeFocused();
		} );

		test( 'keeps the row focused when removal fails', async ( {
			page,
			admin,
			requestUtils,
		} ) => {
			await seedBlockGuidelines( requestUtils, [
				'core/paragraph',
				'core/heading',
			] );
			await visitGuidelinesPage( page, admin );
			const blocksCard = await openBlocksSection( page, 2 );
			const [ target ] = await blockRowLabels( blocksCard );

			await page.route(
				( url ) =>
					(
						url.searchParams.get( 'rest_route' ) ?? url.pathname
					).includes( '/wp/v2/knowledge/' ),
				async ( route ) => {
					const request = route.request();
					const isDelete =
						request.method() === 'DELETE' ||
						request.headers()[ 'x-http-method-override' ] ===
							'DELETE';
					if ( isDelete ) {
						await route.fulfill( {
							status: 500,
							contentType: 'application/json',
							body: JSON.stringify( {
								code: 'rest_cannot_delete',
								message: 'Deletion failed.',
							} ),
						} );
						return;
					}
					await route.continue();
				}
			);

			await removeBlockRow( page, blocksCard, target );

			await expect( blocksCard.getByText( /Error:/ ) ).toBeVisible();
			await expect( blockRowItem( blocksCard, target ) ).toBeVisible();
			await expect(
				blocksCard
					.getByRole( 'row' )
					.filter( { hasText: target } )
					.getByRole( 'button', { name: 'Actions' } )
			).toBeFocused();
			await expect(
				blocksCard.getByRole( 'button', { name: 'Add', exact: true } )
			).not.toBeFocused();
		} );

		test( 'moves focus to Add after removal from the edit modal', async ( {
			page,
			admin,
			requestUtils,
		} ) => {
			await seedBlockGuidelines( requestUtils, SEED_BLOCKS );
			await visitGuidelinesPage( page, admin );
			const blocksCard = await openBlocksSection(
				page,
				SEED_BLOCKS.length
			);
			const [ removed ] = await blockRowLabels( blocksCard );

			await blocksCard
				.getByRole( 'row' )
				.filter( { hasText: removed } )
				.getByRole( 'button', { name: 'Actions' } )
				.click();
			await page.getByRole( 'menuitem', { name: 'Edit' } ).click();
			await page
				.getByRole( 'dialog', { name: 'Edit guideline' } )
				.getByRole( 'button', { name: 'Remove' } )
				.click();
			await page
				.getByRole( 'dialog', { name: 'Remove block guideline' } )
				.getByRole( 'button', { name: 'Remove', exact: true } )
				.click();

			await expect(
				page
					.getByTestId( 'snackbar' )
					.filter( { hasText: 'Guideline removed.' } )
			).toBeVisible();
			await expect(
				blocksCard.getByRole( 'button', { name: 'Add', exact: true } )
			).toBeFocused();
		} );
	} );
} );

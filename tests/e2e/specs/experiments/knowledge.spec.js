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
const KNOWLEDGE_REST_BASE = '/wp/v2/knowledge';
const SEED_BLOCKS = [
	'core/paragraph',
	'core/heading',
	'core/code',
	'core/list',
];

async function visitGuidelinesPage( page, admin ) {
	await admin.visitAdminPage(
		'options-general.php',
		'page=guidelines-wp-admin'
	);
	await expect( page.locator( '.guidelines__content' ) ).toBeVisible();
}

function getSectionCard( page, slug ) {
	return page.locator( `.guidelines__list-item[data-slug="${ slug }"]` );
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

test.describe( 'Knowledge and Guidelines accessibility', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
		await clearKnowledgeRows( requestUtils );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await clearKnowledgeRows( requestUtils );
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
		await expect(
			page.getByRole( 'button', { name: 'Upload guidelines' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Download guidelines' } )
		).toBeVisible();

		const copyCard = getSectionCard( page, 'copy' );
		await copyCard
			.getByRole( 'button', { name: 'Copy', exact: true } )
			.click();

		const textarea = copyCard.getByRole( 'textbox', {
			name: 'Copy guidelines',
		} );
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

	test( 'moves focus to Add after removing a block guideline', async ( {
		page,
		admin,
		requestUtils,
	} ) => {
		await seedBlockGuidelines( requestUtils, SEED_BLOCKS );
		await visitGuidelinesPage( page, admin );
		const blocksCard = await openBlocksSection( page, SEED_BLOCKS.length );
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
					request.headers()[ 'x-http-method-override' ] === 'DELETE';
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
		const blocksCard = await openBlocksSection( page, SEED_BLOCKS.length );
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

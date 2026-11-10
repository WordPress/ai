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

test.describe( 'Topic Pages Experiment', () => {
	let category;
	let posts;
	let stylesheet;

	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await seedCredentials( requestUtils );
		await disableExperiment( admin, page, 'Connector Approval' );
		const themes = await requestUtils.rest( {
			path: '/wp/v2/themes',
			params: { status: 'active' },
		} );
		stylesheet = themes[ 0 ].stylesheet;
		await requestUtils.activateTheme( 'twentytwentyfive' );
		await enableExperiment( admin, page, 'Topic Pages' );
		category = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/categories',
			data: { name: 'Community garden' },
		} );
		posts = [];
		for ( let index = 1; index <= 5; index++ ) {
			posts.push(
				await requestUtils.createPost( {
					title: `Garden report ${ index }`,
					content:
						'Residents prepare shared beds, plant vegetables, and organise a watering rota for the neighbourhood garden.',
					status: 'publish',
					categories: [ category.id ],
					date: `2025-01-0${ index }T10:00:00`,
				} )
			);
		}
	} );

	test.afterEach( async ( { requestUtils } ) => {
		for ( const post of posts ?? [] ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/posts/${ post.id }`,
				params: { force: true },
			} );
		}
		if ( category ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/categories/${ category.id }`,
				params: { force: true },
			} );
		}
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: { 'wpai_feature_topic-pages_enabled': false },
		} );
		if ( stylesheet ) {
			await requestUtils.activateTheme( stylesheet );
		}
	} );

	test( 'Can enable and disable category controls and archive blocks', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'term.php',
			`taxonomy=category&tag_ID=${ category.id }`
		);
		await expect(
			page.getByRole( 'button', {
				name: 'Generate Summary',
				exact: true,
			} )
		).toBeVisible();
		await page.goto( category.link );
		await expect( page.locator( '.wpai-topic-events a' ) ).toHaveCount( 5 );
		await disableExperiment( admin, page, 'Topic Pages' );
		await admin.visitAdminPage(
			'term.php',
			`taxonomy=category&tag_ID=${ category.id }`
		);
		await expect( page.locator( '#ai-topic-review' ) ).toHaveCount( 0 );
		await page.goto( category.link );
		await expect( page.locator( '.wpai-topic-block' ) ).toHaveCount( 0 );
	} );

	test( 'Previews stay private until Save and Discard keeps published text', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage(
			'term.php',
			`taxonomy=category&tag_ID=${ category.id }`
		);
		const summary = page.getByRole( 'region', {
			name: 'Summary',
			exact: true,
		} );
		const overview = page.getByRole( 'region', {
			name: 'Narrative overview',
			exact: true,
		} );
		const archive = await page.context().newPage();
		await archive.goto( category.link );
		await expect( archive.locator( '.wpai-topic-summary' ) ).toHaveCount(
			0
		);
		await summary
			.getByRole( 'button', { name: 'Generate Summary', exact: true } )
			.click();
		await expect(
			summary.getByText( 'Unsaved preview', { exact: true } )
		).toBeVisible();
		await expect(
			summary.getByRole( 'button', {
				name: 'Regenerate Summary',
				exact: true,
			} )
		).toHaveText( 'Regenerate' );
		await summary
			.getByRole( 'button', {
				name: 'Discard Summary preview',
				exact: true,
			} )
			.click();
		await expect(
			summary.getByRole( 'button', {
				name: 'Generate Summary',
				exact: true,
			} )
		).toHaveText( 'Generate' );
		await summary
			.getByRole( 'button', { name: 'Generate Summary', exact: true } )
			.click();
		await expect(
			summary.getByText( 'Unsaved preview', { exact: true } )
		).toBeVisible();
		await archive.reload();
		await expect( archive.locator( '.wpai-topic-summary' ) ).toHaveCount(
			0
		);
		await summary
			.getByRole( 'button', { name: 'Save Summary', exact: true } )
			.click();
		await expect(
			summary.getByText( 'Published', { exact: true } )
		).toBeVisible();
		await archive.reload();
		await expect( archive.locator( '.wpai-topic-summary' ) ).toContainText(
			'Garden report 5'
		);
		await expect( archive.locator( '.wpai-topic-summary a' ) ).toHaveCount(
			0
		);

		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ posts[ 4 ].id }`,
			data: { title: 'Updated harvest report' },
		} );
		await summary
			.getByRole( 'button', { name: 'Regenerate Summary', exact: true } )
			.click();
		await expect(
			summary.locator( '.ai-topic-review__preview' ).first()
		).toContainText( 'Updated harvest report' );
		await summary
			.getByRole( 'button', {
				name: 'Discard Summary preview',
				exact: true,
			} )
			.click();
		await expect(
			summary.getByText( 'Unsaved preview', { exact: true } )
		).toHaveCount( 0 );
		await archive.reload();
		await expect( archive.locator( '.wpai-topic-summary' ) ).toContainText(
			'Garden report 5'
		);
		await expect(
			archive.locator( '.wpai-topic-summary' )
		).not.toContainText( 'Updated harvest report' );

		await overview
			.getByRole( 'button', {
				name: 'Generate Narrative overview',
				exact: true,
			} )
			.click();
		await expect(
			overview.getByText( 'Unsaved preview', { exact: true } )
		).toBeVisible();
		await overview
			.getByRole( 'button', {
				name: 'Save Narrative overview',
				exact: true,
			} )
			.click();
		await expect(
			overview.getByText( 'Published', { exact: true } )
		).toBeVisible();
		await archive.reload();
		const paragraphs = archive.locator(
			'.wpai-topic-overview .wpai-topic-content > p:not(.wpai-topic-note)'
		);
		await expect( paragraphs ).toHaveCount( 2 );
		await expect( archive.locator( '.wpai-topic-overview a' ) ).toHaveCount(
			2
		);
		const summaryText = archive.locator(
			'.wpai-topic-summary .wpai-topic-content p'
		);
		await expect( summaryText ).toHaveCSS( 'font-style', 'normal' );
		await expect( summaryText ).toHaveCSS(
			'font-size',
			await paragraphs
				.first()
				.evaluate(
					( element ) => window.getComputedStyle( element ).fontSize
				)
		);
	} );

	test( 'Requires five public posts and keeps timeline sampling separate from archive pagination', async ( {
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ posts[ 4 ].id }`,
			data: { status: 'draft' },
		} );
		await page.goto( category.link );
		await expect( page.locator( '.wpai-topic-block' ) ).toHaveCount( 0 );
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ posts[ 4 ].id }`,
			data: { status: 'publish' },
		} );
		await page.reload();
		await expect( page.locator( '.wpai-topic-events a' ) ).toHaveCount( 5 );
		for ( let index = 6; index <= 12; index++ ) {
			posts.push(
				await requestUtils.createPost( {
					title: `Garden report ${ index }`,
					content:
						'Volunteers record the next stage of the garden project.',
					status: 'publish',
					categories: [ category.id ],
					date: `2025-02-${ String( index ).padStart(
						2,
						'0'
					) }T10:00:00`,
				} )
			);
		}
		await page.reload();
		await expect( page.locator( '.wpai-topic-events a' ) ).toHaveCount(
			10
		);
		await page.locator( '.wp-block-query-pagination-next' ).click();
		await expect( page.locator( '.wpai-topic-events a' ) ).toHaveCount(
			10
		);
		await expect(
			page.locator( '.wp-block-post-template > li' )
		).toHaveCount( 2 );
	} );
} );

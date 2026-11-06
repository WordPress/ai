/**
 * End-to-end coverage for the AI Workspace starter prompts.
 *
 * Each of the four suggestions on the empty state is sent as it is worded, and
 * each must end in something the person can act on: choices, a filtered list
 * with choices, drafts to approve or a template to approve. The model's tool
 * calls are scripted by fixtures, so these prove the wiring and the wording,
 * not what a live model would choose to do.
 */
/**
 * External dependencies
 */
import { readFileSync } from 'fs';
import { join } from 'path';

/**
 * WordPress dependencies
 */
import {
	test,
	expect,
	type Admin,
	type RequestUtils,
} from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';

/**
 * Internal dependencies
 */
import {
	activateMockScenario,
	deactivateMockScenario,
	disableExperiment,
	enableExperiment,
	getMockScenarioReport,
	seedCredentials,
} from '../../utils/helpers';

const EXPERIMENT_LABEL = 'AI Workspace';
const WORKSPACE_PAGE = 'tools.php?page=ai-workspace';
const SCENARIOS = join(
	__dirname,
	'../../../e2e-testing/responses/Anthropic/scenarios'
);

/**
 * What each choice the demo fixtures offer leads to.
 *
 * A choice the workspace cannot carry out would end a demo on a dead end, so
 * every choice must map to a read tool or a registered proposal kind.
 */
const CHOICE_TOOLS: Record< string, string > = {
	'Draft topic 1': 'ai/propose-drafts',
	'Draft topic 2': 'ai/propose-drafts',
	'Draft topic 3': 'ai/propose-drafts',
	'Suggest three more': 'ai/search-content',
	'Narrow the filter': 'ai/search-content',
	'Show 2022 as well': 'ai/search-content',
};

/**
 * The prompt each suggestion fills the composer with.
 *
 * Pinned so the wording keeps asking only for what the tools can deliver.
 */
const PROMPTS: Record< string, string > = {
	'Find a gap':
		'Look at my 10 most recent posts and the topics I write about most. Suggest three related topics I have not covered yet.',
	'Audit content':
		'List the posts tagged Updates from 2023 that have no featured image.',
	'Plan a series':
		'Plan a 5-part series on remote work, with a title and short excerpt for each, as drafts I can review.',
	'Write a template':
		'Create a 404 page template that helps visitors find my most popular categories.',
};

const DEMO_SCENARIOS = [
	'workspace-find-gap',
	'workspace-audit',
	'workspace-plan-series',
	'workspace-demo-template',
];

/**
 * Sends one starter prompt, as worded, from the empty state.
 *
 * @param admin    The admin fixture.
 * @param page     The page fixture.
 * @param label    The suggestion's label.
 * @param scenario The fixture that scripts the model.
 * @param utils    The requestUtils fixture.
 */
async function sendSuggestion(
	admin: Admin,
	page: Page,
	label: string,
	scenario: string,
	utils: RequestUtils
) {
	await activateMockScenario( utils, scenario );
	await admin.visitAdminPage( WORKSPACE_PAGE );

	await page
		.getByRole( 'button', { name: new RegExp( `^${ label }` ) } )
		.click();
	await expect( page.getByLabel( 'Message', { exact: true } ) ).toHaveValue(
		PROMPTS[ label ]
	);
	await page.getByRole( 'button', { name: 'Send' } ).click();
}

/**
 * Returns the abilities a turn's tool steps list, in the order they ran.
 *
 * @param page The page fixture.
 * @return The ability names.
 */
async function toolOrder( page: Page ): Promise< string[] > {
	const tools = page
		.locator( '.ai-workspace__turn' )
		.first()
		.locator( '.ai-workspace__tools' );

	await tools.locator( 'summary' ).click();

	return tools.locator( 'li > code' ).allTextContents();
}

test.describe( 'AI Workspace starter prompts', () => {
	test.beforeEach( async ( { admin, page, requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await seedCredentials( requestUtils, [ 'anthropic' ] );
		await enableExperiment( admin, page, EXPERIMENT_LABEL );
	} );

	test.afterEach( async ( { admin, page, requestUtils } ) => {
		await deactivateMockScenario( requestUtils );
		await disableExperiment( admin, page, EXPERIMENT_LABEL );
		await seedCredentials( requestUtils, [ 'openai' ] );
		await requestUtils.deleteAllPosts();
	} );

	test( 'the empty state offers exactly four suggestions', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( WORKSPACE_PAGE );

		await expect( page.locator( '.ai-workspace__suggestion' ) ).toHaveCount(
			4
		);
	} );

	test( 'Find a gap reads recent posts and topics, then offers to draft one', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await sendSuggestion(
			admin,
			page,
			'Find a gap',
			'workspace-find-gap',
			requestUtils
		);

		const question = page.getByRole( 'group', {
			name: 'Want me to draft one of these?',
		} );
		await expect( question ).toBeVisible( { timeout: 30000 } );
		await expect(
			question.getByRole( 'button', { name: 'Draft topic 1' } )
		).toBeVisible();

		await expect(
			page.locator( '.ai-workspace__turn' ).first()
		).toContainText( 'Three related topics you have not covered yet' );
		expect( await toolOrder( page ) ).toEqual( [
			'ai/search-content',
			'ai/list-terms',
		] );
		expect(
			( await getMockScenarioReport( requestUtils ) ).calls[
				'Anthropic/workspace-find-gap'
			]
		).toBe( 2 );
	} );

	test( 'Audit content lists exactly the matching posts, then offers next steps', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const tag = ( await requestUtils.rest( {
			path: '/wp/v2/tags',
			method: 'POST',
			data: { name: 'Updates', slug: 'updates' },
		} ) ) as { id: number };

		const post = ( title: string, date: string, tags: number[] ) =>
			requestUtils.createPost( {
				title,
				status: 'publish',
				date,
				tags,
			} as Parameters< RequestUtils[ 'createPost' ] >[ 0 ] );

		await post( 'Spring release notes', '2023-04-10T10:00:00', [ tag.id ] );
		await post( 'Autumn release notes', '2023-10-02T10:00:00', [ tag.id ] );
		await post( 'Release notes from 2024', '2024-03-01T10:00:00', [
			tag.id,
		] );
		await post( 'An untagged 2023 post', '2023-06-01T10:00:00', [] );

		try {
			await sendSuggestion(
				admin,
				page,
				'Audit content',
				'workspace-audit',
				requestUtils
			);

			const question = page.getByRole( 'group', { name: 'What next?' } );
			await expect( question ).toBeVisible( { timeout: 30000 } );
			await expect(
				question.getByRole( 'button', { name: 'Narrow the filter' } )
			).toBeVisible();

			expect( await toolOrder( page ) ).toEqual( [
				'ai/search-content',
			] );

			const table = page.locator( '.ai-workspace__results' );
			await expect( table ).toContainText( 'Spring release notes' );
			await expect( table ).toContainText( 'Autumn release notes' );
			await expect( table ).not.toContainText(
				'Release notes from 2024'
			);
			await expect( table ).not.toContainText( 'An untagged 2023 post' );
		} finally {
			await requestUtils.rest( {
				path: `/wp/v2/tags/${ tag.id }`,
				method: 'DELETE',
				params: { force: true },
			} );
		}
	} );

	test( 'Plan a series ends with five drafts to review', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await sendSuggestion(
			admin,
			page,
			'Plan a series',
			'workspace-plan-series',
			requestUtils
		);

		const card = page.getByRole( 'region', { name: 'Create 5 drafts' } );
		await expect( card ).toBeVisible( { timeout: 30000 } );
		await expect( card.getByRole( 'checkbox' ) ).toHaveCount( 5 );

		expect( await toolOrder( page ) ).toEqual( [ 'ai/propose-drafts' ] );
		expect(
			( await getMockScenarioReport( requestUtils ) ).calls[
				'Anthropic/workspace-plan-series'
			]
		).toBe( 1 );
	} );

	test( 'Write a template reads the categories, then proposes the template', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.activateTheme( 'twentytwentyfive' );
		await requestUtils.deleteAllTemplates( 'wp_template' );

		try {
			await sendSuggestion(
				admin,
				page,
				'Write a template',
				'workspace-demo-template',
				requestUtils
			);

			const card = page.getByRole( 'region', {
				name: 'Create template: Page not found',
			} );
			await expect( card ).toBeVisible( { timeout: 30000 } );
			await expect( card.locator( 'code' ) ).toContainText(
				'wp:categories'
			);

			expect( await toolOrder( page ) ).toEqual( [
				'ai/list-terms',
				'ai/propose-template',
			] );
		} finally {
			await requestUtils.deleteAllTemplates( 'wp_template' );
			await requestUtils.activateTheme( 'twentytwentyone' );
		}
	} );
} );

test.describe( 'AI Workspace starter prompt fixtures', () => {
	test( 'every choice a demo offers leads somewhere the workspace can go', () => {
		for ( const scenario of DEMO_SCENARIOS ) {
			const fixture = JSON.parse(
				readFileSync( join( SCENARIOS, `${ scenario }.json` ), 'utf8' )
			) as {
				sequence: Array< {
					content: Array< {
						name?: string;
						input?: { choices?: string[] };
					} >;
				} >;
			};

			for ( const entry of fixture.sequence ) {
				for ( const part of entry.content ) {
					if ( 'wpab__ai__ask-user' !== part.name ) {
						continue;
					}

					for ( const choice of part.input?.choices ?? [] ) {
						expect(
							CHOICE_TOOLS[ choice ],
							`${ scenario }: "${ choice }" must map to a tool`
						).toBeDefined();
					}
				}
			}
		}
	} );
} );

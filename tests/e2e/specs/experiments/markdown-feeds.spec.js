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
	visitSettingsPage,
} = require( '../../utils/helpers' );

test.describe( 'Markdown Feeds Experiment', () => {
	let post;

	test.beforeAll( async ( { requestUtils } ) => {
		post = await requestUtils.createPost( {
			title: 'Test Markdown Feeds Experiment',
			content:
				'This is some test content for the Markdown Feeds Experiment.',
			status: 'publish',
		} );
	} );

	test( 'Can enable the markdown feeds experiment', async ( {
		admin,
		page,
	} ) => {
		// Enable the Markdown Feeds Experiment.
		await enableExperiment( admin, page, 'Markdown Feeds' );
	} );

	test( 'Can use the Markdown Feeds Experiment', async ( {
		admin,
		page,
	} ) => {
		// Enable the Markdown Feeds Experiment.
		await enableExperiment( admin, page, 'Markdown Feeds' );

		// Go to the post markdown feed.
		const postResponse = await page.goto(
			`${ post.link }?output_format=markdown`
		);
		const postBody = await postResponse.text();

		// Ensure the post content is a markdown document.
		expect( postBody ).toContain( '# Test Markdown Feeds Experiment' );

		// Go to the main markdown feed.
		const feedResponse = await page.goto( '/feed/markdown' );
		const feedBody = await feedResponse.text();

		// Ensure the feed content is a markdown document.
		expect( feedBody ).toContain( '## Test Markdown Feeds Experiment' );
	} );

	test( 'Ensure the Markdown Feed is not accessible when the experiment is disabled', async ( {
		admin,
		page,
	} ) => {
		// Disable the Markdown Feeds Experiment.
		await disableExperiment( admin, page, 'Markdown Feeds' );

		// Ensure the post feed doesn't return markdown.
		const postResponse = await page.goto(
			`${ post.link }?output_format=markdown`
		);
		const postBody = await postResponse.text();
		expect( postBody ).not.toContain( '# Test Markdown Feeds Experiment' );

		// Ensure the feed returns a 404.
		const response = await page.goto( '/feed/markdown' );
		expect( response.status() ).toBe( 404 );
	} );
} );

test.describe( 'Markdown Feeds conflict notice', () => {
	const notice = /Also active and serving Markdown: Hello Dolly\./;

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deactivatePlugin( 'hello-dolly' );
	} );

	test( 'Does not show a notice when no other Markdown plugin is active', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.deactivatePlugin( 'hello-dolly' );
		await visitSettingsPage( admin );

		const toggle = page.getByRole( 'checkbox', { name: 'Markdown Feeds' } );
		await expect( toggle ).toBeVisible( { timeout: 10000 } );
		await expect( page.getByText( notice ) ).toHaveCount( 0 );
	} );

	test( 'Names another active Markdown plugin', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.activatePlugin( 'hello-dolly' );
		await visitSettingsPage( admin );

		const toggle = page.getByRole( 'checkbox', { name: 'Markdown Feeds' } );
		await expect( toggle ).toBeVisible( { timeout: 10000 } );
		await expect( page.getByText( notice ) ).toBeVisible();
		await expect( toggle ).toHaveAccessibleDescription( notice );
	} );
} );

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	disableExperiments,
	enableExperiment,
	enableExperiments,
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
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Markdown Feeds Experiment.
		await enableExperiment( admin, page, 'Markdown Feeds' );
	} );

	test( 'Can use the Markdown Feeds Experiment', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Enable the Markdown Feeds Experiment.
		await enableExperiment( admin, page, 'Markdown Feeds' );

		// Go to the post markdown feed.
		const postResponse = await page.goto( `${ post.link }?format=md` );
		const postBody = await postResponse.text();

		// Ensure the post content is a markdown document.
		expect( postBody ).toContain( '# Test Markdown Feeds Experiment' );

		// Go to the main markdown feed.
		const feedResponse = await page.goto( '/feed/markdown' );
		const feedBody = await feedResponse.text();

		// Ensure the feed content is a markdown document.
		expect( feedBody ).toContain( '## Test Markdown Feeds Experiment' );
	} );

	test( 'Ensure the markdown feeds are not accessible when Experiments are globally disabled', async ( {
		admin,
		page,
	} ) => {
		// Enable the Markdown Feeds Experiment.
		await enableExperiment( admin, page, 'Markdown Feeds' );

		// Globally turn off Experiments.
		await disableExperiments( admin, page );

		// Ensure the post feed doesn't return markdown.
		const postResponse = await page.goto( `${ post.link }?format=md` );
		const postBody = await postResponse.text();
		expect( postBody ).not.toContain( '# Test Markdown Feeds Experiment' );

		// Ensure the main feed returns a 404.
		const feedResponse = await page.goto( '/feed/markdown' );
		expect( feedResponse.status() ).toBe( 404 );
	} );

	test( 'Ensure the Markdown Feed is not accessible when the experiment is disabled', async ( {
		admin,
		page,
	} ) => {
		// Globally turn on Experiments.
		await enableExperiments( admin, page );

		// Disable the Markdown Feeds Experiment.
		await disableExperiment( admin, page, 'Markdown Feeds' );

		// Ensure the post feed doesn't return markdown.
		const postResponse = await page.goto( `${ post.link }?format=md` );
		const postBody = await postResponse.text();
		expect( postBody ).not.toContain( '# Test Markdown Feeds Experiment' );

		// Ensure the feed returns a 404.
		const response = await page.goto( '/feed/markdown' );
		expect( response.status() ).toBe( 404 );
	} );
} );

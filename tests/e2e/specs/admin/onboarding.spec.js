/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	clearCredentials,
	disableAllFeatures,
	seedCredentials,
	visitSettingsPage,
} = require( '../../utils/helpers' );

/**
 * Puts the Get started guide back to its state on a new install.
 *
 * @param {Object} requestUtils The requestUtils fixture from the test context.
 */
async function resetGuide( requestUtils ) {
	await disableAllFeatures( requestUtils );
	await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/settings',
		data: {
			wpai_onboarding_use_cases: [],
			wpai_onboarding_completed: false,
		},
	} );
	await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/users/me',
		data: { meta: { wpai_onboarding_guide_hidden: false } },
	} );
}

/**
 * Reads the site settings over REST.
 *
 * @param {Object} requestUtils The requestUtils fixture from the test context.
 * @return {Promise<Object>} The site settings.
 */
async function getSettings( requestUtils ) {
	return requestUtils.rest( { method: 'GET', path: '/wp/v2/settings' } );
}

test.describe( 'Get started guide', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await seedCredentials( requestUtils );
		await resetGuide( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await seedCredentials( requestUtils );
		await resetGuide( requestUtils );
	} );

	test( 'Starts by asking for an AI provider', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await clearCredentials( requestUtils );
		await visitSettingsPage( admin );

		const guide = page.locator( '.ai-get-started' );

		await expect(
			guide.getByRole( 'heading', {
				name: 'First stop: connect an AI provider.',
			} )
		).toBeVisible();
		await expect(
			guide.getByRole( 'link', { name: 'Choose a provider' } )
		).toHaveAttribute( 'href', /options-connectors\.php/ );

		// The guide replaces the provider notice instead of doubling it.
		await expect(
			page.getByText(
				'The AI plugin requires a valid AI Connector to function properly'
			)
		).toBeHidden();
	} );

	test( 'Turns on the chosen use cases and leaves automatic add-ons off', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await visitSettingsPage( admin );

		const guide = page.locator( '.ai-get-started' );

		await expect(
			guide.getByRole( 'heading', {
				name: 'What do you want help with?',
			} )
		).toBeVisible();

		// Nothing is chosen for the owner.
		const writeAndPublish = guide.getByRole( 'checkbox', {
			name: 'Write and publish',
		} );
		const typeAhead = guide.getByRole( 'checkbox', {
			name: 'Also suggest text as I type',
		} );
		await expect( writeAndPublish ).not.toBeChecked();
		await expect( typeAhead ).toBeDisabled();

		await writeAndPublish.check();
		await guide
			.getByRole( 'checkbox', { name: 'Comments', exact: true } )
			.check();

		// The add-on can be chosen now, but stays off until it is ticked.
		await expect( typeAhead ).toBeEnabled();
		await expect( typeAhead ).not.toBeChecked();

		await guide
			.getByRole( 'button', { name: 'Turn on 2 use cases' } )
			.click();

		await expect(
			guide.getByRole( 'heading', {
				name: "6 tools are on. Let's try one.",
			} )
		).toBeVisible();
		await expect(
			guide.getByRole( 'link', { name: 'Go to Comments' } )
		).toBeVisible();

		// The snackbar confirms the save has finished.
		await expect(
			page.locator( '.components-snackbar__content', {
				hasText: '6 tools are on.',
			} )
		).toBeVisible();

		const settings = await getSettings( requestUtils );
		expect( settings.wpai_onboarding_use_cases ).toEqual( [
			'write-publish',
			'comments',
		] );
		expect( settings[ 'wpai_feature_title-generation_enabled' ] ).toBe(
			true
		);
		expect( settings[ 'wpai_feature_suggest-reply_enabled' ] ).toBe( true );
		expect( settings[ 'wpai_feature_type-ahead_enabled' ] ).toBeFalsy();
		expect(
			settings[ 'wpai_feature_comment-moderation_enabled' ]
		).toBeFalsy();

		// The full list of settings below reflects the same choices.
		await expect(
			page.getByRole( 'checkbox', { name: 'Title Generation' } )
		).toBeChecked();
	} );

	test( 'Changing use cases turns off only what the guide turned on', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: {
				wpai_onboarding_use_cases: [ 'comments' ],
				'wpai_feature_suggest-reply_enabled': true,
				// Turned on by hand, outside the guide.
				'wpai_feature_content-resizing_enabled': true,
			},
		} );

		await visitSettingsPage( admin );

		const guide = page.locator( '.ai-get-started' );

		await guide.getByRole( 'button', { name: 'Change use cases' } ).click();
		await guide
			.getByRole( 'checkbox', { name: 'Comments', exact: true } )
			.uncheck();
		await guide
			.getByRole( 'checkbox', { name: 'Write and publish' } )
			.check();
		await guide
			.getByRole( 'checkbox', { name: 'Also suggest text as I type' } )
			.check();
		await guide.getByRole( 'button', { name: 'Save use cases' } ).click();

		await expect(
			guide.getByRole( 'heading', {
				name: "6 tools are on. Let's try one.",
			} )
		).toBeVisible();

		// The snackbar confirms the save has finished.
		await expect(
			page.locator( '.components-snackbar__content', {
				hasText: '6 tools are on.',
			} )
		).toBeVisible();

		const settings = await getSettings( requestUtils );
		expect( settings.wpai_onboarding_use_cases ).toEqual( [
			'write-publish',
			'type-ahead',
		] );
		expect( settings[ 'wpai_feature_suggest-reply_enabled' ] ).toBeFalsy();
		expect( settings[ 'wpai_feature_type-ahead_enabled' ] ).toBe( true );
		expect( settings[ 'wpai_feature_content-resizing_enabled' ] ).toBe(
			true
		);
	} );

	test( 'Can be skipped, closed and reopened', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: {
				wpai_onboarding_use_cases: [ 'comments' ],
				'wpai_feature_suggest-reply_enabled': true,
			},
		} );

		await visitSettingsPage( admin );

		const guide = page.locator( '.ai-get-started' );

		await guide.getByRole( 'button', { name: 'Skip' } ).click();
		await expect(
			guide.getByRole( 'heading', { name: "You're set up." } )
		).toBeVisible();

		await guide.getByRole( 'button', { name: 'Close guide' } ).click();
		await expect(
			guide.getByText( "1 tool is on. Wapuu's resting." )
		).toBeVisible();

		// Closing the guide is remembered for this user.
		await page.reload();
		await expect(
			guide.getByText( "1 tool is on. Wapuu's resting." )
		).toBeVisible();

		await guide.getByRole( 'button', { name: 'Use cases' } ).click();
		await expect(
			guide.getByRole( 'checkbox', { name: 'Comments', exact: true } )
		).toBeChecked();
	} );

	test( 'Stays collapsed on a site that already has tools on', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/settings',
			data: { 'wpai_feature_title-generation_enabled': true },
		} );

		await visitSettingsPage( admin );

		const guide = page.locator( '.ai-get-started' );

		await expect(
			guide.getByText( "1 tool is on. Wapuu's resting." )
		).toBeVisible();
		await expect(
			guide.getByRole( 'heading', {
				name: 'What do you want help with?',
			} )
		).toBeHidden();
	} );
} );
